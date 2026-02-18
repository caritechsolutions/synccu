<?php
require_once 'config.php';
requireLogin();
if (!hasPermission('manager')) {
    header('Location: index.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncCU - Manager's Menu</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <a href="index.php" class="back-btn">← Back to Dashboard</a>
    <div class="user-info" style="left:auto;right:20px;">
        <?php echo htmlspecialchars($_SESSION['full_name']); ?> | <?php echo date('m/d/Y'); ?>
    </div>

    <div class="page-header" style="padding-top:70px;">
        <h1>Manager's Menu</h1>
        <p>Administrative functions and system management</p>
    </div>

    <div class="dashboard">
        <a href="user_management.php" class="module-card">
            <div class="module-letter">A</div>
            <div class="module-icon">👤</div>
            <div class="module-title">User Management</div>
            <div class="module-description">Add, edit, and manage system users and roles</div>
        </a>

        <a href="manager/edit_accounts.php" class="module-card">
            <div class="module-letter">B</div>
            <div class="module-icon">📝</div>
            <div class="module-title">Edit Share, Loan and Certificate Accounts</div>
            <div class="module-description">Administrative editing of account structures</div>
        </a>

        <a href="manager/signon_dates.php" class="module-card">
            <div class="module-letter">C</div>
            <div class="module-icon">📅</div>
            <div class="module-title">Edit Earliest and Latest Sign-on Dates</div>
            <div class="module-description">Manage user access date restrictions</div>
        </a>

        <a href="manager/backup.php" class="module-card">
            <div class="module-letter">D</div>
            <div class="module-icon">💾</div>
            <div class="module-title">Backup / Restore Data</div>
            <div class="module-description">System backup, restore and maintenance operations</div>
        </a>

        <a href="manager/cash_transfers.php" class="module-card">
            <div class="module-letter">E</div>
            <div class="module-icon">💸</div>
            <div class="module-title">Cash Fund Transfers</div>
            <div class="module-description">Manage internal cash fund movements</div>
        </a>

        <a href="manager/purge.php" class="module-card">
            <div class="module-letter">F</div>
            <div class="module-icon">🗑️</div>
            <div class="module-title">Purge Old Transactions</div>
            <div class="module-description">Archive and remove historical transaction data</div>
        </a>

        <a href="manager/ncua.php" class="module-card">
            <div class="module-letter">G</div>
            <div class="module-icon">🏛️</div>
            <div class="module-title">NCUA Data File</div>
            <div class="module-description">Generate NCUA regulatory reporting files</div>
        </a>

        <a href="manager/special_reports.php" class="module-card">
            <div class="module-letter">H</div>
            <div class="module-icon">📊</div>
            <div class="module-title">Special Reports</div>
            <div class="module-description">Access specialized management reports</div>
        </a>
    </div>

    <script>
    const keyMap = {
        'A': 'user_management.php',
        'B': 'manager/edit_accounts.php',
        'C': 'manager/signon_dates.php',
        'D': 'manager/backup.php',
        'E': 'manager/cash_transfers.php',
        'F': 'manager/purge.php',
        'G': 'manager/ncua.php',
        'H': 'manager/special_reports.php'
    };
    document.addEventListener('keydown', function(e) {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
        const url = keyMap[e.key.toUpperCase()];
        if (url) window.location.href = url;
        if (e.key === 'Escape' || e.key === 'x' || e.key === 'X') window.location.href = 'index.php';
    });
    </script>
</body>
</html>
