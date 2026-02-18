<?php
require_once '../config.php';
requireLogin();
if (!hasPermission('accounts') && !hasPermission('inquiry')) {
    header('Location: ../index.php');
    exit();
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: list.php'); exit(); }

$stmt = $pdo->prepare("SELECT * FROM members WHERE id = ? AND is_active = 1");
$stmt->execute([$id]);
$member = $stmt->fetch();
if (!$member) { header('Location: list.php'); exit(); }

// Get accounts
$stmt = $pdo->prepare("SELECT * FROM member_accounts WHERE member_id = ? ORDER BY account_type, date_opened");
$stmt->execute([$id]);
$accounts = $stmt->fetchAll();

// Get recent transactions (last 20 across all accounts)
$stmt = $pdo->prepare("
    SELECT t.*, a.account_number, a.account_type
    FROM transactions t
    JOIN member_accounts a ON t.account_id = a.id
    WHERE a.member_id = ?
    ORDER BY t.transaction_date DESC
    LIMIT 20
");
$stmt->execute([$id]);
$transactions = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncCU - Member: <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <a href="list.php" class="back-btn">← Back to List</a>
    <div class="user-info" style="left:auto;right:20px;">
        <?php echo htmlspecialchars($_SESSION['full_name']); ?> | <?php echo date('m/d/Y'); ?>
    </div>

    <div class="container" style="margin-top:80px;">
        <div class="container-header">
            <h1><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></h1>
            <p>Account # <?php echo htmlspecialchars($member['member_number']); ?> &bull;
               <?php echo ucfirst($member['member_type']); ?> &bull;
               Joined <?php echo $member['date_joined'] ? date('M j, Y', strtotime($member['date_joined'])) : 'N/A'; ?>
            </p>
        </div>

        <div class="content">
            <div class="d-flex gap-10 mb-20" style="justify-content:flex-end;">
                <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-secondary">Edit Member</a>
                <a href="../trans/deposit.php?member_id=<?php echo $id; ?>" class="btn btn-success">Deposit</a>
                <a href="../trans/withdrawal.php?member_id=<?php echo $id; ?>" class="btn btn-danger">Withdraw</a>
            </div>

            <!-- Personal Info -->
            <h3 class="mb-20">Personal Information</h3>
            <div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));">
                <?php
                $fields = [
                    'Phone'     => $member['phone']    ?: 'N/A',
                    'Email'     => $member['email']    ?: 'N/A',
                    'Employer'  => $member['employer'] ?: 'N/A',
                    'Address'   => trim("{$member['address']}, {$member['city']}, {$member['state']} {$member['zip']}"),
                    'SSN Last 4'=> $member['ssn_last4'] ? '***-**-' . $member['ssn_last4'] : 'N/A',
                    'DOB'       => $member['date_of_birth'] ? date('M j, Y', strtotime($member['date_of_birth'])) : 'N/A',
                ];
                foreach ($fields as $label => $value): ?>
                <div>
                    <strong style="color:#667eea;"><?php echo $label; ?></strong><br>
                    <?php echo htmlspecialchars($value); ?>
                </div>
                <?php endforeach; ?>
            </div>

            <hr style="margin:30px 0;border:none;border-top:2px solid #f0f0f0;">

            <!-- Accounts -->
            <h3 class="mb-20">Accounts</h3>
            <?php if ($accounts): ?>
            <table class="data-table">
                <thead>
                    <tr><th>Account #</th><th>Type</th><th>Balance</th><th>Interest Rate</th><th>Date Opened</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $a): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($a['account_number']); ?></td>
                        <td><?php echo ucfirst($a['account_type']); ?></td>
                        <td>$<?php echo number_format((float)$a['balance'], 2); ?></td>
                        <td><?php echo number_format((float)$a['interest_rate'] * 100, 2); ?>%</td>
                        <td><?php echo date('M j, Y', strtotime($a['date_opened'])); ?></td>
                        <td class="<?php echo $a['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                            <?php echo $a['is_active'] ? 'Active' : 'Closed'; ?>
                        </td>
                        <td>
                            <a href="../trans/history.php?account_id=<?php echo $a['id']; ?>" class="btn btn-sm btn-primary">History</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="alert alert-warning">No accounts found for this member.</div>
            <?php endif; ?>

            <hr style="margin:30px 0;border:none;border-top:2px solid #f0f0f0;">

            <!-- Recent Transactions -->
            <h3 class="mb-20">Recent Transactions</h3>
            <?php if ($transactions): ?>
            <table class="data-table">
                <thead>
                    <tr><th>Date</th><th>Account</th><th>Type</th><th>Amount</th><th>Balance After</th><th>Description</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $t): ?>
                    <tr>
                        <td><?php echo date('M j, Y g:i A', strtotime($t['transaction_date'])); ?></td>
                        <td><?php echo htmlspecialchars($t['account_number']); ?> (<?php echo ucfirst($t['account_type']); ?>)</td>
                        <td><?php echo ucwords(str_replace('_', ' ', $t['transaction_type'])); ?></td>
                        <td style="color:<?php echo in_array($t['transaction_type'], ['deposit','transfer_in','interest']) ? '#28a745' : '#dc3545'; ?>">
                            <?php echo in_array($t['transaction_type'], ['deposit','transfer_in','interest']) ? '+' : '-'; ?>$<?php echo number_format((float)$t['amount'], 2); ?>
                        </td>
                        <td>$<?php echo number_format((float)$t['balance_after'], 2); ?></td>
                        <td><?php echo htmlspecialchars($t['description'] ?: 'N/A'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p class="text-muted">No transactions found.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
