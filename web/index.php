<?php
require_once 'config.php';

// Require user to be logged in
requireLogin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncCU - Dashboard</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .header {
            text-align: center;
            color: white;
            margin-bottom: 30px;
        }

        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }

        .header p {
            font-size: 1.2rem;
            opacity: 0.9;
        }

        .user-info {
            position: fixed;
            top: 20px;
            left: 20px;
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 20px;
            border-radius: 25px;
            font-size: 0.9rem;
        }

        .logout-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            background: rgba(255,255,255,0.2);
            color: white;
            border: 2px solid white;
            padding: 10px 20px;
            border-radius: 25px;
            cursor: pointer;
            transition: background 0.3s ease;
            text-decoration: none;
        }

        .logout-btn:hover {
            background: rgba(255,255,255,0.3);
        }

        .dashboard {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            padding: 20px;
        }

        .module-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .module-card.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background: #f5f5f5;
        }

        .module-card:not(.disabled)::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .module-card:not(.disabled):hover::before {
            left: 100%;
        }

        .module-card:not(.disabled):hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
        }

        .module-icon {
            font-size: 3rem;
            margin-bottom: 20px;
            color: #667eea;
        }

        .module-card.disabled .module-icon {
            color: #ccc;
        }

        .module-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
        }

        .module-card.disabled .module-title {
            color: #999;
        }

        .module-description {
            color: #666;
            font-size: 0.95rem;
            line-height: 1.4;
        }

        .module-card.disabled .module-description {
            color: #aaa;
        }

        .access-denied {
            font-size: 0.8rem;
            color: #e74c3c;
            font-weight: bold;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div class="user-info">
        Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?><br>
        Role: <?php echo ucfirst($_SESSION['user_role']); ?>
    </div>
    
    <a href="logout.php" class="logout-btn">Logout</a>
    
    <div class="header">
        <h1>SyncCU Web 1.0</h1>
        <p>Prudential Credit Union Ltd. - Accounting System</p>
        <p>Current User: <?php echo htmlspecialchars($_SESSION['username']); ?> | Date: <?php echo date('m/d/Y'); ?></p>
    </div>

    <div class="dashboard">
        <!-- Over-the-Counter Transactions -->
        <div class="module-card <?php echo hasPermission('transactions') ? '' : 'disabled'; ?>" 
             onclick="<?php echo hasPermission('transactions') ? 'navigateTo(\'transactions.php\')' : ''; ?>">
            <div class="module-icon">💳</div>
            <div class="module-title">Over-the-Counter Transactions</div>
            <div class="module-description">Process daily transactions and customer services</div>
            <?php if (!hasPermission('transactions')): ?>
            <div class="access-denied">Access Denied</div>
            <?php endif; ?>
        </div>

        <!-- Truth-in-Lending & Savings -->
        <div class="module-card <?php echo hasPermission('lending') ? '' : 'disabled'; ?>" 
             onclick="<?php echo hasPermission('lending') ? 'navigateTo(\'lending.php\')' : ''; ?>">
            <div class="module-icon">🏦</div>
            <div class="module-title">Truth-in-Lending & Savings</div>
            <div class="module-description">Manage lending operations and savings accounts</div>
            <?php if (!hasPermission('lending')): ?>
            <div class="access-denied">Access Denied</div>
            <?php endif; ?>
        </div>

        <!-- Member Account Information -->
        <div class="module-card <?php echo hasPermission('accounts') ? '' : 'disabled'; ?>" 
             onclick="<?php echo hasPermission('accounts') ? 'navigateTo(\'accounts.php\')' : ''; ?>">
            <div class="module-icon">👥</div>
            <div class="module-title">Member Account Information</div>
            <div class="module-description">View and manage member accounts</div>
            <?php if (!hasPermission('accounts')): ?>
            <div class="access-denied">Access Denied</div>
            <?php endif; ?>
        </div>

        <!-- Periodic Automatic Transactions -->
        <div class="module-card <?php echo hasPermission('automatic') ? '' : 'disabled'; ?>" 
             onclick="<?php echo hasPermission('automatic') ? 'navigateTo(\'automatic.php\')' : ''; ?>">
            <div class="module-icon">⚙️</div>
            <div class="module-title">Periodic Automatic Transactions</div>
            <div class="module-description">Set up and manage automated transactions</div>
            <?php if (!hasPermission('automatic')): ?>
            <div class="access-denied">Access Denied</div>
            <?php endif; ?>
        </div>

        <!-- Reports -->
        <div class="module-card <?php echo hasPermission('reports') ? '' : 'disabled'; ?>" 
             onclick="<?php echo hasPermission('reports') ? 'navigateTo(\'reports.php\')' : ''; ?>">
            <div class="module-icon">📊</div>
            <div class="module-title">Reports</div>
            <div class="module-description">Generate financial and operational reports</div>
            <?php if (!hasPermission('reports')): ?>
            <div class="access-denied">Access Denied</div>
            <?php endif; ?>
        </div>

        <!-- Manager's Menu -->
        <div class="module-card <?php echo hasPermission('manager') ? '' : 'disabled'; ?>" 
             onclick="<?php echo hasPermission('manager') ? 'navigateTo(\'manager.php\')' : ''; ?>">
            <div class="module-icon">👨‍💼</div>
            <div class="module-title">Manager's Menu</div>
            <div class="module-description">Administrative functions and settings</div>
            <?php if (!hasPermission('manager')): ?>
            <div class="access-denied">Access Denied</div>
            <?php endif; ?>
        </div>

        <!-- Account Inquiry -->
        <div class="module-card <?php echo hasPermission('inquiry') ? '' : 'disabled'; ?>" 
             onclick="<?php echo hasPermission('inquiry') ? 'navigateTo(\'inquiry.php\')' : ''; ?>">
            <div class="module-icon">🔍</div>
            <div class="module-title">Account Inquiry</div>
            <div class="module-description">Search and view account details</div>
            <?php if (!hasPermission('inquiry')): ?>
            <div class="access-denied">Access Denied</div>
            <?php endif; ?>
        </div>

        <!-- Change Password -->
        <div class="module-card <?php echo hasPermission('password') ? '' : 'disabled'; ?>" 
             onclick="<?php echo hasPermission('password') ? 'navigateTo(\'password.php\')' : ''; ?>">
            <div class="module-icon">🔐</div>
            <div class="module-title">Change Password</div>
            <div class="module-description">Update user security credentials</div>
            <?php if (!hasPermission('password')): ?>
            <div class="access-denied">Access Denied</div>
            <?php endif; ?>
        </div>

        <!-- JCR-General Ledger -->
        <div class="module-card <?php echo hasPermission('ledger') ? '' : 'disabled'; ?>" 
             onclick="<?php echo hasPermission('ledger') ? 'navigateTo(\'ledger.php\')' : ''; ?>">
            <div class="module-icon">📋</div>
            <div class="module-title">JCR-General Ledger</div>
            <div class="module-description">Access general ledger and accounting records</div>
            <?php if (!hasPermission('ledger')): ?>
            <div class="access-denied">Access Denied</div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function navigateTo(page) {
            window.location.href = page;
        }
    </script>
</body>
</html>
