<?php
require_once 'config.php';

// Require login and check permissions
requireLogin();
if (!hasPermission('password')) {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';
$is_admin = ($_SESSION['user_role'] === 'admin');

// Get all users if admin
$users = [];
if ($is_admin) {
    $stmt = $pdo->prepare("SELECT id, username, full_name FROM users WHERE is_active = 1 ORDER BY full_name");
    $stmt->execute();
    $users = $stmt->fetchAll();
}

// Handle password change submission
if ($_POST) {
    $target_user_id = $is_admin && isset($_POST['target_user']) ? $_POST['target_user'] : $_SESSION['user_id'];
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    $force_change = $is_admin && isset($_POST['force_change']) ? 1 : 0;
    
    // Validate inputs
    if (!$new_password || !$confirm_password) {
        $error = 'Please fill in all required fields';
    } elseif ($new_password !== $confirm_password) {
        $error = 'New passwords do not match';
    } elseif (strlen($new_password) < 6) {
        $error = 'Password must be at least 6 characters long';
    } else {
        // For non-admin users, verify current password
        if (!$is_admin || $target_user_id == $_SESSION['user_id']) {
            if (!$current_password) {
                $error = 'Current password is required';
            } else {
                $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->execute([$target_user_id]);
                $user_data = $stmt->fetch();
                
                if (!$user_data || !password_verify($current_password, $user_data['password'])) {
                    $error = 'Current password is incorrect';
                }
            }
        }
        
        if (!$error) {
            // Update password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            if ($force_change) {
                // Add a flag to force password change - we'll need to add this column
                $stmt = $pdo->prepare("UPDATE users SET password = ?, must_change_password = 1 WHERE id = ?");
            } else {
                $stmt = $pdo->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?");
            }
            
            try {
                $stmt->execute([$hashed_password, $target_user_id]);
                
                // Get target user info for message
                $stmt = $pdo->prepare("SELECT username, full_name FROM users WHERE id = ?");
                $stmt->execute([$target_user_id]);
                $target_user = $stmt->fetch();
                
                if ($target_user_id == $_SESSION['user_id']) {
                    $message = 'Your password has been changed successfully';
                } else {
                    $action = $force_change ? 'reset (user must change on next login)' : 'changed';
                    $message = "Password {$action} for user: {$target_user['full_name']} ({$target_user['username']})";
                }
                
                // Clear form
                $_POST = [];
                
            } catch (Exception $e) {
                $error = 'Error updating password. Please try again.';
            }
        }
    }
}

// Add the must_change_password column if it doesn't exist
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN must_change_password BOOLEAN DEFAULT FALSE");
} catch (Exception $e) {
    // Column probably already exists
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncCU - Change Password</title>
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

        .password-container {
            max-width: 600px;
            margin: 80px auto 0;
            background: rgba(255,255,255,0.95);
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }

        .password-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .password-header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }

        .password-header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .form-section {
            padding: 40px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 600;
        }

        .form-group select,
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        .form-group select:focus,
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            margin-top: 10px;
        }

        .checkbox-group input[type="checkbox"] {
            width: auto;
            margin-right: 10px;
        }

        .checkbox-group label {
            margin-bottom: 0;
            font-weight: normal;
        }

        .submit-btn {
            width: 100%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 15px;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        .message {
            background: #d4edda;
            color: #155724;
            padding: 12px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #c3e6cb;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 12px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
        }

        .admin-section {
            background: #fff3cd;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            border: 1px solid #ffeaa7;
        }

        .admin-section h3 {
            color: #856404;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }

        .admin-section h3::before {
            content: "👨‍💼";
            margin-right: 10px;
        }

        .password-requirements {
            background: #e3f2fd;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #2196F3;
        }

        .password-requirements h4 {
            color: #1976D2;
            margin-bottom: 10px;
        }

        .password-requirements ul {
            color: #333;
            padding-left: 20px;
        }
    </style>
</head>
<body>
    <a href="index.php" class="back-btn">← Back to Dashboard</a>
    
    <div class="user-info">
        <?php echo htmlspecialchars($_SESSION['full_name']); ?><br>
        <?php echo date('m/d/Y'); ?>
    </div>
    
    <div class="password-container">
        <div class="password-header">
            <h1>Change Password</h1>
            <p><?php echo $is_admin ? 'Manage user passwords and security' : 'Update your password'; ?></p>
        </div>

        <div class="form-section">
            <?php if ($message): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($is_admin): ?>
            <div class="admin-section">
                <h3>Administrator Functions</h3>
                <p>As an administrator, you can reset passwords for any user and force them to change their password on next login.</p>
            </div>
            <?php endif; ?>

            <form method="POST">
                <?php if ($is_admin): ?>
                <div class="form-group">
                    <label for="target_user">Select User:</label>
                    <select id="target_user" name="target_user" required onchange="toggleCurrentPassword()">
                        <option value="">Choose a user...</option>
                        <?php foreach ($users as $user): ?>
                        <option value="<?php echo $user['id']; ?>" <?php echo (isset($_POST['target_user']) && $_POST['target_user'] == $user['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($user['full_name'] . ' (' . $user['username'] . ')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="form-group" id="current_password_group">
                    <label for="current_password">
                        <?php echo $is_admin ? 'Current Password (only required if changing your own):' : 'Current Password:'; ?>
                    </label>
                    <input 
                        type="password" 
                        id="current_password" 
                        name="current_password"
                        <?php echo !$is_admin ? 'required' : ''; ?>
                    >
                </div>

                <div class="form-group">
                    <label for="new_password">New Password:</label>
                    <input type="password" id="new_password" name="new_password" required>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password:</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>

                <?php if ($is_admin): ?>
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="force_change" name="force_change" value="1">
                        <label for="force_change">Force user to change password on next login</label>
                    </div>
                </div>
                <?php endif; ?>

                <div class="password-requirements">
                    <h4>Password Requirements:</h4>
                    <ul>
                        <li>Minimum 6 characters long</li>
                        <li>Use a combination of letters, numbers, and symbols</li>
                        <li>Avoid using personal information</li>
                        <li>Don't reuse recent passwords</li>
                    </ul>
                </div>

                <button type="submit" class="submit-btn">
                    <?php echo $is_admin ? 'Update Password' : 'Change My Password'; ?>
                </button>
            </form>
        </div>
    </div>

    <script>
        function toggleCurrentPassword() {
            <?php if ($is_admin): ?>
            const targetUser = document.getElementById('target_user').value;
            const currentUserId = '<?php echo $_SESSION['user_id']; ?>';
            const currentPasswordGroup = document.getElementById('current_password_group');
            const currentPasswordInput = document.getElementById('current_password');
            
            if (targetUser && targetUser !== currentUserId) {
                // Admin changing someone else's password - current password not required
                currentPasswordInput.required = false;
                currentPasswordGroup.style.opacity = '0.5';
            } else {
                // Admin changing their own password - current password required
                currentPasswordInput.required = true;
                currentPasswordGroup.style.opacity = '1';
            }
            <?php endif; ?>
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            toggleCurrentPassword();
        });
    </script>
</body>
</html>
