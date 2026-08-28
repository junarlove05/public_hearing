<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pdo=db();$pageTitle='Dashboard';$activeMenu='dashboard';$hideHeader=true;$hideFooter=true;

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
<style>
/* Hide top header bar & footer on dashboard */
.topnav,
.app-footer,
footer,
.orlms-footer {
    display: none !important;
}
body {
    margin: 0 !important;
    padding-top: 0 !important;
    background-color: #f8fafc !important;
}
.app-wrapper {
    padding-top: 0 !important;
}

/* SIDEBAR OPEN (Default State: 286px Width) */
.sidebar,
.orlms-sidebar {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    bottom: 0 !important;
    width: 286px !important;
    z-index: 1030 !important;
    padding-top: 0 !important;
    transition: width 0.25s ease !important;
}

/* MAIN CONTENT ALIGNMENT (Beside Open Sidebar: margin-left 286px, minimal top padding 0.5rem) */
.main-content,
.orlms-main-content {
    margin-left: 286px !important;
    padding-top: 0.5rem !important;
    padding-left: 1.5rem !important;
    padding-right: 1.5rem !important;
    min-height: 100vh !important;
    position: relative !important;
    transition: margin-left 0.25s ease !important;
}



/* COLLAPSED SIDEBAR - ICON ONLY MODE (74px) */
body.sidebar-collapsed {
    --side: 74px !important;
    --gov-sidebar-width: 74px !important;
}

body.sidebar-collapsed .sidebar,
body.sidebar-collapsed .orlms-sidebar,
.sidebar.collapsed,
.orlms-sidebar.collapsed {
    width: 74px !important;
    min-width: 74px !important;
    max-width: 74px !important;
    transform: none !important;
}

body.sidebar-collapsed .main-content,
body.sidebar-collapsed .orlms-main-content {
    margin-left: 74px !important;
}

/* Hide brand text, text labels, badges, section titles, and footer details in collapsed mode */
body.sidebar-collapsed .sidebar-brand div,
body.sidebar-collapsed .orlms-sidebar-brand div,
body.sidebar-collapsed .sidebar-navigation span,
body.sidebar-collapsed .orlms-sidebar-nav span,
body.sidebar-collapsed .orlms-sidebar-section,
body.sidebar-collapsed .sidebar-section-title,
body.sidebar-collapsed .orlms-sidebar-link > span,
body.sidebar-collapsed .orlms-sidebar-link > em,
body.sidebar-collapsed .orlms-sidebar-footer div {
    display: none !important;
}

body.sidebar-collapsed .sidebar-brand,
body.sidebar-collapsed .orlms-sidebar-brand {
    padding: 0.8rem 0.4rem !important;
    justify-content: center !important;
}

body.sidebar-collapsed .orlms-sidebar-logo,
body.sidebar-collapsed .sidebar-logo {
    width: 42px !important;
    height: 42px !important;
    max-width: 42px !important;
    max-height: 42px !important;
}

body.sidebar-collapsed .sidebar-navigation a,
body.sidebar-collapsed .orlms-sidebar-link {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0.75rem 0 !important;
    margin: 0.25rem 0.4rem !important;
    text-align: center !important;
}

body.sidebar-collapsed .sidebar-navigation i,
body.sidebar-collapsed .orlms-sidebar-link i {
    font-size: 1.4rem !important;
    margin: 0 !important;
    display: inline-block !important;
    visibility: visible !important;
    opacity: 1 !important;
}

body.sidebar-collapsed .orlms-sidebar-footer {
    justify-content: center !important;
    padding: 0.6rem 0 !important;
}

/* Mobile Responsiveness */
@media (max-width: 1050px) {
    .main-content,
    .orlms-main-content,
    body.sidebar-collapsed .main-content,
    body.sidebar-collapsed .orlms-main-content {
        margin-left: 0 !important;
        padding-left: 1rem !important;
        padding-right: 1rem !important;
    }
}
</style>
<div class="app-wrapper"><?php include __DIR__.'/layouts/sidebar.php'; ?><div class="main-content">

