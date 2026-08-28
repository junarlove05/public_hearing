<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_ADMIN,ROLE_STAFF]);
require_once __DIR__ . '/report_data.php';

$pageTitle='Reports & Analytics';$activeMenu='reports';$pdo=db();
$meta=reportTypeMeta();
$counts=[
 'hearings'=>(int)$pdo->query('SELECT COUNT(*) FROM hearings')->fetchColumn(),
 'registrations'=>(int)$pdo->query('SELECT COUNT(*) FROM registrations')->fetchColumn(),
 'attendance'=>(int)$pdo->query('SELECT COUNT(*) FROM attendance')->fetchColumn(),
 'stakeholders'=>(int)$pdo->query('SELECT COUNT(*) FROM stakeholders')->fetchColumn(),
 'feedback'=>(int)$pdo->query('SELECT COUNT(*) FROM feedback')->fetchColumn(),
 'issues'=>(int)$pdo->query('SELECT COUNT(*) FROM hearing_issues')->fetchColumn(),
 'actions'=>(int)$pdo->query('SELECT COUNT(*) FROM hearing_actions')->fetchColumn(),
 'responses'=>(int)$pdo->query('SELECT COUNT(*) FROM hearing_responses')->fetchColumn(),
 'surveys'=>(int)$pdo->query('SELECT COUNT(*) FROM surveys')->fetchColumn(),
 'activity_logs'=>(int)$pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn(),
];

include __DIR__.'/../layouts/header.php';
?>
<link rel="stylesheet" href="<?= e(APP_URL.'/assets/css/lph-workflow-final.css') ?>">
<div class="app-wrapper"><?php include __DIR__.'/../layouts/sidebar.php'; ?><div class="main-content">
<?php include __DIR__ . '/../layouts/top_controls.php'; ?>
<div class="lphwf-head"><div><div class="lphwf-eyebrow">Step 8 · Consolidated Monitoring</div><h1>Reports & Analytics</h1><p>Generate date-filtered operational reports across every completed Subsystem #7 module. Existing Print, PDF, Excel and CSV exports continue to use the same centralized data engine.</p></div><a href="<?= e(APP_URL) ?>/dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-speedometer2"></i> Dashboard</a></div>

<div class="row g-3">
<?php foreach($meta as $key=>$m): ?><div class="col-md-6 col-xl-4"><div class="card lphwf-card h-100"><div class="card-body"><div class="d-flex justify-content-between"><i class="bi <?= e($m['icon']) ?> fs-3 text-primary"></i><span class="badge text-bg-light"><?= (int)($counts[$key]??0) ?></span></div><h5 class="mt-3"><?= e($m['label']) ?> Report</h5><form action="view.php" method="get" class="row g-2 mt-1"><input type="hidden" name="type" value="<?= e($key) ?>"><div class="col-6"><input type="date" class="form-control form-control-sm" name="date_from"></div><div class="col-6"><input type="date" class="form-control form-control-sm" name="date_to"></div><div class="col-12"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-bar-chart"></i> Open Report</button></div></form></div></div></div><?php endforeach; ?>
</div>
</div></div>
<?php include __DIR__.'/../layouts/footer.php'; ?>
