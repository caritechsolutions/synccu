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
            font-size: 2.2rem;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }

        .header p {
            font-size: 1rem;
            opacity: 0.9;
        }

        .back-btn {
            position: fixed;
            top: 20px;
            left: 20px;
            background: rgba(255,255,255,0.2);
            color: white;
            border: 2px solid white;
            padding: 10px 20px;
            border-radius: 25px;
            cursor: pointer;
            transition: background 0.3s ease;
            text-decoration: none;
        }

        .back-btn:hover {
            background: rgba(255,255,255,0.3);
        }

        .user-info {
            position: fixed;
            top: 20px;
            right: 20px;
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 20px;
            border-radius: 25px;
            font-size: 0.9rem;
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

        .module-letter {
            position: absolute;
            top: 15px;
            left: 15px;
            background: #667eea;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1rem;
        }
    </style>
</head>
<body>
    <a href="index.php" class="back-btn">← Back to Dashboard</a>
    
    <div class="user-info">
        <?php echo htmlspecialchars($_SESSION['full_name']); ?><br>
        <?php echo date('m/d/Y'); ?>
    </div>
    
    <div class="header">
        <h1>Over-the-Counter Transactions</h1>
        <p>Select a transaction type to process</p>
    </div>

    <div class="dashboard">
        <div class="module-card" onclick="navigateTo('deposit_savings')">
            <div class="module-letter">A</div>
            <div class="module-icon">💰</div>
            <div class="module-title">Deposit to Savings Account</div>
            <div class="module-description">Process deposits to member savings accounts</div>
        </div>

        <div class="module-card" onclick="navigateTo('deposit_checking')">
            <div class="module-letter">B</div>
            <div class="module-icon">🏦</div>
            <div class="module-title">Deposit to Checking Account</div>
            <div class="module-description">Process deposits to member checking accounts</div>
        </div>

        <div class="module-card" onclick="navigateTo('withdrawal_savings')">
            <div class="module-letter">C</div>
            <div class="module-icon">💸</div>
            <div class="module-title">Withdrawal from Savings</div>
            <div class="module-description">Process withdrawals from savings accounts</div>
        </div>

        <div class="module-card" onclick="navigateTo('withdrawal_checking')">
            <div class="module-letter">D</div>
            <div class="module-icon">💳</div>
            <div class="module-title">Withdrawal from Checking</div>
            <div class="module-description">Process withdrawals from checking accounts</div>
        </div>

        <div class="module-card" onclick="navigateTo('transfer')">
            <div class="module-letter">E</div>
            <div class="module-icon">🔄</div>
            <div class="module-title">Transfer Between Accounts</div>
            <div class="module-description">Transfer funds between member accounts</div>
        </div>

        <div class="module-card" onclick="navigateTo('loan_payment')">
            <div class="module-letter">F</div>
            <div class="module-icon">📄</div>
            <div class="module-title">Loan Payment</div>
            <div class="module-description">Process loan payments and update balances</div>
        </div>

        <div class="module-card" onclick="navigateTo('balance_inquiry')">
            <div class="module-letter">G</div>
            <div class="module-icon">🔍</div>
            <div class="module-title">Account Balance Inquiry</div>
            <div class="module-description">Check account balances and details</div>
        </div>

        <div class="module-card" onclick="navigateTo('print_receipt')">
            <div class="module-letter">H</div>
            <div class="module-icon">🖨️</div>
            <div class="module-title">Print Receipt</div>
            <div class="module-description">Print transaction receipts and statements</div>
        </div>

        <div class="module-card" onclick="navigateTo('transaction_history')">
            <div class="module-letter">I</div>
            <div class="module-icon">📋</div>
            <div class="module-title">Transaction History</div>
            <div class="module-description">View and search transaction records</div>
        </div>
    </div>

    <script>
        function navigateTo(page) {
            alert(`Opening: ${page}\n(This will be implemented next)`);
        }

        // Add keyboard support for original DOS-style navigation
        document.addEventListener('keydown', function(e) {
            const key = e.key.toUpperCase();
            const keyMap = {
                'A': 'deposit_savings',
                'B': 'deposit_checking', 
                'C': 'withdrawal_savings',
                'D': 'withdrawal_checking',
                'E': 'transfer',
                'F': 'loan_payment',
                'G': 'balance_inquiry',
                'H': 'print_receipt',
                'I': 'transaction_history'
            };
            
            if (keyMap[key]) {
                navigateTo(keyMap[key]);
            } else if (key === 'X' || key === 'ESCAPE') {
                window.location.href = 'index.php';
            }
        });
    </script>
</body>
</html>
