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

// Count accounts and transactions
$stmt = $pdo->prepare("SELECT COUNT(*) FROM member_accounts WHERE member_id = ? AND is_active = 1");
$stmt->execute([$id]);
$account_count = $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM transactions t
    JOIN member_accounts a ON t.account_id = a.id
    WHERE a.member_id = ?
");
$stmt->execute([$id]);
$transaction_count = $stmt->fetchColumn();

$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (isset($_POST['confirm_delete'])) {
        // Soft-delete: mark inactive
        $pdo->prepare("UPDATE members SET is_active = 0 WHERE id = ?")->execute([$id]);
        $pdo->prepare("UPDATE member_accounts SET is_active = 0 WHERE member_id = ?")->execute([$id]);
        header('Location: list.php?msg=deleted');
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncCU - Delete Member</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <a href="view.php?id=<?php echo $id; ?>" class="back-btn">← Back to Member</a>
    <div class="user-info" style="left:auto;right:20px;">
        <?php echo htmlspecialchars($_SESSION['full_name']); ?> | <?php echo date('m/d/Y'); ?>
    </div>

    <div class="container-sm">
        <div class="container-header" style="background:linear-gradient(135deg,#dc3545,#c0392b);">
            <h1>Delete Member</h1>
            <p>This action cannot be easily undone</p>
        </div>

        <div class="content">
            <div class="alert alert-danger">
                <strong>Warning:</strong> You are about to deactivate member account
                <strong><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></strong>
                (Account # <?php echo htmlspecialchars($member['member_number']); ?>).
            </div>

            <div class="alert alert-warning">
                This member has <strong><?php echo $account_count; ?> active account(s)</strong>
                and <strong><?php echo $transaction_count; ?> transaction record(s)</strong>.
                All accounts will be closed. Transaction history will be retained for audit purposes.
            </div>

            <?php if ($account_count > 0): ?>
            <div class="alert alert-danger">
                <strong>Note:</strong> Please ensure all account balances are at $0.00 before deleting.
                Accounts with positive balances should be zeroed first.
            </div>
            <?php endif; ?>

            <form method="POST">
                <?php echo csrfField(); ?>
                <div class="d-flex gap-10">
                    <button type="submit" name="confirm_delete" value="1"
                            class="btn btn-danger"
                            onclick="return confirm('Are you absolutely sure you want to delete this member?')">
                        Confirm Delete
                    </button>
                    <a href="view.php?id=<?php echo $id; ?>" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
