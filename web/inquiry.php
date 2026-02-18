<?php
require_once 'config.php';
requireLogin();
if (!hasPermission('inquiry')) {
    header('Location: index.php');
    exit();
}

$results  = [];
$searched = false;
$query    = trim($_GET['q'] ?? '');
$search_type = $_GET['by'] ?? 'account';

if ($query) {
    $searched = true;
    switch ($search_type) {
        case 'name':
            $stmt = $pdo->prepare("
                SELECT m.id, m.member_number, m.first_name, m.last_name, m.phone, m.email,
                       COUNT(a.id) AS account_count
                FROM members m
                LEFT JOIN member_accounts a ON a.member_id = m.id AND a.is_active = 1
                WHERE CONCAT(m.first_name, ' ', m.last_name) LIKE ?
                AND m.is_active = 1
                GROUP BY m.id
                ORDER BY m.last_name, m.first_name
                LIMIT 50
            ");
            $stmt->execute(['%' . $query . '%']);
            break;
        case 'phone':
            $stmt = $pdo->prepare("
                SELECT m.id, m.member_number, m.first_name, m.last_name, m.phone, m.email,
                       COUNT(a.id) AS account_count
                FROM members m
                LEFT JOIN member_accounts a ON a.member_id = m.id AND a.is_active = 1
                WHERE m.phone LIKE ? AND m.is_active = 1
                GROUP BY m.id ORDER BY m.last_name LIMIT 50
            ");
            $stmt->execute(['%' . $query . '%']);
            break;
        case 'email':
            $stmt = $pdo->prepare("
                SELECT m.id, m.member_number, m.first_name, m.last_name, m.phone, m.email,
                       COUNT(a.id) AS account_count
                FROM members m
                LEFT JOIN member_accounts a ON a.member_id = m.id AND a.is_active = 1
                WHERE m.email LIKE ? AND m.is_active = 1
                GROUP BY m.id ORDER BY m.last_name LIMIT 50
            ");
            $stmt->execute(['%' . $query . '%']);
            break;
        default: // account number
            $stmt = $pdo->prepare("
                SELECT m.id, m.member_number, m.first_name, m.last_name, m.phone, m.email,
                       COUNT(a.id) AS account_count
                FROM members m
                LEFT JOIN member_accounts a ON a.member_id = m.id AND a.is_active = 1
                WHERE m.member_number = ? AND m.is_active = 1
                GROUP BY m.id LIMIT 50
            ");
            $stmt->execute([$query]);
    }
    $results = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncCU - Account Inquiry</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .search-bar { display:flex; gap:10px; margin-bottom:20px; }
        .search-bar input  { flex:1; padding:12px 15px; border:2px solid #667eea; border-radius:8px; font-size:1rem; }
        .search-bar select { padding:12px 15px; border:2px solid #667eea; border-radius:8px; font-size:1rem; background:white; }
        .search-bar input:focus, .search-bar select:focus { outline:none; border-color:#764ba2; }
    </style>
</head>
<body>
    <a href="index.php" class="back-btn">← Back to Dashboard</a>
    <div class="user-info" style="left:auto;right:20px;">
        <?php echo htmlspecialchars($_SESSION['full_name']); ?> | <?php echo date('m/d/Y'); ?>
    </div>

    <div class="container-sm" style="margin-top:80px;">
        <div class="container-header">
            <h1>Account Inquiry</h1>
            <p>Search and view member account information</p>
        </div>

        <div class="content">
            <form method="GET">
                <div class="search-bar">
                    <select name="by">
                        <option value="account" <?php echo $search_type==='account' ? 'selected' : ''; ?>>Account Number</option>
                        <option value="name"    <?php echo $search_type==='name'    ? 'selected' : ''; ?>>Member Name</option>
                        <option value="phone"   <?php echo $search_type==='phone'   ? 'selected' : ''; ?>>Phone</option>
                        <option value="email"   <?php echo $search_type==='email'   ? 'selected' : ''; ?>>Email</option>
                    </select>
                    <input type="text" name="q" value="<?php echo htmlspecialchars($query); ?>"
                           placeholder="Enter search term…" autofocus>
                    <button type="submit" class="btn btn-primary">Search</button>
                </div>
            </form>

            <?php if ($searched): ?>
                <?php if ($results): ?>
                <p class="text-muted mb-20"><?php echo count($results); ?> result(s) found for "<?php echo htmlspecialchars($query); ?>"</p>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Account #</th>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Accounts</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['member_number']); ?></td>
                            <td><?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?></td>
                            <td><?php echo htmlspecialchars($r['phone'] ?: 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($r['email'] ?: 'N/A'); ?></td>
                            <td><?php echo (int)$r['account_count']; ?></td>
                            <td>
                                <a href="members/view.php?id=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-primary">View</a>
                                <a href="members/edit.php?id=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-secondary">Edit</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="alert alert-warning">No members found matching "<?php echo htmlspecialchars($query); ?>".</div>
                <?php endif; ?>
            <?php else: ?>
            <div class="info-box">
                <h4>Search Tips</h4>
                <ul>
                    <li>Enter a full account number to find an exact match</li>
                    <li>Search by name using partial first or last name</li>
                    <li>Use phone or email for alternative lookup methods</li>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
