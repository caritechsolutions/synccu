<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncCU - Accounting System</title>
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

        .module-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .module-card:hover::before {
            left: 100%;
        }

        .module-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
        }

        .module-icon {
            font-size: 3rem;
            margin-bottom: 20px;
            color: #667eea;
        }

        .module-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
        }

        .module-description {
            color: #666;
            font-size: 0.95rem;
            line-height: 1.4;
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
    </style>
</head>
<body>
    <a href="logout.php" class="logout-btn">Exit SyncCU</a>
    
    <div class="header">
        <h1>SyncCU Web 1.0</h1>
        <p>Prudential Credit Union Ltd. - Accounting System</p>
        <p>Current User: ROI | Transaction Date: <?php echo date('m/d/Y'); ?></p>
    </div>

    <div class="dashboard">
        <div class="module-card" onclick="navigateTo('transactions.php')">
            <div class="module-icon">💳</div>
            <div class="module-title">Over-the-Counter Transactions</div>
            <div class="module-description">Process daily transactions and customer services</div>
        </div>

        <div class="module-card" onclick="navigateTo('lending.php')">
            <div class="module-icon">🏦</div>
            <div class="module-title">Truth-in-Lending & Savings</div>
            <div class="module-description">Manage lending operations and savings accounts</div>
        </div>

        <div class="module-card" onclick="navigateTo('accounts.php')">
            <div class="module-icon">👥</div>
            <div class="module-title">Member Account Information</div>
            <div class="module-description">View and manage member accounts</div>
        </div>

        <div class="module-card" onclick="navigateTo('automatic.php')">
            <div class="module-icon">⚙️</div>
            <div class="module-title">Periodic Automatic Transactions</div>
            <div class="module-description">Set up and manage automated transactions</div>
        </div>

        <div class="module-card" onclick="navigateTo('reports.php')">
            <div class="module-icon">📊</div>
            <div class="module-title">Reports</div>
            <div class="module-description">Generate financial and operational reports</div>
        </div>

        <div class="module-card" onclick="navigateTo('manager.php')">
            <div class="module-icon">👨‍💼</div>
            <div class="module-title">Manager's Menu</div>
            <div class="module-description">Administrative functions and settings</div>
        </div>

        <div class="module-card" onclick="navigateTo('inquiry.php')">
            <div class="module-icon">🔍</div>
            <div class="module-title">Account Inquiry</div>
            <div class="module-description">Search and view account details</div>
        </div>

        <div class="module-card" onclick="navigateTo('password.php')">
            <div class="module-icon">🔐</div>
            <div class="module-title">Change Password</div>
            <div class="module-description">Update user security credentials</div>
        </div>

        <div class="module-card" onclick="navigateTo('ledger.php')">
            <div class="module-icon">📋</div>
            <div class="module-title">JCR-General Ledger</div>
            <div class="module-description">Access general ledger and accounting records</div>
        </div>
    </div>

    <script>
        function navigateTo(page) {
            window.location.href = page;
        }
    </script>
</body>
</html>
