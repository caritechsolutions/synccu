<?php
require_once 'config.php';
requireLogin();
if (!hasPermission('reports')) {
    header('Location: index.php');
    exit();
}

$cards = [
    ['letter'=>'A','icon'=>'📋','title'=>'OTC Transaction Report',       'desc'=>'Daily transaction and maintenance reports',        'href'=>'trans/history.php'],
    ['letter'=>'B','icon'=>'⚠️','title'=>'Delinquent Loan Report',       'desc'=>'Report on overdue and delinquent loan accounts',   'href'=>'reports/delinquent.php'],
    ['letter'=>'C','icon'=>'💰','title'=>'Share Totals',                  'desc'=>'Summary of all share account balances',            'href'=>'reports/share_totals.php'],
    ['letter'=>'D','icon'=>'🏦','title'=>'Loan Totals',                   'desc'=>'Summary of all outstanding loan balances',         'href'=>'reports/loan_totals.php'],
    ['letter'=>'E','icon'=>'⚖️','title'=>'Share and Loan Trial Balance',  'desc'=>'Trial balance report for shares and loans',        'href'=>'reports/trial_balance.php'],
    ['letter'=>'F','icon'=>'📊','title'=>'Balance Statistics',            'desc'=>'Statistical analysis of account balances',         'href'=>'reports/statistics.php'],
    ['letter'=>'G','icon'=>'📄','title'=>'Member Statements',             'desc'=>'Generate member account statements',               'href'=>'reports/statements.php'],
    ['letter'=>'H','icon'=>'🏛️','title'=>'IRS Reports',                  'desc'=>'Tax reporting forms and IRS documents',            'href'=>'reports/irs.php'],
    ['letter'=>'I','icon'=>'📜','title'=>'Share Certificate Trial Balance','desc'=>'Trial balance for share certificates',            'href'=>'reports/cert_trial.php'],
    ['letter'=>'J','icon'=>'🏷️','title'=>'Labels and Listings',           'desc'=>'Mailing labels and member listings',              'href'=>'reports/labels.php'],
    ['letter'=>'K','icon'=>'📈','title'=>'New and Closed Accounts',       'desc'=>'Report on newly opened and closed accounts',       'href'=>'reports/new_closed.php'],
    ['letter'=>'L','icon'=>'📑','title'=>'Additional Reports',            'desc'=>'Access additional and specialty reports',          'href'=>'reports/additional.php'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncCU - Reports</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <a href="index.php" class="back-btn">← Back to Dashboard</a>
    <div class="user-info" style="left:auto;right:20px;">
        <?php echo htmlspecialchars($_SESSION['full_name']); ?> | <?php echo date('m/d/Y'); ?>
    </div>

    <div class="page-header" style="padding-top:70px;">
        <h1>Reports</h1>
        <p>Generate financial and operational reports</p>
    </div>

    <div class="dashboard">
        <?php foreach ($cards as $c): ?>
        <a href="<?php echo htmlspecialchars($c['href']); ?>" class="module-card">
            <div class="module-letter"><?php echo $c['letter']; ?></div>
            <div class="module-icon"><?php echo $c['icon']; ?></div>
            <div class="module-title"><?php echo htmlspecialchars($c['title']); ?></div>
            <div class="module-description"><?php echo htmlspecialchars($c['desc']); ?></div>
        </a>
        <?php endforeach; ?>
    </div>

    <script>
    const keyMap = {
        <?php foreach ($cards as $c): ?>'<?php echo $c['letter']; ?>': '<?php echo addslashes($c['href']); ?>',
        <?php endforeach ?>
    };
    document.addEventListener('keydown', function(e) {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
        const url = keyMap[e.key.toUpperCase()];
        if (url) window.location.href = url;
        if (e.key === 'Escape' || e.key === 'x' || e.key === 'X') window.location.href = 'index.php';
    });
    </script>
</body>
</html>
