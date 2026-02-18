<?php
require_once 'config.php';

requireLogin();

if (!mustChangePassword()) {
    header('Location: index.php');
    exit();
}

$error   = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $current_password = $_POST['current_password'] ?? '';
    $new_password     = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (!$current_password || !$new_password || !$confirm_password) {
        $error = 'Please fill in all fields';
    } elseif ($new_password !== $confirm_password) {
        $error = 'New passwords do not match';
    } else {
        $strength_error = validatePasswordStrength($new_password);
        if ($strength_error) {
            $error = $strength_error;
        }
    }

    if (!$error) {
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user_data = $stmt->fetch();

        if (!password_verify($current_password, $user_data['password'])) {
            $error = 'Current password is incorrect';
        } elseif (password_verify($new_password, $user_data['password'])) {
            $error = 'New password cannot be the same as your current password';
        } else {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?")
                ->execute([$hashed, $_SESSION['user_id']]);

            $message = 'Password changed successfully! Redirecting to dashboard…';
            header('refresh:2;url=index.php');
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
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="login-body">
    <div class="login-container" style="max-width:500px;">
        <div class="login-header">
            <h1 style="color:#e74c3c;font-size:1.8rem;">⚠ Password Change Required</h1>
            <p>Your administrator has required you to change your password before continuing.</p>
        </div>

        <div class="alert alert-warning">
            <strong>Security Notice:</strong> You must change your password to access SyncCU.
        </div>

        <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <?php if ($error):   ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <form method="POST">
            <?php echo csrfField(); ?>

            <div class="form-group">
                <label for="current_password">Current Password</label>
                <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
            </div>

            <div class="form-group">
                <label for="new_password">New Password</label>
                <input type="password" id="new_password" name="new_password" required autocomplete="new-password"
                       oninput="checkStrength(this.value)">
                <div class="strength-bar"><div class="strength-fill" id="strength-fill" style="width:0"></div></div>
                <small id="strength-label" class="text-muted"></small>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm New Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
            </div>

            <div class="info-box mb-20">
                <h4>Password Requirements</h4>
                <ul>
                    <li>Minimum 8 characters</li>
                    <li>At least one uppercase letter</li>
                    <li>At least one lowercase letter</li>
                    <li>At least one number</li>
                    <li>Cannot be the same as your current password</li>
                </ul>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Change Password</button>
        </form>

        <p class="text-center mt-10">
            <a href="logout.php" style="color:#667eea;font-size:0.9rem;">Logout</a>
        </p>
    </div>

    <script>
    function checkStrength(pw) {
        let score = 0;
        if (pw.length >= 8)           score++;
        if (/[A-Z]/.test(pw))        score++;
        if (/[a-z]/.test(pw))        score++;
        if (/[0-9]/.test(pw))        score++;
        if (/[^A-Za-z0-9]/.test(pw)) score++;

        const colors = ['#dc3545','#fd7e14','#ffc107','#28a745','#155724'];
        const labels = ['Very Weak','Weak','Fair','Strong','Very Strong'];
        document.getElementById('strength-fill').style.width      = (score * 20) + '%';
        document.getElementById('strength-fill').style.background = colors[score - 1] || '#e0e0e0';
        document.getElementById('strength-label').textContent     = score ? labels[score - 1] : '';
    }
    </script>
</body>
</html>
