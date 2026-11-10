#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Import the accountant's "LOAN REGISTER & TRANSACTION HISTORY" report
 * (pdftotext -layout output) into the platform.
 *
 * For every loan account in the report with a balance owing (or all loans
 * with --include-paid-off) it:
 *   1. Finds the member (users.member_number = the part before the dash in
 *      "1212-B") and the loan (loan_number "1212B"), creating the loan and its
 *      loan account when missing.
 *   2. Takes the latest note from the header line (loan date, principal, APR,
 *      term, instalment, first payment, current balance) and the journal rows
 *      from that note onward (or from "Balance brought forward" when the note
 *      predates the journal).
 *   3. Writes every payment AS POSTED in the register (its own principal /
 *      interest split and balance), nets out reversals, keeps unmatched
 *      reversals as adjustments, and sets the loan's balance to the register's
 *      current balance. Same-day principal/interest rows are merged into one
 *      payment.
 *   4. Rebuilds the schedule (paid rows + remaining instalments) and reports,
 *      for information, whether the platform's daily-interest formula would
 *      have reproduced the register (it does for most loans; the rest carry
 *      manual allocations in the legacy system).
 *
 * Dry run by default; nothing is written without --commit. Each loan is its
 * own transaction, so one bad loan does not block the rest.
 *
 * Usage:
 *   pdftotext -layout Part_2.pdf part2.txt   (one per PDF, then cat them together)
 *   php import_loan_register.php --text=/root/register.txt                 # dry run, active loans
 *   php import_loan_register.php --text=/root/register.txt --commit
 *   Options: --only=1212B,1263A  --skip=1263A  --include-paid-off  --parse-only
 *            --tenant=UUID  --env=/path/.env  --position=YYYY-MM-DD (date of the report)
 */

// ---------- Options ----------
$opts = ['text' => null, 'only' => '', 'skip' => '', 'include-paid-off' => false, 'parse-only' => false,
         'tenant' => '00000000-0000-0000-0000-000000000001', 'env' => __DIR__ . '/../backend/.env',
         'commit' => false, 'position' => null];
foreach (array_slice($argv, 1) as $arg) {
    if (in_array($arg, ['--commit', '--include-paid-off', '--parse-only'], true)) { $opts[substr($arg, 2)] = true; continue; }
    if (str_starts_with($arg, '--') && str_contains($arg, '=')) { [$k, $v] = explode('=', substr($arg, 2), 2); $opts[$k] = trim($v); continue; }
    fwrite(STDERR, "Unknown argument: {$arg}\n"); exit(1);
}
if (empty($opts['text']) || !is_readable($opts['text'])) { fwrite(STDERR, "--text=/path/to/register.txt is required (pdftotext -layout output)\n"); exit(1); }
$only = array_filter(array_map('trim', explode(',', strtoupper($opts['only']))));
$skip = array_filter(array_map('trim', explode(',', strtoupper($opts['skip']))));

$TYPE_CODES = [
    'home improvement' => 'A', 'car purchase' => 'B', 'debt consolidation' => 'C', 'small business' => 'D',
    'car repairs' => 'E', 'travel' => 'F', 'home furnishing' => 'G', 'miscellaneous' => 'H', 'insurance' => 'I',
    'land purchase' => 'J', 'educational' => 'K', 'medical' => 'L', 'xmas loan' => 'M', 'back to school' => 'N', 'other' => 'O',
];
$CATEGORY = ['A' => 'personal', 'B' => 'auto', 'C' => 'personal', 'D' => 'business', 'E' => 'personal', 'F' => 'personal', 'G' => 'personal',
             'H' => 'personal', 'I' => 'personal', 'J' => 'mortgage', 'K' => 'education', 'L' => 'personal', 'M' => 'personal', 'N' => 'education', 'O' => 'personal'];

