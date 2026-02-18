<?php
require_once 'config.php';

// Require login and check permissions
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
            max-width: 1400px;
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

        .restricted-note {
            background: #e74c3c;
            color: white;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
            margin-top: 10px;
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
        <h1>Manager's Menu</h1>
        <p>Administrative functions and system management</p>
    </div>

    <div class="dashboard">
        <div class="module-card" onclick="navigateTo('installation_menu')">
            <div class="module-letter">A</div>
            <div class="module-icon">⚙️</div>
            <div class="module-title">Installation Menu</div>
            <div class="module-description">System installation and configuration options</div>
        </div>

        <div class="module-card" onclick="navigateTo('edit_accounts')">
            <div class="module-letter">B</div>
            <div class="module-icon">📝</div>
            <div class="module-title">Edit Share, Loan and Certificate Accounts</div>
            <div class="module-description">Administrative editing of account structures</div>
        </div>

        <div class="module-card" onclick="navigateTo('edit_signon_dates')">
            <div class="module-letter">C</div>
            <div class="module-icon">📅</div>
            <div class="module-title">Edit Earliest and Latest Sign-on Dates</div>
            <div class="module-description">Manage user access date restrictions</div>
        </div>

        <div class="module-card" onclick="navigateTo('backup_restore')">
            <div class="module-letter">D</div>
            <div class="module-icon">💾</div>
            <div class="module-title">Backup Data, Restore Data, Install Update or Format</div>
            <div class="module-description">System backup, restore and maintenance operations</div>
        </div>

        <div class="module-card" onclick="navigateTo('cash_transfers')">
            <div class="module-letter">E</div>
            <div class="module-icon">💸</div>
            <div class="module-title">Cash Fund Transfers</div>
            <div class="module-description">Manage internal cash fund movements</div>
        </div>

        <div class="module-card" onclick="navigateTo('technical_support')">
            <div class="module-letter">F</div>
            <div class="module-icon">🔧</div>
            <div class="module-title">Technical Support Menu</div>
            <div class="module-description">Access technical support functions</div>
            <div class="restricted-note">Requires Service Passnumber</div>
        </div>

        <div class="module-card" onclick="navigateTo('purge_transactions')">
            <div class="module-letter">G</div>
            <div class="module-icon">🗑️</div>
            <div class="module-title">Purge Old Transactions</div>
            <div class="module-description">Archive and remove historical transaction data</div>
        </div>

        <div class="module-card" onclick="navigateTo('ncua_data')">
            <div class="module-letter">H</div>
            <div class="module-icon">🏛️</div>
            <div class="module-title">NCUA Data File</div>
            <div class="module-description">Generate NCUA regulatory reporting files</div>
        </div>

        <div class="module-card" onclick="navigateTo('special_reports')">
            <div class="module-letter">I</div>
            <div class="module-icon">📊</div>
            <div class="module-title">Special Reports</div>
            <div class="module-description">Access specialized management reports</div>
        </div>
    </div>

    <script>
        function navigateTo(page) {
            alert(`Opening: ${page}\n(This will be implemented next)`);
        }

        // Add keyboard support
        document.addEventListener('keydown', function(e) {
            const key = e.key.toUpperCase();
            const keyMap = {
                'A': 'installation_menu',
                'B': 'edit_accounts',
                'C': 'edit_signon_dates',
                'D': 'backup_restore',
                'E': 'cash_transfers',
                'F': 'technical_support',
                'G': 'purge_transactions',
                'H': 'ncua_data',
                'I': 'special_reports'
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
