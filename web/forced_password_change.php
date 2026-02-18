<?php
require_once 'config.php';

// Require user to be logged in
requireLogin();

// If user doesn't need to change password, redirect to dashboard
if (!mustChangePassword()) {
    header('Location: index.php');
    exit();
}

$error = '';
$message = '';

// Handle password change submission
if ($_POST) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate inputs
    if (!$current_password || !$new_password || !$confirm_password) {
        $error = 'Please fill in all fields';
    } elseif ($new_password !== $confirm_password) {
        $error = 'New passwords do not match';
    } elseif (strlen($new_password) < 6) {
        $error = 'Password must be at least 6 characters long';
    } else {
        // Verify current password
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user_data = $stmt->fetch();
        
        if (!password_verify($current_password, $user_data['password'])) {
            $error = 'Current password is incorrect';
        } else {
            // Update password and clear the force change flag
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?");
            
            try {
                $stmt->execute([$hashed_password, $_SESSION['user_id']]);
                $message = 'Password changed successfully! Redirecting to dashboard...';
                
                // Redirect after 2 seconds
                header("refresh:2;url=index.php");
                
            } catch (Exception $e) {
                $error = 'Error updating password. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncCU - Password Change Required</title>
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
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .password-container {
            background: white;
            border-radius: 15px;
            padding: 40px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 500px;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .header h1 {
            color: #e74c3c;
            font-size: 2.2rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .header h1::before {
            content: "⚠️";
            margin-right: 10px;
        }

        .header p {
            color: #666;
            font-size: 1rem;
            line-height: 1.5;
        }

        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            border-left: 4px solid #f39c12;
        }

        .warning-box h3 {
            color: #856404;
            margin-bottom: 10px;
        }

        .warning-box p {
            color: #856404;
            font-size: 0.95rem;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 600;
        }

        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }

        .submit-btn {
            width: 100%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 12px;
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

        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 12px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
        }

        .message {
            background: #d4edda;
            color: #155724;
            padding: 12px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #c3e6cb;
        }

        .logout-link {
            text-align: center;
            margin-top: 20px;
        }

        .logout-link a {
            color: #667eea;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .logout-link a:hover {
            text-decoration: underline;
        }

        .requirements {
            background: #e3f2fd;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #2196F3;
        }

        .requirements h4 {
            color: #1976D2;
            margin-bottom: 10px;
        }

        .requirements ul {
            color: #333;
            padding-left: 20px;
        }
    </style>
</head>
<body>
    <div class="password-container">
        <div class="header">
            <h1>Password Change Required</h1>
            <p>Your administrator has required you to change your password before continuing.</p>
        </div>

        <div class="warning-box">
            <h3>Important Security Notice</h3>
            <p>For security reasons, you must change your password before accessing the system. You will not be able to use SyncCU until you update your password.</p>
        </div>

        <?php if ($message): ?>
        <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="current_password">Current Password</label>
                <input type="password" id="current_password" name="current_password" required>
            </div>

            <div class="form-group">
                <label for="new_password">New Password</label>
                <input type="password" id="new_password" name="new_password" required>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm New Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>

            <div class="requirements">
                <h4>Password Requirements:</h4>
                <ul>
                    <li>Minimum 6 characters long</li>
                    <li>Use a combination of letters, numbers, and symbols</li>
                    <li>Cannot be the same as your current password</li>
                </ul>
            </div>

            <button type="submit" class="submit-btn">Change Password</button>
        </form>

        <div class="logout-link">
            <a href="logout.php">Logout and return to login page</a>
        </div>
    </div>
</body>
</html>