// ---------- Parser ----------
function pdate(string $s): ?string { $d = DateTime::createFromFormat('d-M-Y', $s); return $d ? $d->format('Y-m-d') : null; }
function money(string $t): float { $neg = str_starts_with($t, '(') || str_starts_with($t, '-'); $v = (float) str_replace(',', '', trim($t, '()-')); return $neg ? -$v : $v; }

function parseRegister(string $text): array
{
    $blocks = []; $ci = -1; $colmap = null; $section = 'earlier'; $position = null;
    foreach (explode("\n", $text) as $line) {
        if ($position === null && preg_match('/Position at (\d{1,2} \w+ \d{4})/', $line, $pm)) {
            $position = date('Y-m-d', strtotime($pm[1]));
        }
        if (str_contains($line, 'Chartered Accountant') || str_starts_with($line, 'LOAN REGISTER')) continue;
        if (preg_match('/^\s*Loan account (\S+) \| (.+?) \| Loan No\. (\S+) \| (.+?)\s*$/', $line, $m)) {
            $blocks[] = ['account' => $m[1], 'name' => trim($m[2]), 'loan_no' => $m[3], 'type' => trim($m[4]), 'hdr' => null, 'rows' => [], 'flags' => []];
            $ci = count($blocks) - 1;
            $colmap = null; $section = 'earlier';
            continue;
        }
        if ($ci < 0) continue;
        if ($blocks[$ci]['hdr'] === null && preg_match('/Loan date (\d{2}-\w{3}-\d{4}); principal ([\d,]+\.\d{2}); ([\d.]+)% over (\d+) months; instalment ([\d,]+\.\d{2}); first payment (\d{2}-\w{3}-\d{4})\.? Current balance (-?[\d,]+\.\d{2}); last activity ([^.]*)/', $line, $m)) {
            $blocks[$ci]['hdr'] = ['loan_date' => pdate($m[1]), 'principal' => money($m[2]), 'rate' => (float) $m[3], 'term' => (int) $m[4],
                           'instalment' => money($m[5]), 'first_payment' => pdate($m[6]), 'current_balance' => money($m[7]),
                           'last_activity' => pdate(trim($m[8])) ?: null];
            continue;
        }
        if (preg_match('/^\s*Date\s+/', $line) && (str_contains($line, 'Principal') || str_contains($line, 'Balance'))) {
            $colmap = [];
            foreach (['Principal', 'Interest', 'Total received', 'Advanced', 'Amount / balance', 'Balance'] as $c) {
                $i = strpos($line, $c); if ($i !== false) $colmap[$c] = $i + strlen($c);
            }
            if (!isset($colmap['Total received']) && ($i = strpos($line, 'Total')) !== false) $colmap['Total'] = $i + 5;
            $section = str_contains($line, 'Trans No') ? 'journal' : 'earlier';
            continue;
        }
        if (str_contains($line, 'may be incomplete')) { $blocks[$ci]['flags'][] = 'earlier history incomplete'; continue; }
        if (str_contains($line, 'No transaction history')) { $blocks[$ci]['flags'][] = 'no history'; continue; }
        if ($colmap && preg_match('/^\s*(\d{2}-\w{3}-\d{4})\b/', $line, $dm, PREG_OFFSET_CAPTURE)) {
            $date = pdate($dm[1][0]); if ($date === null) continue;
            $dateEnd = $dm[1][1] + strlen($dm[1][0]);
            preg_match_all('/\(?-?[\d,]+\.\d{2}\)?/', $line, $mm, PREG_OFFSET_CAPTURE);
            $firstMoney = $mm[0] ? $mm[0][0][1] : strlen($line);
            $desc = trim(preg_replace('/^\s*(\d{3,}|[A-Z])\s+/', '', substr($line, $dateEnd, $firstMoney - $dateEnd)));
            $transNo = preg_match('/^\s*(\d{3,})\s/', substr($line, $dateEnd), $tm) ? $tm[1] : null;
            $row = ['date' => $date, 'desc' => $desc, 'section' => $section, 'trans_no' => $transNo];
            foreach ($mm[0] as [$tok, $off]) {
                $end = $off + strlen($tok); $best = null; $bestD = PHP_INT_MAX;
                foreach ($colmap as $name => $edge) { $d = abs($edge - $end); if ($d < $bestD) { $bestD = $d; $best = $name; } }
                $row[$best] = money($tok);
            }
            if (isset($row['Total']) && !isset($row['Total received'])) { $row['Total received'] = $row['Total']; unset($row['Total']); }
            if (isset($row['Amount / balance'])) {
                $v = $row['Amount / balance']; unset($row['Amount / balance']);
                if (in_array(strtoupper($desc), ['NEW LOAN', 'REFINANCED LOAN', 'INITIAL BALANCE'], true)) $row['Advanced'] = $v; else $row['Balance'] = $v;
            }
            $blocks[$ci]['rows'][] = $row;
        }
    }
    return ['position' => $position, 'blocks' => $blocks];
}

