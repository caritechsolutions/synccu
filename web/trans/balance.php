<?php
require_once '../config.php';
requireLogin();
if (!hasPermission('transactions') && !hasPermission('inquiry')) {
    header('Location: ../index.php');
    exit();
}

$q       = trim($_GET['q'] ?? '');
$results = [];
$searched = false;

if ($q) {
    $searched = true;
    $stmt = $pdo->prepare("
        SELECT m.member_number, CONCAT(m.first_name,' ',m.last_name) AS member_name,
               a.id, a.account_number, a.account_type, a.balance, a.interest_rate,
               a.date_opened, a.is_active
        FROM member_accounts a
        JOIN members m ON a.member_id = m.id
        WHERE (m.member_number = ? OR a.account_number LIKE ? OR CONCAT(m.first_name,' ',m.last_name) LIKE ?)
          AND m.is_active = 1
        ORDER BY m.last_name, a.account_type
        LIMIT 30
    ");
    $stmt->execute([$q, "%{$q}%", "%{$q}%"]);
    $results = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncCU - Account Balance Inquiry</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .search-bar { display:flex; gap:10px; margin-bottom:20px; }
        .search-bar input { flex:1; padding:12px 15px; border:2px solid #667eea; border-radius:8px; font-size:1rem; }
        .balance-positive { color:#28a745; font-weight:bold; }
        .balance-zero     { color:#6c757d; }
    </style>
</head>
<body>
    <a href="../transactions.php" class="back-btn">← Back</a>
    <div class="user-info" style="left:auto;right:20px;">
        <?php echo htmlspecialchars($_SESSION['full_name']); ?> | <?php echo date('m/d/Y'); ?>
    </div>

    <div class="container-sm" style="margin-top:80px;max-width:900px;">
        <div class="container-header">
            <h1>Account Balance Inquiry</h1>
            <p>Search for a member to view all account balances</p>
        </div>

        <div class="content">
            <form method="GET">
                <div class="search-bar">
                    <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>"
                           placeholder="Account number, member number, or name…" autofocus>
                    <button type="submit" class="btn btn-primary">Search</button>
                </div>
            </form>

            <?php if ($searched): ?>
                <?php if ($results): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Member</th>
                            <th>Account #</th>
                            <th>Type</th>
                            <th>Balance</th>
                            <th>Interest Rate</th>
                            <th>Opened</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['member_name']); ?><br>
                                <small class="text-muted">#<?php echo htmlspecialchars($r['member_number']); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($r['account_number']); ?></td>
                            <td><?php echo ucfirst($r['account_type']); ?></td>
                            <td class="<?php echo $r['balance'] > 0 ? 'balance-positive' : 'balance-zero'; ?>">
                                $<?php echo number_format((float)$r['balance'], 2); ?>
                            </td>
                            <td><?php echo number_format((float)$r['interest_rate'] * 100, 3); ?>%</td>
                            <td><?php echo date('M j, Y', strtotime($r['date_opened'])); ?></td>
                            <td class="<?php echo $r['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                <?php echo $r['is_active'] ? 'Active' : 'Closed'; ?>
                            </td>
                            <td>
                                <a href="history.php?account_id=<?php echo $r['id']; ?>" class="btn btn-sm btn-primary">History</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="alert alert-warning">No accounts found for "<?php echo htmlspecialchars($q); ?>".</div>
                <?php endif; ?>
            <?php else: ?>
            <div class="info-box">
                <h4>Search Tips</h4>
                <ul>
                    <li>Enter a member account number (e.g. 2024001234)</li>
                    <li>Enter a specific account number (e.g. SAV-2024001234)</li>
                    <li>Search by member name to see all their accounts</li>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
