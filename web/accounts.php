<?php
require_once 'config.php';
requireLogin();
if (!hasPermission('accounts')) {
    header('Location: index.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncCU - Member Account Information</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <a href="index.php" class="back-btn">← Back to Dashboard</a>
    <div class="user-info" style="left:auto;right:20px;">
        <?php echo htmlspecialchars($_SESSION['full_name']); ?> | <?php echo date('m/d/Y'); ?>
    </div>

    <div class="page-header" style="padding-top:70px;">
        <h1>Member Account Information</h1>
        <p>Manage member accounts and organizational information</p>
    </div>

    <div class="dashboard">
        <a href="members/add.php" class="module-card">
            <div class="module-letter">A</div>
            <div class="module-icon">👤</div>
            <div class="module-title">Add a New Member</div>
            <div class="module-description">Register new members and create their accounts</div>
        </a>

        <a href="members/list.php?action=edit" class="module-card">
            <div class="module-letter">B</div>
            <div class="module-icon">✏️</div>
            <div class="module-title">Edit a Member's Basic Information</div>
            <div class="module-description">Update member personal details and contact information</div>
        </a>

        <a href="members/list.php?action=delete" class="module-card">
            <div class="module-letter">C</div>
            <div class="module-icon">🗑️</div>
            <div class="module-title">Delete a Member</div>
            <div class="module-description">Remove member accounts and associated data</div>
        </a>

        <a href="members/list.php?filter=loan" class="module-card">
            <div class="module-letter">D</div>
            <div class="module-icon">🏦</div>
            <div class="module-title">Create or Delete a Loan Account</div>
            <div class="module-description">Manage member loan accounts and credit facilities</div>
        </a>

        <a href="members/list.php?filter=share" class="module-card">
            <div class="module-letter">E</div>
            <div class="module-icon">💰</div>
            <div class="module-title">Create or Delete a Share Subaccount</div>
            <div class="module-description">Manage member savings and share subaccounts</div>
        </a>

        <a href="members/add.php?type=company" class="module-card">
            <div class="module-letter">F</div>
            <div class="module-icon">🏢</div>
            <div class="module-title">Add Company / Organization Account</div>
            <div class="module-description">Register business and organizational accounts</div>
        </a>

        <a href="members/list.php?filter=company" class="module-card">
            <div class="module-letter">G</div>
            <div class="module-icon">🏭</div>
            <div class="module-title">Edit a Company's Basic Information</div>
            <div class="module-description">Update business account details and information</div>
        </a>
    </div>

    <script>
    const keyMap = {
        'A': 'members/add.php',
        'B': 'members/list.php?action=edit',
        'C': 'members/list.php?action=delete',
        'D': 'members/list.php?filter=loan',
        'E': 'members/list.php?filter=share',
        'F': 'members/add.php?type=company',
        'G': 'members/list.php?filter=company'
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