/**
 * Pick the starting point and the as-posted payments for the latest note.
 * Returns [start => ['date','balance','desc'], payments => [...], prior => ['balance','last_payment'], error]
 */
function latestHistory(array $b): array
{
    $h = $b['hdr'];
    $journal = array_values(array_filter($b['rows'], fn($r) => $r['section'] === 'journal'));
    $rows = $journal ?: $b['rows'];
    // Latest advance (new/refinanced) in the journal; else the balance brought forward
    $startIdx = null;
    foreach ($rows as $i => $r) { if (($r['Advanced'] ?? 0) > 0) $startIdx = $i; }
    if ($startIdx === null) {
        foreach ($rows as $i => $r) { if (stripos($r['desc'], 'brought forward') !== false || stripos($r['desc'], 'INITIAL BALANCE') !== false) { $startIdx = $i; break; } }
    }
    if ($startIdx === null) return ['error' => 'no starting point (no advance or balance brought forward)'];
    $s = $rows[$startIdx];
    $start = ['date' => $s['date'], 'balance' => $s['Balance'] ?? ($s['Advanced'] ?? $h['principal']), 'desc' => $s['desc']];
    // prior balance / last payment before the start, for the refinance carry-over check
    $prior = ['balance' => 0.0, 'last_payment' => null];
    for ($i = $startIdx - 1; $i >= 0; $i--) { if (isset($rows[$i]['Balance'])) { $prior['balance'] = $rows[$i]['Balance']; break; } }
    for ($i = $startIdx - 1; $i >= 0; $i--) { if (($rows[$i]['Total received'] ?? 0) > 0) { $prior['last_payment'] = $rows[$i]['date']; break; } }

    $pays = [];
    for ($i = $startIdx + 1; $i < count($rows); $i++) {
        $r = $rows[$i];
        if (($r['Advanced'] ?? 0) > 0) return ['error' => 'a later advance follows the one matching the header'];
        $tot = $r['Total received'] ?? 0.0;
        $prin = $r['Principal'] ?? 0.0; $int = $r['Interest'] ?? 0.0;
        if ($tot == 0 && $prin == 0 && $int == 0) continue;
        if ($tot == 0) $tot = $prin + $int;
        $pays[] = ['date' => $r['date'], 'total' => $tot, 'principal' => $prin, 'interest' => $int, 'balance' => $r['Balance'] ?? null, 'desc' => $r['desc'], 'trans_no' => $r['trans_no']];
    }
    // Net reversals against an equal earlier posting; keep the rest as adjustments
    $out = [];
    foreach ($pays as $p) {
        if ($p['total'] < 0) {
            $matched = false;
            for ($j = count($out) - 1; $j >= 0; $j--) {
                if (abs($out[$j]['total'] + $p['total']) < 0.005 && $out[$j]['total'] > 0) { array_splice($out, $j, 1); $matched = true; break; }
            }
            if (!$matched) $out[] = $p;
            continue;
        }
        $out[] = $p;
    }
    // Merge same-day positive rows (principal and interest posted separately)
    $merged = [];
    foreach ($out as $p) {
        $last = $merged ? $merged[count($merged) - 1] : null;
        if ($last && $last['date'] === $p['date'] && $last['total'] > 0 && $p['total'] > 0) {
            $merged[count($merged) - 1]['total'] += $p['total'];
            $merged[count($merged) - 1]['principal'] += $p['principal'];
            $merged[count($merged) - 1]['interest'] += $p['interest'];
            if ($p['balance'] !== null) $merged[count($merged) - 1]['balance'] = $p['balance'];
        } else {
            $merged[] = $p;
        }
    }
    return ['start' => $start, 'payments' => $merged, 'prior' => $prior, 'error' => null];
}

