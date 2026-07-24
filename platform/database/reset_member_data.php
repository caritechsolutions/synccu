<?php

declare(strict_types=1);

/**
 * DANGEROUS: wipes member (client) data for a tenant so the legacy import can
 * be re-run on a clean slate. KEEPS all staff logins (super_admin/admin/
 * manager/teller), tenants, tenant settings, account products and network config.
 *
 * DELETES (for the target tenant): every account and all member-facing /
 * transactional data — accounts, transactions, loans, loan_schedules,
 * bill_payments, payees, applications, member_messages, documents,
 * notifications, member refresh_tokens — and all users whose role = 'member'.
 *
 * Defaults to a DRY RUN (counts only). Nothing is deleted without --confirm.
 *
 * Usage:
 *   php reset_member_data.php                 # DRY RUN — shows what would be deleted
 *   php reset_member_data.php --confirm       # actually delete
 *   Options:
 *     --tenant=UUID          target tenant (default: seeded default tenant)
 *     --env=/path/to/.env    DB_* creds file (default: backend/.env)
 */

$argvv = $argv; array_shift($argvv);
$opts = [
    'confirm' => false,
    'tenant'  => '00000000-0000-0000-0000-000000000001',
    'env'     => __DIR__ . '/../backend/.env',
];
foreach ($argvv as $arg) {
    if ($arg === '--confirm') { $opts['confirm'] = true; continue; }
    if (str_starts_with($arg, '--')) {
        [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, '');
        $opts[$k] = $v;
    }
}

function loadEnv(string $path): void {
    if (!is_readable($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        putenv(trim($k) . '=' . trim($v, " \t\"'"));
    }
}
loadEnv($opts['env']);
$pdo = new PDO(
    'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('DB_PORT') ?: '3306')
        . ';dbname=' . (getenv('DB_DATABASE') ?: 'synccu') . ';charset=utf8mb4',
    getenv('DB_USERNAME') ?: 'synccu_user',
    getenv('DB_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
);
$dbName = getenv('DB_DATABASE') ?: 'synccu';
$tenant = $opts['tenant'];

function tableExists(PDO $pdo, string $db, string $t): bool {
    $s = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
    $s->execute([$db, $t]);
    return (bool) $s->fetchColumn();
}
function hasColumn(PDO $pdo, string $db, string $t, string $c): bool {
    $s = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
    $s->execute([$db, $t, $c]);
    return (bool) $s->fetchColumn();
}

/** Build the WHERE clause + params that scope a table's rows to the tenant's member data. */
function scopeFor(PDO $pdo, string $db, string $t, string $tenant): ?array {
    if (!tableExists($pdo, $db, $t)) return null;
    if (hasColumn($pdo, $db, $t, 'tenant_id')) {
        return ['tenant_id = ?', [$tenant]];
    }
    if (hasColumn($pdo, $db, $t, 'loan_id')) {   // loan_schedules
        return ['loan_id IN (SELECT id FROM loans WHERE tenant_id = ?)', [$tenant]];
    }
    if (hasColumn($pdo, $db, $t, 'user_id')) {   // refresh_tokens
        return ["user_id IN (SELECT id FROM users WHERE role='member' AND tenant_id = ?)", [$tenant]];
    }
    return null;
}

// Children before parents.
$tables = [
    'bill_payments', 'payees', 'applications', 'member_messages',
    'documents', 'notifications', 'loan_schedules', 'loans',
    'transactions', 'accounts', 'refresh_tokens',
];

// Report / plan
$staffKept = (int) $pdo->query(
    "SELECT COUNT(*) FROM users WHERE tenant_id = " . $pdo->quote($tenant) . " AND role <> 'member'"
)->fetchColumn();
$memberUsers = (int) $pdo->query(
    "SELECT COUNT(*) FROM users WHERE tenant_id = " . $pdo->quote($tenant) . " AND role = 'member'"
)->fetchColumn();

echo 'SyncCU member-data reset — ' . ($opts['confirm'] ? 'CONFIRM (DELETING)' : 'DRY RUN') . "\n";
echo str_repeat('-', 60) . "\n";
echo "Tenant           : {$tenant}\n";
echo "Staff logins KEPT: {$staffKept}\n";
echo "Member users     : {$memberUsers}  (will be deleted)\n";
echo "Rows to delete:\n";

$plan = [];
foreach ($tables as $t) {
    $scope = scopeFor($pdo, $dbName, $t, $tenant);
    if ($scope === null) { continue; }
    [$where, $params] = $scope;
    $c = $pdo->prepare("SELECT COUNT(*) FROM `{$t}` WHERE {$where}");
    $c->execute($params);
    $n = (int) $c->fetchColumn();
    $plan[] = [$t, $where, $params, $n];
    printf("  %-16s %d\n", $t, $n);
}
printf("  %-16s %d\n", 'users(member)', $memberUsers);
echo str_repeat('-', 60) . "\n";

if (!$opts['confirm']) {
    echo "DRY RUN — nothing deleted. Re-run with --confirm to wipe.\n";
    echo "TIP: back up first →  mysqldump {$dbName} | gzip > /root/synccu-before-reset.sql.gz\n";
    exit(0);
}

if ($staffKept === 0) {
    fwrite(STDERR, "ABORT: no staff logins would remain for this tenant — refusing to wipe.\n");
    exit(1);
}

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$pdo->beginTransaction();
try {
    $deleted = [];
    foreach ($plan as [$t, $where, $params, $n]) {
        $st = $pdo->prepare("DELETE FROM `{$t}` WHERE {$where}");
        $st->execute($params);
        $deleted[$t] = $st->rowCount();
    }
    $du = $pdo->prepare("DELETE FROM users WHERE role='member' AND tenant_id = ?");
    $du->execute([$tenant]);
    $deleted['users(member)'] = $du->rowCount();

    $pdo->commit();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
} catch (Throwable $e) {
    $pdo->rollBack();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    fwrite(STDERR, 'RESET FAILED (rolled back): ' . $e->getMessage() . "\n");
    exit(1);
}

echo "DELETED\n";
foreach ($deleted as $t => $n) printf("  %-16s %d\n", $t, $n);
echo "Staff logins preserved: {$staffKept}\n";
echo "Now re-import:  php " . __DIR__ . "/import_legacy_report.php /root/REPORT.TXT --commit\n";
