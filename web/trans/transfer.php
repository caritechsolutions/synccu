<?php
require_once '../config.php';
requireLogin();
if (!hasPermission('transactions')) {
    header('Location: ../index.php');
    exit();
}

$message      = '';
$error        = '';
$receipt_data = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $from_id     = (int)($_POST['from_account_id'] ?? 0);
    $to_id       = (int)($_POST['to_account_id']   ?? 0);
    $amount      = (float)($_POST['amount']         ?? 0);
    $description = trim($_POST['description']       ?? '');

    if (!$from_id || !$to_id || $amount <= 0) {
        $error = 'Please select both accounts and enter a valid amount';
    } elseif ($from_id === $to_id) {
        $error = 'Source and destination accounts must be different';
    } else {
        $stmt = $pdo->prepare("
            SELECT a.*, m.first_name, m.last_name, m.member_number
            FROM member_accounts a JOIN members m ON a.member_id = m.id
            WHERE a.id = ? AND a.is_active = 1
        ");
        $stmt->execute([$from_id]);
        $from_acct = $stmt->fetch();

        $stmt->execute([$to_id]);
        $to_acct = $stmt->fetch();

        if (!$from_acct || !$to_acct) {
            $error = 'One or both accounts not found';
        } elseif ($from_acct['balance'] < $amount) {
            $error = 'Insufficient funds in source account. Balance: $' . number_format($from_acct['balance'], 2);
        } else {
            $new_from = $from_acct['balance'] - $amount;
            $new_to   = $to_acct['balance']   + $amount;
            $ref      = 'TRF' . date('YmdHis') . rand(10,99);
            $desc     = $description ?: "Transfer";

            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE member_accounts SET balance = ? WHERE id = ?")->execute([$new_from, $from_id]);
                $pdo->prepare("UPDATE member_accounts SET balance = ? WHERE id = ?")->execute([$new_to,   $to_id]);
                $pdo->prepare("INSERT INTO transactions (account_id, transaction_type, amount, balance_after, reference_number, description, teller_id) VALUES (?, 'transfer_out', ?, ?, ?, ?, ?)")
                    ->execute([$from_id, $amount, $new_from, $ref, $desc, $_SESSION['user_id']]);
                $pdo->prepare("INSERT INTO transactions (account_id, transaction_type, amount, balance_after, reference_number, description, teller_id) VALUES (?, 'transfer_in',  ?, ?, ?, ?, ?)")
                    ->execute([$to_id,   $amount, $new_to,   $ref, $desc, $_SESSION['user_id']]);
                $pdo->commit();

                $receipt_data = compact('from_acct','to_acct','amount','new_from','new_to','ref','desc');
                $receipt_data['date']   = date('M j, Y g:i A');
                $receipt_data['teller'] = $_SESSION['full_name'];
                $message = "Transfer of $" . number_format($amount, 2) . " completed. Ref: {$ref}";
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Transfer failed. Please try again.';
                error_log($e->getMessage());
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
    <title>SyncCU - Transfer Between Accounts</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <a href="../transactions.php" class="back-btn">← Back</a>
    <div class="user-info" style="left:auto;right:20px;">
        <?php echo htmlspecialchars($_SESSION['full_name']); ?> | <?php echo date('m/d/Y'); ?>
    </div>

    <div class="container-sm">
        <div class="container-header">
            <h1>Transfer Between Accounts</h1>
            <p>Move funds from one account to another</p>
        </div>

        <div class="content">
            <?php if ($receipt_data): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <div style="border:2px solid #667eea;border-radius:10px;padding:25px;margin-bottom:20px;font-family:monospace;">
                <h3 style="text-align:center;color:#667eea;margin-bottom:15px;">TRANSFER RECEIPT</h3>
                <p><strong>Date:</strong>         <?php echo htmlspecialchars($receipt_data['date']); ?></p>
                <p><strong>From Account:</strong> <?php echo htmlspecialchars($receipt_data['from_acct']['account_number']); ?> (<?php echo htmlspecialchars($receipt_data['from_acct']['first_name'] . ' ' . $receipt_data['from_acct']['last_name']); ?>)</p>
                <p><strong>To Account:</strong>   <?php echo htmlspecialchars($receipt_data['to_acct']['account_number']); ?> (<?php echo htmlspecialchars($receipt_data['to_acct']['first_name'] . ' ' . $receipt_data['to_acct']['last_name']); ?>)</p>
                <p><strong>Amount:</strong>        $<?php echo number_format($receipt_data['amount'], 2); ?></p>
                <p><strong>New From Balance:</strong>$<?php echo number_format($receipt_data['new_from'], 2); ?></p>
                <p><strong>New To Balance:</strong>  $<?php echo number_format($receipt_data['new_to'],   2); ?></p>
                <p><strong>Reference:</strong>     <?php echo htmlspecialchars($receipt_data['ref']); ?></p>
                <p><strong>Teller:</strong>        <?php echo htmlspecialchars($receipt_data['teller']); ?></p>
            </div>
            <div class="d-flex gap-10">
                <button onclick="window.print()" class="btn btn-secondary">Print Receipt</button>
                <a href="transfer.php" class="btn btn-primary">New Transfer</a>
                <a href="../transactions.php" class="btn btn-secondary">Back</a>
            </div>

            <?php else: ?>
            <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

            <form method="POST">
                <?php echo csrfField(); ?>

                <div class="info-box mb-20">
                    <h4>How to Transfer</h4>
                    <ul>
                        <li>Enter account numbers manually in the fields below</li>
                        <li>Verify balances before processing</li>
                        <li>Both accounts must be active</li>
                    </ul>
                </div>

                <div class="form-group">
                    <label for="from_account_id">From Account ID</label>
                    <input type="number" id="from_account_id" name="from_account_id" min="1" required
                           placeholder="Enter source account ID"
                           value="<?php echo htmlspecialchars($_POST['from_account_id'] ?? ''); ?>">
                    <small class="text-muted">Use Account Inquiry to find the account ID</small>
                </div>

                <div class="form-group">
                    <label for="to_account_id">To Account ID</label>
                    <input type="number" id="to_account_id" name="to_account_id" min="1" required
                           placeholder="Enter destination account ID"
                           value="<?php echo htmlspecialchars($_POST['to_account_id'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="amount">Transfer Amount ($)</label>
                    <input type="number" id="amount" name="amount" step="0.01" min="0.01" required
                           placeholder="0.00"
                           value="<?php echo htmlspecialchars($_POST['amount'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="description">Description (optional)</label>
                    <input type="text" id="description" name="description"
                           value="<?php echo htmlspecialchars($_POST['description'] ?? ''); ?>">
                </div>

                <div class="d-flex gap-10">
                    <button type="submit" class="btn btn-primary">Process Transfer</button>
                    <a href="../transactions.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
