<?php
require_once 'config.php';

// Require login and admin permissions
requireLogin();
if ($_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Handle form submissions
if ($_POST) {
    $action = $_POST['action'];
    
    if ($action === 'add_user') {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $role = $_POST['role'];
        
        // Validate inputs
        if (!$username || !$password || !$full_name || !$role) {
            $error = 'Please fill in all required fields';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters long';
        } else {
            // Check if username already exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $stmt->execute([$username]);
            
            if ($stmt->fetchColumn() > 0) {
                $error = 'Username already exists';
            } else {
                // Create new user
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, email, role, is_active) VALUES (?, ?, ?, ?, ?, 1)");
                
                try {
                    $stmt->execute([$username, $hashed_password, $full_name, $email, $role]);
                    $message = "User '{$full_name}' created successfully";
                    $_POST = []; // Clear form
                } catch (Exception $e) {
                    $error = 'Error creating user: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'toggle_status') {
        $user_id = $_POST['user_id'];
        $new_status = $_POST['new_status'];
        
        $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ? AND id != ?");
        $stmt->execute([$new_status, $user_id, $_SESSION['user_id']]);
        
        $action_text = $new_status ? 'activated' : 'deactivated';
        $message = "User {$action_text} successfully";
    }
}

// Get all users
$stmt = $pdo->prepare("SELECT id, username, full_name, email, role, is_active, created_at, last_login FROM users ORDER BY full_name");
$stmt->execute();
$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncCU - User Management</title>
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
            text-decoration: none;
            transition: background 0.3s ease;
        }

        .back-btn:hover {
            background: rgba(255,255,255,0.3);
        }

        .container {
            max-width: 1200px;
            margin: 80px auto 0;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .content {
            padding: 30px;
        }

        .tabs {
            display: flex;
            margin-bottom: 30px;
            border-bottom: 2px solid #f0f0f0;
        }

        .tab {
            padding: 15px 25px;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
        }

        .tab.active {
            border-bottom-color: #667eea;
            color: #667eea;
            font-weight: bold;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 1rem;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }

        .submit-btn {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            font-size: 1.1rem;
            cursor: pointer;
            transition: transform 0.2s ease;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
        }

        .users-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .users-table th,
        .users-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        .users-table th {
            background: #f8f9fa;
            font-weight: 600;
        }

        .status-active {
            color: #28a745;
            font-weight: bold;
        }

        .status-inactive {
            color: #dc3545;
            font-weight: bold;
        }

        .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            margin: 0 2px;
        }

        .btn-deactivate {
            background: #dc3545;
            color: white;
        }

        .btn-activate {
            background: #28a745;
            color: white;
        }

        .message {
            background: #d4edda;
            color: #155724;
            padding: 12px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 12px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <a href="manager.php" class="back-btn">← Back to Manager's Menu</a>

    <div class="container">
        <div class="header">
            <h1>User Management</h1>
            <p>Manage system users and permissions</p>
        </div>

        <div class="content">
            <?php if ($message): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="tabs">
                <div class="tab active" onclick="showTab('add-user')">Add New User</div>
                <div class="tab" onclick="showTab('manage-users')">Manage Users</div>
            </div>

            <div id="add-user" class="tab-content active">
                <h3>Add New User</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="add_user">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="username">Username *</label>
                            <input type="text" id="username" name="username" required value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label for="password">Password *</label>
                            <input type="password" id="password" name="password" required>
                        </div>

                        <div class="form-group">
                            <label for="full_name">Full Name *</label>
                            <input type="text" id="full_name" name="full_name" required value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label for="role">Role *</label>
                            <select id="role" name="role" required>
                                <option value="">Select Role...</option>
                                <option value="admin" <?php echo (isset($_POST['role']) && $_POST['role'] === 'admin') ? 'selected' : ''; ?>>Administrator</option>
                                <option value="manager" <?php echo (isset($_POST['role']) && $_POST['role'] === 'manager') ? 'selected' : ''; ?>>Manager</option>
                                <option value="teller" <?php echo (isset($_POST['role']) && $_POST['role'] === 'teller') ? 'selected' : ''; ?>>Teller</option>
                                <option value="user" <?php echo (isset($_POST['role']) && $_POST['role'] === 'user') ? 'selected' : ''; ?>>User</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="submit-btn">Create User</button>
                </form>
            </div>

            <div id="manage-users" class="tab-content">
                <h3>Existing Users</h3>
                <table class="users-table">
                    <thead>
                        <tr>
                            <th>Full Name</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Last Login</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                            <td><?php echo htmlspecialchars($user['email'] ?: 'N/A'); ?></td>
                            <td><?php echo ucfirst($user['role']); ?></td>
                            <td class="<?php echo $user['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                            </td>
                            <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                            <td><?php echo $user['last_login'] ? date('M j, Y', strtotime($user['last_login'])) : 'Never'; ?></td>
                            <td>
                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <input type="hidden" name="new_status" value="<?php echo $user['is_active'] ? '0' : '1'; ?>">
                                    <button type="submit" class="action-btn <?php echo $user['is_active'] ? 'btn-deactivate' : 'btn-activate'; ?>" 
                                            onclick="return confirm('Are you sure?')">
                                        <?php echo $user['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                    </button>
                                </form>
                                <?php else: ?>
                                <em>Current User</em>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        function showTab(tabName) {
            // Hide all tab contents
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(content => content.classList.remove('active'));
            
            // Remove active class from all tabs
            const tabs = document.querySelectorAll('.tab');
            tabs.forEach(tab => tab.classList.remove('active'));
            
            // Show selected tab content
            document.getElementById(tabName).classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }
    </script>
</body>
</html>
