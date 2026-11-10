#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Load a legacy loan ledger (the old core system's "Loan Ledger" print-out)
 * onto a member's loan and replay it through the platform's own interest
 * engine, so the loan ends up with the same splits, balance and history.
 *
 * What it does (inside one DB transaction; dry run by default):
 *   1. Finds the member (by member number) and the loan (by loan number),
 *      creating the loan + loan account if they do not exist.
 *   2. Resets the loan to its note terms: principal, APR, term, installment,
 *      loan date, first payment date, type code; wipes any payments already
 *      posted in this system for the loan (cash/legacy ones only).
 *   3. Posts every payment line from the ledger file at its date.
 *   4. Replays the history (LoanService::replayPayments): interest = balance
 *      × APR ÷ 365 × days since the previous payment, interest first.
 *   5. Prints a row-by-row comparison with the ledger's own interest,
 *      principal and balance columns, then commits only with --commit.
 *
 * Ledger file: one line per ledger row, copied from the print-out. Each line
 * starts with the date (MM/DD/YY or YYYY-MM-DD) followed by the numbers, e.g.
 *     08/29/24  400.00  239.95  160.05  25835.94        (pmt int prin bal)
 *     08/29/24  400.00                                   (pmt only)
 *     08/08/24  1921  25995.99  20435.99  0.00  20435.99  25995.99   (note row)
 * A row whose "amount loaned" is > 0 is the note (disbursement / refinance)
 * row and is not a payment. Lines starting with # are ignored.
 *
 * Usage:
 *   php import_loan_ledger.php --member=1011 --loan=1011A --ledger=/root/1011A.txt \
 *       --principal=25995.99 --rate=12 --term=96 --payment=425.68 \
 *       --loan-date=2024-08-08 --first-payment=2024-09-30 --type=A \
 *       [--refinanced-balance=20435.99 --refinanced-last-payment=2024-07-30 | --opening-interest=60.47] \
 *       [--pledged=8578.68 --pledged-account=1011] [--tenant=UUID] [--env=/path/.env] --commit
 *
 *   --refinanced-balance / --refinanced-last-payment: when the note refinanced an
 *       older one, the old note's balance and its last payment date. The interest
 *       that accrued on the old balance up to the loan date is carried into the
 *       first payment (that is how the legacy ledger does it). --opening-interest
 *       sets that carried amount directly instead.
 */

// ---------- Options ----------
$opts = [
    'member' => null, 'loan' => null, 'ledger' => null,
    'principal' => null, 'rate' => null, 'term' => null, 'payment' => null,
    'loan-date' => null, 'first-payment' => null, 'type' => null,
    'refinanced-balance' => null, 'refinanced-last-payment' => null, 'opening-interest' => null,
    'pledged' => null, 'pledged-account' => null,
    'tenant' => '00000000-0000-0000-0000-000000000001',
    'env' => __DIR__ . '/../backend/.env',
    'commit' => false,
];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--commit') { $opts['commit'] = true; continue; }
    if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
        [$k, $v] = explode('=', substr($arg, 2), 2);
        $opts[$k] = trim($v);
        continue;
    }
    fwrite(STDERR, "Unknown argument: {$arg}\n");
    exit(1);
}
foreach (['member', 'loan', 'ledger'] as $req) {
    if (empty($opts[$req])) { fwrite(STDERR, "--{$req} is required\n"); exit(1); }
}
if (!is_readable($opts['ledger'])) { fwrite(STDERR, "Cannot read ledger file {$opts['ledger']}\n"); exit(1); }

