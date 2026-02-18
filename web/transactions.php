<?php
require_once 'config.php';

// Require login and check permissions
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
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
        }

        .navbar {
            background: #2c3e50;
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .nav-left {
            display: flex;
            align-items: center;
        }

        .back-btn {
            background: #3498db;
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            text-decoration: none;
            margin-right: 20px;
            cursor: pointer;
        }

        .back-btn:hover {
            background: #2980b9;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: bold;
        }

        .user-info {
            font-size: 0.9rem;
        }

        .container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .transaction-menu {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .menu-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border-left: 4px solid #3498db;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .menu-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .menu-card h3 {
            color: #2c3e50;
            margin-bottom: 10px;
            font-size: 1.3rem;
        }

        .menu-card p {
            color: #666;
            line-height: 1.5;
        }

        .menu-icon {
            font-size: 2rem;
            margin-bottom: 15px;
            color: #3498db;
        }

        .quick-stats {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        .quick-stats h3 {
            color: #2c3e50;
            margin-bottom: 20px;
            border-bottom: 2px solid #ecf0f1;
            padding-bottom: 10px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .stat-item {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: #3498db;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #666;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-left">
            <a href="index.php" class="back-btn">← Back to Dashboard</a>
            <div class="page-title">Over-the-Counter Transactions</div>
        </div>
        <div class="user-info">
            <?php echo htmlspecialchars($_SESSION['full_name']); ?> | <?php echo date('M d, Y'); ?>
        </div>
    </nav>

    <div class="container">
        <div class="quick-stats">
            <h3>Today's Transaction Summary</h3>
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-value">$0.00</div>
                    <div class="stat-label">Total Deposits</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value">$0.00</div>
                    <div class="stat-label">Total Withdrawals</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value">0</div>
                    <div class="stat-label">Transactions</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value">$0.00</div>
                    <div class="stat-label">Net Cash Flow</div>
                </div>
            </div>
        </div>

        <div class="transaction-menu">
            <div class="menu-card" onclick="openTransaction('deposit')">
                <div class="menu-icon">💰</div>
                <h3>Deposit</h3>
                <p>Process customer deposits to savings or checking accounts</p>
            </div>

            <div class="menu-card" onclick="openTransaction('withdrawal')">
                <div class="menu-icon">💸</div>
                <h3>Withdrawal</h3>
                <p>Process customer withdrawals from accounts</p>
            </div>

            <div class="menu-card" onclick="openTransaction('transfer')">
                <div class="menu-icon">🔄</div>
                <h3>Transfer</h3>
                <p>Transfer funds between customer accounts</p>
            </div>

            <div class="menu-card" onclick="openTransaction('payment')">
                <div class="menu-icon">💳</div>
                <h3>Loan Payment</h3>
                <p>Process loan payments and update balances</p>
            </div>

            <div class="menu-card" onclick="openTransaction('cashier_check')">
                <div class="menu-icon">📄</div>
                <h3>Cashier's Check</h3>
                <p>Issue cashier's checks and money orders</p>
            </div>

            <div class="menu-card" onclick="openTransaction('history')">
                <div class="menu-icon">📋</div>
                <h3>Transaction History</h3>
                <p>View and search transaction records</p>
            </div>
        </div>
    </div>

    <script>
        function openTransaction(type) {
            // For now, show an alert. Later we'll create separate pages for each transaction type
            alert('Opening ' + type + ' transaction form\n(This will be implemented in the next step)');
        }
    </script>
</body>
</html>
