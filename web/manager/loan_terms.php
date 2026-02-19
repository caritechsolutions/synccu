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
        $loan_type     = trim($_POST['loan_type'] ?? '');
        $interest_rate = (float)($_POST['interest_rate'] ?? 0);
        $term_months   = (int)($_POST['term_months'] ?? 0);

        if (!$name || !$loan_type || $term_months < 1) {
            $error = 'Please fill in all required fields.';
        } else {
            try {
                $pdo->prepare("
                    INSERT INTO loan_terms (name, loan_type, interest_rate, term_months)
                    VALUES (?, ?, ?, ?)
                ")->execute([$name, $loan_type, $interest_rate / 100, $term_months]);
                $message = "Loan term \"{$name}\" created successfully.";
            } catch (Exception $e) {
                $error = 'Failed to create loan term.';
                error_log($e->getMessage());
            }
        }

    } elseif ($action === 'update') {
        $id            = (int)($_POST['term_id'] ?? 0);
        $name          = trim($_POST['name'] ?? '');
        $loan_type     = trim($_POST['loan_type'] ?? '');
        $interest_rate = (float)($_POST['interest_rate'] ?? 0);
        $term_months   = (int)($_POST['term_months'] ?? 0);

        if (!$id || !$name || !$loan_type || $term_months < 1) {
            $error = 'Please fill in all required fields.';
        } else {
            try {
                $pdo->prepare("
                    UPDATE loan_terms SET name = ?, loan_type = ?, interest_rate = ?, term_months = ?
                    WHERE id = ?
                ")->execute([$name, $loan_type, $interest_rate / 100, $term_months, $id]);
                $message = "Loan term updated successfully.";
            } catch (Exception $e) {
                $error = 'Failed to update loan term.';
                error_log($e->getMessage());
            }
        }

    } elseif ($action === 'toggle') {
        $id = (int)($_POST['term_id'] ?? 0);
        if ($id) {
            $pdo->prepare("UPDATE loan_terms SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
            $message = 'Loan term status updated.';
        }
    }
}

// Fetch all loan terms
$terms = $pdo->query("SELECT * FROM loan_terms ORDER BY is_active DESC, name")->fetchAll();

// Editing?
$editing = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    foreach ($terms as $t) {
        if ($t['id'] === $edit_id) { $editing = $t; break; }
    }
}

$loan_types = ['Personal', 'Auto', 'Mortgage', 'Business', 'Education', 'Secured', 'Other'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncCU - Loan Terms</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <a href="../manager.php" class="back-btn">&larr; Back</a>
    <div class="user-info" style="left:auto;right:20px;">
        <?php echo htmlspecialchars($_SESSION['full_name']); ?> | <?php echo date('m/d/Y'); ?>
    </div>

    <div class="container" style="margin-top:80px;">
        <div class="container-header">
            <h1>Loan Terms</h1>
            <p>Define reusable loan terms (rate, duration, type) that can be linked to new loans</p>
        </div>

        <div class="content">
            <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

            <!-- Create / Edit form -->
            <p class="section-title" style="font-size:1.1rem;font-weight:600;color:#667eea;margin:0 0 15px;border-bottom:2px solid #f0f0f0;padding-bottom:6px;">
                <?php echo $editing ? 'Edit Loan Term' : 'Create New Loan Term'; ?>
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
                               placeholder="e.g. Standard Personal 12-month"
                               value="<?php echo htmlspecialchars($editing['name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="loan_type">Loan Type *</label>
                        <select id="loan_type" name="loan_type" required>
                            <?php foreach ($loan_types as $lt): ?>
                            <option value="<?php echo $lt; ?>"
                                <?php echo ($editing['loan_type'] ?? '') === $lt ? 'selected' : ''; ?>>
                                <?php echo $lt; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="interest_rate">Annual Interest Rate (%) *</label>
                        <input type="number" id="interest_rate" name="interest_rate"
                               step="0.001" min="0" max="100" required
                               placeholder="e.g. 12.0"
                               value="<?php echo $editing ? number_format((float)$editing['interest_rate'] * 100, 3) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="term_months">Term (months) *</label>
                        <input type="number" id="term_months" name="term_months"
                               min="1" max="600" required
                               placeholder="e.g. 12"
                               value="<?php echo htmlspecialchars($editing['term_months'] ?? ''); ?>">
                    </div>
                </div>

                <div class="d-flex gap-10">
                    <button type="submit" class="btn btn-primary">
                        <?php echo $editing ? 'Update Term' : 'Create Term'; ?>
                    </button>
                    <?php if ($editing): ?>
                    <a href="loan_terms.php" class="btn btn-secondary">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Existing terms -->
            <p class="section-title" style="font-size:1.1rem;font-weight:600;color:#667eea;margin:30px 0 10px;border-bottom:2px solid #f0f0f0;padding-bottom:6px;">
                Existing Loan Terms
            </p>

            <?php if ($terms): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Loan Type</th>
                        <th>Rate</th>
                        <th>Term</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($terms as $t): ?>
                    <tr style="<?php echo !$t['is_active'] ? 'opacity:0.5;' : ''; ?>">
                        <td><?php echo htmlspecialchars($t['name']); ?></td>
                        <td><?php echo htmlspecialchars($t['loan_type']); ?></td>
                        <td><?php echo number_format((float)$t['interest_rate'] * 100, 2); ?>%</td>
                        <td><?php echo $t['term_months']; ?> months</td>
                        <td class="<?php echo $t['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                            <?php echo $t['is_active'] ? 'Active' : 'Inactive'; ?>
                        </td>
                        <td>
                            <a href="loan_terms.php?edit=<?php echo $t['id']; ?>" class="btn btn-sm btn-primary">Edit</a>
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
            <div class="alert alert-warning">No loan terms defined yet. Create one above.</div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
