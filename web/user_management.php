<?php
require_once 'config.php';

requireLogin();
if ($_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'add_user') {
        $username  = trim($_POST['username'] ?? '');
        $password  = $_POST['password'] ?? '';
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $role      = $_POST['role'] ?? '';

        if (!$username || !$password || !$full_name || !$role) {
            $error = 'Please fill in all required fields';
        } else {
            $strength_error = validatePasswordStrength($password);
            if ($strength_error) {
                $error = $strength_error;
            }
        }

        if (!$error) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetchColumn() > 0) {
                $error = 'Username already exists';
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt   = $pdo->prepare(
                    "INSERT INTO users (username, password, full_name, email, role, is_active) VALUES (?, ?, ?, ?, ?, 1)"
                );
                try {
                    $stmt->execute([$username, $hashed, $full_name, $email, $role]);
                    $message = "User '{$full_name}' created successfully";
                    $_POST   = [];
                } catch (Exception $e) {
                    $error = 'Error creating user. Please try again.';
                    error_log($e->getMessage());
                }
            }
        }

    } elseif ($action === 'toggle_status') {
        $user_id    = (int)($_POST['user_id'] ?? 0);
        $new_status = (int)($_POST['new_status'] ?? 0);

        // Prevent admin from deactivating their own account
        if ($user_id === (int)$_SESSION['user_id']) {
            $error = 'You cannot deactivate your own account';
        } else {
            $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?")
                ->execute([$new_status, $user_id]);
            $message = 'User ' . ($new_status ? 'activated' : 'deactivated') . ' successfully';
        }
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
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <a href="manager.php" class="back-btn">← Back to Manager's Menu</a>

    <div class="container">
        <div class="container-header">
            <h1>User Management</h1>
            <p>Manage system users and access</p>
        </div>

        <div class="content">
            <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
            <?php if ($error):   ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

            <div class="tabs">
                <div class="tab active" onclick="showTab('add-user', this)">Add New User</div>
                <div class="tab" onclick="showTab('manage-users', this)">Manage Users</div>
            </div>

            <!-- Add User Tab -->
            <div id="add-user" class="tab-content active">
                <h3 class="mb-20">Add New User</h3>
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="add_user">

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="username">Username *</label>
                            <input type="text" id="username" name="username" required
                                   value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="password">Password *</label>
                            <input type="password" id="password" name="password" required autocomplete="new-password"
                                   oninput="checkStrength(this.value)">
                            <div class="strength-bar"><div class="strength-fill" id="strength-fill" style="width:0"></div></div>
                            <small id="strength-label" class="text-muted"></small>
                        </div>
                        <div class="form-group">
                            <label for="full_name">Full Name *</label>
                            <input type="text" id="full_name" name="full_name" required
                                   value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email"
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="role">Role *</label>
                            <select id="role" name="role" required>
                                <option value="">Select Role…</option>
                                <?php foreach (['admin' => 'Administrator', 'manager' => 'Manager', 'teller' => 'Teller', 'user' => 'User'] as $val => $label): ?>
                                <option value="<?php echo $val; ?>"
                                    <?php echo (($_POST['role'] ?? '') === $val) ? 'selected' : ''; ?>>
                                    <?php echo $label; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="info-box mb-20">
                        <h4>Password Requirements</h4>
                        <ul>
                            <li>Minimum 8 characters</li>
                            <li>At least one uppercase letter, one lowercase letter, one number</li>
                        </ul>
                    </div>

                    <button type="submit" class="btn btn-primary">Create User</button>
                </form>
            </div>

            <!-- Manage Users Tab -->
            <div id="manage-users" class="tab-content">
                <h3 class="mb-20">Existing Users</h3>
                <table class="data-table">
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
                        <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($u['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($u['username']); ?></td>
                            <td><?php echo htmlspecialchars($u['email'] ?: 'N/A'); ?></td>
                            <td><?php echo ucfirst($u['role']); ?></td>
                            <td class="<?php echo $u['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                <?php echo $u['is_active'] ? 'Active' : 'Inactive'; ?>
                            </td>
                            <td><?php echo date('M j, Y', strtotime($u['created_at'])); ?></td>
                            <td><?php echo $u['last_login'] ? date('M j, Y', strtotime($u['last_login'])) : 'Never'; ?></td>
                            <td>
                                <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                <form method="POST" style="display:inline;">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action"     value="toggle_status">
                                    <input type="hidden" name="user_id"   value="<?php echo $u['id']; ?>">
                                    <input type="hidden" name="new_status" value="<?php echo $u['is_active'] ? '0' : '1'; ?>">
                                    <button type="submit"
                                            class="btn btn-sm <?php echo $u['is_active'] ? 'btn-danger' : 'btn-success'; ?>"
                                            onclick="return confirm('Are you sure?')">
                                        <?php echo $u['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                    </button>
                                </form>
                                <?php else: ?>
                                <em class="text-muted">Current User</em>
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
    function showTab(id, el) {
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.getElementById(id).classList.add('active');
        el.classList.add('active');
    }

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
        document.getElementById('strength-fill').style.background = colors[score-1] || '#e0e0e0';
        document.getElementById('strength-label').textContent     = score ? labels[score-1] : '';
    }
    </script>
</body>
</html>
