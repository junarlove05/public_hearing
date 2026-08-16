<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pdo=db();$pageTitle='Dashboard';$activeMenu='dashboard';

$stats=[
 'hearings'=>(int)$pdo->query('SELECT COUNT(*) FROM hearings')->fetchColumn(),
 'upcoming'=>(int)$pdo->query("SELECT COUNT(*) FROM hearings WHERE status='Upcoming'")->fetchColumn(),
 'stakeholders'=>(int)$pdo->query('SELECT COUNT(*) FROM stakeholders')->fetchColumn(),
 'registrations'=>(int)$pdo->query("SELECT COUNT(*) FROM registrations WHERE registration_status='Approved'")->fetchColumn(),
 'feedback'=>(int)$pdo->query('SELECT COUNT(*) FROM feedback')->fetchColumn(),
 'new_feedback'=>(int)$pdo->query("SELECT COUNT(*) FROM feedback WHERE status='New'")->fetchColumn(),
 'issues'=>(int)$pdo->query('SELECT COUNT(*) FROM hearing_issues')->fetchColumn(),
 'open_issues'=>(int)$pdo->query("SELECT COUNT(*) FROM hearing_issues WHERE status IN ('Open','In Progress')")->fetchColumn(),
 'actions'=>(int)$pdo->query('SELECT COUNT(*) FROM hearing_actions')->fetchColumn(),
 'overdue_actions'=>(int)$pdo->query("SELECT COUNT(*) FROM hearing_actions WHERE deadline<CURDATE() AND status NOT IN ('Completed','Cancelled')")->fetchColumn(),
 'responses'=>(int)$pdo->query('SELECT COUNT(*) FROM hearing_responses')->fetchColumn(),
 'published_responses'=>(int)$pdo->query("SELECT COUNT(*) FROM hearing_responses WHERE status='Published'")->fetchColumn(),
];

$hearingTrend=$pdo->query(
 "SELECT DATE_FORMAT(hearing_date,'%Y-%m') ym,COUNT(*) total
  FROM hearings WHERE hearing_date>=DATE_SUB(CURDATE(),INTERVAL 6 MONTH)
  GROUP BY ym ORDER BY ym"
)->fetchAll();

$feedbackStatus=$pdo->query('SELECT status,COUNT(*) total FROM feedback GROUP BY status ORDER BY total DESC')->fetchAll();
$issueStatus=$pdo->query('SELECT status,COUNT(*) total FROM hearing_issues GROUP BY status ORDER BY total DESC')->fetchAll();
$actionStatus=$pdo->query('SELECT status,COUNT(*) total FROM hearing_actions GROUP BY status ORDER BY total DESC')->fetchAll();

$recent=$pdo->query(
 "SELECT al.action,al.details,al.created_at,u.full_name
  FROM activity_logs al LEFT JOIN users u ON u.id=al.user_id
  ORDER BY al.created_at DESC LIMIT 10"
)->fetchAll();

$upcoming=$pdo->query(
 "SELECT id,reference_number,title,hearing_date,hearing_time,venue
  FROM hearings
  WHERE status='Upcoming' AND hearing_date>=CURDATE()
  ORDER BY hearing_date,hearing_time LIMIT 6"
)->fetchAll();

include __DIR__.'/layouts/header.php';
?>
<link rel="stylesheet" href="<?= e(APP_URL.'/assets/css/lph-workflow-final.css') ?>">
<div class="app-wrapper"><?php include __DIR__.'/layouts/sidebar.php'; ?><div class="main-content">
<div class="lphwf-head"><div><div class="lphwf-eyebrow">Subsystem #7 · Complete Operational Dashboard</div><h1>Public Hearing & Consultation Management</h1><p>Live operational monitoring across hearings, stakeholders, registration, attendance, feedback, issues, actions and official responses.</p></div><a href="<?= e(APP_URL) ?>/reports/index.php" class="btn btn-primary"><i class="bi bi-bar-chart-line"></i> Reports & Analytics</a></div>

