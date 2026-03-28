<?php
require_once '../config.php';
requireLogin();
if (!hasPermission('transactions') && !hasPermission('inquiry') && !hasPermission('reports')) {
    header('Location: ../index.php');
    exit();
}

$account_id  = (int)($_GET['account_id'] ?? 0);
$member_id   = (int)($_GET['member_id']  ?? 0);
$date_from   = $_GET['date_from']  ?? date('Y-m-01');  // first of month
$date_to     = $_GET['date_to']    ?? date('Y-m-d');
$type_filter = $_GET['type']       ?? '';

$transactions = [];
$account      = null;

$valid_types = ['deposit','withdrawal','transfer_in','transfer_out','loan_payment','interest','fee','adjustment'];

if ($account_id) {
    $stmt = $pdo->prepare("
        SELECT a.*, m.first_name, m.last_name, m.member_number
        FROM member_accounts a JOIN members m ON a.member_id = m.id
        WHERE a.id = ?
    ");
    $stmt->execute([$account_id]);
    $account = $stmt->fetch();
}

if ($account_id) {
    $where  = ['t.account_id = ?'];
    $params = [$account_id];
} elseif ($member_id) {
    $where  = ['m.id = ?'];
    $params = [$member_id];
} else {
    $where  = ['1=1'];
    $params = [];
}

$where[] = 'DATE(t.transaction_date) BETWEEN ? AND ?';
$params[] = $date_from;
$params[] = $date_to;

if ($type_filter && in_array($type_filter, $valid_types)) {
    $where[]  = 't.transaction_type = ?';
    $params[] = $type_filter;
}

$sql = "
    SELECT t.*, a.account_number, a.account_type,
           CONCAT(m.first_name,' ',m.last_name) AS member_name, m.member_number,
           u.full_name AS teller_name
    FROM transactions t
    JOIN member_accounts a ON t.account_id = a.id
    JOIN members m ON a.member_id = m.id
    LEFT JOIN users u ON t.teller_id = u.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY t.transaction_date DESC
    LIMIT 500
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

$total_in  = 0;
$total_out = 0;
foreach ($transactions as $t) {
    if (in_array($t['transaction_type'], ['deposit','transfer_in','interest'])) $total_in  += $t['amount'];
    else $total_out += $t['amount'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncCU - Transaction History</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <a href="../transactions.php" class="back-btn">← Back</a>
    <div class="user-info" style="left:auto;right:20px;">
        <?php echo htmlspecialchars($_SESSION['full_name']); ?> | <?php echo date('m/d/Y'); ?>
    </div>

    <div class="container" style="margin-top:80px;">
        <div class="container-header">
            <h1>Transaction History</h1>
            <p>
                <?php if ($account): ?>
                    Account <?php echo htmlspecialchars($account['account_number']); ?> —
                    <?php echo htmlspecialchars($account['first_name'] . ' ' . $account['last_name']); ?>
                <?php else: ?>
                    All Transactions
                <?php endif; ?>
            </p>
        </div>

        <div class="content">
            <!-- Filters -->
            <form method="GET" class="d-flex gap-10 mb-20" style="flex-wrap:wrap;align-items:flex-end;">
                <?php if ($account_id): ?><input type="hidden" name="account_id" value="<?php echo $account_id; ?>"><?php endif; ?>
                <?php if ($member_id):  ?><input type="hidden" name="member_id"  value="<?php echo $member_id; ?>"><?php endif; ?>

                <div class="form-group" style="margin-bottom:0;">
                    <label>From</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"
                           style="padding:10px;border:2px solid #ddd;border-radius:8px;">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>To</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>"
                           style="padding:10px;border:2px solid #ddd;border-radius:8px;">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>Type</label>
                    <select name="type" style="padding:10px;border:2px solid #ddd;border-radius:8px;background:white;">
                        <option value="">All Types</option>
                        <?php foreach ($valid_types as $vt): ?>
                        <option value="<?php echo $vt; ?>" <?php echo $type_filter === $vt ? 'selected' : ''; ?>>
                            <?php echo ucwords(str_replace('_', ' ', $vt)); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Filter</button>
                <button type="button" onclick="window.print()" class="btn btn-secondary">Print</button>
            </form>

            <!-- Summary -->
            <div class="form-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px;">
                <div style="background:#d4edda;padding:15px;border-radius:8px;text-align:center;">
                    <div style="font-size:0.9rem;color:#155724;">Total In (Credits)</div>
                    <div style="font-size:1.5rem;font-weight:bold;color:#155724;">$<?php echo number_format($total_in, 2); ?></div>
                </div>
                <div style="background:#f8d7da;padding:15px;border-radius:8px;text-align:center;">
                    <div style="font-size:0.9rem;color:#721c24;">Total Out (Debits)</div>
                    <div style="font-size:1.5rem;font-weight:bold;color:#721c24;">$<?php echo number_format($total_out, 2); ?></div>
                </div>
                <div style="background:#e3f2fd;padding:15px;border-radius:8px;text-align:center;">
                    <div style="font-size:0.9rem;color:#1976D2;">Transactions</div>
                    <div style="font-size:1.5rem;font-weight:bold;color:#1976D2;"><?php echo count($transactions); ?></div>
                </div>
            </div>

            <?php if ($transactions): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date &amp; Time</th>
                        <th>Account</th>
                        <th>Member</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Balance After</th>
                        <th>Reference</th>
                        <th>Description</th>
                        <th>Teller</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $t): ?>
                    <?php $is_credit = in_array($t['transaction_type'], ['deposit','transfer_in','interest']); ?>
                    <tr>
                        <td><?php echo date('M j, Y g:i A', strtotime($t['transaction_date'])); ?></td>
                        <td><?php echo htmlspecialchars($t['account_number']); ?><br>
                            <small class="text-muted"><?php echo ucfirst($t['account_type']); ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($t['member_name']); ?><br>
                            <small class="text-muted">#<?php echo htmlspecialchars($t['member_number']); ?></small>
                        </td>
                        <td><?php echo ucwords(str_replace('_', ' ', $t['transaction_type'])); ?></td>
                        <td style="color:<?php echo $is_credit ? '#28a745' : '#dc3545'; ?>;font-weight:bold;">
                            <?php echo $is_credit ? '+' : '-'; ?>$<?php echo number_format((float)$t['amount'], 2); ?>
                        </td>
                        <td>$<?php echo number_format((float)$t['balance_after'], 2); ?></td>
                        <td><small><?php echo htmlspecialchars($t['reference_number'] ?? 'N/A'); ?></small></td>
                        <td><?php echo htmlspecialchars($t['description'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($t['teller_name'] ?? 'System'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="alert alert-warning">No transactions found for the selected period and filters.</div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
