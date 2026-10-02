<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pdo=db();$pageTitle='Dashboard';$activeMenu='dashboard';$hideHeader=true;$hideFooter=true;

$stats=[
 'hearings'=>(int)$pdo->query('SELECT COUNT(*) FROM hearings')->fetchColumn(),
 'upcoming'=>(int)$pdo->query("SELECT COUNT(*) FROM hearings WHERE status='Upcoming'")->fetchColumn(),
 'stakeholders'=>(int)$pdo->query('SELECT COUNT(*) FROM stakeholders')->fetchColumn(),
 'completed_hearings'=>(int)$pdo->query("SELECT COUNT(*) FROM hearings WHERE status='Completed'")->fetchColumn(),
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

$recent=[];
$lphSysId=function_exists('lphSystemId')?lphSystemId():4;
$qRecent=$pdo->prepare(
 "SELECT al.action,al.details,al.created_at,u.full_name
  FROM activity_logs al LEFT JOIN users u ON u.id=al.user_id
  WHERE al.system_id=:system
  ORDER BY al.created_at DESC,al.id DESC LIMIT 50"
);
$qRecent->execute([':system'=>$lphSysId]);
$recent=$qRecent->fetchAll();

$upcoming=$pdo->query(
 "SELECT id,reference_number,title,hearing_date,hearing_time,venue
  FROM hearings
  WHERE status='Upcoming' AND hearing_date>=CURDATE()
  ORDER BY hearing_date,hearing_time LIMIT 6"
)->fetchAll();

$myAssignedIssues = [];
if (function_exists('currentUserId') && currentUserId()) {
    $myStmt = $pdo->prepare(
        "SELECT i.id, i.reference_number, i.title, i.priority, i.status, o.name office_name, i.due_at
         FROM hearing_issues i
         LEFT JOIN offices o ON o.id = i.assigned_office_id
         WHERE i.assigned_user_id = :uid AND i.status NOT IN ('Closed', 'Resolved')
         ORDER BY i.updated_at DESC LIMIT 6"
    );
    $myStmt->execute([':uid' => currentUserId()]);
    $myAssignedIssues = $myStmt->fetchAll();
}

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

/* Stat KPI Cards Uniform Height & Size */
.lphwf-stats-row {
    display: flex;
    flex-wrap: wrap;
    align-items: stretch;
}
.lphwf-stats-row > div {
    display: flex;
}
.lphwf-stat {
    display: flex !important;
    align-items: center !important;
    gap: 0.7rem !important;
    width: 100% !important;
    height: 100% !important;
    min-height: 92px !important;
    padding: 0.75rem 0.8rem !important;
    background: #ffffff !important;
    border: 1px solid #e2e8f0 !important;
    border-bottom: 3px solid #a97900 !important;
    border-radius: 11px !important;
    box-sizing: border-box !important;
    box-shadow: 0 2px 6px rgba(10, 22, 40, 0.04) !important;
}
.lphwf-stat i {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 40px !important;
    height: 40px !important;
    min-width: 40px !important;
    flex-shrink: 0 !important;
    color: #1a3a5c !important;
    background: #eef5fb !important;
    border-radius: 9px !important;
    font-size: 1.2rem !important;
}
.lphwf-stat > div {
    flex: 1 1 auto !important;
    min-width: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    justify-content: center !important;
}
.lphwf-stat strong {
    display: block !important;
    color: #0a1628 !important;
    font-size: 1.25rem !important;
    font-weight: 700 !important;
    line-height: 1.2 !important;
    margin-bottom: 2px !important;
}
.lphwf-stat small {
    display: block !important;
    color: #64748b !important;
    font-size: 0.65rem !important;
    font-weight: 600 !important;
    line-height: 1.25 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.2px !important;
    word-break: break-word !important;
}
</style>
<div class="app-wrapper"><?php include __DIR__.'/layouts/sidebar.php'; ?><div class="main-content">

<?php include __DIR__ . '/layouts/top_controls.php'; ?>

<div class="lphwf-head"><div><div class="lphwf-eyebrow">Subsystem #7 · Complete Operational Dashboard</div><h1>Public Hearing & Consultation Management</h1><p>Live operational monitoring across hearings, consultation attendance, feedback, issues, actions and official responses.</p></div><a href="<?= e(APP_URL) ?>/reports/index.php" class="btn btn-primary"><i class="bi bi-bar-chart-line"></i> Reports & Analytics</a></div>

<div class="lphwf-funnel mb-3">
<a href="<?= e(APP_URL) ?>/modules/hearings/index.php"><strong><?= $stats['hearings'] ?></strong><span>Hearings</span></a>
<a href="<?= e(APP_URL) ?>/modules/feedback/index.php"><strong><?= $stats['feedback'] ?></strong><span>Feedback</span></a>
<a href="<?= e(APP_URL) ?>/modules/issues/index.php"><strong><?= $stats['issues'] ?></strong><span>Issues</span></a>
<a href="<?= e(APP_URL) ?>/modules/actions/index.php"><strong><?= $stats['actions'] ?></strong><span>Actions</span></a>
<a href="<?= e(APP_URL) ?>/modules/actions/responses.php"><strong><?= $stats['published_responses'] ?></strong><span>Published Responses</span></a>
</div>

<?php if (!empty($myAssignedIssues)): ?>
<div class="card mb-3 border-0 shadow-sm" style="border-left: 4.5px solid #d97706 !important; background: linear-gradient(135deg, #fffbeb 0%, #ffffff 100%);">
    <div class="card-body p-3">
        <div class="d-flex align-items-center justify-content-between mb-2 pb-2 border-bottom" style="border-color: rgba(217, 119, 6, 0.2) !important;">
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-warning text-dark px-2 py-1"><i class="bi bi-bell-fill me-1"></i> My Assigned Issues (Nakatoka sa Iyo)</span>
                <span class="text-muted small">May <strong><?= count($myAssignedIssues) ?></strong> aktibong issue na naka-assign sa iyong account</span>
            </div>
            <a href="<?= e(APP_URL) ?>/modules/issues/index.php" class="btn btn-sm btn-outline-dark py-0 px-2" style="font-size:0.75rem;">Tingnan Lahat</a>
        </div>
        <div class="d-flex flex-column gap-2">
            <?php foreach ($myAssignedIssues as $mIss): ?>
                <div class="d-flex flex-wrap align-items-center justify-content-between p-2 rounded bg-white border" style="border-color: #fde68a !important; gap: 8px;">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="badge bg-light text-dark border font-monospace"><?= e($mIss['reference_number']) ?></span>
                        <a href="<?= e(APP_URL) ?>/modules/issues/view.php?id=<?= (int)$mIss['id'] ?>" class="fw-bold text-dark text-decoration-none">
                            <?= e($mIss['title']) ?>
                        </a>
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle" style="font-size: 0.72rem;"><?= e($mIss['priority']) ?></span>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size: 0.72rem;"><?= e($mIss['status']) ?></span>
                    </div>
                    <div>
                        <a href="<?= e(APP_URL) ?>/modules/issues/view.php?id=<?= (int)$mIss['id'] ?>" class="btn btn-sm btn-primary py-1 px-3" style="background:#0F2137; border-color:#0F2137;">
                            <i class="bi bi-box-arrow-up-right me-1"></i> Buksan ang Issue
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-3 lphwf-stats-row">
<?php foreach([
 ['Upcoming Hearings',$stats['upcoming'],'bi-calendar-event'],
 ['Completed Hearings',$stats['completed_hearings'],'bi-calendar-check'],
 ['New Feedback',$stats['new_feedback'],'bi-chat-dots'],
 ['Open / In Progress Issues',$stats['open_issues'],'bi-exclamation-triangle'],
 ['Overdue Actions',$stats['overdue_actions'],'bi-alarm'],
 ['Official Responses',$stats['responses'],'bi-reply-all'],
] as [$l,$v,$i]): ?><div class="col-6 col-md-4 col-xl-2 d-flex"><div class="lphwf-stat w-100 h-100"><i class="bi <?= e($i) ?>"></i><div><strong><?= $v ?></strong><small><?= e($l) ?></small></div></div></div><?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
<div class="col-xl-6">
    <div class="card lphwf-card h-100" style="border-top: 3.5px solid #B8860B; box-shadow: 0 4px 18px rgba(10,22,40,0.06);">
        <div class="card-header d-flex align-items-center justify-content-between" style="color: #0F2137; font-weight: 750; background: #ffffff !important; border-bottom: 1px solid #e2e8f0 !important;">
            <div class="d-flex align-items-center">
                <span style="display:inline-block;width:4px;height:16px;background:#B8860B;border-radius:2px;margin-right:8px;"></span>
                <span>Hearings Trend — Last 6 Months</span>
            </div>
            <span class="badge" style="background: rgba(184, 134, 11, 0.12); color: #8A6400; border: 1px solid rgba(184, 134, 11, 0.3); font-size: 0.7rem; font-weight: 650; border-radius: 6px; padding: 4px 8px;">
                <i class="bi bi-graph-up me-1"></i>Trend
            </span>
        </div>
        <div class="card-body py-3" style="position: relative; height: 230px;">
            <canvas id="hearingTrend"></canvas>
        </div>
    </div>
</div>
<div class="col-xl-6">
    <div class="card lphwf-card h-100" style="border-top: 3.5px solid #0F2137; box-shadow: 0 4px 18px rgba(10,22,40,0.06);">
        <div class="card-header d-flex align-items-center justify-content-between" style="color: #0F2137; font-weight: 750; background: #ffffff !important; border-bottom: 1px solid #e2e8f0 !important;">
            <div class="d-flex align-items-center">
                <span style="display:inline-block;width:4px;height:16px;background:#0F2137;border-radius:2px;margin-right:8px;"></span>
                <span>Issue / Action Workflow</span>
            </div>
            <span class="badge" style="background: rgba(15, 33, 55, 0.08); color: #0F2137; border: 1px solid rgba(15, 33, 55, 0.2); font-size: 0.7rem; font-weight: 650; border-radius: 6px; padding: 4px 8px;">
                <i class="bi bi-bar-chart-steps me-1"></i>Workflow
            </span>
        </div>
        <div class="card-body py-3 d-flex justify-content-center align-items-center" style="position: relative; height: 230px;">
            <div style="width: 100%; max-width: 310px; height: 100%; position: relative; margin: 0 auto;">
                <canvas id="workflowChart"></canvas>
            </div>
        </div>
    </div>
</div>
</div>

<div class="row g-3">
<div class="col-xl-7"><div class="card lphwf-card"><div class="card-header">Upcoming Hearings</div><div class="table-responsive"><table class="table lphwf-table mb-0"><thead><tr><th>Reference</th><th>Hearing</th><th>Date</th><th>Venue</th></tr></thead><tbody><?php if(!$upcoming): ?><tr><td colspan="4" class="lphwf-empty">No upcoming hearings.</td></tr><?php endif; ?><?php foreach($upcoming as $h): ?><tr><td><span class="lphwf-code"><?= e($h['reference_number']?:'—') ?></span></td><td><a href="<?= e(APP_URL) ?>/modules/hearings/view.php?id=<?= (int)$h['id'] ?>"><?= e($h['title']) ?></a></td><td><?= formatDate($h['hearing_date']) ?> <?= formatTime($h['hearing_time']) ?></td><td><?= e($h['venue']?:'—') ?></td></tr><?php endforeach; ?></tbody></table></div></div></div>
<div class="col-xl-5"><div class="card lphwf-card h-100" id="lphRecentActivityCard">
    <div class="card-header">Recent Activity</div>
    <div class="card-body lphwf-timeline" id="lphActivityList">
        <?php if(!$recent): ?>
            <div class="text-muted small">No recent activity.</div>
        <?php endif; ?>
        <?php foreach($recent as $idx => $r): ?>
            <div class="<?= $idx >= 5 ? 'lph-activity-extra d-none' : '' ?>">
                <strong><?= e($r['action']) ?> · <?= e($r['full_name']?:'System') ?></strong>
                <small><?= e(mb_strimwidth($r['details']?:'',0,120,'…')) ?><br><?= formatDateTime($r['created_at']) ?></small>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if(count($recent) > 5): ?>
        <div class="card-footer bg-transparent border-top-0 pt-0 pb-3 px-3">
            <button type="button" class="btn btn-sm btn-light border w-100 py-1 fw-semibold text-secondary d-flex align-items-center justify-content-center gap-2" id="btnToggleLphActivityBottom" onclick="toggleLphActivity()" style="font-size: 0.8rem; border-radius: 8px;">
                <i class="bi bi-chevron-down" id="iconToggleLphActivityBottom"></i>
                <span id="textToggleLphActivityBottom">See All (<?= count($recent) ?>)</span>
            </button>
        </div>
    <?php endif; ?>
</div></div>
</div>
</div></div>

<?php
// Prepare 6-month continuous trend labels and totals for Hearings Trend chart
$trendLabels = [];
$trendTotals = [];
for ($i = 5; $i >= 0; $i--) {
    $mKey = date('Y-m', strtotime("-$i months"));
    $trendLabels[] = date('M Y', strtotime("-$i months"));
    $found = 0;
    foreach ($hearingTrend as $ht) {
        if ($ht['ym'] === $mKey) {
            $found = (int)$ht['total'];
            break;
        }
    }
    $trendTotals[] = $found;
}
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Hearings Trend (Line chart with Dark Blue stroke & Dark Gold fill gradient)
    const trendCanvas = document.getElementById('hearingTrend');
    if (trendCanvas && typeof Chart !== 'undefined') {
        const ctx = trendCanvas.getContext('2d');
        const fillGradient = ctx.createLinearGradient(0, 0, 0, 200);
        fillGradient.addColorStop(0, 'rgba(184, 134, 11, 0.32)'); // Dark Gold
        fillGradient.addColorStop(0.65, 'rgba(15, 33, 55, 0.08)'); // Dark Blue
        fillGradient.addColorStop(1, 'rgba(184, 134, 11, 0.00)');

        new Chart(trendCanvas, {
            type: 'line',
            data: {
                labels: <?= json_encode($trendLabels) ?>,
                datasets: [{
                    label: 'Hearings Conducted',
                    data: <?= json_encode($trendTotals) ?>,
                    borderColor: '#0F2137', // Dark Blue Line
                    backgroundColor: fillGradient, // Dark Gold to Blue Gradient Fill
                    fill: true,
                    tension: 0.35,
                    borderWidth: 3,
                    pointBackgroundColor: '#B8860B', // Dark Gold Points
                    pointBorderColor: '#FFFFFF',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    pointHoverBackgroundColor: '#071426', // Deep Dark Blue Hover
                    pointHoverBorderColor: '#B8860B', // Dark Gold
                    pointHoverBorderWidth: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: '#071426', // Deep Dark Blue Tooltip
                        titleColor: '#D4AF37', // Gold Title
                        bodyColor: '#FFFFFF',
                        borderColor: '#B8860B', // Dark Gold Border
                        borderWidth: 1.5,
                        padding: 10,
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: {
                            label: function(context) {
                                return ' Public Hearings: ' + context.parsed.y;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            color: 'rgba(226, 232, 240, 0.6)',
                            drawBorder: false
                        },
                        ticks: {
                            color: '#0F2137', // Dark Blue Ticks
                            font: {
                                family: 'Plus Jakarta Sans, sans-serif',
                                weight: '600',
                                size: 11
                            }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(226, 232, 240, 0.6)',
                            drawBorder: false
                        },
                        ticks: {
                            color: '#0F2137', // Dark Blue Ticks
                            precision: 0,
                            font: {
                                family: 'Plus Jakarta Sans, sans-serif',
                                weight: '600',
                                size: 11
                            }
                        }
                    }
                }
            }
        });
    }

    // 2. Issue / Action Workflow (Coordinated Dark Blue & Dark Gold Bar Chart)
    const workflowCanvas = document.getElementById('workflowChart');
    if (workflowCanvas && typeof Chart !== 'undefined') {
        new Chart(workflowCanvas, {
            type: 'bar',
            data: {
                labels: ['Feedback', 'Issues', 'Actions', 'Responses'],
                datasets: [{
                    label: 'Workflow Records',
                    data: [
                        <?= (int)$stats['feedback'] ?>,
                        <?= (int)$stats['issues'] ?>,
                        <?= (int)$stats['actions'] ?>,
                        <?= (int)$stats['responses'] ?>
                    ],
                    backgroundColor: [
                        '#0F2137', // Feedback: Executive Dark Blue
                        '#B8860B', // Issues: Executive Dark Gold
                        '#1A3A5C', // Actions: Medium Dark Blue
                        '#9A6A00'  // Responses: Burnished Dark Gold
                    ],
                    hoverBackgroundColor: [
                        '#1A3A5C', // Dark Blue Hover
                        '#9A6A00', // Dark Gold Hover
                        '#071426', // Deep Blue Hover
                        '#B8860B'  // Dark Gold Hover
                    ],
                    borderColor: 'transparent',
                    borderWidth: 0,
                    hoverBorderColor: 'transparent',
                    hoverBorderWidth: 0,
                    borderRadius: 0,
                    borderSkipped: false,
                    barPercentage: 1.0,
                    categoryPercentage: 1.0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: '#071426', // Deep Dark Blue Tooltip
                        titleColor: '#D4AF37', // Gold Title
                        bodyColor: '#FFFFFF',
                        borderColor: '#B8860B', // Dark Gold Border
                        borderWidth: 1.5,
                        padding: 10,
                        cornerRadius: 8,
                        displayColors: true,
                        boxPadding: 4
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false,
                            drawBorder: false
                        },
                        border: {
                            display: false
                        },
                        ticks: {
                            color: '#0F2137', // Dark Blue Ticks
                            autoSkip: false,
                            maxRotation: 0,
                            minRotation: 0,
                            padding: 2,
                            font: {
                                family: 'Plus Jakarta Sans, sans-serif',
                                weight: '700',
                                size: 10.5
                            }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(226, 232, 240, 0.6)',
                            drawBorder: false
                        },
                        border: {
                            display: false
                        },
                        ticks: {
                            color: '#0F2137', // Dark Blue Ticks
                            precision: 0,
                            font: {
                                family: 'Plus Jakarta Sans, sans-serif',
                                weight: '600',
                                size: 11
                            }
                        }
                    }
                }
            }
        });
    }
});
</script>
<style>
.lph-activity-extra:not(.d-none) {
    animation: fadeInLphActivity 0.25s ease-in-out;
}
@keyframes fadeInLphActivity {
    from { opacity: 0; transform: translateY(-3px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>
<script>
function toggleLphActivity() {
  const extras = document.querySelectorAll('.lph-activity-extra');
  if (!extras.length) return;
  const isHidden = extras[0].classList.contains('d-none');

  extras.forEach(el => {
    if (isHidden) {
      el.classList.remove('d-none');
    } else {
      el.classList.add('d-none');
    }
  });

  const totalCount = extras.length + 5;
  const textBottom = document.getElementById('textToggleLphActivityBottom');
  const iconBottom = document.getElementById('iconToggleLphActivityBottom');

  if (isHidden) {
    if (textBottom) textBottom.textContent = 'Hide';
    if (iconBottom) iconBottom.className = 'bi bi-chevron-up';
  } else {
    if (textBottom) textBottom.textContent = 'See All (' + totalCount + ')';
    if (iconBottom) iconBottom.className = 'bi bi-chevron-down';

    const card = document.getElementById('lphRecentActivityCard');
    if (card) {
      card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
  }
}
</script>
<?php include __DIR__.'/layouts/footer.php'; ?>