/** Does the platform's daily formula reproduce the register? (information only) */
function formulaCheck(array $h, array $start, array $pays, array $prior): array
{
    $tryCarry = [0.0];
    if ($prior['balance'] > 0.005 && $prior['last_payment'] && stripos($start['desc'], 'refinanc') !== false) {
        $days = (new DateTimeImmutable($start['date']))->diff(new DateTimeImmutable($prior['last_payment']))->days;
        $tryCarry[] = round($prior['balance'] * $h['rate'] / 100 / 365 * $days, 2);
    }
    $best = null;
    foreach ($tryCarry as $carry) {
        $bal = $start['balance']; $last = new DateTimeImmutable($start['date']); $r = $h['rate'] / 100 / 365; $bad = 0; $first = true;
        foreach ($pays as $p) {
            if ($p['total'] <= 0) continue;
            $pd = new DateTimeImmutable($p['date']); $days = max(0, (int) $pd->diff($last)->days);
            $int = round($bal * $r * $days, 2); if ($first) { $int = round($int + $carry, 2); $first = false; }
            $ip = round(min($int, $p['total']), 2); $prin = round($p['total'] - $ip, 2); if ($prin > $bal) $prin = round($bal, 2);
            $bal = round($bal - $prin, 2);
            if (abs($ip - $p['interest']) >= 0.005 || ($p['balance'] !== null && abs($bal - $p['balance']) >= 0.005)) $bad++;
            $last = $pd;
        }
        if ($best === null || $bad < $best['bad']) $best = ['bad' => $bad, 'carry' => $carry, 'final' => $bal];
    }
    return $best;
}

// ---------- Parse ----------
$parsed = parseRegister(file_get_contents($opts['text']));
$blocks = $parsed['blocks'];
$position = $opts['position'] ?? $parsed['position'] ?? date('Y-m-d');
$active = array_filter($blocks, fn($b) => $b['hdr'] && $b['hdr']['current_balance'] > 0.005);
printf("Register parsed: %d loan accounts, %d with a balance owing (position %s)\n", count($blocks), count($active), $position);

$selected = [];
foreach ($blocks as $b) {
    $loanNo = strtoupper(str_replace('-', '', $b['account']));
    if ($only && !in_array($loanNo, $only, true)) continue;
    if ($skip && in_array($loanNo, $skip, true)) continue;
    if (!$b['hdr']) { echo "  skip {$b['account']}: no note header\n"; continue; }
    if (!$opts['include-paid-off'] && $b['hdr']['current_balance'] <= 0.005) continue;
    if (in_array('no history', $b['flags'], true) && $b['hdr']['current_balance'] <= 0.005) continue;
    $selected[] = $b;
}
printf("Selected %d loan(s)%s\n", count($selected), $opts['commit'] ? ' — COMMIT' : ' — DRY RUN (nothing written)');

