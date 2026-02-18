<?php
require_once '../config.php';
requireLogin();
if (!hasPermission('transactions')) {
    header('Location: ../index.php');
    exit();
}

$account_type = in_array($_GET['type'] ?? '', ['savings','checking']) ? $_GET['type'] : 'savings';
$member_id    = (int)($_GET['member_id'] ?? 0);
$message      = '';
$error        = '';
$receipt_data = null;

// Lookup members for dropdown search
$members = [];
if ($member_id) {
    $stmt = $pdo->prepare("
        SELECT m.id, m.member_number, CONCAT(m.first_name,' ',m.last_name) AS name,
               a.id AS account_id, a.account_number, a.balance
        FROM members m
        JOIN member_accounts a ON a.member_id = m.id
        WHERE m.id = ? AND a.account_type = ? AND a.is_active = 1 AND m.is_active = 1
    ");
    $stmt->execute([$member_id, $account_type]);
    $members = $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $account_id  = (int)($_POST['account_id']  ?? 0);
    $amount      = (float)($_POST['amount']     ?? 0);
    $description = trim($_POST['description']   ?? '');

    if (!$account_id || $amount <= 0) {
        $error = 'Please select an account and enter a valid amount';
    } else {
        // Verify account exists and get current balance
        $stmt = $pdo->prepare("
            SELECT a.*, m.first_name, m.last_name, m.member_number
            FROM member_accounts a JOIN members m ON a.member_id = m.id
            WHERE a.id = ? AND a.account_type = ? AND a.is_active = 1
        ");
        $stmt->execute([$account_id, $account_type]);
        $account = $stmt->fetch();

        if (!$account) {
            $error = 'Account not found';
        } else {
            $new_balance = $account['balance'] + $amount;
            $ref = 'DEP' . date('YmdHis') . rand(10,99);

            try {
                $pdo->beginTransaction();

                $pdo->prepare("UPDATE member_accounts SET balance = ? WHERE id = ?")
                    ->execute([$new_balance, $account_id]);

                $pdo->prepare("
                    INSERT INTO transactions (account_id, transaction_type, amount, balance_after, reference_number, description, teller_id)
                    VALUES (?, 'deposit', ?, ?, ?, ?, ?)
                ")->execute([$account_id, $amount, $new_balance, $ref, $description ?: "Deposit to {$account_type}", $_SESSION['user_id']]);

                $pdo->commit();

                $receipt_data = [
                    'type'        => 'Deposit',
                    'account'     => $account['account_number'],
                    'member'      => $account['first_name'] . ' ' . $account['last_name'],
                    'member_num'  => $account['member_number'],
                    'amount'      => $amount,
                    'balance'     => $new_balance,
                    'reference'   => $ref,
                    'teller'      => $_SESSION['full_name'],
                    'date'        => date('M j, Y g:i A'),
                ];
                $message = "Deposit of $" . number_format($amount, 2) . " processed. Ref: {$ref}";
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Transaction failed. Please try again.';
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
    <title>SyncCU - Deposit to <?php echo ucfirst($account_type); ?></title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <a href="../transactions.php" class="back-btn">← Back</a>
    <div class="user-info" style="left:auto;right:20px;">
        <?php echo htmlspecialchars($_SESSION['full_name']); ?> | <?php echo date('m/d/Y'); ?>
    </div>

    <div class="container-sm">
        <div class="container-header">
            <h1>Deposit — <?php echo ucfirst($account_type); ?></h1>
            <p>Process a deposit to a member's <?php echo $account_type; ?> account</p>
        </div>

        <div class="content">
            <?php if ($receipt_data): ?>
            <!-- Receipt -->
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <div style="border:2px solid #667eea;border-radius:10px;padding:25px;margin-bottom:20px;font-family:monospace;">
                <h3 style="text-align:center;color:#667eea;margin-bottom:15px;">TRANSACTION RECEIPT</h3>
                <p><strong>Type:</strong>       <?php echo htmlspecialchars($receipt_data['type']); ?></p>
                <p><strong>Date:</strong>       <?php echo htmlspecialchars($receipt_data['date']); ?></p>
                <p><strong>Member:</strong>     <?php echo htmlspecialchars($receipt_data['member']); ?> (<?php echo htmlspecialchars($receipt_data['member_num']); ?>)</p>
                <p><strong>Account:</strong>    <?php echo htmlspecialchars($receipt_data['account']); ?></p>
                <p><strong>Amount:</strong>     $<?php echo number_format($receipt_data['amount'], 2); ?></p>
                <p><strong>New Balance:</strong>$<?php echo number_format($receipt_data['balance'], 2); ?></p>
                <p><strong>Reference:</strong>  <?php echo htmlspecialchars($receipt_data['reference']); ?></p>
                <p><strong>Teller:</strong>     <?php echo htmlspecialchars($receipt_data['teller']); ?></p>
            </div>
            <div class="d-flex gap-10">
                <button onclick="window.print()" class="btn btn-secondary">Print Receipt</button>
                <a href="deposit.php?type=<?php echo $account_type; ?>" class="btn btn-primary">New Deposit</a>
                <a href="../transactions.php" class="btn btn-secondary">Back to Transactions</a>
            </div>

            <?php else: ?>
            <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

            <form method="POST">
                <?php echo csrfField(); ?>

                <!-- Member / Account lookup -->
                <div class="form-group">
                    <label>Member Account Number</label>
                    <div class="d-flex gap-10">
                        <input type="text" id="member_search" placeholder="Enter account number or member name…"
                               style="flex:1;padding:12px;border:2px solid #ddd;border-radius:8px;font-size:1rem;">
                        <button type="button" onclick="lookupMember()" class="btn btn-secondary">Lookup</button>
                    </div>
                    <div id="lookup-result" style="margin-top:10px;"></div>
                </div>

                <div class="form-group">
                    <label for="account_id">Account</label>
                    <select id="account_id" name="account_id" required>
                        <option value="">— Select after member lookup —</option>
                        <?php foreach ($members as $m): ?>
                        <option value="<?php echo $m['account_id']; ?>">
                            <?php echo htmlspecialchars($m['account_number'] . ' — ' . $m['name'] . ' (Balance: $' . number_format($m['balance'],2) . ')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="amount">Deposit Amount ($)</label>
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
                    <button type="submit" class="btn btn-primary">Process Deposit</button>
                    <a href="../transactions.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function lookupMember() {
        const q = document.getElementById('member_search').value.trim();
        if (!q) return;
        const url = `../members/list.php?q=${encodeURIComponent(q)}&action=select`;
        fetch(`../inquiry.php?by=account&q=${encodeURIComponent(q)}`)
            .then(() => {
                // Simplified: direct to member list for account selection
                window.location.href = `deposit.php?type=<?php echo $account_type; ?>&member_search=${encodeURIComponent(q)}`;
            });
    }
    </script>
</body>
</html>