<!-- TOP CONTROLS (Sidebar Toggle + Subsystems Navigation + Admin Profile) -->
<div class="d-flex align-items-center justify-content-between gap-2 mb-2">
  <!-- 3-Line Hamburger Sidebar Toggle Button -->
  <button type="button" class="btn btn-sm btn-white border shadow-sm d-flex align-items-center justify-content-center" id="sidebarToggleBtn" title="Toggle Sidebar" onclick="document.body.classList.toggle('sidebar-collapsed');" style="width: 36px; height: 36px; border-radius: 8px; background: #fff; color: #0F172A; cursor: pointer;">
    <i class="bi bi-list fs-5"></i>
  </button>

  <!-- Right Controls Section -->
  <div class="d-flex align-items-center gap-2">
    <!-- Subsystems Switcher Dropdown -->
    <div class="dropdown">
      <button class="btn btn-sm btn-outline-secondary dropdown-toggle d-flex align-items-center gap-2 shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="border-radius: 8px; font-weight: 600; background: #fff;">
        <i class="bi bi-grid-3x3-gap-fill text-warning"></i>
        <span>Subsystems</span>
      </button>
      <ul class="dropdown-menu dropdown-menu-end shadow-sm border p-2 mt-2" style="min-width: 230px; font-size: 0.825rem; border-radius: 10px;">
        <li><a class="dropdown-item rounded py-1.5" href="<?= e(APP_URL) ?>/index.php"><i class="bi bi-house-door me-2 text-primary"></i>Main Portal</a></li>
        <li><hr class="dropdown-divider my-1"></li>
        <li><a class="dropdown-item rounded py-1.5" href="http://localhost/orlms/" target="_blank">#1 Ordinance & Resolution</a></li>
        <li><a class="dropdown-item rounded py-1.5" href="http://localhost/slmms/" target="_blank">#2 Session & Meeting</a></li>
        <li><a class="dropdown-item rounded py-1.5" href="http://localhost/lacms/" target="_blank">#3 Agenda & Calendar</a></li>
        <li><a class="dropdown-item rounded py-1.5" href="http://localhost/cmas/" target="_blank">#4 Committee Management</a></li>
        <li><a class="dropdown-item rounded py-1.5" href="http://localhost/vqdss/" target="_blank">#5 Voting & Quorum</a></li>
        <li><a class="dropdown-item rounded py-1.5" href="http://localhost/lrdms/" target="_blank">#6 Records & Documents</a></li>
        <li><a class="dropdown-item rounded py-1.5 fw-bold text-primary active" href="<?= e(APP_URL) ?>/dashboard.php">#7 Public Hearing</a></li>
        <li><a class="dropdown-item rounded py-1.5" href="http://localhost/lahrs/" target="_blank">#8 Archives & Repository</a></li>
        <li><a class="dropdown-item rounded py-1.5" href="http://localhost/lrpaies/" target="_blank">#9 Research & Policy</a></li>
        <li><a class="dropdown-item rounded py-1.5" href="http://localhost/cepfms/" target="_blank">#10 Citizen Engagement</a></li>
      </ul>
    </div>

    <!-- Admin / User Profile Dropdown -->
    <div class="dropdown">
      <a href="#" class="d-flex align-items-center text-dark text-decoration-none gap-2 px-3 py-1.5 bg-white border rounded-3 shadow-sm" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-person-circle fs-5 text-primary"></i>
        <div class="d-flex flex-column text-start lh-1">
          <strong style="font-size: 0.825rem; color: #0F172A;"><?= e($user['full_name'] ?? 'Admin') ?></strong>
          <small style="font-size: 0.65rem; color: #64748B;"><?= e($user['role_name'] ?? 'Administrator') ?></small>
        </div>
        <i class="bi bi-chevron-down ms-1 text-muted" style="font-size: 0.75rem;"></i>
      </a>
      <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 mt-2" style="font-size: 0.85rem;">
        <li><a class="dropdown-item py-2" href="<?= e(APP_URL) ?>/pages/profile.php"><i class="bi bi-person me-2 text-primary"></i> My Profile</a></li>
        <li><hr class="dropdown-divider my-1"></li>
        <li><a class="dropdown-item py-2 text-danger" href="<?= e(APP_URL) ?>/logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
      </ul>
    </div>
  </div>
</div>

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