<div class="lphwf-funnel mb-3">
<a href="<?= e(APP_URL) ?>/modules/hearings/index.php"><strong><?= $stats['hearings'] ?></strong><span>Hearings</span></a>
<a href="<?= e(APP_URL) ?>/modules/feedback/index.php"><strong><?= $stats['feedback'] ?></strong><span>Feedback</span></a>
<a href="<?= e(APP_URL) ?>/modules/issues/index.php"><strong><?= $stats['issues'] ?></strong><span>Issues</span></a>
<a href="<?= e(APP_URL) ?>/modules/actions/index.php"><strong><?= $stats['actions'] ?></strong><span>Actions</span></a>
<a href="<?= e(APP_URL) ?>/modules/actions/responses.php"><strong><?= $stats['published_responses'] ?></strong><span>Published Responses</span></a>
</div>

<div class="row g-3 mb-3">
<?php foreach([
 ['Upcoming Hearings',$stats['upcoming'],'bi-calendar-event'],
 ['Approved Registrations',$stats['registrations'],'bi-person-check'],
 ['New Feedback',$stats['new_feedback'],'bi-chat-dots'],
 ['Open / In Progress Issues',$stats['open_issues'],'bi-exclamation-triangle'],
 ['Overdue Actions',$stats['overdue_actions'],'bi-alarm'],
 ['Official Responses',$stats['responses'],'bi-reply-all'],
] as [$l,$v,$i]): ?><div class="col-6 col-xl-2"><div class="lphwf-stat"><i class="bi <?= e($i) ?>"></i><div><strong><?= $v ?></strong><small><?= e($l) ?></small></div></div></div><?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
<div class="col-xl-6"><div class="card lphwf-card h-100"><div class="card-header">Hearings Trend — Last 6 Months</div><div class="card-body"><canvas id="hearingTrend" height="140"></canvas></div></div></div>
<div class="col-xl-6"><div class="card lphwf-card h-100"><div class="card-header">Issue / Action Workflow</div><div class="card-body"><canvas id="workflowChart" height="140"></canvas></div></div></div>
</div>

<div class="row g-3">
<div class="col-xl-7"><div class="card lphwf-card"><div class="card-header">Upcoming Hearings</div><div class="table-responsive"><table class="table lphwf-table mb-0"><thead><tr><th>Reference</th><th>Hearing</th><th>Date</th><th>Venue</th></tr></thead><tbody><?php if(!$upcoming): ?><tr><td colspan="4" class="lphwf-empty">No upcoming hearings.</td></tr><?php endif; ?><?php foreach($upcoming as $h): ?><tr><td><span class="lphwf-code"><?= e($h['reference_number']?:'—') ?></span></td><td><a href="<?= e(APP_URL) ?>/modules/hearings/view.php?id=<?= (int)$h['id'] ?>"><?= e($h['title']) ?></a></td><td><?= formatDate($h['hearing_date']) ?> <?= formatTime($h['hearing_time']) ?></td><td><?= e($h['venue']?:'—') ?></td></tr><?php endforeach; ?></tbody></table></div></div></div>
<div class="col-xl-5"><div class="card lphwf-card"><div class="card-header">Recent Activity</div><div class="card-body lphwf-timeline"><?php foreach($recent as $r): ?><div><strong><?= e($r['action']) ?> · <?= e($r['full_name']?:'System') ?></strong><small><?= e(mb_strimwidth($r['details']?:'',0,120,'…')) ?><br><?= formatDateTime($r['created_at']) ?></small></div><?php endforeach; ?></div></div></div>
</div>
</div></div>

<script>
document.addEventListener('DOMContentLoaded',function(){
 new Chart(document.getElementById('hearingTrend'),{type:'line',data:{labels:<?= json_encode(array_column($hearingTrend,'ym')) ?>,datasets:[{label:'Hearings',data:<?= json_encode(array_map('intval',array_column($hearingTrend,'total'))) ?>,tension:.3}]},options:{responsive:true,plugins:{legend:{display:false}}}});
 new Chart(document.getElementById('workflowChart'),{type:'bar',data:{labels:['Feedback','Issues','Actions','Responses'],datasets:[{label:'Records',data:[<?= $stats['feedback'] ?>,<?= $stats['issues'] ?>,<?= $stats['actions'] ?>,<?= $stats['responses'] ?>]}]},options:{responsive:true,plugins:{legend:{display:false}}}});
});
</script>
<?php include __DIR__.'/layouts/footer.php'; ?>
