<?php
require_once '../config.php';
requireLogin();
if (!hasPermission('accounts')) {
    header('Location: ../index.php');
    exit();
}

$member_id = (int)($_GET['member_id'] ?? 0);
$filter    = $_GET['filter'] ?? 'loan'; // 'loan' or 'share'
$message   = '';
$error     = '';

// Load member
$member = null;
if ($member_id) {
    $stmt = $pdo->prepare("SELECT * FROM members WHERE id = ? AND is_active = 1");
    $stmt->execute([$member_id]);
    $member = $stmt->fetch();
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'create_account') {
        $mid          = (int)($_POST['member_id']    ?? 0);
        $account_type = $_POST['account_type']       ?? '';
        $interest_rate = (float)($_POST['interest_rate'] ?? 0);
        $notes        = trim($_POST['notes']         ?? '');

        $valid_types = ['loan', 'share', 'savings', 'checking', 'certificate'];
        if (!$mid || !in_array($account_type, $valid_types)) {
            $error = 'Invalid member or account type';
        } else {
            // Build account number prefix
            $prefix_map = [
                'loan'        => 'LN',
                'share'       => 'SH',
                'savings'     => 'SAV',
                'checking'    => 'CHK',
                'certificate' => 'CD',
            ];
            $prefix = $prefix_map[$account_type];

            // Get member number for account number
            $stmt = $pdo->prepare("SELECT member_number FROM members WHERE id = ?");
            $stmt->execute([$mid]);
            $mn = $stmt->fetchColumn();

            // Count existing accounts of this type for suffix
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM member_accounts WHERE member_id = ? AND account_type = ?");
            $stmt->execute([$mid, $account_type]);
            $count = (int)$stmt->fetchColumn() + 1;

            $account_number = "{$prefix}-{$mn}-" . str_pad($count, 2, '0', STR_PAD_LEFT);

            // Ensure uniqueness
            $check = $pdo->prepare("SELECT COUNT(*) FROM member_accounts WHERE account_number = ?");
            $check->execute([$account_number]);
            if ($check->fetchColumn() > 0) {
                $account_number .= '-' . rand(10, 99);
            }

            try {
                $pdo->prepare("
                    INSERT INTO member_accounts (member_id, account_number, account_type, interest_rate, notes)
                    VALUES (?, ?, ?, ?, ?)
                ")->execute([$mid, $account_number, $account_type, $interest_rate / 100, $notes]);

                // If loan, also create a loans record
                if ($account_type === 'loan') {
                    $principal    = (float)($_POST['principal']      ?? 0);
                    $term_months  = (int)($_POST['term_months']      ?? 0);
                    $loan_type    = trim($_POST['loan_type']         ?? 'Personal');
                    $date_issued  = $_POST['date_issued']            ?? date('Y-m-d');
                    $monthly_pmt  = $term_months > 0 ? round($principal / $term_months, 2) : 0;
                    $date_due     = date('Y-m-d', strtotime("+{$term_months} months", strtotime($date_issued)));
                    $acct_id      = (int)$pdo->lastInsertId();

                    // Set loan account balance to principal
                    $pdo->prepare("UPDATE member_accounts SET balance = ? WHERE id = ?")
                        ->execute([$principal, $acct_id]);

                    $pdo->prepare("
                        INSERT INTO loans (member_id, account_id, loan_type, principal_amount, interest_rate,
                                           term_months, monthly_payment, outstanding_balance, date_issued, date_due)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $mid, $acct_id, $loan_type, $principal,
                        $interest_rate / 100, $term_months, $monthly_pmt, $principal,
                        $date_issued, $date_due
                    ]);
                }

                $message = "Account {$account_number} ({$account_type}) created successfully.";
                $member_id = $mid;
                // Reload member
                $stmt = $pdo->prepare("SELECT * FROM members WHERE id = ?");
                $stmt->execute([$mid]);
                $member = $stmt->fetch();
            } catch (Exception $e) {
                $error = 'Error creating account. Please try again.';
                error_log($e->getMessage());
            }
        }

    } elseif ($action === 'close_account') {
        $acct_id = (int)($_POST['account_id'] ?? 0);

        // Verify account belongs to a member and has zero balance
        $stmt = $pdo->prepare("SELECT * FROM member_accounts WHERE id = ? AND is_active = 1");
        $stmt->execute([$acct_id]);
        $acct = $stmt->fetch();

        if (!$acct) {
            $error = 'Account not found';
        } elseif ($acct['balance'] != 0) {
            $error = 'Cannot close account with non-zero balance ($' . number_format($acct['balance'], 2) . '). Zero the balance first.';
        } else {
            $pdo->prepare("UPDATE member_accounts SET is_active = 0, date_closed = CURDATE() WHERE id = ?")
                ->execute([$acct_id]);
            // Deactivate any linked loan record
            $pdo->prepare("UPDATE loans SET is_active = 0 WHERE account_id = ?")
                ->execute([$acct_id]);

            $message = 'Account ' . htmlspecialchars($acct['account_number']) . ' has been closed.';
            $member_id = (int)$acct['member_id'];
            $stmt = $pdo->prepare("SELECT * FROM members WHERE id = ?");
            $stmt->execute([$member_id]);
            $member = $stmt->fetch();
        }
    }
}