// ---------- Bootstrap backend (unless parse-only) ----------
if (!$opts['parse-only']) {
    define('BASE_PATH', realpath(__DIR__ . '/../backend'));
    spl_autoload_register(function (string $class): void {
        if (strncmp($class, 'App\\', 4) !== 0) return;
        $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (file_exists($file)) require_once $file;
    });
    if (!function_exists('env')) {
        function env(string $key, mixed $default = null): mixed {
            $v = $_ENV[$key] ?? getenv($key); if ($v === false || $v === '') return $default;
            return match (strtolower((string) $v)) { 'true' => true, 'false' => false, 'null' => null, default => $v };
        }
    }
    if (is_readable($opts['env'])) {
        foreach (file($opts['env'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line); if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2); $k = trim($k); $v = trim($v, " \t\"'"); $_ENV[$k] = $v; putenv("{$k}={$v}");
        }
    }
    $db = \App\Core\Database::getInstance();
    $db->setTenantId($opts['tenant']);
    $tenant = $opts['tenant'];
    $loans = new \App\Services\LoanService();   // schema checks (DDL) happen here, outside any transaction
}
$uuid = function (): string { $d = random_bytes(16); $d[6] = chr(ord($d[6]) & 0x0f | 0x40); $d[8] = chr(ord($d[8]) & 0x3f | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4)); };

