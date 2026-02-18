<?php
require_once 'config.php';
requireLogin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncCU - Dashboard</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="user-info">
        <?php echo htmlspecialchars($_SESSION['full_name']); ?><br>
        Role: <?php echo ucfirst($_SESSION['user_role']); ?>
    </div>

    <a href="logout.php" class="logout-btn">Logout</a>

    <div class="dash-header" style="padding-top:70px;">
        <h1>SyncCU Web 1.0</h1>
        <p>Prudential Credit Union Ltd. — Accounting System</p>
        <p style="font-size:0.95rem;opacity:0.8;">
            <?php echo htmlspecialchars($_SESSION['username']); ?> | <?php echo date('m/d/Y'); ?>
        </p>
    </div>

    <div class="dashboard">
        <?php
        $modules = [
            ['perm'=>'transactions','href'=>'transactions.php','icon'=>'💳','title'=>'Over-the-Counter Transactions','desc'=>'Process daily transactions and customer services'],
            ['perm'=>'lending',     'href'=>'lending.php',     'icon'=>'🏦','title'=>'Truth-in-Lending &amp; Savings','desc'=>'Manage lending operations and savings accounts'],
            ['perm'=>'accounts',    'href'=>'accounts.php',    'icon'=>'👥','title'=>'Member Account Information','desc'=>'View and manage member accounts'],
            ['perm'=>'automatic',   'href'=>'automatic.php',   'icon'=>'⚙️','title'=>'Periodic Automatic Transactions','desc'=>'Set up and manage automated transactions'],
            ['perm'=>'reports',     'href'=>'reports.php',     'icon'=>'📊','title'=>'Reports','desc'=>'Generate financial and operational reports'],
            ['perm'=>'manager',     'href'=>'manager.php',     'icon'=>'👨‍💼','title'=>"Manager's Menu",'desc'=>'Administrative functions and settings'],
            ['perm'=>'inquiry',     'href'=>'inquiry.php',     'icon'=>'🔍','title'=>'Account Inquiry','desc'=>'Search and view account details'],
            ['perm'=>'password',    'href'=>'password.php',    'icon'=>'🔐','title'=>'Change Password','desc'=>'Update user security credentials'],
            ['perm'=>'ledger',      'href'=>'ledger.php',      'icon'=>'📋','title'=>'JCR-General Ledger','desc'=>'Access general ledger and accounting records'],
        ];
        foreach ($modules as $m):
            $allowed = hasPermission($m['perm']);
        ?>
        <?php if ($allowed): ?>
        <a href="<?php echo $m['href']; ?>" class="module-card">
        <?php else: ?>
        <div class="module-card disabled">
        <?php endif; ?>
            <div class="module-icon"><?php echo $m['icon']; ?></div>
            <div class="module-title"><?php echo $m['title']; ?></div>
            <div class="module-description"><?php echo $m['desc']; ?></div>
            <?php if (!$allowed): ?>
            <div class="access-denied">Access Denied</div>
            <?php endif; ?>
        <?php echo $allowed ? '</a>' : '</div>'; ?>
        <?php endforeach; ?>
    </div>
</body>
</html>
