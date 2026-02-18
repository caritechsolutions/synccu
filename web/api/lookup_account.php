<?php
require_once '../config.php';
requireLogin();

header('Content-Type: application/json');

if (!hasPermission('transactions') && !hasPermission('inquiry')) {
    http_response_code(403);
    echo json_encode([]);
    exit();
}

$q    = trim($_GET['q']    ?? '');
$type = trim($_GET['type'] ?? '');

if (!$q) {
    echo json_encode([]);
    exit();
}

// Whitelist account types
$valid_types = ['savings', 'checking', 'loan', 'share', 'certificate'];
$type_filter = in_array($type, $valid_types) ? $type : null;

$params = [$q, "%{$q}%", "%{$q}%"];
$type_clause = '';
if ($type_filter) {
    $type_clause = "AND a.account_type = ?";
    $params[] = $type_filter;
}

$stmt = $pdo->prepare("
    SELECT a.id, a.account_number, a.account_type, a.balance,
           CONCAT(m.first_name, ' ', m.last_name) AS name,
           m.member_number
    FROM member_accounts a
    JOIN members m ON a.member_id = m.id
    WHERE (m.member_number = ? OR a.account_number LIKE ? OR CONCAT(m.first_name,' ',m.last_name) LIKE ?)
      AND a.is_active = 1
      AND m.is_active = 1
      {$type_clause}
    ORDER BY m.last_name, a.account_type
    LIMIT 20
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Cast balance to float for clean JSON
foreach ($rows as &$r) {
    $r['balance'] = (float)$r['balance'];
}

echo json_encode($rows);
