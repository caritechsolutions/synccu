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

    $account_id  = (int)($_POST['account_id']  ?? 0);
    $amount      = (float)($_POST['amount']     ?? 0);
    $description = trim($_POST['description']   ?? '');

    if (!$account_id || $amount <= 0) {
        $error = 'Please select a loan account and enter a valid payment amount';
    } else {
        $stmt = $pdo->prepare("
            SELECT a.*, m.first_name, m.last_name, m.member_number,
                   l.outstanding_balance, l.monthly_payment, l.id AS loan_id
            FROM member_accounts a
            JOIN members m ON a.member_id = m.id
            LEFT JOIN loans l ON l.account_id = a.id AND l.is_active = 1
            WHERE a.id = ? AND a.account_type = 'loan' AND a.is_active = 1
        ");
        $stmt->execute([$account_id]);
        $account = $stmt->fetch();

        if (!$account) {
            $error = 'Loan account not found';
        } elseif ($amount > $account['balance']) {
            // Can't overpay - cap at outstanding balance
            $error = 'Payment exceeds outstanding balance of $' . number_format($account['balance'], 2) . '. Please enter the exact or lesser amount.';
        } else {
            $new_balance = $account['balance'] - $amount;
            $ref = 'PMT' . date('YmdHis') . rand(10,99);

            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE member_accounts SET balance = ? WHERE id = ?")->execute([$new_balance, $account_id]);
                $pdo->prepare("INSERT INTO transactions (account_id, transaction_type, amount, balance_after, reference_number, description, teller_id) VALUES (?, 'loan_payment', ?, ?, ?, ?, ?)")
                    ->execute([$account_id, $amount, $new_balance, $ref, $description ?: 'Loan payment', $_SESSION['user_id']]);

                // Update loan record if exists
                if ($account['loan_id']) {
                    $pdo->prepare("UPDATE loans SET outstanding_balance = ?, last_payment_date = CURDATE(), is_active = ? WHERE id = ?")
                        ->execute([$new_balance, $new_balance > 0 ? 1 : 0, $account['loan_id']]);
                }

                $pdo->commit();

                $receipt_data = [
                    'member'     => $account['first_name'] . ' ' . $account['last_name'],
                    'member_num' => $account['member_number'],
                    'account'    => $account['account_number'],
                    'amount'     => $amount,
                    'remaining'  => $new_balance,
                    'reference'  => $ref,
                    'teller'     => $_SESSION['full_name'],
                    'date'       => date('M j, Y g:i A'),
                    'paid_off'   => $new_balance <= 0,
                ];
                $message = "Loan payment of $" . number_format($amount, 2) . " applied. Ref: {$ref}";
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Payment failed. Please try again.';
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
    <title>SyncCU - Loan Payment</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <a href="../transactions.php" class="back-btn">← Back</a>
    <div class="user-info" style="left:auto;right:20px;">
        <?php echo htmlspecialchars($_SESSION['full_name']); ?> | <?php echo date('m/d/Y'); ?>
    </div>

    <div class="container-sm">
        <div class="container-header">
            <h1>Loan Payment</h1>
            <p>Process a payment against a member loan account</p>
        </div>

        <div class="content">
            <?php if ($receipt_data): ?>
            <div class="alert alert-<?php echo $receipt_data['paid_off'] ? 'success' : 'success'; ?>">
                <?php echo htmlspecialchars($message); ?>
                <?php if ($receipt_data['paid_off']): ?> <strong>Loan fully paid off!</strong><?php endif; ?>
            </div>
            <div style="border:2px solid #667eea;border-radius:10px;padding:25px;margin-bottom:20px;font-family:monospace;">
                <h3 style="text-align:center;color:#667eea;margin-bottom:15px;">PAYMENT RECEIPT</h3>
                <p><strong>Date:</strong>              <?php echo htmlspecialchars($receipt_data['date']); ?></p>
                <p><strong>Member:</strong>            <?php echo htmlspecialchars($receipt_data['member']); ?> (#<?php echo htmlspecialchars($receipt_data['member_num']); ?>)</p>
                <p><strong>Loan Account:</strong>      <?php echo htmlspecialchars($receipt_data['account']); ?></p>
                <p><strong>Payment Amount:</strong>    $<?php echo number_format($receipt_data['amount'], 2); ?></p>
                <p><strong>Remaining Balance:</strong> $<?php echo number_format($receipt_data['remaining'], 2); ?></p>
                <p><strong>Reference:</strong>         <?php echo htmlspecialchars($receipt_data['reference']); ?></p>
                <p><strong>Teller:</strong>            <?php echo htmlspecialchars($receipt_data['teller']); ?></p>
                <?php if ($receipt_data['paid_off']): ?>
                <p style="color:#28a745;font-weight:bold;text-align:center;margin-top:15px;">*** LOAN PAID IN FULL ***</p>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-10">
                <button onclick="window.print()" class="btn btn-secondary">Print Receipt</button>
                <a href="loan_payment.php" class="btn btn-primary">New Payment</a>
                <a href="../transactions.php" class="btn btn-secondary">Back</a>
            </div>

            <?php else: ?>
            <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

            <form method="POST">
                <?php echo csrfField(); ?>

                <div class="form-group">
                    <label for="account_id">Loan Account ID</label>
                    <input type="number" id="account_id" name="account_id" min="1" required
                           placeholder="Enter loan account ID"
                           value="<?php echo htmlspecialchars($_POST['account_id'] ?? ''); ?>">
                    <small class="text-muted">Use Account Balance Inquiry to find the loan account ID</small>
                </div>

                <div class="form-group">
                    <label for="amount">Payment Amount ($)</label>
                    <input type="number" id="amount" name="amount" step="0.01" min="0.01" required
                           placeholder="0.00"
                           value="<?php echo htmlspecialchars($_POST['amount'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="description">Notes (optional)</label>
                    <input type="text" id="description" name="description"
                           value="<?php echo htmlspecialchars($_POST['description'] ?? ''); ?>">
                </div>

                <div class="d-flex gap-10">
                    <button type="submit" class="btn btn-primary">Process Payment</button>
                    <a href="../transactions.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
