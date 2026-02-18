<?php
require_once '../config.php';
requireLogin();
if (!hasPermission('transactions')) {
    header('Location: ../index.php');
    exit();
}

$account_type = in_array($_GET['type'] ?? '', ['savings','checking']) ? $_GET['type'] : 'savings';
$message      = '';
$error        = '';
$receipt_data = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $account_id  = (int)($_POST['account_id']  ?? 0);
    $amount      = (float)($_POST['amount']     ?? 0);
    $description = trim($_POST['description']   ?? '');

    if (!$account_id || $amount <= 0) {
        $error = 'Please select an account and enter a valid amount';
    } else {
        $stmt = $pdo->prepare("
            SELECT a.*, m.first_name, m.last_name, m.member_number
            FROM member_accounts a JOIN members m ON a.member_id = m.id
            WHERE a.id = ? AND a.account_type = ? AND a.is_active = 1
        ");
        $stmt->execute([$account_id, $account_type]);
        $account = $stmt->fetch();

        if (!$account) {
            $error = 'Account not found';
        } elseif ($account['balance'] < $amount) {
            $error = 'Insufficient funds. Available balance: $' . number_format($account['balance'], 2);
        } else {
            $new_balance = $account['balance'] - $amount;
            $ref = 'WDR' . date('YmdHis') . rand(10,99);

            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE member_accounts SET balance = ? WHERE id = ?")->execute([$new_balance, $account_id]);
                $pdo->prepare("
                    INSERT INTO transactions (account_id, transaction_type, amount, balance_after, reference_number, description, teller_id)
                    VALUES (?, 'withdrawal', ?, ?, ?, ?, ?)
                ")->execute([$account_id, $amount, $new_balance, $ref, $description ?: "Withdrawal from {$account_type}", $_SESSION['user_id']]);
                $pdo->commit();

                $receipt_data = [
                    'type'       => 'Withdrawal',
                    'account'    => $account['account_number'],
                    'member'     => $account['first_name'] . ' ' . $account['last_name'],
                    'member_num' => $account['member_number'],
                    'amount'     => $amount,
                    'balance'    => $new_balance,
                    'reference'  => $ref,
                    'teller'     => $_SESSION['full_name'],
                    'date'       => date('M j, Y g:i A'),
                ];
                $message = "Withdrawal of $" . number_format($amount, 2) . " processed. Ref: {$ref}";
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
    <title>SyncCU - Withdrawal from <?php echo ucfirst($account_type); ?></title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <a href="../transactions.php" class="back-btn">← Back</a>
    <div class="user-info" style="left:auto;right:20px;">
        <?php echo htmlspecialchars($_SESSION['full_name']); ?> | <?php echo date('m/d/Y'); ?>
    </div>

    <div class="container-sm">
        <div class="container-header">
            <h1>Withdrawal — <?php echo ucfirst($account_type); ?></h1>
            <p>Process a withdrawal from a member's <?php echo $account_type; ?> account</p>
        </div>

        <div class="content">
            <?php if ($receipt_data): ?>
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
                <a href="withdrawal.php?type=<?php echo $account_type; ?>" class="btn btn-primary">New Withdrawal</a>
                <a href="../transactions.php" class="btn btn-secondary">Back</a>
            </div>

            <?php else: ?>
            <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

            <form method="POST">
                <?php echo csrfField(); ?>

                <div class="form-group">
                    <label>Find Account</label>
                    <div class="d-flex gap-10">
                        <input type="text" id="account_search" placeholder="Enter account number…"
                               style="flex:1;padding:12px;border:2px solid #ddd;border-radius:8px;font-size:1rem;">
                        <button type="button" onclick="lookupAccount()" class="btn btn-secondary">Lookup</button>
                    </div>
                    <div id="lookup-result" style="margin-top:10px;color:#667eea;"></div>
                </div>

                <div class="form-group">
                    <label for="account_id">Account</label>
                    <select id="account_id" name="account_id" required>
                        <option value="">— Use lookup above —</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="amount">Withdrawal Amount ($)</label>
                    <input type="number" id="amount" name="amount" step="0.01" min="0.01" required placeholder="0.00"
                           value="<?php echo htmlspecialchars($_POST['amount'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="description">Description (optional)</label>
                    <input type="text" id="description" name="description"
                           value="<?php echo htmlspecialchars($_POST['description'] ?? ''); ?>">
                </div>

                <div class="d-flex gap-10">
                    <button type="submit" class="btn btn-danger">Process Withdrawal</button>
                    <a href="../transactions.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function lookupAccount() {
        const q = document.getElementById('account_search').value.trim();
        if (!q) return;
        fetch(`../api/lookup_account.php?q=${encodeURIComponent(q)}&type=<?php echo $account_type; ?>`)
            .then(r => r.json())
            .then(data => {
                const sel = document.getElementById('account_id');
                sel.innerHTML = '<option value="">— Select account —</option>';
                data.forEach(a => {
                    const opt = document.createElement('option');
                    opt.value = a.id;
                    opt.text  = `${a.account_number} — ${a.name} (Balance: $${parseFloat(a.balance).toFixed(2)})`;
                    sel.appendChild(opt);
                });
                document.getElementById('lookup-result').textContent = data.length + ' account(s) found';
            })
            .catch(() => {
                document.getElementById('lookup-result').textContent = 'Lookup unavailable — please enter account details manually';
            });
    }
    </script>
</body>
</html>
