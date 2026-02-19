<?php
/**
 * Import historical transactions from transactions.csv into the transactions table.
 *
 * Usage (run from project root):
 *   php import_transactions.php
 *
 * Source file: transactions.csv
 *
 * CSV columns:
 *   0: GL_Account    1: GL_Name     2: Date (MM/DD/YYYY)  3: Trans_No
 *   4: Sub_Account   5: Member_Acct 6: Acct_Suffix        7: Acct_Type (Loan|Share|empty)
 *   8: Acct_Type_Desc  9: Check_No  10: Explanation      11: Debit    12: Credit
 *
 * GL account mapping (member-linked rows only):
 *   Loan accounts:
 *     111.00 INTEREST ON LOANS  Credit > 0  → 'interest'     balance +amount
 *     719.00 LOANS TO MEMBERS   Credit > 0  → 'loan_payment' balance -amount
 *     719.00 LOANS TO MEMBERS   Debit > 0   → 'deposit'      balance +amount (new loan disbursement)
 *     700.00/701.00 CASH                    → SKIPPED (cash-side counter-entry, already captured above)
 *
 *   Share/Savings accounts (GL 840, 900, 901):
 *     Credit > 0 → 'deposit'    balance +amount
 *     Debit  > 0 → 'withdrawal' balance -amount
 *     700.00/701.00 CASH        → SKIPPED (balance-sheet rows cover all share transactions)
 *
 * Account lookup:
 *   Loans:  member_number + suffix A/B/C → 1st/2nd/3rd loan account (by date_opened)
 *   Shares: member_number + share_type_code (00/01/04/05 etc.) → share subaccount
 *           suffix 00 also checks savings account (account_type='savings')
 *
 * Idempotent: reference_number = "{Trans_No}-{GL_Account}" — safe to re-run.
 */

// ── Load .env ─────────────────────────────────────────────────────────────────
$envFile = __DIR__ . '/web/.env';
if (!file_exists($envFile)) {
    die("[ERROR] .env not found at {$envFile}. Run install.sh first.\n");
}

foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if (str_starts_with($line, '#') || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $_ENV[trim($k)] = trim($v);
}

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'synccu';
$dbUser = $_ENV['DB_USER'] ?? 'root';
$dbPass = $_ENV['DB_PASS'] ?? '';

// ── Connect ───────────────────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("[ERROR] Database connection failed: " . $e->getMessage() . "\n");
}

// ── Load CSV ──────────────────────────────────────────────────────────────────
$csvFile = __DIR__ . '/transactions.csv';
if (!file_exists($csvFile)) {
    die("[ERROR] File not found: {$csvFile}\n");
}

// ── GL accounts we import (skip CASH rows — they are counter-entries) ─────────
const GL_LOAN_INTEREST  = '111.00';  // INTEREST ON LOANS
const GL_LOAN_BALANCE   = '719.00';  // LOANS TO MEMBERS
const GL_SHARE_DEPOSIT  = ['840.00', '900.00', '901.00']; // balance-sheet accounts

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Convert MM/DD/YYYY → YYYY-MM-DD, return null on failure. */
function parseDate(string $d): ?string
{
    $dt = DateTime::createFromFormat('m/d/Y', $d);
    return $dt ? $dt->format('Y-m-d') : null;
}

/** Cache: member_number → id */
$memberCache = [];
$stmtMember = $pdo->prepare("SELECT id FROM members WHERE member_number = ? LIMIT 1");

function getMemberId(string $memberNo): ?int
{
    global $pdo, $memberCache, $stmtMember;
    if (!isset($memberCache[$memberNo])) {
        $stmtMember->execute([$memberNo]);
        $id = $stmtMember->fetchColumn();
        $memberCache[$memberNo] = $id !== false ? (int)$id : null;
    }
    return $memberCache[$memberNo];
}

/**
 * Cache: (member_id, acct_type, key) → account_id
 * For loans:  key = 'A'/'B'/'C' (ordinal suffix)
 * For shares: key = share_type_code string '00','01', etc.
 */
$accountCache = [];

