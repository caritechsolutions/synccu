<?php
require_once 'config.php';

// Require login and check permissions
requireLogin();
if (!hasPermission('inquiry')) {
    header('Location: index.php');
    exit();
}

$search_result = '';
$account_number = '';

// Handle search submission
if ($_POST && isset($_POST['account_number'])) {
    $account_number = trim($_POST['account_number']);
    if ($account_number) {
        // For now, show a placeholder result
        $search_result = "Account search functionality will be implemented here";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncCU - Account Inquiry</title>
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

        .inquiry-container {
            max-width: 800px;
            margin: 80px auto 0;
            background: rgba(255,255,255,0.95);
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }

        .inquiry-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .inquiry-header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }

        .inquiry-header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .search-section {
            padding: 40px;
        }

        .search-form {
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 10px;
            color: #333;
            font-weight: 600;
            font-size: 1.2rem;
        }

        .search-input-container {
            position: relative;
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .search-input {
            flex: 1;
            padding: 15px 20px;
            border: 3px solid #667eea;
            border-radius: 10px;
            font-size: 1.1rem;
            background: #f8f9fa;
            transition: all 0.3s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: #764ba2;
            background: white;
            box-shadow: 0 0 20px rgba(102, 126, 234, 0.3);
        }

        .search-btn {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 10px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .search-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
        }

        .search-btn:active {
            transform: translateY(0);
        }

        .quick-search {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 30px;
        }

        .quick-search h3 {
            color: #333;
            margin-bottom: 15px;
            font-size: 1.3rem;
        }

        .quick-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .quick-btn {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
            padding: 12px 20px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .quick-btn:hover {
            background: #667eea;
            color: white;
            transform: translateY(-2px);
        }

        .search-results {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 10px;
            margin-top: 20px;
            border-left: 4px solid #667eea;
        }

        .search-results h3 {
            color: #333;
            margin-bottom: 15px;
        }

        .no-results {
            color: #666;
            font-style: italic;
        }

        .help-text {
            background: #e3f2fd;
            padding: 20px;
            border-radius: 10px;
            margin-top: 20px;
            border-left: 4px solid #2196F3;
        }

        .help-text h4 {
            color: #1976D2;
            margin-bottom: 10px;
        }

        .help-text ul {
            color: #333;
            padding-left: 20px;
        }

        .help-text li {
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
    <a href="index.php" class="back-btn">← Back to Dashboard</a>
    
    <div class="user-info">
        <?php echo htmlspecialchars($_SESSION['full_name']); ?><br>
        <?php echo date('m/d/Y'); ?>
    </div>
    
    <div class="inquiry-container">
        <div class="inquiry-header">
            <h1>Account Inquiry</h1>
            <p>Search and view member account information</p>
        </div>

        <div class="search-section">
            <form method="POST" class="search-form">
                <div class="form-group">
                    <label for="account_number">Enter Account Number:</label>
                    <div class="search-input-container">
                        <input 
                            type="text" 
                            id="account_number" 
                            name="account_number" 
                            class="search-input"
                            placeholder="Enter member account number..."
                            value="<?php echo htmlspecialchars($account_number); ?>"
                            autofocus
                        >
                        <button type="submit" class="search-btn">🔍 Search</button>
                    </div>
                </div>
            </form>

            <div class="quick-search">
                <h3>Quick Search Options</h3>
                <div class="quick-buttons">
                    <button class="quick-btn" onclick="searchByType('name')">Search by Name</button>
                    <button class="quick-btn" onclick="searchByType('ssn')">Search by SSN</button>
                    <button class="quick-btn" onclick="searchByType('phone')">Search by Phone</button>
                    <button class="quick-btn" onclick="searchByType('email')">Search by Email</button>
                </div>
            </div>

            <?php if ($search_result): ?>
            <div class="search-results">
                <h3>Search Results</h3>
                <p><?php echo htmlspecialchars($search_result); ?></p>
            </div>
            <?php elseif ($_POST && !$account_number): ?>
            <div class="search-results">
                <h3>Search Results</h3>
                <p class="no-results">Please enter an account number to search.</p>
            </div>
            <?php endif; ?>

            <div class="help-text">
                <h4>Search Tips:</h4>
                <ul>
                    <li>Enter the full member account number for best results</li>
                    <li>Account numbers are typically 6-10 digits</li>
                    <li>Use Quick Search options for alternative search methods</li>
                    <li>Ensure you have proper permissions to view account information</li>
                </ul>
            </div>
        </div>
    </div>

    <script>
        function searchByType(type) {
            alert(`${type.charAt(0).toUpperCase() + type.slice(1)} search functionality will be implemented here`);
        }

        // Auto-focus on the search input
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('account_number').focus();
        });

        // Allow Enter key to submit form
        document.getElementById('account_number').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                document.querySelector('.search-form').submit();
            }
        });
    </script>
</body>
</html>
