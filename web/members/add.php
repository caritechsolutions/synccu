<?php
require_once '../config.php';
requireLogin();
if (!hasPermission('accounts')) {
    header('Location: ../index.php');
    exit();
}

$type    = ($_GET['type'] ?? '') === 'company' ? 'company' : 'individual';
$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $first_name  = trim($_POST['first_name']  ?? '');
    $last_name   = trim($_POST['last_name']   ?? '');
    $dob         = trim($_POST['date_of_birth'] ?? '');
    $address     = trim($_POST['address']     ?? '');
    $city        = trim($_POST['city']        ?? '');
    $state       = strtoupper(trim($_POST['state'] ?? ''));
    $zip         = trim($_POST['zip']         ?? '');
    $phone       = trim($_POST['phone']       ?? '');
    $email       = trim($_POST['email']       ?? '');
    $employer    = trim($_POST['employer']    ?? '');
    $member_type = ($_POST['member_type'] ?? 'individual') === 'company' ? 'company' : 'individual';
    $ssn_last4   = trim($_POST['ssn_last4'] ?? '');

    if (!$first_name || !$last_name) {
        $error = 'First name and last name are required';
    } elseif ($ssn_last4 && !preg_match('/^\d{4}$/', $ssn_last4)) {
        $error = 'SSN last 4 digits must be exactly 4 numbers';
    } else {
        // Generate unique member number
        do {
            $member_number = date('Y') . str_pad(random_int(1, 999999), 6, '0', STR_PAD_LEFT);
            $check = $pdo->prepare("SELECT COUNT(*) FROM members WHERE member_number = ?");
            $check->execute([$member_number]);
        } while ($check->fetchColumn() > 0);

        $stmt = $pdo->prepare("
            INSERT INTO members
                (member_number, member_type, first_name, last_name, date_of_birth, ssn_last4,
                 address, city, state, zip, phone, email, employer, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        try {
            $stmt->execute([
                $member_number, $member_type, $first_name, $last_name,
                $dob ?: null, $ssn_last4 ?: null,
                $address, $city, $state, $zip, $phone, $email, $employer,
                $_SESSION['user_id']
            ]);
            $new_id = $pdo->lastInsertId();

            // Auto-create the base savings account.
            // The savings account number equals the member number (e.g. member 2025001234
            // → savings account 2025001234), matching the legacy import convention.
            $pdo->prepare("INSERT INTO member_accounts (member_id, account_number, account_type) VALUES (?, ?, 'savings')")
                ->execute([$new_id, $member_number]);

            $message = "Member #{$member_number} ({$first_name} {$last_name}) created successfully.";
            $_POST   = [];
        } catch (Exception $e) {
            $error = 'Error creating member. Please try again.';
            error_log($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncCU - Add <?php echo $type === 'company' ? 'Company' : 'Member'; ?></title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <a href="../accounts.php" class="back-btn">← Back</a>
    <div class="user-info" style="left:auto;right:20px;">
        <?php echo htmlspecialchars($_SESSION['full_name']); ?> | <?php echo date('m/d/Y'); ?>
    </div>

    <div class="container-sm">
        <div class="container-header">
            <h1><?php echo $type === 'company' ? 'Add Company / Organization' : 'Add New Member'; ?></h1>
            <p>Enter the member's personal and contact information</p>
        </div>

        <div class="content">
            <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
            <?php if ($error):   ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="member_type" value="<?php echo htmlspecialchars($type); ?>">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="first_name"><?php echo $type === 'company' ? 'Company / Organization Name *' : 'First Name *'; ?></label>
                        <input type="text" id="first_name" name="first_name" required
                               value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>">
                    </div>
                    <?php if ($type !== 'company'): ?>
                    <div class="form-group">
                        <label for="last_name">Last Name *</label>
                        <input type="text" id="last_name" name="last_name" required
                               value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="date_of_birth">Date of Birth</label>
                        <input type="date" id="date_of_birth" name="date_of_birth"
                               value="<?php echo htmlspecialchars($_POST['date_of_birth'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="ssn_last4">SSN Last 4 Digits</label>
                        <input type="text" id="ssn_last4" name="ssn_last4" maxlength="4" pattern="\d{4}"
                               placeholder="XXXX"
                               value="<?php echo htmlspecialchars($_POST['ssn_last4'] ?? ''); ?>">
                    </div>
                    <?php else: ?>
                    <div class="form-group">
                        <label for="last_name">Contact Person</label>
                        <input type="text" id="last_name" name="last_name"
                               value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>">
                    </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label for="phone">Phone</label>
                        <input type="tel" id="phone" name="phone"
                               value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email"
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="employer">Employer / Occupation</label>
                        <input type="text" id="employer" name="employer"
                               value="<?php echo htmlspecialchars($_POST['employer'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="address">Street Address</label>
                        <input type="text" id="address" name="address"
                               value="<?php echo htmlspecialchars($_POST['address'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="city">City</label>
                        <input type="text" id="city" name="city"
                               value="<?php echo htmlspecialchars($_POST['city'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="state">State</label>
                        <input type="text" id="state" name="state" maxlength="2"
                               value="<?php echo htmlspecialchars($_POST['state'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="zip">ZIP Code</label>
                        <input type="text" id="zip" name="zip"
                               value="<?php echo htmlspecialchars($_POST['zip'] ?? ''); ?>">
                    </div>
                </div>

                <div class="d-flex gap-10">
                    <button type="submit" class="btn btn-primary">Save Member</button>
                    <a href="../accounts.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
