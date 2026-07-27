<?php

declare(strict_types=1);

/**
 * Legacy loan importer (TEMP01.DAT) for the SyncCU platform.
 *
 * Source columns (whitespace-delimited, 11 fields):
 *   [0] member number    e.g. "1011"
 *   [1] loan sequence    e.g. "1"
 *   [2] loan number      e.g. "1011A"  (member number + letter slot)
 *   [3] current balance  (outstanding)
 *   [4] loan type code   A-O  (see loan_types)
 *   [5] loan amount      (original principal)
 *   [6] date of loan     MM/DD/YY
 *   [7] first payment    MM/DD/YY
 *   [8] APR              e.g. 12.000
 *   [9] scheduled payment
 *   [10] number of payments  (term)
 *
 * Behaviour (confirmed):
 *   - Each row => a loans record AND a linked loan account (account_type=loan),
 *     numbered with the legacy loan number (1011A). balance/outstanding = the
 *     file's current balance; status active (balance>0) or paid_off ($0).
 *   - Loan type code -> platform category + human description (stored in purpose).
 *   - A loan whose member does not exist in `users` is SKIPPED (e.g. 1347, whose
 *     share account was closed and never imported).
 *
 * Usage:
 *   php import_legacy_loans.php /path/to/TEMP01.DAT            # DRY RUN (default)
 *   php import_legacy_loans.php /path/to/TEMP01.DAT --commit   # write to DB
 *   Options: --tenant=UUID  --currency=USD  --env=/path/.env
 */

$argvv = $argv; array_shift($argvv);
$file = null;
$opts = [
    'commit'   => false,
    'tenant'   => '00000000-0000-0000-0000-000000000001',
    'currency' => 'USD',
    'env'      => __DIR__ . '/../backend/.env',
];
foreach ($argvv as $arg) {
    if ($arg === '--commit') { $opts['commit'] = true; continue; }
    if (str_starts_with($arg, '--')) { [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, ''); $opts[$k] = $v; continue; }
    $file = $arg;
}
if ($file === null || !is_readable($file)) {
    fwrite(STDERR, "ERROR: pass a readable path to TEMP01.DAT as the first argument.\n");
    exit(1);
}

// code => [platform category, human description]
$TYPES = [
    'A' => ['personal', 'Home Improvement'],   'B' => ['auto', 'Car Purchase'],
    'C' => ['personal', 'Debt Consolidation'], 'D' => ['business', 'Small Business'],
    'E' => ['personal', 'Car Repairs'],        'F' => ['personal', 'Travel'],
    'G' => ['personal', 'Home Furnishing'],    'H' => ['personal', 'Miscellaneous'],
    'I' => ['personal', 'Insurance'],          'J' => ['mortgage', 'Land Purchase'],
    'K' => ['education', 'Educational'],        'L' => ['personal', 'Medical'],
    'M' => ['personal', 'Xmas Loan'],          'N' => ['education', 'Back to School'],
    'O' => ['personal', 'Other'],
];

function loadEnv(string $path): void {
    if (!is_readable($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        putenv(trim($k) . '=' . trim($v, " \t\"'"));
    }
}
function pdo(array $opts): PDO {
    loadEnv($opts['env']);
    return new PDO(
        'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('DB_PORT') ?: '3306')
            . ';dbname=' . (getenv('DB_DATABASE') ?: 'synccu') . ';charset=utf8mb4',
        getenv('DB_USERNAME') ?: 'synccu_user', getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
    );
}
function uuid(): string {
    $d = random_bytes(16); $d[6] = chr(ord($d[6]) & 0x0f | 0x40); $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}
function pdate(string $s): ?string {   // MM/DD/YY -> Y-m-d (PHP pivot: 00-68 => 2000s, 69-99 => 1900s)
    $s = trim($s); if ($s === '') return null;
    $dt = DateTime::createFromFormat('m/d/y', $s);
    return $dt ? $dt->format('Y-m-d') : null;
}

// ── Parse ────────────────────────────────────────────────────────
$loans = []; $warnings = [];
foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
    if (trim($line) === '') continue;
    $p = preg_split('/\s+/', trim($line));
    if (count($p) !== 11) { $warnings[] = "unexpected column count (" . count($p) . "): " . trim($line); continue; }
    [$member, $seq, $loanNo, $bal, $code, $amt, $dLoan, $dFirst, $apr, $sched, $npay] = $p;
    $code = strtoupper($code);
    if (!isset($TYPES[$code])) { $warnings[] = "loan {$loanNo}: unknown type code '{$code}' -> Other"; $code = 'O'; }
    [$category, $desc] = $TYPES[$code];
    $outstanding = (float) $bal;
    $loans[] = [
        'member'      => $member,
        'loan_number' => $loanNo,
        'code'        => $code,
        'category'    => $category,
        'description' => $desc,
        'principal'   => (float) $amt,
        'outstanding' => $outstanding,
        'rate'        => (float) $apr,
        'term'        => (int) $npay,
        'payment'     => (float) $sched,
        'date_loan'   => pdate($dLoan),
        'date_first'  => pdate($dFirst),
        'status'      => $outstanding > 0 ? 'active' : 'paid_off',
    ];
}

