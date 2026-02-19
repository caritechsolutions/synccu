<?php
/**
 * Import accounts from account_listing.xlsx into the member_accounts table.
 *
 * Usage (run from project root):
 *   php import_accounts.php
 *
 * Reads two sheets from the workbook:
 *
 *   "Loan Accounts"  → member_accounts (account_type = 'loan')
 *     Columns: Member Acct# | Loan Account | Member Name | Loan Code | Loan Type
 *
 *   "Share Accounts" → member_accounts (account_type = 'share')
 *     Columns: Member Acct# | Share Account | Member Name | Share Code
 *
 * member_id is resolved by looking up member_number in the members table.
 * Uses ON DUPLICATE KEY UPDATE on account_number — safe to re-run.
 *
 * Note: the loans table (principal, rate, term, etc.) requires data not present
 * in this file and must be populated separately.
 */

// ── Load .env ─────────────────────────────────────────────────────────────────
$envFile = __DIR__ . '/web/.env';
if (!file_exists($envFile)) {
    die("[ERROR] .env not found at {$envFile}. Run install.sh first.\n");
}

foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if (str_starts_with($line, '#') || !str_contains($line, '=')) {
        continue;
    }
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

// ── XLSX helpers (uses system unzip + SimpleXML — no PHP extensions needed) ────

/**
 * Extract the xlsx into a temp directory and return that directory path.
 * Registers a shutdown function to clean up the temp dir automatically.
 */
function xlsxExtract(string $xlsxPath): string
{
    if (!is_file($xlsxPath)) {
        die("[ERROR] File not found: {$xlsxPath}\n");
    }
    $tmpDir = sys_get_temp_dir() . '/synccu_xlsx_' . uniqid();
    $safe   = escapeshellarg($xlsxPath);
    $safeTmp = escapeshellarg($tmpDir);
    exec("unzip -q {$safe} -d {$safeTmp} 2>&1", $out, $rc);
    if ($rc !== 0) {
        die("[ERROR] unzip failed (exit {$rc}): " . implode("\n", $out) . "\n");
    }
    // Clean up temp dir when the script finishes
    register_shutdown_function(function () use ($tmpDir) {
        exec('rm -rf ' . escapeshellarg($tmpDir));
    });
    return $tmpDir;
}

/**
 * Parse xl/_rels/workbook.xml.rels and xl/workbook.xml to return a map of
 * sheet name → absolute filesystem path to the sheet XML.
 */
