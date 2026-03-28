<?php
require_once '../config.php';
requireLogin();
if (!hasPermission('accounts')) {
    header('Location: ../index.php');
    exit();
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: list.php'); exit(); }

$stmt = $pdo->prepare("SELECT * FROM members WHERE id = ? AND is_active = 1");
$stmt->execute([$id]);
$member = $stmt->fetch();
if (!$member) { header('Location: list.php'); exit(); }

$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $first_name = trim($_POST['first_name']     ?? '');
    $last_name  = trim($_POST['last_name']      ?? '');
    $dob        = trim($_POST['date_of_birth']  ?? '');
    $address    = trim($_POST['address']        ?? '');
    $city       = trim($_POST['city']           ?? '');
    $state      = strtoupper(trim($_POST['state'] ?? ''));
    $zip        = trim($_POST['zip']            ?? '');
    $phone      = trim($_POST['phone']          ?? '');
    $email      = trim($_POST['email']          ?? '');
    $employer   = trim($_POST['employer']       ?? '');

    if (!$first_name || !$last_name) {
        $error = 'First name and last name are required';
    } else {
        $pdo->prepare("
            UPDATE members SET first_name=?, last_name=?, date_of_birth=?,
                address=?, city=?, state=?, zip=?, phone=?, email=?, employer=?
            WHERE id=?
        ")->execute([$first_name, $last_name, $dob ?: null, $address, $city, $state, $zip, $phone, $email, $employer, $id]);

        $message = 'Member information updated successfully';
        // Refresh member data
        $stmt = $pdo->prepare("SELECT * FROM members WHERE id = ?");
        $stmt->execute([$id]);
        $member = $stmt->fetch();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncCU - Edit Member</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <a href="view.php?id=<?php echo $id; ?>" class="back-btn">← Back to Member</a>
    <div class="user-info" style="left:auto;right:20px;">
        <?php echo htmlspecialchars($_SESSION['full_name']); ?> | <?php echo date('m/d/Y'); ?>
    </div>

    <div class="container-sm">
        <div class="container-header">
            <h1>Edit Member</h1>
            <p><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?> — Account #<?php echo htmlspecialchars($member['member_number']); ?></p>
        </div>

        <div class="content">
            <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
            <?php if ($error):   ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

            <form method="POST">
                <?php echo csrfField(); ?>
                <div class="form-grid">
                    <div class="form-group">
                        <label>First Name *</label>
                        <input type="text" name="first_name" required value="<?php echo htmlspecialchars($member['first_name']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Last Name *</label>
                        <input type="text" name="last_name" required value="<?php echo htmlspecialchars($member['last_name']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Date of Birth</label>
                        <input type="date" name="date_of_birth" value="<?php echo htmlspecialchars($member['date_of_birth'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="tel" name="phone" value="<?php echo htmlspecialchars($member['phone'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($member['email'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Employer</label>
                        <input type="text" name="employer" value="<?php echo htmlspecialchars($member['employer'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Street Address</label>
                        <input type="text" name="address" value="<?php echo htmlspecialchars($member['address'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>City</label>
                        <input type="text" name="city" value="<?php echo htmlspecialchars($member['city'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>State</label>
                        <input type="text" name="state" maxlength="2" value="<?php echo htmlspecialchars($member['state'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>ZIP</label>
                        <input type="text" name="zip" value="<?php echo htmlspecialchars($member['zip'] ?? ''); ?>">
                    </div>
                </div>

                <div class="d-flex gap-10">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <a href="view.php?id=<?php echo $id; ?>" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
