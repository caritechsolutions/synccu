<?php
require_once '../config.php';
requireLogin();
if (!hasPermission('manager')) {
    header('Location: ' . rootUrl('index.php'));
    exit();
}

$message = '';
$error   = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name          = trim($_POST['name'] ?? '');
        $interest_rate = (float)($_POST['interest_rate'] ?? 0);
        $payout_month  = (int)($_POST['payout_month'] ?? 0);
        $payout_day    = (int)($_POST['payout_day'] ?? 0);

        if (!$name || $payout_month < 1 || $payout_month > 12 || $payout_day < 1 || $payout_day > 31) {
            $error = 'Please fill in all required fields with valid values.';
        } else {
            try {
                $pdo->prepare("
                    INSERT INTO deposit_terms (name, interest_rate, payout_month, payout_day)
                    VALUES (?, ?, ?, ?)
                ")->execute([$name, $interest_rate / 100, $payout_month, $payout_day]);
                $message = "Deposit term \"{$name}\" created successfully.";
            } catch (Exception $e) {
                $error = 'Failed to create deposit term.';
                error_log($e->getMessage());
            }
        }

    } elseif ($action === 'update') {
        $id            = (int)($_POST['term_id'] ?? 0);
        $name          = trim($_POST['name'] ?? '');
        $interest_rate = (float)($_POST['interest_rate'] ?? 0);
        $payout_month  = (int)($_POST['payout_month'] ?? 0);
        $payout_day    = (int)($_POST['payout_day'] ?? 0);

        if (!$id || !$name || $payout_month < 1 || $payout_month > 12 || $payout_day < 1 || $payout_day > 31) {
            $error = 'Please fill in all required fields with valid values.';
        } else {
            try {
                $pdo->prepare("
                    UPDATE deposit_terms SET name = ?, interest_rate = ?, payout_month = ?, payout_day = ?
                    WHERE id = ?
                ")->execute([$name, $interest_rate / 100, $payout_month, $payout_day, $id]);
                $message = "Deposit term updated successfully.";
            } catch (Exception $e) {
                $error = 'Failed to update deposit term.';
                error_log($e->getMessage());
            }
        }

    } elseif ($action === 'toggle') {
        $id = (int)($_POST['term_id'] ?? 0);
        if ($id) {
            $pdo->prepare("UPDATE deposit_terms SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
            $message = 'Deposit term status updated.';
        }
    }
}

// Fetch all deposit terms
$terms = $pdo->query("SELECT * FROM deposit_terms ORDER BY is_active DESC, name")->fetchAll();

// Editing?
$editing = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    foreach ($terms as $t) {
        if ($t['id'] === $edit_id) { $editing = $t; break; }
    }
}

$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncCU - Deposit Terms</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <a href="../manager.php" class="back-btn">&larr; Back</a>
    <div class="user-info" style="left:auto;right:20px;">
        <?php echo htmlspecialchars($_SESSION['full_name']); ?> | <?php echo date('m/d/Y'); ?>
    </div>

    <div class="container" style="margin-top:80px;">
        <div class="container-header">
            <h1>Deposit Terms</h1>
            <p>Define interest rates and annual payout dates for deposit accounts</p>
        </div>

        <div class="content">
            <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

            <!-- Create / Edit form -->
            <p class="section-title" style="font-size:1.1rem;font-weight:600;color:#667eea;margin:0 0 15px;border-bottom:2px solid #f0f0f0;padding-bottom:6px;">
                <?php echo $editing ? 'Edit Deposit Term' : 'Create New Deposit Term'; ?>
            </p>

            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="<?php echo $editing ? 'update' : 'create'; ?>">
                <?php if ($editing): ?>
                <input type="hidden" name="term_id" value="<?php echo $editing['id']; ?>">
                <?php endif; ?>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="name">Term Name *</label>
                        <input type="text" id="name" name="name" required
                               placeholder="e.g. Regular Savings Interest"
                               value="<?php echo htmlspecialchars($editing['name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="interest_rate">Annual Interest Rate (%) *</label>
                        <input type="number" id="interest_rate" name="interest_rate"
                               step="0.001" min="0" max="100" required
                               placeholder="e.g. 2.5"
                               value="<?php echo $editing ? number_format((float)$editing['interest_rate'] * 100, 3) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="payout_month">Payout Month *</label>
                        <select id="payout_month" name="payout_month" required>
                            <option value="">-- Select --</option>
                            <?php foreach ($months as $num => $name): ?>
                            <option value="<?php echo $num; ?>"
                                <?php echo (int)($editing['payout_month'] ?? 0) === $num ? 'selected' : ''; ?>>
                                <?php echo $name; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="payout_day">Payout Day *</label>
                        <input type="number" id="payout_day" name="payout_day"
                               min="1" max="31" required
                               placeholder="e.g. 31"
                               value="<?php echo htmlspecialchars($editing['payout_day'] ?? ''); ?>">
                    </div>
                </div>

                <div class="d-flex gap-10">
                    <button type="submit" class="btn btn-primary">
                        <?php echo $editing ? 'Update Term' : 'Create Term'; ?>
                    </button>
                    <?php if ($editing): ?>
                    <a href="deposit_terms.php" class="btn btn-secondary">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Info box -->
            <div class="info-box" style="margin-top:25px;">
                <h4>How Deposit Interest Works</h4>
                <ul>
                    <li>Interest is calculated on the <strong>account balance</strong> on the payout date</li>
                    <li>Interest = Balance &times; Annual Rate</li>
                    <li>The interest is credited directly to the <strong>same account</strong></li>
                    <li>Paid <strong>once per year</strong> on the configured date</li>
                </ul>
            </div>

            <!-- Existing terms -->
            <p class="section-title" style="font-size:1.1rem;font-weight:600;color:#667eea;margin:30px 0 10px;border-bottom:2px solid #f0f0f0;padding-bottom:6px;">
                Existing Deposit Terms
            </p>

            <?php if ($terms): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Rate</th>
                        <th>Payout Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($terms as $t): ?>
                    <tr style="<?php echo !$t['is_active'] ? 'opacity:0.5;' : ''; ?>">
                        <td><?php echo htmlspecialchars($t['name']); ?></td>
                        <td><?php echo number_format((float)$t['interest_rate'] * 100, 2); ?>%</td>
                        <td><?php echo $months[(int)$t['payout_month']] . ' ' . $t['payout_day']; ?></td>
                        <td class="<?php echo $t['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                            <?php echo $t['is_active'] ? 'Active' : 'Inactive'; ?>
                        </td>
                        <td>
                            <a href="deposit_terms.php?edit=<?php echo $t['id']; ?>" class="btn btn-sm btn-primary">Edit</a>
                            <form method="POST" style="display:inline;">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="term_id" value="<?php echo $t['id']; ?>">
                                <button type="submit" class="btn btn-sm <?php echo $t['is_active'] ? 'btn-danger' : 'btn-success'; ?>">
                                    <?php echo $t['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="alert alert-warning">No deposit terms defined yet. Create one above.</div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
