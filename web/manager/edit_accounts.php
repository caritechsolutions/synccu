<?php
require_once '../config.php';
requireLogin();
if (!hasPermission('manager')) {
    header('Location: ' . rootUrl('index.php'));
    exit();
}

$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $codes = $_POST['codes'] ?? [];
    try {
        $stmt = $pdo->prepare("UPDATE share_account_types SET name = ?, special_class = ? WHERE code = ?");
        foreach ($codes as $code => $fields) {
            $name          = trim($fields['name'] ?? '') ?: null;
            $special_class = in_array($fields['special_class'] ?? '', ['I','E','C','R','U'])
                             ? $fields['special_class']
                             : null;
            $stmt->execute([$name, $special_class, $code]);
        }
        $message = 'Share account classifications updated successfully.';
    } catch (Exception $e) {
        $error = 'Failed to save changes. Please try again.';
        error_log($e->getMessage());
    }
}

$stmt = $pdo->query("SELECT code, name, special_class FROM share_account_types ORDER BY code");
$types = $stmt->fetchAll();

$special_classes = [
    ''  => '— None —',
    'I' => 'I — Traditional IRA',
    'E' => 'E — Education IRA',
    'C' => 'C — Roth Contribution IRA',
    'R' => 'R — Roth Rollover IRA',
    'U' => 'U — Unavailable for Withdrawal',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncCU - Edit Share Account Classifications</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <a href="../manager.php" class="back-btn">← Back</a>
    <div class="user-info" style="left:auto;right:20px;">
        <?php echo htmlspecialchars($_SESSION['full_name']); ?> | <?php echo date('m/d/Y'); ?>
    </div>

    <div class="container" style="margin-top:80px;">
        <div class="container-header">
            <h1>Share Subaccount Classifications</h1>
            <p>Define names and special classes for share subaccount type codes (00–19)</p>
        </div>

        <div class="content">
            <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST">
                <?php echo csrfField(); ?>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width:70px;">Code</th>
                            <th>Name</th>
                            <th style="width:260px;">Special Class</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($types as $t): ?>
                        <tr>
                            <td style="font-family:monospace;font-weight:bold;text-align:center;">
                                <?php echo htmlspecialchars($t['code']); ?>
                            </td>
                            <td>
                                <input type="text"
                                       name="codes[<?php echo htmlspecialchars($t['code']); ?>][name]"
                                       value="<?php echo htmlspecialchars($t['name'] ?? ''); ?>"
                                       placeholder="Undefined"
                                       style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;font-size:0.95rem;">
                            </td>
                            <td>
                                <select name="codes[<?php echo htmlspecialchars($t['code']); ?>][special_class]"
                                        style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;font-size:0.95rem;">
                                    <?php foreach ($special_classes as $val => $label): ?>
                                    <option value="<?php echo htmlspecialchars($val); ?>"
                                        <?php echo ($t['special_class'] ?? '') === $val ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="d-flex gap-10" style="margin-top:20px;">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <a href="../manager.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>

            <hr style="margin:30px 0;border:none;border-top:2px solid #f0f0f0;">

            <div style="background:#f8f9fa;border-radius:8px;padding:20px;">
                <h4 style="margin-bottom:12px;color:#667eea;">Special Class Legend</h4>
                <table style="border-collapse:collapse;width:100%;max-width:500px;">
                    <?php foreach ($special_classes as $val => $label): ?>
                    <?php if ($val === '') continue; ?>
                    <tr>
                        <td style="padding:4px 12px 4px 0;font-family:monospace;font-weight:bold;color:#667eea;white-space:nowrap;">
                            <?php echo htmlspecialchars($val); ?>
                        </td>
                        <td style="padding:4px 0;color:#555;">
                            <?php echo htmlspecialchars(substr($label, 4)); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td style="padding:4px 12px 4px 0;font-family:monospace;font-weight:bold;color:#999;">—</td>
                        <td style="padding:4px 0;color:#555;">No special class</td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
