<?php
require_once '../config.php';
requireLogin();
if (!hasPermission('accounts')) {
    header('Location: ../index.php');
    exit();
}

$action = $_GET['action'] ?? '';
$filter = $_GET['filter'] ?? '';
$search = trim($_GET['q'] ?? '');

// Build query based on filter
$where = ['m.is_active = 1'];
$params = [];

if ($filter === 'company') {
    $where[] = "m.member_type = 'company'";
} elseif ($filter === 'loan') {
    $where[] = "EXISTS (SELECT 1 FROM member_accounts a WHERE a.member_id = m.id AND a.account_type = 'loan' AND a.is_active = 1)";
} elseif ($filter === 'share') {
    $where[] = "EXISTS (SELECT 1 FROM member_accounts a WHERE a.member_id = m.id AND a.account_type IN ('savings','share') AND a.is_active = 1)";
}

if ($search) {
    $where[] = "(CONCAT(m.first_name,' ',m.last_name) LIKE ? OR m.member_number = ?)";
    $params[] = "%{$search}%";
    $params[] = $search;
}

$sql = "SELECT m.id, m.member_number, m.first_name, m.last_name, m.member_type,
               m.phone, m.email, m.date_joined,
               COUNT(a.id) AS account_count,
               SUM(CASE WHEN a.account_type != 'loan' THEN a.balance ELSE 0 END) AS total_savings,
               SUM(CASE WHEN a.account_type = 'loan'  THEN a.balance ELSE 0 END) AS total_loans
        FROM members m
        LEFT JOIN member_accounts a ON a.member_id = m.id AND a.is_active = 1
        WHERE " . implode(' AND ', $where) . "
        GROUP BY m.id
        ORDER BY m.last_name, m.first_name
        LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$members = $stmt->fetchAll();

$page_titles = [
    'edit'   => 'Select Member to Edit',
    'delete' => 'Select Member to Delete',
    ''       => 'Member List',
];
$page_title = $page_titles[$action] ?? 'Member List';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncCU - <?php echo htmlspecialchars($page_title); ?></title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <a href="../accounts.php" class="back-btn">← Back</a>
    <div class="user-info" style="left:auto;right:20px;">
        <?php echo htmlspecialchars($_SESSION['full_name']); ?> | <?php echo date('m/d/Y'); ?>
    </div>

    <div class="container">
        <div class="container-header">
            <h1><?php echo htmlspecialchars($page_title); ?></h1>
            <p><?php echo count($members); ?> member(s) found</p>
        </div>

        <div class="content">
            <!-- Search bar -->
            <form method="GET" class="d-flex gap-10 mb-20">
                <input type="hidden" name="action" value="<?php echo htmlspecialchars($action); ?>">
                <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="Search by name or account number…" style="flex:1;padding:10px;border:2px solid #ddd;border-radius:8px;font-size:1rem;">
                <button type="submit" class="btn btn-primary">Search</button>
                <a href="add.php" class="btn btn-success">+ Add Member</a>
            </form>

            <?php if ($members): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Account #</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Savings Balance</th>
                        <th>Loan Balance</th>
                        <th>Date Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($members as $m): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($m['member_number']); ?></td>
                        <td><?php echo htmlspecialchars($m['first_name'] . ' ' . $m['last_name']); ?></td>
                        <td><?php echo ucfirst($m['member_type']); ?></td>
                        <td><?php echo htmlspecialchars($m['phone'] ?: 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($m['email'] ?: 'N/A'); ?></td>
                        <td>$<?php echo number_format((float)$m['total_savings'], 2); ?></td>
                        <td>$<?php echo number_format((float)$m['total_loans'],   2); ?></td>
                        <td><?php echo $m['date_joined'] ? date('M j, Y', strtotime($m['date_joined'])) : 'N/A'; ?></td>
                        <td>
                            <a href="view.php?id=<?php echo $m['id']; ?>" class="btn btn-sm btn-primary">View</a>
                            <?php if ($action !== 'delete'): ?>
                            <a href="edit.php?id=<?php echo $m['id']; ?>" class="btn btn-sm btn-secondary">Edit</a>
                            <?php endif; ?>
                            <?php if ($action === 'delete'): ?>
                            <a href="delete.php?id=<?php echo $m['id']; ?>" class="btn btn-sm btn-danger"
                               onclick="return confirm('Delete <?php echo addslashes($m['first_name'] . ' ' . $m['last_name']); ?>?')">Delete</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="alert alert-warning">No members found. <a href="add.php">Add the first member.</a></div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