// Load member's accounts if we have a member
$accounts = [];
if ($member) {
    $stmt = $pdo->prepare("
        SELECT a.*, l.loan_type, l.principal_amount, l.term_months, l.monthly_payment,
               l.outstanding_balance, l.date_issued, l.date_due
        FROM member_accounts a
        LEFT JOIN loans l ON l.account_id = a.id AND l.is_active = 1
        WHERE a.member_id = ?
        ORDER BY a.is_active DESC, a.account_type, a.date_opened
    ");
    $stmt->execute([$member['id']]);
    $accounts = $stmt->fetchAll();
}

// Member search results
$search_results = [];
$search_q = trim($_GET['q'] ?? '');
if ($search_q && !$member) {
    $stmt = $pdo->prepare("
        SELECT id, member_number, first_name, last_name, member_type
        FROM members
        WHERE (CONCAT(first_name,' ',last_name) LIKE ? OR member_number = ?)
          AND is_active = 1
        ORDER BY last_name, first_name
        LIMIT 20
    ");
    $stmt->execute(["%{$search_q}%", $search_q]);
    $search_results = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncCU - Manage Member Accounts</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .loan-details { font-size:0.85rem; color:#666; margin-top:4px; }
        .section-title { font-size:1.1rem; font-weight:600; color:#667eea; margin:20px 0 10px; border-bottom:2px solid #f0f0f0; padding-bottom:6px; }
    </style>
</head>
<body>
    <a href="../accounts.php" class="back-btn">← Back</a>
    <div class="user-info" style="left:auto;right:20px;">
        <?php echo htmlspecialchars($_SESSION['full_name']); ?> | <?php echo date('m/d/Y'); ?>
    </div>

    <div class="container" style="margin-top:80px;">
        <div class="container-header">
            <h1>Manage Member Accounts</h1>
            <p>Create or close loan and share subaccounts</p>
        </div>

        <div class="content">
            <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
            <?php if ($error):   ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

            <?php if (!$member): ?>
            <!-- Member search -->
            <p class="section-title">Find a Member</p>
            <form method="GET" class="d-flex gap-10 mb-20">
                <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                <input type="text" name="q" value="<?php echo htmlspecialchars($search_q); ?>"
                       placeholder="Search by name or member number…"
                       style="flex:1;padding:12px;border:2px solid #ddd;border-radius:8px;font-size:1rem;" autofocus>
                <button type="submit" class="btn btn-primary">Search</button>
            </form>

            <?php if ($search_results): ?>
            <table class="data-table">
                <thead>
                    <tr><th>Account #</th><th>Name</th><th>Type</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($search_results as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['member_number']); ?></td>
                        <td><?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?></td>
                        <td><?php echo ucfirst($r['member_type']); ?></td>
                        <td>
                            <a href="accounts.php?member_id=<?php echo $r['id']; ?>&filter=<?php echo htmlspecialchars($filter); ?>"
                               class="btn btn-sm btn-primary">Select</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php elseif ($search_q): ?>
            <div class="alert alert-warning">No members found for "<?php echo htmlspecialchars($search_q); ?>".</div>
            <?php endif; ?>

            <?php else: ?>
            <!-- Member selected -->
            <div class="d-flex justify-between align-center mb-20">
                <div>
                    <strong style="font-size:1.1rem;"><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></strong>
                    &nbsp;|&nbsp; Account # <?php echo htmlspecialchars($member['member_number']); ?>
                    &nbsp;|&nbsp; <?php echo ucfirst($member['member_type']); ?>
                </div>
                <a href="accounts.php?filter=<?php echo htmlspecialchars($filter); ?>" class="btn btn-sm btn-secondary">Search Different Member</a>
            </div>

            <!-- Existing accounts -->
            <p class="section-title">Existing Accounts</p>
            <?php if ($accounts): ?>
            <table class="data-table">
                <thead>
                    <tr><th>Account #</th><th>Type</th><th>Balance</th><th>Rate</th><th>Opened</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $a): ?>
                    <tr>
                        <td>
                            <?php echo htmlspecialchars($a['account_number']); ?>
                            <?php if ($a['loan_type']): ?>
                            <div class="loan-details">
                                <?php echo htmlspecialchars($a['loan_type']); ?> &bull;
                                <?php echo $a['term_months']; ?> mo &bull;
                                $<?php echo number_format((float)$a['monthly_payment'], 2); ?>/mo
                            </div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo ucfirst($a['account_type']); ?></td>
                        <td>$<?php echo number_format((float)$a['balance'], 2); ?></td>
                        <td><?php echo number_format((float)$a['interest_rate'] * 100, 2); ?>%</td>
                        <td><?php echo date('M j, Y', strtotime($a['date_opened'])); ?></td>
                        <td class="<?php echo $a['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                            <?php echo $a['is_active'] ? 'Active' : 'Closed'; ?>
                        </td>
                        <td>
                            <?php if ($a['is_active']): ?>
                            <a href="../trans/history.php?account_id=<?php echo $a['id']; ?>" class="btn btn-sm btn-primary">History</a>
                            <?php if ($a['balance'] == 0): ?>
                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirm('Close account <?php echo addslashes($a['account_number']); ?>?')">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="close_account">
                                <input type="hidden" name="account_id" value="<?php echo $a['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Close</button>
                            </form>
                            <?php else: ?>
                            <span class="text-muted" style="font-size:0.8rem;">Zero balance to close</span>
                            <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="alert alert-warning">No accounts found for this member.</div>
            <?php endif; ?>

            <!-- Create new account -->
            <p class="section-title" style="margin-top:30px;">Create New Account</p>

            <div class="tabs">
                <div class="tab <?php echo $filter !== 'loan' ? 'active' : ''; ?>" onclick="switchTab('share')">Share / Savings</div>
                <div class="tab <?php echo $filter === 'loan' ? 'active' : ''; ?>" onclick="switchTab('loan')">Loan Account</div>
            </div>

            <!-- Share/Savings form -->
            <div id="tab-share" class="tab-content <?php echo $filter !== 'loan' ? 'active' : ''; ?>">
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="create_account">
                    <input type="hidden" name="member_id" value="<?php echo $member['id']; ?>">

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="account_type_share">Account Type *</label>
                            <select id="account_type_share" name="account_type" required>
                                <option value="share">Share</option>
                                <option value="savings">Savings</option>
                                <option value="checking">Checking</option>
                                <option value="certificate">Certificate of Deposit</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="interest_rate_share">Interest Rate (%)</label>
                            <input type="number" id="interest_rate_share" name="interest_rate"
                                   step="0.001" min="0" max="100" placeholder="e.g. 2.5" value="0">
                        </div>
                        <div class="form-group">
                            <label for="notes_share">Notes (optional)</label>
                            <input type="text" id="notes_share" name="notes" placeholder="Optional notes">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">Create Account</button>
                </form>
            </div>

            <!-- Loan form -->
            <div id="tab-loan" class="tab-content <?php echo $filter === 'loan' ? 'active' : ''; ?>">
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="create_account">
                    <input type="hidden" name="member_id" value="<?php echo $member['id']; ?>">
                    <input type="hidden" name="account_type" value="loan">

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="loan_type">Loan Type *</label>
                            <select id="loan_type" name="loan_type" required>
                                <option value="Personal">Personal</option>
                                <option value="Auto">Auto</option>
                                <option value="Mortgage">Mortgage</option>
                                <option value="Business">Business</option>
                                <option value="Education">Education</option>
                                <option value="Secured">Secured</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="principal">Principal Amount ($) *</label>
                            <input type="number" id="principal" name="principal"
                                   step="0.01" min="1" required placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label for="interest_rate_loan">Annual Interest Rate (%)</label>
                            <input type="number" id="interest_rate_loan" name="interest_rate"
                                   step="0.001" min="0" max="100" placeholder="e.g. 6.5" value="0">
                        </div>
                        <div class="form-group">
                            <label for="term_months">Term (months) *</label>
                            <input type="number" id="term_months" name="term_months"
                                   min="1" max="600" required placeholder="e.g. 60">
                        </div>
                        <div class="form-group">
                            <label for="date_issued">Date Issued *</label>
                            <input type="date" id="date_issued" name="date_issued"
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Monthly Payment (calculated)</label>
                            <input type="text" id="monthly_pmt_display" readonly
                                   style="background:#f8f9fa;color:#666;" placeholder="Enter principal &amp; term above">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">Create Loan Account</button>
                </form>
            </div>

            <?php endif; ?>
        </div>
    </div>

    <script>
    function switchTab(tab) {
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
        document.getElementById('tab-' + tab).classList.add('active');
        event.currentTarget.classList.add('active');
    }

    // Auto-calculate monthly payment
    function calcPayment() {
        const p = parseFloat(document.getElementById('principal')?.value || 0);
        const m = parseInt(document.getElementById('term_months')?.value || 0);
        const display = document.getElementById('monthly_pmt_display');
        if (display && p > 0 && m > 0) {
            display.value = '$' + (p / m).toFixed(2);
        }
    }
    document.getElementById('principal')?.addEventListener('input', calcPayment);
    document.getElementById('term_months')?.addEventListener('input', calcPayment);
    </script>
</body>
</html>
