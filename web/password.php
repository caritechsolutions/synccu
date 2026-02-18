<?php
require_once 'config.php';

requireLogin();
if (!hasPermission('password')) {
    header('Location: index.php');
    exit();
}

$message = '';
$error   = '';
$is_admin = ($_SESSION['user_role'] === 'admin');

// Get all active users if admin
$users = [];
if ($is_admin) {
    $stmt = $pdo->prepare("SELECT id, username, full_name FROM users WHERE is_active = 1 ORDER BY full_name");
    $stmt->execute();
    $users = $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $target_user_id  = ($is_admin && isset($_POST['target_user'])) ? (int)$_POST['target_user'] : (int)$_SESSION['user_id'];
    $current_password = $_POST['current_password'] ?? '';
    $new_password     = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $force_change     = ($is_admin && !empty($_POST['force_change'])) ? 1 : 0;

    if (!$new_password || !$confirm_password) {
        $error = 'Please fill in all required fields';
    } elseif ($new_password !== $confirm_password) {
        $error = 'New passwords do not match';
    } else {
        $strength_error = validatePasswordStrength($new_password);
        if ($strength_error) {
            $error = $strength_error;
        }
    }

    if (!$error) {
        // Non-admin or admin changing their own password: require current password
        if (!$is_admin || $target_user_id === (int)$_SESSION['user_id']) {
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
    }

    if (!$error) {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt   = $pdo->prepare("UPDATE users SET password = ?, must_change_password = ? WHERE id = ?");
        $stmt->execute([$hashed, $force_change, $target_user_id]);

        $stmt = $pdo->prepare("SELECT username, full_name FROM users WHERE id = ?");
        $stmt->execute([$target_user_id]);
        $target_user = $stmt->fetch();

        if ($target_user_id === (int)$_SESSION['user_id']) {
            $message = 'Your password has been changed successfully';
        } else {
            $action  = $force_change ? 'reset (must change on next login)' : 'changed';
            $message = "Password {$action} for: {$target_user['full_name']} ({$target_user['username']})";
        }
        $_POST = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncCU - Change Password</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <a href="index.php" class="back-btn">← Back to Dashboard</a>
    <div class="user-info" style="left:auto;right:20px;">
        <?php echo htmlspecialchars($_SESSION['full_name']); ?> | <?php echo date('m/d/Y'); ?>
    </div>

    <div class="container-sm">
        <div class="container-header">
            <h1>Change Password</h1>
            <p><?php echo $is_admin ? 'Manage user passwords and security' : 'Update your password'; ?></p>
        </div>

        <div class="content">
            <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
            <?php if ($error):   ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

            <?php if ($is_admin): ?>
            <div class="admin-box">
                <h3>Administrator Functions</h3>
                <p>As an administrator you can reset any user's password and force them to change it on next login.</p>
            </div>
            <?php endif; ?>

            <form method="POST">
                <?php echo csrfField(); ?>

                <?php if ($is_admin): ?>
                <div class="form-group">
                    <label for="target_user">Select User</label>
                    <select id="target_user" name="target_user" required onchange="toggleCurrentPw()">
                        <option value="">Choose a user…</option>
                        <?php foreach ($users as $u): ?>
                        <option value="<?php echo $u['id']; ?>"
                            <?php echo (isset($_POST['target_user']) && $_POST['target_user'] == $u['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($u['full_name'] . ' (' . $u['username'] . ')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="form-group" id="cur_pw_group">
                    <label for="current_password">
                        <?php echo $is_admin ? 'Current Password (required only when changing your own)' : 'Current Password'; ?>
                    </label>
                    <input type="password" id="current_password" name="current_password"
                           <?php echo !$is_admin ? 'required' : ''; ?> autocomplete="current-password">
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

                <?php if ($is_admin): ?>
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="force_change" name="force_change" value="1">
                        <label for="force_change">Force user to change password on next login</label>
                    </div>
                </div>
                <?php endif; ?>

                <div class="info-box">
                    <h4>Password Requirements</h4>
                    <ul>
                        <li>Minimum 8 characters</li>
                        <li>At least one uppercase letter</li>
                        <li>At least one lowercase letter</li>
                        <li>At least one number</li>
                    </ul>
                </div>

                <button type="submit" class="btn btn-primary btn-block">
                    <?php echo $is_admin ? 'Update Password' : 'Change My Password'; ?>
                </button>
            </form>
        </div>
    </div>

    <script>
    const myId = '<?php echo (int)$_SESSION['user_id']; ?>';

    function toggleCurrentPw() {
        <?php if ($is_admin): ?>
        const sel = document.getElementById('target_user').value;
        const grp = document.getElementById('cur_pw_group');
        const inp = document.getElementById('current_password');
        const own = sel === myId || sel === '';
        inp.required = own;
        grp.style.opacity = own ? '1' : '0.5';
        <?php endif; ?>
    }

    function checkStrength(pw) {
        let score = 0;
        if (pw.length >= 8)              score++;
        if (/[A-Z]/.test(pw))           score++;
        if (/[a-z]/.test(pw))           score++;
        if (/[0-9]/.test(pw))           score++;
        if (/[^A-Za-z0-9]/.test(pw))    score++;

        const bar    = document.getElementById('strength-fill');
        const label  = document.getElementById('strength-label');
        const colors = ['#dc3545','#fd7e14','#ffc107','#28a745','#155724'];
        const labels = ['Very Weak','Weak','Fair','Strong','Very Strong'];
        bar.style.width     = (score * 20) + '%';
        bar.style.background = colors[score - 1] || '#e0e0e0';
        label.textContent   = score ? labels[score - 1] : '';
    }

    document.addEventListener('DOMContentLoaded', toggleCurrentPw);
    </script>
</body>
</html>