$byType = []; $active = 0; $paid = 0;
foreach ($loans as $l) {
    $byType[$l['description']] = ($byType[$l['description']] ?? 0) + 1;
    $l['status'] === 'active' ? $active++ : $paid++;
}
ksort($byType);

echo "SyncCU legacy loan import — " . ($opts['commit'] ? "COMMIT" : "DRY RUN") . "\n";
echo str_repeat('-', 60) . "\n";
echo "Source file    : {$file}\n";
echo "Loans parsed   : " . count($loans) . "  (active {$active}, paid_off {$paid})\n";
echo "By type:\n";
foreach ($byType as $t => $n) printf("  %-20s %d\n", $t, $n);
echo "Warnings       : " . count($warnings) . "\n";
foreach (array_slice($warnings, 0, 15) as $w) echo "    ! {$w}\n";
echo str_repeat('-', 60) . "\n";

if (!$opts['commit']) {
    echo "DRY RUN — nothing written. Loans whose member is absent from `users` are skipped on --commit.\n";
    exit(0);
}

// ── Write ────────────────────────────────────────────────────────
$pdo = pdo($opts); $tenant = $opts['tenant'];
$findUser = $pdo->prepare('SELECT id FROM users WHERE tenant_id = ? AND member_number = ?');
$findLoan = $pdo->prepare('SELECT id FROM loans WHERE tenant_id = ? AND loan_number = ?');
$insAcct  = $pdo->prepare(
    'INSERT INTO accounts (id, tenant_id, user_id, account_number, account_type, name, currency,
                           balance, available_balance, interest_rate, status, opened_at, created_at, updated_at)
     VALUES (?, ?, ?, ?, "loan", ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
);
$insLoan = $pdo->prepare(
    'INSERT INTO loans (id, tenant_id, user_id, account_id, loan_number, loan_type, principal_amount,
                        interest_rate, term_months, monthly_payment, outstanding_balance, disbursed_amount,
                        total_paid, status, disbursed_at, maturity_date, purpose, metadata, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
);

$stats = ['loans_created' => 0, 'loans_existing' => 0, 'skipped_no_member' => 0];
$pdo->beginTransaction();
try {
    foreach ($loans as $l) {
        $findUser->execute([$tenant, $l['member']]);
        $userId = $findUser->fetchColumn();
        if ($userId === false) { $stats['skipped_no_member']++; continue; }   // e.g. 1347

        $findLoan->execute([$tenant, $l['loan_number']]);
        if ($findLoan->fetchColumn() !== false) { $stats['loans_existing']++; continue; }

        $acctStatus = $l['status'] === 'active' ? 'active' : 'closed';
        $acctId = uuid();
        $insAcct->execute([
            $acctId, $tenant, $userId, $l['loan_number'], $l['description'], $opts['currency'],
            $l['outstanding'], $l['outstanding'], $l['rate'], $acctStatus, $l['date_loan'] ?? date('Y-m-d'),
        ]);

        $maturity = $l['date_loan'] ? date('Y-m-d', strtotime("+{$l['term']} months", strtotime($l['date_loan']))) : null;
        $totalPaid = max(0, $l['principal'] - $l['outstanding']);
        $meta = json_encode([
            'source' => 'legacy_import', 'type_code' => $l['code'], 'first_payment_date' => $l['date_first'],
        ]);
        $insLoan->execute([
            uuid(), $tenant, $userId, $acctId, $l['loan_number'], $l['category'], $l['principal'],
            $l['rate'], $l['term'], $l['payment'], $l['outstanding'], $l['principal'],
            $totalPaid, $l['status'], $l['date_loan'], $maturity, $l['description'], $meta,
        ]);
        $stats['loans_created']++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "IMPORT FAILED (rolled back): " . $e->getMessage() . "\n");
    exit(1);
}

echo "COMMITTED\n";
echo "  loans created        {$stats['loans_created']}\n";
echo "  loans already existed {$stats['loans_existing']}\n";
echo "  skipped (no member)   {$stats['skipped_no_member']}\n";