// ---------- Process ----------
$stats = ['imported' => 0, 'created' => 0, 'skipped' => 0, 'failed' => 0, 'formula_ok' => 0];
printf("\n%-8s %-26s %-10s %4s %12s %12s  %-8s %s\n", 'Loan', 'Member', 'Start', 'Pmts', 'Total paid', 'Balance', 'Formula', 'Result');
foreach ($selected as $b) {
    $h = $b['hdr']; $acct = $b['account']; $loanNo = strtoupper(str_replace('-', '', $acct)); $memberNo = explode('-', $acct)[0];
    $code = $TYPE_CODES[strtolower($b['type'])] ?? 'O';
    $hist = latestHistory($b);
    $label = sprintf('%-8s %-26s', $loanNo, mb_substr($b['name'], 0, 26));
    if ($hist['error']) { printf("%s %s\n", $label, 'SKIP: ' . $hist['error']); $stats['skipped']++; continue; }
    $start = $hist['start']; $pays = $hist['payments'];
    $paid = array_sum(array_map(fn($p) => $p['total'], $pays));
    $lastBal = null; foreach ($pays as $p) { if ($p['balance'] !== null) $lastBal = $p['balance']; }
    $final = $lastBal ?? $start['balance'];
    $balanceNote = abs($final - $h['current_balance']) < 0.005 ? '' : sprintf(' (journal ends %.2f, header says %.2f — header used)', $final, $h['current_balance']);
    $fc = formulaCheck($h, $start, $pays, $hist['prior']);
    $formula = $fc['bad'] === 0 ? 'exact' : "{$fc['bad']} diff";
    if ($fc['bad'] === 0) $stats['formula_ok']++;

    if ($opts['parse-only']) {
        printf("%s %-10s %4d %12.2f %12.2f  %-8s %s\n", $label, $start['date'], count($pays), $paid, $h['current_balance'], $formula, 'parsed' . $balanceNote);
        continue;
    }

    try {
        $member = $db->fetchOne("SELECT id FROM users WHERE tenant_id = ? AND member_number = ?", [$tenant, $memberNo]);
        if ($member === null) { printf("%s %-10s %4d %12.2f %12.2f  %-8s %s\n", $label, $start['date'], count($pays), $paid, $h['current_balance'], $formula, "SKIP: member {$memberNo} not in users"); $stats['skipped']++; continue; }

        $db->beginTransaction();
        $loan = $db->fetchOne('SELECT * FROM loans WHERE tenant_id = ? AND loan_number = ?', [$tenant, $loanNo]);
        if ($loan !== null && $loan['user_id'] !== $member['id']) throw new RuntimeException('loan belongs to a different member');
        $created = false;
        $category = $CATEGORY[$code]; $description = $b['type'];
        try { $lt = $db->fetchOne('SELECT category, description FROM loan_types WHERE tenant_id = ? AND code = ?', [$tenant, $code]); if ($lt) { $category = $lt['category']; $description = $lt['description']; } } catch (Throwable) {}

        if ($loan === null) {
            $acctId = $uuid();
            $db->query('INSERT INTO accounts (id, tenant_id, user_id, account_number, account_type, name, currency, balance, available_balance, interest_rate, status, opened_at, created_at, updated_at)
                        VALUES (?, ?, ?, ?, "loan", ?, "USD", 0, 0, ?, "active", ?, NOW(), NOW())',
                [$acctId, $tenant, $member['id'], $loanNo, $description . ' - ' . $loanNo, $h['rate'], $h['loan_date']]);
            $loanId = $uuid();
            $db->query('INSERT INTO loans (id, tenant_id, user_id, account_id, loan_number, loan_type, principal_amount, interest_rate, term_months, monthly_payment, outstanding_balance, status, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, "active", ?, NOW())',
                [$loanId, $tenant, $member['id'], $acctId, $loanNo, $category, $h['principal'], $h['rate'], $h['term'], $h['instalment'], $h['loan_date'] . ' 00:00:00']);
            $loan = $db->fetchOne('SELECT * FROM loans WHERE id = ?', [$loanId]);
            $created = true;
        }
        if (empty($loan['account_id'])) throw new RuntimeException('loan has no loan account');
        $funded = (int) $db->fetchColumn("SELECT COUNT(*) FROM transactions WHERE tenant_id = ? AND account_id = ? AND type = 'loan_payment' AND related_account_id IS NOT NULL", [$tenant, $loan['account_id']]);
        if ($funded > 0) throw new RuntimeException("{$funded} payment(s) were paid from member accounts; reverse them first");

        $db->execute("DELETE FROM transactions WHERE tenant_id = ? AND account_id = ? AND type IN ('loan_payment','loan_disbursement','adjustment')", [$tenant, $loan['account_id']]);
        $db->execute('DELETE FROM loan_schedules WHERE loan_id = ?', [$loan['id']]);

        $outstanding = max(0.0, $h['current_balance']);
        $paidOff = $outstanding <= 0.005;
        $lastPay = null; foreach ($pays as $p) { if ($p['total'] > 0) $lastPay = $p['date']; }
        $meta = json_decode((string) ($loan['metadata'] ?? ''), true) ?: [];
        $meta = array_merge($meta, [
            'source' => 'legacy_register', 'as_posted' => true, 'type_code' => $code, 'legacy_loan_no' => $b['loan_no'],
            'first_payment_date' => $h['first_payment'], 'last_payment_date' => $start['date'],
            'start_balance' => $start['balance'], 'start_desc' => $start['desc'],
            'register_position' => $position, 'register_imported_at' => date('Y-m-d H:i:s'), 'register_rows' => count($pays),
            'formula_matches' => $fc['bad'] === 0, 'opening_interest' => 0,
        ]);
        $db->execute('UPDATE loans SET principal_amount = ?, disbursed_amount = ?, interest_rate = ?, term_months = ?, monthly_payment = ?,
                        outstanding_balance = ?, total_paid = ?, status = ?, loan_type = ?, loan_type_code = ?, purpose = COALESCE(purpose, ?),
                        disbursed_at = ?, approved_at = COALESCE(approved_at, ?), next_payment_date = ?, maturity_date = ?, metadata = ?, updated_at = NOW()
                      WHERE id = ?',
            [$h['principal'], $h['principal'], $h['rate'], $h['term'], $h['instalment'],
             $outstanding, round($paid, 2), $paidOff ? 'paid_off' : 'active', $category, $code, $description,
             $h['loan_date'] . ' 00:00:00', $h['loan_date'] . ' 00:00:00',
             $paidOff ? null : date('Y-m-d', strtotime('+1 month', strtotime($lastPay ?? $start['date']))),
             date('Y-m-d', strtotime("+{$h['term']} months", strtotime($h['loan_date']))), json_encode($meta), $loan['id']]);
        $db->execute('UPDATE accounts SET balance = ?, available_balance = ?, interest_rate = ?, status = ?, opened_at = ?, updated_at = NOW() WHERE id = ?',
            [$outstanding, $outstanding, $h['rate'], $paidOff ? 'closed' : 'active', $h['loan_date'], $loan['account_id']]);

        // Payments as posted
        $lastDate = new DateTimeImmutable($start['date']); $n = 0;
        foreach ($pays as $p) {
            $pd = new DateTimeImmutable($p['date']);
            if ($p['total'] < 0) {
                $db->query('INSERT INTO transactions (id, tenant_id, reference_number, type, status, amount, account_id, balance_after, description, metadata, created_at)
                            VALUES (?, ?, ?, "adjustment", "completed", ?, ?, ?, ?, ?, ?)',
                    [$uuid(), $tenant, 'LR-' . str_replace('-', '', $p['date']) . '-' . strtoupper(bin2hex(random_bytes(3))), $p['total'], $loan['account_id'], $p['balance'],
                     'Reversal (legacy register) - ' . $loanNo, json_encode(['loan_id' => $loan['id'], 'source' => 'legacy_register', 'reversal' => true, 'principal' => $p['principal'], 'interest' => $p['interest'], 'trans_no' => $p['trans_no']]),
                     $p['date'] . ' 12:00:00']);
                continue;
            }
            $n++;
            $days = max(0, (int) $pd->diff($lastDate)->days);
            $db->query('INSERT INTO transactions (id, tenant_id, reference_number, type, status, amount, account_id, balance_after, description, metadata, created_at)
                        VALUES (?, ?, ?, "loan_payment", "completed", ?, ?, ?, ?, ?, ?)',
                [$uuid(), $tenant, 'LP-' . str_replace('-', '', $p['date']) . '-' . strtoupper(bin2hex(random_bytes(3))), $p['total'], $loan['account_id'], $p['balance'],
                 "Loan payment - {$loanNo} (" . ($p['desc'] ?: 'legacy register') . ')',
                 json_encode(['loan_id' => $loan['id'], 'payment_date' => $p['date'], 'method' => 'legacy', 'source' => 'legacy_register',
                              'principal' => round($p['principal'], 2), 'interest' => round($p['interest'], 2), 'days_accrued' => $days,
                              'daily_rate' => $h['rate'] / 100 / 365, 'trans_no' => $p['trans_no'], 'provisional' => false]),
                 $p['date'] . ' 12:00:00']);
            $db->query("INSERT INTO loan_schedules (id, loan_id, payment_number, due_date, principal_amount, interest_amount, total_amount, paid_amount, status, paid_at, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'paid', ?, NOW())",
                [$uuid(), $loan['id'], $n, $p['date'], round($p['principal'], 2), round($p['interest'], 2), $p['total'], $p['total'], $p['date'] . ' 12:00:00']);
            $lastDate = $pd;
        }
        $loans->regenerateUpcoming($loan['id'], $lastPay ?? $start['date']);

        if ($opts['commit']) $db->commit(); else $db->rollBack();
        $stats['imported']++; if ($created) $stats['created']++;
        printf("%s %-10s %4d %12.2f %12.2f  %-8s %s\n", $label, $start['date'], $n, $paid, $outstanding, $formula, ($created ? 'created' : 'updated') . ($opts['commit'] ? '' : ' (dry run)') . $balanceNote);
    } catch (Throwable $e) {
        try { $db->rollBack(); } catch (Throwable) {}
        $stats['failed']++;
        printf("%s %-10s %4d %12.2f %12.2f  %-8s %s\n", $label, $start['date'], count($pays), $paid, $h['current_balance'], $formula, 'FAILED: ' . $e->getMessage());
    }
}
echo str_repeat('-', 100) . "\n";
printf("%s: %d imported (%d created), %d skipped, %d failed; the daily formula reproduces %d of them exactly.\n",
    $opts['parse-only'] ? 'Parsed' : ($opts['commit'] ? 'COMMITTED' : 'Dry run'), $stats['imported'] ?: count($selected), $stats['created'], $stats['skipped'], $stats['failed'], $stats['formula_ok']);
if (!$opts['commit'] && !$opts['parse-only']) echo "Nothing was written. Re-run with --commit to apply.\n";
