<?php
require_once 'config.php';
requireLogin();
if (!hasPermission('transactions')) {
    header('Location: index.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncCU - Over-the-Counter Transactions</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <a href="index.php" class="back-btn">← Back to Dashboard</a>
    <div class="user-info" style="left:auto;right:20px;">
        <?php echo htmlspecialchars($_SESSION['full_name']); ?> | <?php echo date('m/d/Y'); ?>
    </div>

    <div class="page-header" style="padding-top:70px;">
        <h1>Over-the-Counter Transactions</h1>
        <p>Select a transaction type to process</p>
    </div>

    <div class="dashboard">
        <a href="trans/deposit.php?type=savings" class="module-card">
            <div class="module-letter">A</div>
            <div class="module-icon">💰</div>
            <div class="module-title">Deposit to Savings Account</div>
            <div class="module-description">Process deposits to member savings accounts</div>
        </a>

        <a href="trans/deposit.php?type=checking" class="module-card">
            <div class="module-letter">B</div>
            <div class="module-icon">🏦</div>
            <div class="module-title">Deposit to Checking Account</div>
            <div class="module-description">Process deposits to member checking accounts</div>
        </a>

        <a href="trans/withdrawal.php?type=savings" class="module-card">
            <div class="module-letter">C</div>
            <div class="module-icon">💸</div>
            <div class="module-title">Withdrawal from Savings</div>
            <div class="module-description">Process withdrawals from savings accounts</div>
        </a>

        <a href="trans/withdrawal.php?type=checking" class="module-card">
            <div class="module-letter">D</div>
            <div class="module-icon">💳</div>
            <div class="module-title">Withdrawal from Checking</div>
            <div class="module-description">Process withdrawals from checking accounts</div>
        </a>

        <a href="trans/transfer.php" class="module-card">
            <div class="module-letter">E</div>
            <div class="module-icon">🔄</div>
            <div class="module-title">Transfer Between Accounts</div>
            <div class="module-description">Transfer funds between member accounts</div>
        </a>

        <a href="trans/loan_payment.php" class="module-card">
            <div class="module-letter">F</div>
            <div class="module-icon">📄</div>
            <div class="module-title">Loan Payment</div>
            <div class="module-description">Process loan payments and update balances</div>
        </a>

        <a href="trans/balance.php" class="module-card">
            <div class="module-letter">G</div>
            <div class="module-icon">🔍</div>
            <div class="module-title">Account Balance Inquiry</div>
            <div class="module-description">Check account balances and details</div>
        </a>

        <a href="trans/history.php" class="module-card">
            <div class="module-letter">H</div>
            <div class="module-icon">📋</div>
            <div class="module-title">Transaction History</div>
            <div class="module-description">View and search transaction records</div>
        </a>
    </div>

    <script>
    const keyMap = {
        'A': 'trans/deposit.php?type=savings',
        'B': 'trans/deposit.php?type=checking',
        'C': 'trans/withdrawal.php?type=savings',
        'D': 'trans/withdrawal.php?type=checking',
        'E': 'trans/transfer.php',
        'F': 'trans/loan_payment.php',
        'G': 'trans/balance.php',
        'H': 'trans/history.php'
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