function getAccountId(int $memberId, string $acctType, string $suffix): ?int
{
    global $pdo, $accountCache;
    $cacheKey = "{$memberId}|{$acctType}|{$suffix}";
    if (array_key_exists($cacheKey, $accountCache)) {
        return $accountCache[$cacheKey];
    }

    $id = null;

    if ($acctType === 'Loan') {
        // A=1st, B=2nd, C=3rd loan account ordered by date_opened
        $ordinal = ord(strtoupper($suffix)) - ord('A'); // 0-based
        $stmt = $pdo->prepare("
            SELECT id FROM member_accounts
            WHERE member_id = ? AND account_type = 'loan' AND is_active = 1
            ORDER BY date_opened, id
            LIMIT 1 OFFSET ?
        ");
        $stmt->execute([$memberId, $ordinal]);
        $row = $stmt->fetchColumn();
        $id = $row !== false ? (int)$row : null;

    } elseif ($acctType === 'Share') {
        // suffix is share_type_code (e.g. '00', '01', '05')
        $stmt = $pdo->prepare("
            SELECT id FROM member_accounts
            WHERE member_id = ? AND account_type = 'share' AND share_type_code = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$memberId, $suffix]);
        $row = $stmt->fetchColumn();
        $id = $row !== false ? (int)$row : null;

        // Suffix '00' may also be the base savings account
        if ($id === null && $suffix === '00') {
            $stmt2 = $pdo->prepare("
                SELECT id FROM member_accounts
                WHERE member_id = ? AND account_type = 'savings' AND is_active = 1
                LIMIT 1
            ");
            $stmt2->execute([$memberId]);
            $row2 = $stmt2->fetchColumn();
            $id = $row2 !== false ? (int)$row2 : null;
        }
    }

    $accountCache[$cacheKey] = $id;
    return $id;
}

// ── Running balances per account (for balance_after calculation) ───────────────
$runningBalances = [];

function getRunningBalance(int $accountId): float
{
    global $runningBalances, $pdo;
    if (!isset($runningBalances[$accountId])) {
        // Start from any already-imported transactions for this account
        // (so re-runs pick up from where they left off)
        $stmt = $pdo->prepare("
            SELECT balance_after FROM transactions
            WHERE account_id = ? ORDER BY transaction_date, id DESC LIMIT 1
        ");
        $stmt->execute([$accountId]);
        $last = $stmt->fetchColumn();
        $runningBalances[$accountId] = $last !== false ? (float)$last : 0.0;
    }
    return $runningBalances[$accountId];
}

// ── Prepared statements ───────────────────────────────────────────────────────
$stmtCheckRef = $pdo->prepare(
    "SELECT COUNT(*) FROM transactions WHERE reference_number = ?"
);

$stmtInsert = $pdo->prepare("
    INSERT INTO transactions
        (account_id, transaction_type, amount, balance_after, reference_number,
         description, transaction_date, created_at)
    VALUES
        (?, ?, ?, ?, ?, ?, ?, ?)
");

// ── Parse and sort all importable rows first ─────────────────────────────────
// We sort by date ASC so running balances are computed chronologically.

echo "Reading transactions.csv...\n";
$rows = [];

$fh = fopen($csvFile, 'r');
$header = fgetcsv($fh); // skip header row
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) < 13) continue;

    $glAccount = trim($row[0]);
    $acctType  = trim($row[7]);   // 'Loan', 'Share', or ''

    // Only process member-linked rows
    if (!in_array($acctType, ['Loan', 'Share'], true)) continue;

    // Only import the primary GL accounts (skip CASH counter-entries)
    if ($acctType === 'Loan') {
        if (!in_array($glAccount, [GL_LOAN_INTEREST, GL_LOAN_BALANCE], true)) continue;
    } else {
        if (!in_array($glAccount, GL_SHARE_DEPOSIT, true)) continue;
    }

    $dateStr = parseDate(trim($row[2]));
    if (!$dateStr) continue; // skip rows with unparseable dates

    $debit  = (float)($row[11] ?? 0);
    $credit = (float)($row[12] ?? 0);
    $amount = $debit > 0 ? $debit : $credit;
    if ($amount <= 0) continue; // skip zero-amount rows

    $rows[] = [
        'gl'        => $glAccount,
        'gl_name'   => trim($row[1]),
        'date'      => $dateStr,
        'trans_no'  => trim($row[3]),
        'member_no' => trim($row[5]),
        'suffix'    => trim($row[6]),
        'acct_type' => $acctType,
        'desc'      => trim($row[8]),  // Acct_Type_Desc
        'debit'     => $debit,
        'credit'    => $credit,
        'amount'    => $amount,
    ];
}
fclose($fh);