function xlsxSheetPaths(string $tmpDir): array
{
    $relXml = file_get_contents("{$tmpDir}/xl/_rels/workbook.xml.rels");
    $wbXml  = file_get_contents("{$tmpDir}/xl/workbook.xml");

    if ($relXml === false || $wbXml === false) {
        die("[ERROR] Could not read workbook XML files from extracted xlsx.\n");
    }

    // rId → filesystem path (targets are like /xl/worksheets/sheet2.xml)
    $targets = [];
    $rel = new SimpleXMLElement($relXml);
    foreach ($rel->Relationship as $r) {
        $target = ltrim((string)$r['Target'], '/'); // strip leading /
        $targets[(string)$r['Id']] = "{$tmpDir}/{$target}";
    }

    // Sheet name → rId
    $wb = new SimpleXMLElement($wbXml);
    $wb->registerXPathNamespace('ns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $rNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    $sheets = [];
    foreach ($wb->xpath('//ns:sheet') as $sheet) {
        $name = (string)$sheet['name'];
        $rId  = (string)$sheet->attributes($rNs)['id'];
        if (isset($targets[$rId])) {
            $sheets[$name] = $targets[$rId];
        }
    }

    return $sheets;
}

/**
 * Convert a column letter(s) to a 0-based index (A=0, B=1, … Z=25, AA=26 …).
 */
function colIndex(string $col): int
{
    $idx = 0;
    foreach (str_split(strtoupper($col)) as $ch) {
        $idx = $idx * 26 + (ord($ch) - 64);
    }
    return $idx - 1;
}

/**
 * Read a worksheet XML file and return a 2D array of string values (row-major, 0-based).
 * All cells in this workbook use t="inlineStr"; numeric cells have no type attr.
 */
function xlsxReadSheet(string $sheetFile): array
{
    $xml = file_get_contents($sheetFile);
    if ($xml === false) {
        die("[ERROR] Cannot read sheet file: {$sheetFile}\n");
    }

    $doc = new SimpleXMLElement($xml);
    $doc->registerXPathNamespace('ns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    $sparse = []; // rowIdx → [ colIdx => value ]
    $maxCol = 0;

    foreach ($doc->xpath('//ns:row') as $xmlRow) {
        $rowIdx = (int)$xmlRow['r'] - 1; // 0-based

        foreach ($xmlRow->xpath('ns:c') as $c) {
            // Extract column letter from cell reference (e.g. "B3" → "B")
            preg_match('/^([A-Za-z]+)/', (string)$c['r'], $m);
            $colIdx = colIndex($m[1]);
            $maxCol = max($maxCol, $colIdx);

            $type = (string)$c['t'];

            if ($type === 'inlineStr') {
                $val = (string)($c->is->t ?? '');
            } elseif ($type === 's') {
                // Shared string (not present in this file, but handled gracefully)
                $val = (string)($c->v ?? '');
            } else {
                // Numeric or empty
                $val = (string)($c->v ?? '');
            }

            $sparse[$rowIdx][$colIdx] = trim($val);
        }
    }

    // Flatten to dense rows
    $rows = [];
    foreach ($sparse as $rowIdx => $cells) {
        $dense = [];
        for ($c = 0; $c <= $maxCol; $c++) {
            $dense[] = $cells[$c] ?? '';
        }
        $rows[$rowIdx] = $dense;
    }
    ksort($rows);

    return array_values($rows);
}

// ── Prepared statements ───────────────────────────────────────────────────────

$stmtLookup = $pdo->prepare(
    "SELECT id FROM members WHERE member_number = :mn LIMIT 1"
);

$stmtUpsert = $pdo->prepare("
    INSERT INTO member_accounts
        (member_id, account_number, account_type, notes)
    VALUES
        (:member_id, :account_number, :account_type, :notes)
    ON DUPLICATE KEY UPDATE
        member_id    = VALUES(member_id),
        account_type = VALUES(account_type),
        notes        = VALUES(notes)
");

// ── Open workbook ─────────────────────────────────────────────────────────────

$xlsxFile = __DIR__ . '/account_listing.xlsx';
if (!file_exists($xlsxFile)) {
    die("[ERROR] File not found: {$xlsxFile}\n");
}

$tmpDir     = xlsxExtract($xlsxFile);
$sheetPaths = xlsxSheetPaths($tmpDir);

// ── Import function ───────────────────────────────────────────────────────────

/**
 * @param array  $rows        Sheet rows (header on row 0).
 * @param string $accountType 'loan' or 'share'.
 * @param int    $colMemberNo Column index for the member number.
 * @param int    $colAcctNo   Column index for the account number.
 * @param int    $colCode     Column index for loan/share code.
 * @param int    $colLabel    Column index for human-readable type label (loans only; -1 = n/a).
 */
function importSheet(
    array  $rows,
    string $accountType,
    int    $colMemberNo,
    int    $colAcctNo,
    int    $colCode,
    int    $colLabel,
    PDO    $pdo,
    PDOStatement $stmtLookup,
    PDOStatement $stmtUpsert
): array {
    $inserted  = 0;
    $updated   = 0;
    $skipped   = 0;
    $notFound  = [];

    foreach (array_slice($rows, 1) as $row) { // skip header
        $memberNo  = $row[$colMemberNo] ?? '';
        $accountNo = $row[$colAcctNo]   ?? '';
        $code      = $row[$colCode]     ?? '';
        $label     = $colLabel >= 0 ? ($row[$colLabel] ?? '') : '';

        if ($memberNo === '' || $accountNo === '') {
            $skipped++;
            continue;
        }

        // Build notes string
        if ($accountType === 'loan') {
            $notes = $label !== ''
                ? "Loan Type: {$label}"
                : ($code !== '' ? "Loan Code: {$code}" : null);
        } else {
            $notes = $code !== '' ? "Share Code: {$code}" : null;
        }

        // Resolve member_id
        $stmtLookup->execute([':mn' => $memberNo]);
        $memberId = $stmtLookup->fetchColumn();

        if ($memberId === false) {
            if (!in_array($memberNo, $notFound, true)) {
                $notFound[] = $memberNo;
            }
            $skipped++;
            continue;
        }

        try {
            $exists = (int)$pdo->query(
                "SELECT COUNT(*) FROM member_accounts WHERE account_number = "
                . $pdo->quote($accountNo)
            )->fetchColumn();

            $stmtUpsert->execute([
                ':member_id'     => $memberId,
                ':account_number'=> $accountNo,
                ':account_type'  => $accountType,
                ':notes'         => $notes,
            ]);

            $exists === 0 ? $inserted++ : $updated++;
        } catch (PDOException $e) {
            echo "[ERROR] Account #{$accountNo}: " . $e->getMessage() . "\n";
            $skipped++;
        }
    }

    return compact('inserted', 'updated', 'skipped', 'notFound');
}

// ── Process Loan Accounts ─────────────────────────────────────────────────────
// Columns: 0=Member Acct# | 1=Loan Account | 2=Member Name | 3=Loan Code | 4=Loan Type

$loanStats = ['inserted'=>0,'updated'=>0,'skipped'=>0,'notFound'=>[]];

if (!isset($sheetPaths['Loan Accounts'])) {
    echo "[WARN] 'Loan Accounts' sheet not found — skipping.\n";
} else {
    echo "Processing Loan Accounts...\n";
    $rows      = xlsxReadSheet($sheetPaths['Loan Accounts']);
    $loanStats = importSheet($rows, 'loan', 0, 1, 3, 4, $pdo, $stmtLookup, $stmtUpsert);
}

// ── Process Share Accounts ────────────────────────────────────────────────────
// Columns: 0=Member Acct# | 1=Share Account | 2=Member Name | 3=Share Code

$shareStats = ['inserted'=>0,'updated'=>0,'skipped'=>0,'notFound'=>[]];

if (!isset($sheetPaths['Share Accounts'])) {
    echo "[WARN] 'Share Accounts' sheet not found — skipping.\n";
} else {
    echo "Processing Share Accounts...\n";
    $rows       = xlsxReadSheet($sheetPaths['Share Accounts']);
    $shareStats = importSheet($rows, 'share', 0, 1, 3, -1, $pdo, $stmtLookup, $stmtUpsert);
}

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\n";
echo "╔══════════════════════════════════════╗\n";
echo "║     Import Complete                  ║\n";
echo "╚══════════════════════════════════════╝\n";
echo "\n";

$sections = [
    'Loan Accounts'  => $loanStats,
    'Share Accounts' => $shareStats,
];

foreach ($sections as $label => $s) {
    $total = $s['inserted'] + $s['updated'] + $s['skipped'];
    echo "  {$label}:\n";
    echo "    Rows read : {$total}\n";
    echo "    Inserted  : {$s['inserted']}\n";
    echo "    Updated   : {$s['updated']}\n";
    echo "    Skipped   : {$s['skipped']}\n";
    if (!empty($s['notFound'])) {
        sort($s['notFound']);
        echo "    Not in members table: " . implode(', ', $s['notFound']) . "\n";
    }
    echo "\n";
}
