<?php

declare(strict_types=1);

/**
 * Legacy REPORT.TXT importer for the SyncCU platform.
 *
 * Imports the old core-system member/account dump into the platform's
 * `users` (role=member) and `accounts` tables, preserving the
 * {member_number}-{suffix} account-numbering pattern.
 *
 * Source columns (CSV, 7 fields):
 *   [0] account number   e.g. "1011" (base) or "1011-05" (sub)
 *   [1] name             "LAST,FIRST MIDDLE"
 *   [2] national id      "YYMMDD-XXXX"  (sometimes a joint name)
 *   [3] suffix code      "00".."07"     (blank on the base row)
 *   [4] balance
 *   [5] alt national id  (rarely populated)
 *   [6] date of birth    "MM/DD/YYYY"
 *
 * Rules (confirmed):
 *   - Every row (base AND sub) is its own account, NOT a total.
 *   - Suffix "05" => permanent_shares; every other account => regular_shares.
 *   - One member per base number. Online banking enabled for all: placeholder
 *     email when none, a standard password, force change on first login.
 *
 * Usage:
 *   php import_legacy_report.php /path/to/REPORT.TXT               # DRY RUN (default)
 *   php import_legacy_report.php /path/to/REPORT.TXT --commit      # write to DB
 *   Options:
 *     --commit                 actually write (otherwise dry run)
 *     --tenant=UUID            target tenant (default: the seeded default tenant)
 *     --password=SECRET        standard initial password (default: ChangeMe#2024)
 *     --currency=USD           account currency (default: USD)
 *     --email-domain=host      placeholder email domain (default: import.local)
 *     --env=/path/to/.env      load DB_* creds from an env file (default: backend/.env)
 */

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------
$argvv = $argv;
array_shift($argvv);
$file = null;
$opts = [
    'commit'       => false,
    'tenant'       => '00000000-0000-0000-0000-000000000001',
    'password'     => 'ChangeMe#2024',
    'currency'     => 'USD',
    'email-domain' => 'import.local',
    'env'          => __DIR__ . '/../backend/.env',
];
foreach ($argvv as $arg) {
    if ($arg === '--commit') { $opts['commit'] = true; continue; }
    if (str_starts_with($arg, '--')) {
        [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, '');
        $opts[$k] = $v;
        continue;
    }
    $file = $arg;
}
if ($file === null || !is_readable($file)) {
    fwrite(STDERR, "ERROR: pass a readable path to REPORT.TXT as the first argument.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// DB connection (only needed for --commit; loaded lazily)
// ---------------------------------------------------------------------------
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
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $db   = getenv('DB_DATABASE') ?: 'synccu';
    $user = getenv('DB_USERNAME') ?: 'synccu_user';
    $pass = getenv('DB_PASSWORD') ?: '';
    $dsn  = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}
function uuid(): string {
    $d = random_bytes(16);
    $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
    $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

// ---------------------------------------------------------------------------
// Parse
// ---------------------------------------------------------------------------
$fh = fopen($file, 'r');
$members = [];   // member_number => identity
$accounts = [];  // list of account rows
$warnings = [];
$idRe = '/^\d{6}-?\d{3,4}$/';  // national id YYMMDD-XXXX, dash optional

while (($row = fgetcsv($fh)) !== false) {
    if (count($row) < 7) continue;
    $acctNo = trim($row[0]);
    if ($acctNo === '') continue;

    $base   = explode('-', $acctNo)[0];
    $suffix = str_contains($acctNo, '-') ? trim($row[3]) : '';
    $name   = trim($row[1]);
    $col3   = trim($row[2]);
    $col6   = trim($row[5]);
    $balRaw = trim($row[4]);
    $dobRaw = trim($row[6]);
    $balance = is_numeric($balRaw) ? (float) $balRaw : 0.0;

    // Member identity (taken from the base row; sub rows just confirm it)
    if (!isset($members[$base])) {
        // Name "LAST,FIRST MIDDLE"
        $last = $name; $first = '';
        if (str_contains($name, ',')) {
            [$last, $first] = array_map('trim', explode(',', $name, 2));
        }
        // National id: prefer col3 if id-shaped, else col6 if id-shaped
        $nid = null;
        if (preg_match($idRe, $col3))      $nid = $col3;
        elseif (preg_match($idRe, $col6))  $nid = $col6;
        elseif ($col3 !== '' && !preg_match($idRe, $col3)) {
            $warnings[] = "member {$base}: col3 is not an ID (\"{$col3}\") — treated as no national_id (possible joint owner)";
        }
        // DOB "MM/DD/YYYY" => Y-m-d
        $dob = null;
        if ($dobRaw !== '') {
            $dt = DateTime::createFromFormat('m/d/Y', $dobRaw);
            if ($dt) $dob = $dt->format('Y-m-d');
            else $warnings[] = "member {$base}: unparseable DOB \"{$dobRaw}\"";
        }
        $members[$base] = [
            'member_number' => $base,
            'first_name'    => $first,
            'last_name'     => $last,
            'national_id'   => $nid,
            'date_of_birth' => $dob,
        ];
    }

    if ($balance < 0) $warnings[] = "account {$acctNo}: negative balance {$balance}";

    $accounts[] = [
        'account_number' => $acctNo,
        'member_number'  => $base,
        'account_type'   => ($suffix === '05') ? 'permanent_shares' : 'regular_shares',
        'name'           => ($suffix === '05') ? 'Permanent Shares' : 'Regular Shares',
        'balance'        => $balance,
    ];
}
fclose($fh);

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
$byType = array_count_values(array_column($accounts, 'account_type'));
$withDob = count(array_filter($members, fn($m) => $m['date_of_birth'] !== null));
$withNid = count(array_filter($members, fn($m) => $m['national_id'] !== null));

echo "SyncCU legacy import — " . ($opts['commit'] ? "COMMIT" : "DRY RUN") . "\n";
echo str_repeat('-', 60) . "\n";
echo "Source file        : {$file}\n";
echo "Tenant             : {$opts['tenant']}\n";
echo "Members (users)    : " . count($members) . "  (with DOB {$withDob}, with national_id {$withNid})\n";
echo "Accounts total     : " . count($accounts) . "\n";
foreach ($byType as $t => $n) echo "  - {$t}: {$n}\n";
echo "Placeholder email  : m{member_number}@{$opts['email-domain']}\n";
echo "Initial password   : {$opts['password']}  (force change on first login)\n";
echo "Warnings           : " . count($warnings) . "\n";
foreach (array_slice($warnings, 0, 15) as $w) echo "    ! {$w}\n";
if (count($warnings) > 15) echo "    ... (" . (count($warnings) - 15) . " more)\n";
echo str_repeat('-', 60) . "\n";

if (!$opts['commit']) {
    echo "DRY RUN — nothing written. Re-run with --commit to import.\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Write (idempotent)
// ---------------------------------------------------------------------------
$pdo = pdo($opts);
$tenant = $opts['tenant'];
$hash = password_hash($opts['password'], PASSWORD_BCRYPT, ['cost' => 12]);

$findUser = $pdo->prepare('SELECT id FROM users WHERE tenant_id = ? AND member_number = ?');
$findAcct = $pdo->prepare('SELECT id FROM accounts WHERE tenant_id = ? AND account_number = ?');
$insUser  = $pdo->prepare(
    'INSERT INTO users (id, member_number, tenant_id, email, password_hash, first_name, last_name,
                        national_id, date_of_birth, role, status, force_password_change, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "member", "active", 1, NOW(), NOW())'
);
$insAcct = $pdo->prepare(
    'INSERT INTO accounts (id, tenant_id, user_id, account_number, account_type, name, currency,
                           balance, available_balance, status, opened_at, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "active", NOW(), NOW(), NOW())'
);

$stats = ['members_created' => 0, 'members_existing' => 0, 'accounts_created' => 0, 'accounts_existing' => 0];
$userIdByMember = [];

$pdo->beginTransaction();
try {
    foreach ($members as $mnum => $m) {
        $findUser->execute([$tenant, $mnum]);
        $existing = $findUser->fetchColumn();
        if ($existing !== false) {
            $userIdByMember[$mnum] = $existing;
            $stats['members_existing']++;
            continue;
        }
        $uid = uuid();
        $email = 'm' . $mnum . '@' . $opts['email-domain'];
        $insUser->execute([
            $uid, $mnum, $tenant, $email, $hash,
            $m['first_name'], $m['last_name'], $m['national_id'], $m['date_of_birth'],
        ]);
        $userIdByMember[$mnum] = $uid;
        $stats['members_created']++;
    }

    foreach ($accounts as $a) {
        $findAcct->execute([$tenant, $a['account_number']]);
        if ($findAcct->fetchColumn() !== false) { $stats['accounts_existing']++; continue; }
        $uid = $userIdByMember[$a['member_number']] ?? null;
        if ($uid === null) continue; // member skipped/missing — should not happen
        $insAcct->execute([
            uuid(), $tenant, $uid, $a['account_number'], $a['account_type'], $a['name'],
            $opts['currency'], $a['balance'], $a['balance'],
        ]);
        $stats['accounts_created']++;
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "IMPORT FAILED (rolled back): " . $e->getMessage() . "\n");
    exit(1);
}

echo "COMMITTED\n";
echo "  members  created {$stats['members_created']}, already existed {$stats['members_existing']}\n";
echo "  accounts created {$stats['accounts_created']}, already existed {$stats['accounts_existing']}\n";