// Sort chronologically for accurate running balances
usort($rows, fn($a, $b) => strcmp($a['date'] . $a['trans_no'], $b['date'] . $b['trans_no']));

echo count($rows) . " importable rows found. Processing...\n\n";

// ── Import ────────────────────────────────────────────────────────────────────
$inserted   = 0;
$skipped    = 0;
$dupRef     = 0;
$noMember   = [];
$noAccount  = [];

foreach ($rows as $r) {
    $ref = $r['trans_no'] . '-' . $r['gl'];

    // Idempotency: skip already-imported rows
    $stmtCheckRef->execute([$ref]);
    if ($stmtCheckRef->fetchColumn() > 0) {
        $dupRef++;
        continue;
    }

    // Resolve member
    $memberId = getMemberId($r['member_no']);
    if (!$memberId) {
        $noMember[$r['member_no']] = true;
        $skipped++;
        continue;
    }

    // Resolve account
    $accountId = getAccountId($memberId, $r['acct_type'], $r['suffix']);
    if (!$accountId) {
        $noAccount[$r['member_no'] . '-' . $r['suffix']] = true;
        $skipped++;
        continue;
    }

    // Determine transaction type and balance effect
    $type        = null;
    $balanceDelta = 0.0;

    if ($r['acct_type'] === 'Loan') {
        if ($r['gl'] === GL_LOAN_INTEREST && $r['credit'] > 0) {
            $type         = 'interest';
            $balanceDelta = +$r['amount']; // interest increases outstanding balance
        } elseif ($r['gl'] === GL_LOAN_BALANCE && $r['credit'] > 0) {
            $type         = 'loan_payment';
            $balanceDelta = -$r['amount']; // payment reduces outstanding balance
        } elseif ($r['gl'] === GL_LOAN_BALANCE && $r['debit'] > 0) {
            $type         = 'deposit';
            $balanceDelta = +$r['amount']; // new loan disbursement increases balance
        }
    } else {
        // Share / savings account
        if ($r['credit'] > 0) {
            $type         = 'deposit';
            $balanceDelta = +$r['amount'];
        } else {
            $type         = 'withdrawal';
            $balanceDelta = -$r['amount'];
        }
    }

    if (!$type) {
        $skipped++;
        continue;
    }

    // Running balance
    $balanceBefore = getRunningBalance($accountId);
    $balanceAfter  = round($balanceBefore + $balanceDelta, 2);
    $runningBalances[$accountId] = $balanceAfter;

    $description = $r['gl_name'];
    if ($r['desc']) $description .= ' — ' . $r['desc'];

    try {
        $stmtInsert->execute([
            $accountId,
            $type,
            $r['amount'],
            $balanceAfter,
            $ref,
            $description,
            $r['date'] . ' 00:00:00',
            $r['date'] . ' 00:00:00',
        ]);
        $inserted++;
    } catch (PDOException $e) {
        echo "[ERROR] Trans {$ref}: " . $e->getMessage() . "\n";
        $skipped++;
    }
}

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\n";
echo "╔══════════════════════════════════════╗\n";
echo "║    Transaction Import Complete       ║\n";
echo "╚══════════════════════════════════════╝\n";
echo "\n";
echo "  Rows in CSV          : " . (count($rows) + $skipped) . "\n";
echo "  Inserted             : {$inserted}\n";
echo "  Already imported     : {$dupRef}\n";
echo "  Skipped (errors)     : {$skipped}\n";
echo "\n";

if (!empty($noMember)) {
    $keys = array_keys($noMember);
    sort($keys);
    echo "  Members not found (" . count($keys) . "): " . implode(', ', $keys) . "\n";
}

if (!empty($noAccount)) {
    $keys = array_keys($noAccount);
    sort($keys);
    echo "  Accounts not found (" . count($keys) . "):\n";
    foreach ($keys as $k) {
        echo "    - {$k}\n";
    }
}

echo "\n";
echo "  Note: balance_after values are approximations based on the order of\n";
echo "  imported rows. They do not affect the live account balances in\n";
echo "  member_accounts (those remain unchanged).\n";
echo "\n";