// ---------- Bootstrap the backend ----------
define('BASE_PATH', realpath(__DIR__ . '/../backend'));
spl_autoload_register(function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) return;
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (file_exists($file)) require_once $file;
});
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed {
        $v = $_ENV[$key] ?? getenv($key);
        if ($v === false || $v === '') return $default;
        return match (strtolower((string) $v)) { 'true' => true, 'false' => false, 'null' => null, default => $v };
    }
}
if (is_readable($opts['env'])) {
    foreach (file($opts['env'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v, " \t\"'");
        $_ENV[$k] = $v; putenv("{$k}={$v}");
    }
}

use App\Core\Database;
use App\Services\LoanService;

$db = Database::getInstance();
$db->setTenantId($opts['tenant']);
$tenant = $opts['tenant'];
$loans = new LoanService();   // runs its schema checks (DDL) before we open a transaction

// ---------- Parse the ledger ----------
function parseDate(string $s): ?string {
    $s = trim($s);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
    $dt = DateTime::createFromFormat('m/d/y', $s) ?: DateTime::createFromFormat('m/d/Y', $s);
    return $dt ? $dt->format('Y-m-d') : null;
}
$payments = []; $noteRow = null; $warnings = [];
foreach (file($opts['ledger'], FILE_IGNORE_NEW_LINES) as $n => $raw) {
    $line = trim($raw);
    if ($line === '' || $line[0] === '#') continue;
    $tokens = preg_split('/[\s*]+/', $line);
    $date = parseDate($tokens[0] ?? '');
    if ($date === null) { $warnings[] = "line " . ($n + 1) . ": no date, skipped"; continue; }
    $nums = array_values(array_filter(array_slice($tokens, 1), fn($t) => preg_match('/^-?\d+\.\d{2}$/', $t)));
    $nums = array_map('floatval', $nums);
    if (count($nums) >= 5 && $nums[0] > 0) {            // note row: loaned, pmt, int, prin, bal
        $noteRow = ['date' => $date, 'loaned' => $nums[0], 'balance' => $nums[4]];
        continue;
    }
    if (count($nums) >= 5) { array_shift($nums); }      // drop the 0.00 "amount loaned"
    if (count($nums) === 0 || $nums[0] <= 0) { $warnings[] = "line " . ($n + 1) . ": no payment amount, skipped"; continue; }
    $payments[] = [
        'date' => $date, 'amount' => $nums[0],
        'interest' => $nums[1] ?? null, 'principal' => $nums[2] ?? null, 'balance' => $nums[3] ?? null,
    ];
}
usort($payments, fn($a, $b) => strcmp($a['date'], $b['date']));
if (!$payments) { fwrite(STDERR, "No payment rows found in the ledger file.\n"); exit(1); }

// ---------- Resolve the member and loan ----------
$member = $db->fetchOne(
    "SELECT id, CONCAT(first_name, ' ', last_name) AS name FROM users WHERE tenant_id = ? AND member_number = ?",
    [$tenant, $opts['member']],
);
if ($member === null) { fwrite(STDERR, "Member {$opts['member']} not found (users.member_number).\n"); exit(1); }

$loan = $db->fetchOne('SELECT * FROM loans WHERE tenant_id = ? AND loan_number = ?', [$tenant, $opts['loan']]);
if ($loan !== null && $loan['user_id'] !== $member['id']) {
    fwrite(STDERR, "Loan {$opts['loan']} belongs to a different member.\n"); exit(1);
}

$principal = $opts['principal'] !== null ? (float) $opts['principal'] : ($noteRow['loaned'] ?? ($loan['principal_amount'] ?? null));
$rate      = $opts['rate'] !== null ? (float) $opts['rate'] : ($loan['interest_rate'] ?? null);
$term      = $opts['term'] !== null ? (int) $opts['term'] : ($loan['term_months'] ?? null);
$loanDate  = $opts['loan-date'] ?? ($noteRow['date'] ?? ($loan['disbursed_at'] ?? null));
$loanDate  = $loanDate ? substr((string) $loanDate, 0, 10) : null;
foreach (['principal' => $principal, 'rate' => $rate, 'term' => $term, 'loan-date' => $loanDate] as $k => $v) {
    if ($v === null) { fwrite(STDERR, "--{$k} is required (not on the ledger file or the existing loan).\n"); exit(1); }
}
$installment = $opts['payment'] !== null ? (float) $opts['payment'] : null;
if ($installment === null) {
    $r = $rate / 100 / 12;
    $installment = $r > 0 ? round($principal * $r / (1 - pow(1 + $r, -$term)), 2) : round($principal / $term, 2);
}
$firstPayment = $opts['first-payment'] ?? date('Y-m-d', strtotime('+1 month', strtotime($loanDate)));

// Interest carried over from a refinanced note
$openingInterest = 0.0; $carryNote = '';
if ($opts['opening-interest'] !== null) {
    $openingInterest = round((float) $opts['opening-interest'], 2);
    $carryNote = 'given';
} elseif ($opts['refinanced-balance'] !== null && $opts['refinanced-last-payment'] !== null) {
    $days = (int) (new DateTimeImmutable($loanDate))->diff(new DateTimeImmutable($opts['refinanced-last-payment']))->days;
    $openingInterest = round((float) $opts['refinanced-balance'] * $rate / 100 / 365 * $days, 2);
    $carryNote = sprintf('%.2f × %.2f%% ÷ 365 × %d days (%s → %s)', (float) $opts['refinanced-balance'], $rate, $days, $opts['refinanced-last-payment'], $loanDate);
}

// Loan type code → category + description
$typeCode = $opts['type'] !== null ? strtoupper(trim($opts['type'])) : ($loan['loan_type_code'] ?? null);
$category = $loan['loan_type'] ?? 'personal'; $description = $loan['purpose'] ?? null;
if ($typeCode) {
    try {
        $lt = $db->fetchOne('SELECT category, description FROM loan_types WHERE tenant_id = ? AND code = ?', [$tenant, $typeCode]);
        if ($lt) { $category = $lt['category']; $description = $lt['description']; }
        else $warnings[] = "loan type code {$typeCode} not found in loan_types; category left as {$category}";
    } catch (Throwable) {}
}

// ---------- Report the plan ----------
echo "SyncCU loan ledger import — " . ($opts['commit'] ? 'COMMIT' : 'DRY RUN (nothing written)') . "\n";
echo str_repeat('-', 72) . "\n";
printf("Member        : %s (#%s)\n", $member['name'], $opts['member']);
printf("Loan          : %s  %s\n", $opts['loan'], $loan ? '(exists — will be reset and replayed)' : '(new)');
printf("Note          : %.2f at %.2f%% for %d months, installment %.2f, loan date %s, first payment %s\n", $principal, $rate, $term, $installment, $loanDate, $firstPayment);
if ($typeCode) printf("Type          : %s — %s (%s)\n", $typeCode, $description, $category);
if ($openingInterest > 0) printf("Carried int.  : %.2f  [%s]\n", $openingInterest, $carryNote);
if ($opts['pledged'] !== null) printf("Pledged       : %.2f from account %s\n", (float) $opts['pledged'], $opts['pledged-account'] ?? $opts['member']);
printf("Payments      : %d rows, %s → %s, total %.2f\n", count($payments), $payments[0]['date'], end($payments)['date'], array_sum(array_column($payments, 'amount')));
foreach ($warnings as $w) echo "  ! {$w}\n";
echo str_repeat('-', 72) . "\n";

// ---------- Apply inside a transaction ----------
$db->beginTransaction();
try {
    $now = date('Y-m-d H:i:s');
    $uuid = function (): string {
        $d = random_bytes(16); $d[6] = chr(ord($d[6]) & 0x0f | 0x40); $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    };

    if ($loan === null) {
        $acctId = $uuid();
        $db->query(
            'INSERT INTO accounts (id, tenant_id, user_id, account_number, account_type, name, currency, balance, available_balance, interest_rate, status, opened_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, "loan", ?, "USD", ?, ?, ?, "active", ?, NOW(), NOW())',
            [$acctId, $tenant, $member['id'], $opts['loan'], ($description ?: 'Loan') . ' - ' . $opts['loan'], $principal, $principal, $rate, $loanDate],
        );
        $loanId = $uuid();
        $db->query(
            'INSERT INTO loans (id, tenant_id, user_id, account_id, loan_number, loan_type, principal_amount, interest_rate, term_months,
                                monthly_payment, outstanding_balance, disbursed_amount, total_paid, status, disbursed_at, approved_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, "active", ?, ?, ?, NOW())',
            [$loanId, $tenant, $member['id'], $acctId, $opts['loan'], $category, $principal, $rate, $term, $installment, $principal, $principal, $loanDate, $loanDate, $loanDate . ' 00:00:00'],
        );
        $loan = $db->fetchOne('SELECT * FROM loans WHERE id = ?', [$loanId]);
    }
    $loanId = $loan['id'];
    if (empty($loan['account_id'])) {
        throw new RuntimeException('Loan has no loan account; cannot replay.');
    }

    // Refuse to wipe payments that moved money out of member accounts
    $funded = (int) $db->fetchColumn(
        "SELECT COUNT(*) FROM transactions WHERE tenant_id = ? AND account_id = ? AND type = 'loan_payment' AND related_account_id IS NOT NULL",
        [$tenant, $loan['account_id']],
    );
    if ($funded > 0) {
        throw new RuntimeException("{$funded} payment(s) on this loan were paid from member accounts; reverse those manually before re-importing.");
    }
    $removed = $db->execute(
        "DELETE FROM transactions WHERE tenant_id = ? AND account_id = ? AND type IN ('loan_payment', 'loan_disbursement')",
        [$tenant, $loan['account_id']],
    );
    $db->execute('DELETE FROM loan_schedules WHERE loan_id = ?', [$loanId]);

    // Reset the note terms
    $meta = json_decode((string) ($loan['metadata'] ?? ''), true) ?: [];
    $meta = array_merge($meta, [
        'source'             => 'legacy_import',
        'type_code'          => $typeCode,
        'first_payment_date' => $firstPayment,
        'last_payment_date'  => $loanDate,       // accrual starts at the loan date
        'opening_interest'   => $openingInterest,
        'ledger_imported_at' => $now,
        'ledger_rows'        => count($payments),
    ]);
    $collateral = $loan['collateral'] ?? null;
    if ($opts['pledged'] !== null) {
        $collateral = json_encode([
            'type' => 'shares', 'value' => (float) $opts['pledged'],
            'description' => 'Funds pledged from account ' . ($opts['pledged-account'] ?? $opts['member']),
        ]);
    }
    $db->execute(
        'UPDATE loans SET principal_amount = ?, disbursed_amount = ?, interest_rate = ?, term_months = ?, monthly_payment = ?,
                          outstanding_balance = ?, total_paid = 0, status = "active", loan_type = ?, loan_type_code = ?, purpose = ?,
                          disbursed_at = ?, approved_at = COALESCE(approved_at, ?), next_payment_date = ?,
                          maturity_date = ?, collateral = ?, metadata = ?, updated_at = NOW()
         WHERE id = ?',
        [$principal, $principal, $rate, $term, $installment, $principal, $category, $typeCode, $description ?: $loan['purpose'],
         $loanDate . ' 00:00:00', $loanDate . ' 00:00:00', $firstPayment,
         date('Y-m-d', strtotime("+{$term} months", strtotime($loanDate))), $collateral, json_encode($meta), $loanId],
    );
    $db->execute(
        'UPDATE accounts SET balance = ?, available_balance = ?, interest_rate = ?, status = "active", opened_at = ?, updated_at = NOW() WHERE id = ?',
        [$principal, $principal, $rate, $loanDate, $loan['account_id']],
    );

    // Post the payments (provisional; the replay fills in the splits)
    foreach ($payments as $i => $p) {
        $db->query(
            'INSERT INTO transactions (id, tenant_id, reference_number, type, status, amount, account_id, balance_after, processed_by, description, metadata, created_at)
             VALUES (?, ?, ?, "loan_payment", "completed", ?, ?, NULL, NULL, ?, ?, ?)',
            [
                $uuid(), $tenant, 'LP-' . str_replace('-', '', $p['date']) . '-' . strtoupper(bin2hex(random_bytes(3))),
                $p['amount'], $loan['account_id'], "Loan payment - {$opts['loan']} (legacy ledger)",
                json_encode(['loan_id' => $loanId, 'payment_date' => $p['date'], 'method' => 'legacy', 'source' => 'legacy_ledger',
                             'principal' => 0, 'interest' => 0, 'provisional' => true, 'ledger_row' => $i + 1]),
                $p['date'] . ' 12:00:00',
            ],
        );
    }

    // Replay through the platform's interest engine
    $replay = $loans->replayPayments($loanId);

    // Compare with the ledger
    echo sprintf("%-11s %9s %5s %9s %9s %11s   %s\n", 'Date', 'Payment', 'Days', 'Interest', 'Principal', 'Balance', 'vs ledger');
    $mismatch = 0;
    foreach ($replay['payments'] as $i => $r) {
        $exp = $payments[$i] ?? null;
        $flags = [];
        if ($exp && $exp['interest'] !== null && abs($r['interest'] - $exp['interest']) >= 0.005) $flags[] = sprintf('int %.2f', $exp['interest']);
        if ($exp && $exp['principal'] !== null && abs($r['principal'] - $exp['principal']) >= 0.005) $flags[] = sprintf('prin %.2f', $exp['principal']);
        if ($exp && $exp['balance'] !== null && abs($r['balance_after'] - $exp['balance']) >= 0.005) $flags[] = sprintf('bal %.2f', $exp['balance']);
        if ($flags) $mismatch++;
        printf("%-11s %9.2f %5d %9.2f %9.2f %11.2f   %s\n", $r['payment_date'], $r['amount'], $r['days_accrued'], $r['interest'], $r['principal'], $r['balance_after'], $flags ? 'DIFF: ' . implode(', ', $flags) : 'ok');
    }
    echo str_repeat('-', 72) . "\n";
    printf("Outstanding now %.2f, total paid %.2f, status %s, %d row(s) differ from the ledger%s\n",
        $replay['outstanding_balance'], $replay['total_paid'], $replay['status'], $mismatch,
        ($payments[count($payments) - 1]['balance'] !== null ? sprintf(' (ledger closing balance %.2f)', end($payments)['balance']) : ''));
    if ($removed > 0) echo "Replaced {$removed} previously posted loan transaction(s).\n";

    if ($opts['commit']) {
        $db->commit();
        echo "COMMITTED.\n";
    } else {
        $db->rollBack();
        echo "Dry run — rolled back. Re-run with --commit to apply.\n";
    }
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, 'FAILED (rolled back): ' . $e->getMessage() . "\n");
    exit(1);
}
