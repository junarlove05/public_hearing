<?php
/**
 * dashboard.php (project root)
 * ------------------------------------------------------------------
 * Professional government dashboard with dark coastal blue, white, and teal theme.
 * Shows KPI stat cards, recent activity, and Chart.js visualizations.
 * All figures are pulled live from the existing schema with read-only aggregate queries.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pageTitle  = 'Dashboard';
$activeMenu = 'dashboard';
$pdo = db();

/* ============================================================
 * KPI QUERIES  (all read-only aggregates, no schema changes)
 * ============================================================ */
$stats = [
    'total_hearings' => (int)$pdo
        ->query("SELECT COUNT(*) FROM hearings")
        ->fetchColumn(),

    'upcoming_hearings' => (int)$pdo
        ->query("
            SELECT COUNT(*)
            FROM hearings
            WHERE status = 'Upcoming'
        ")
        ->fetchColumn(),

    'completed_hearings' => (int)$pdo
        ->query("
            SELECT COUNT(*)
            FROM hearings
            WHERE status = 'Completed'
        ")
        ->fetchColumn(),

    'total_stakeholders' => (int)$pdo
        ->query("SELECT COUNT(*) FROM stakeholders")
        ->fetchColumn(),

    'attendance_today' => (int)$pdo
        ->query("
            SELECT COUNT(*)
            FROM attendance
            WHERE DATE(checked_in_at) = CURDATE()
        ")
        ->fetchColumn(),

    'pending_invitations' => (int)$pdo
        ->query("
            SELECT COUNT(*)
            FROM invitations
            WHERE status = 'Pending'
        ")
        ->fetchColumn(),

    'total_feedback' => (int)$pdo
        ->query("SELECT COUNT(*) FROM feedback")
        ->fetchColumn(),

    'new_feedback' => (int)$pdo
        ->query("
            SELECT COUNT(*)
            FROM feedback
            WHERE status = 'New'
        ")
        ->fetchColumn(),

    /* Updated Public Hearing issue tables */
    'open_issues' => (int)$pdo
        ->query("
            SELECT COUNT(*)
            FROM hearing_issues
            WHERE status = 'Open'
        ")
        ->fetchColumn(),

    'resolved_issues' => (int)$pdo
        ->query("
            SELECT COUNT(*)
            FROM hearing_issues
            WHERE status = 'Resolved'
        ")
        ->fetchColumn(),

    /* Updated Public Hearing action tables */
    'pending_actions' => (int)$pdo
        ->query("
            SELECT COUNT(*)
            FROM hearing_actions
            WHERE status = 'Pending'
        ")
        ->fetchColumn(),

    'completed_actions' => (int)$pdo
        ->query("
            SELECT COUNT(*)
            FROM hearing_actions
            WHERE status = 'Completed'
        ")
        ->fetchColumn(),

    /* Updated issue-priority queries */
    'low_priority' => (int)$pdo
        ->query("
            SELECT COUNT(*)
            FROM hearing_issues
            WHERE priority = 'Low'
        ")
        ->fetchColumn(),

    'medium_priority' => (int)$pdo
        ->query("
            SELECT COUNT(*)
            FROM hearing_issues
            WHERE priority = 'Medium'
        ")
        ->fetchColumn(),

    'high_priority' => (int)$pdo
        ->query("
            SELECT COUNT(*)
            FROM hearing_issues
            WHERE priority = 'High'
        ")
        ->fetchColumn(),

    'critical_priority' => (int)$pdo
        ->query("
            SELECT COUNT(*)
            FROM hearing_issues
            WHERE priority = 'Critical'
        ")
        ->fetchColumn(),
];

/* ---- Hearings per month (last 6 months) for line/bar chart ---- */
$hearingsTrendStmt = $pdo->query(
    "SELECT DATE_FORMAT(hearing_date, '%Y-%m') AS ym, COUNT(*) AS total
     FROM hearings
     WHERE hearing_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
     GROUP BY ym ORDER BY ym"
);
$hearingsTrend = $hearingsTrendStmt->fetchAll();

/* ---- Hearing status breakdown (doughnut) ---- */
$hearingStatusStmt = $pdo->query(
    "SELECT status, COUNT(*) AS total FROM hearings GROUP BY status"
);
$hearingStatusData = $hearingStatusStmt->fetchAll();

/* ---- Issue priority breakdown (pie) ---- */
$priorityChart = [
    'Low'      => $stats['low_priority'],
    'Medium'   => $stats['medium_priority'],
    'High'     => $stats['high_priority'],
    'Critical' => $stats['critical_priority'],
];

/* ---- Feedback by category (bar) ---- */
$feedbackCatStmt = $pdo->query(
    "SELECT fc.name, COUNT(f.id) AS total
     FROM feedback_categories fc
     LEFT JOIN feedback f ON f.category_id = fc.id
     GROUP BY fc.id, fc.name ORDER BY total DESC"
);
$feedbackCatData = $feedbackCatStmt->fetchAll();

/* ---- Recent activity log (latest 8) ---- */
$recentActivity = $pdo->query(
    "SELECT al.action, al.details, al.created_at, u.full_name
     FROM activity_logs al
     LEFT JOIN users u ON u.id = al.user_id
     ORDER BY al.created_at DESC LIMIT 8"
)->fetchAll();

/* ---- Upcoming hearings (next 5) ---- */
$upcomingList = $pdo->query(
    "SELECT h.title, h.hearing_date, h.hearing_time, h.venue, c.name AS committee_name
     FROM hearings h
     LEFT JOIN committees c ON c.id = h.committee_id
     WHERE h.status = 'Upcoming' AND h.hearing_date >= CURDATE()
     ORDER BY h.hearing_date ASC, h.hearing_time ASC LIMIT 5"
)->fetchAll();

/* ---- AI Sentiment summary (graceful no-op if AI tables aren't set up) ---- */
$aiSummary = null;
try {
    require_once __DIR__ . '/includes/AI/AIAnalysisManager.php';
    if (AIAnalysisManager::tablesExist()) {
        $sentimentCounts = $pdo->query(
            "SELECT sentiment, COUNT(*) AS total FROM feedback_ai_analysis WHERE status = 'completed' AND sentiment IS NOT NULL GROUP BY sentiment"
        )->fetchAll();
        $totals = ['Positive' => 0, 'Neutral' => 0, 'Negative' => 0];
        foreach ($sentimentCounts as $row) {
            if (isset($totals[$row['sentiment']])) $totals[$row['sentiment']] = (int)$row['total'];
        }
        $flaggedList = $pdo->query(
            "SELECT f.id, f.name, f.subject, ai.confidence_score, ai.analyzed_at
             FROM feedback_ai_analysis ai JOIN feedback f ON f.id = ai.feedback_id
             WHERE ai.flagged_for_review = 1 ORDER BY ai.analyzed_at DESC LIMIT 5"
        )->fetchAll();
        $aiSummary = ['totals' => $totals, 'flagged' => $flaggedList];
    }
} catch (Throwable $e) {
    $aiSummary = null;
}

include __DIR__ . '/layouts/header.php';
?>
<style>
    /* ============================================================
       DASHBOARD - Dark Coastal Blue with Yellow Lining/Accents
       Colors: Dark Coastal Blue (#0a1628, #0d2137, #1a365d), Yellow (#FFD700)
       ============================================================ */
    :root {
        --db-white: #ffffff;
        --db-light-bg: #E8EEF5;
        --db-dark-blue-darkest: #0a1628;
        --db-dark-blue-darker: #0d2137;
        --db-dark-blue-dark: #122a45;
        --db-dark-blue-medium: #1a365d;
        --db-dark-blue-primary: #1e4a7a;
        --db-dark-blue-light: #2d6a9f;
        --db-dark-blue-soft: #4a8fc9;
        --db-dark-blue-pale: #D6E4F0;
        --db-text-dark: #0a1628;
        --db-text-light: #E8EEF5;
        --db-gray-300: #CBD5E1;
        --db-gray-400: #94A3B8;
        --db-gray-500: #64748B;
        --db-gray-600: #475569;
        --db-teal: #00838f;
        --db-teal-light: #4dd0e1;
        --db-teal-dark: #006064;
        --db-teal-bg: #E0F7FA;
        --db-teal-soft: #26C6DA;
        --db-ocean: #01579b;
        --db-sky: #4fc3f7;
        --db-yellow: #FFD700;
        --db-yellow-soft: #FFC107;
        --db-yellow-light: #FFE082;
    }

    /* ===== MAIN CONTENT ===== */
    .main-content {
        padding: 0.5rem 1.5rem 1.5rem 1.5rem;
        margin-top: 20px;
        min-height: calc(100vh - 72px);
        background: #E8EEF5;
    }

    /* ===== BREADCRUMB BAR ===== */
    .breadcrumb-bar {
        background: var(--db-white);
        border-left: 4px solid var(--db-yellow);
        box-shadow: 0 2px 15px rgba(13, 33, 55, 0.1);
        padding: 0.75rem 1.25rem;
        border-radius: 12px;
        margin-bottom: 0.75rem;
        margin-top: 0;
        border: 1px solid rgba(255, 215, 0, 0.3);
    }

    .breadcrumb-bar h5 {
        color: var(--db-text-dark);
        font-size: 1.1rem;
        margin-bottom: 0.1rem;
    }

    .breadcrumb-bar h5 i {
        color: var(--db-dark-blue-primary);
    }

    .breadcrumb-bar .badge {
        background: linear-gradient(135deg, var(--db-dark-blue-darker), var(--db-dark-blue-primary)) !important;
        color: var(--db-white) !important;
        border: 2px solid var(--db-yellow) !important;
    }

    .breadcrumb-bar .text-muted {
        color: var(--db-gray-500) !important;
        font-size: 0.8rem;
    }

    /* ===== STAT CARDS ===== */
    .stat-card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 4px 20px rgba(13, 33, 55, 0.1);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
        cursor: default;
        padding: 1.25rem 1.5rem !important;
        min-height: 120px;
        background: var(--db-white);
        border: 1px solid rgba(255, 215, 0, 0.4) !important;
    }

    /* Bottom accent bar - Yellow lining */
    .stat-card::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: var(--db-yellow);
        opacity: 0.6;
        transition: opacity 0.3s ease, height 0.3s ease;
    }

    .stat-card:hover::after {
        opacity: 1;
        height: 4px;
        box-shadow: 0 0 20px rgba(255, 215, 0, 0.3);
    }

    .stat-card::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -30%;
        width: 80%;
        height: 200%;
        background: linear-gradient(135deg, rgba(13, 33, 55, 0.04), transparent 60%);
        border-radius: 50%;
        transform: rotate(25deg) scale(0);
        transition: all 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        pointer-events: none;
    }

    .stat-card:hover::before {
        transform: rotate(25deg) scale(1);
    }

    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 30px rgba(13, 33, 55, 0.15);
        border-color: var(--db-yellow) !important;
    }

    /* ===== ICONS - Dark Coastal Blue ===== */
    .stat-card .stat-icon {
        font-size: 1.75rem;
        opacity: 1;
        margin-bottom: 0.5rem;
        display: block;
        color: var(--db-dark-blue-primary) !important;
    }

    /* ===== VALUE - Dark Text ===== */
    .stat-card .stat-value {
        font-size: 2.2rem;
        font-weight: 800;
        line-height: 1.2;
        margin-bottom: 0.25rem;
        color: var(--db-text-dark);
        letter-spacing: -0.5px;
    }

    /* ===== LABEL - Gray ===== */
    .stat-card .stat-label {
        font-size: 0.75rem;
        opacity: 1;
        font-weight: 500;
        color: var(--db-gray-500) !important;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        text-shadow: none;
    }

    /* ===== CARD BACKGROUNDS ===== */
    .bg-gov-white {
        background: var(--db-white);
    }

    .bg-gov-dark-blue-light {
        background: linear-gradient(135deg, #E8EEF5 0%, #D6E4F0 100%);
    }

    .bg-gov-dark-blue-pale {
        background: linear-gradient(135deg, #D6E4F0 0%, #B8CDE0 100%);
    }

    .bg-gov-dark-blue-primary {
        background: linear-gradient(135deg, var(--db-dark-blue-primary) 0%, var(--db-dark-blue-dark) 100%);
    }

    .bg-gov-dark-blue-dark {
        background: linear-gradient(135deg, var(--db-dark-blue-dark) 0%, var(--db-dark-blue-darker) 100%);
    }

    .bg-gov-dark-blue-darkest {
        background: linear-gradient(135deg, var(--db-dark-blue-darker) 0%, var(--db-dark-blue-darkest) 100%);
    }

    /* Teal cards */
    .bg-gov-teal {
        background: linear-gradient(135deg, var(--db-teal-bg) 0%, #B2DFDB 100%);
        border-bottom: 4px solid var(--db-yellow);
    }

    .bg-gov-teal-dark {
        background: linear-gradient(135deg, var(--db-teal-dark) 0%, var(--db-teal) 100%);
        border-bottom: 4px solid var(--db-yellow);
    }

    /* Ocean card */
    .bg-gov-ocean {
        background: linear-gradient(135deg, var(--db-ocean) 0%, var(--db-dark-blue-dark) 100%);
        border-bottom: 4px solid var(--db-yellow);
    }

    /* ===== CHART CARDS ===== */
    .card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 2px 20px rgba(13, 33, 55, 0.08);
        background: var(--db-white);
        transition: all 0.3s ease;
        border: 1px solid rgba(13, 33, 55, 0.06);
    }

    .card:hover {
        box-shadow: 0 4px 30px rgba(13, 33, 55, 0.12);
        transform: translateY(-2px);
        border-color: var(--db-yellow);
    }

    .card-header {
        background: linear-gradient(135deg, var(--db-dark-blue-darkest) 0%, var(--db-dark-blue-dark) 100%);
        color: var(--db-text-light);
        border-bottom: 3px solid var(--db-yellow);
        border-radius: 16px 16px 0 0 !important;
        padding: 0.75rem 1.25rem;
    }

    .card-header i {
        color: var(--db-dark-blue-soft);
        margin-right: 0.5rem;
    }

    .card-header a.small {
        color: var(--db-dark-blue-soft) !important;
        transition: color 0.3s ease;
    }

    .card-header a.small:hover {
        color: var(--db-yellow) !important;
        text-decoration: none;
    }

    .card-body {
        background: var(--db-white);
        padding: 1.25rem;
    }

    /* ===== LIST GROUP ITEMS ===== */
    .list-group-item {
        border-bottom: 1px solid #D6E4F0;
        background: transparent;
        color: var(--db-text-dark);
        transition: all 0.3s ease;
        padding: 0.75rem 1.25rem;
        border-left: 3px solid transparent;
    }

    .list-group-item:hover {
        background: #D6E4F0;
        transform: translateX(4px);
        border-left: 3px solid var(--db-yellow);
    }

    .list-group-item:last-child {
        border-bottom: none;
    }

    .list-group-item .fw-semibold {
        color: var(--db-text-dark);
    }

    .list-group-item .text-muted {
        color: var(--db-gray-500) !important;
    }

    .list-group-item .badge {
        background: linear-gradient(135deg, var(--db-text-dark), var(--db-dark-blue-dark)) !important;
        color: var(--db-white) !important;
        border: 1px solid var(--db-yellow) !important;
    }

    .list-group-item .badge.bg-teal {
        background: var(--db-teal) !important;
        border-color: var(--db-yellow) !important;
        color: white !important;
    }

    /* ===== AI SENTIMENT - Keep original colors ===== */
    .card .fs-4 {
        color: var(--db-text-dark);
    }

    .card .fs-4 .text-muted {
        color: var(--db-gray-500) !important;
    }

    /* ===== LINKS ===== */
    a.small {
        color: var(--db-dark-blue-primary) !important;
        font-weight: 500;
    }

    a.small:hover {
        color: var(--db-yellow) !important;
        text-decoration: none;
    }

    /* ===== RESPONSIVE ===== */
    @media (max-width: 768px) {
        .main-content {
            padding: 0.5rem 0.75rem 1rem 0.75rem;
        }

        .breadcrumb-bar {
            padding: 0.6rem 1rem;
            margin-bottom: 0.5rem;
        }

        .breadcrumb-bar h5 {
            font-size: 0.95rem;
        }

        .stat-card .stat-value {
            font-size: 1.8rem;
        }
        .stat-card .stat-icon {
            font-size: 1.5rem;
        }
        .stat-card .stat-label {
            font-size: 0.65rem;
        }
        .breadcrumb-bar {
            flex-direction: column;
            gap: 0.5rem;
            align-items: flex-start;
        }
        .card-header {
            font-size: 0.9rem;
        }
    }

    @media (max-width: 576px) {
        .main-content {
            padding: 0.25rem 0.5rem 0.75rem 0.5rem;
        }

        .breadcrumb-bar {
            padding: 0.5rem 0.75rem;
            margin-bottom: 0.4rem;
        }

        .stat-card {
            padding: 1rem !important;
            min-height: 100px;
        }
        .stat-card .stat-value {
            font-size: 1.5rem;
        }
        .stat-card .stat-label {
            font-size: 0.6rem;
        }
    }

    /* ============================================
       MAIN CONTENT - Adjust based on sidebar state
       ============================================ */

    /* Default state - sidebar expanded (260px) */
    .main-content {
        margin-left: 260px !important;
        transition: margin-left 0.3s ease !important;
        padding: 20px !important;
        min-height: calc(100vh - 72px) !important;
        margin-top: 10px !important;
        width: auto !important;
        max-width: calc(100% - 260px) !important;
    }

    /* When sidebar is collapsed (72px) */
    .main-content.sidebar-collapsed {
        margin-left: 72px !important;
        max-width: calc(100% - 72px) !important;
    }

    /* When sidebar is completely hidden on mobile */
    @media (max-width: 992px) {
        .main-content {
            margin-left: 0 !important;
            max-width: 100% !important;
            padding: 15px !important;
        }
    }
</style>
<div class="app-wrapper">
  <?php include __DIR__ . '/layouts/sidebar.php'; ?>

  <div class="main-content">
    <!-- ===== BREADCRUMB BAR ===== -->
    <div class="breadcrumb-bar d-flex justify-content-between align-items-center">
      <div>
        <h5 class="mb-0"><i class="bi bi-speedometer2"></i> Dashboard</h5>
        <small class="text-muted">Overview of hearings, stakeholders, feedback, and issues</small>
      </div>
      <span class="badge"><?= e(date('l, F j, Y')) ?></span>
    </div>

    <!-- ===== KPI CARDS - Dark Coastal Blue with Yellow Lining ===== -->
    <div class="row g-3 mb-2">
      <div class="col-sm-6 col-lg-3">
        <div class="card stat-card bg-gov-white p-3">
          <i class="bi bi-calendar-event stat-icon"></i>
          <div class="stat-value"><?= $stats['total_hearings'] ?></div>
          <div class="stat-label">Total Hearings</div>
        </div>
      </div>
      <div class="col-sm-6 col-lg-3">
        <div class="card stat-card bg-gov-dark-blue-light p-3">
          <i class="bi bi-calendar-plus stat-icon"></i>
          <div class="stat-value"><?= $stats['upcoming_hearings'] ?></div>
          <div class="stat-label">Upcoming Hearings</div>
        </div>
      </div>
      <div class="col-sm-6 col-lg-3">
        <div class="card stat-card bg-gov-teal p-3">
          <i class="bi bi-calendar-check stat-icon"></i>
          <div class="stat-value"><?= $stats['completed_hearings'] ?></div>
          <div class="stat-label">Completed Hearings</div>
        </div>
      </div>
      <div class="col-sm-6 col-lg-3">
        <div class="card stat-card bg-gov-dark-blue-primary p-3">
          <i class="bi bi-people stat-icon"></i>
          <div class="stat-value"><?= $stats['total_stakeholders'] ?></div>
          <div class="stat-label">Total Stakeholders</div>
        </div>
      </div>
    </div>

    <div class="row g-3 mb-2">
      <div class="col-sm-6 col-lg-3">
        <div class="card stat-card bg-gov-dark-blue-pale p-3">
          <i class="bi bi-qr-code-scan stat-icon"></i>
          <div class="stat-value"><?= $stats['attendance_today'] ?></div>
          <div class="stat-label">Attendance Today</div>
        </div>
      </div>
      <div class="col-sm-6 col-lg-3">
        <div class="card stat-card bg-gov-white p-3">
          <i class="bi bi-envelope-paper stat-icon"></i>
          <div class="stat-value"><?= $stats['pending_invitations'] ?></div>
          <div class="stat-label">Pending Invitations</div>
        </div>
      </div>
      <div class="col-sm-6 col-lg-3">
        <div class="card stat-card bg-gov-dark-blue-light p-3">
          <i class="bi bi-chat-square-text stat-icon"></i>
          <div class="stat-value"><?= $stats['total_feedback'] ?></div>
          <div class="stat-label">Feedback Received (<?= $stats['new_feedback'] ?> new)</div>
        </div>
      </div>
      <div class="col-sm-6 col-lg-3">
        <div class="card stat-card bg-gov-ocean p-3">
          <i class="bi bi-exclamation-triangle stat-icon"></i>
          <div class="stat-value"><?= $stats['open_issues'] ?></div>
          <div class="stat-label">Open Issues</div>
        </div>
      </div>
    </div>

    <div class="row g-3 mb-4">
      <div class="col-sm-6 col-lg-3">
        <div class="card stat-card bg-gov-teal p-3">
          <i class="bi bi-check2-circle stat-icon"></i>
          <div class="stat-value"><?= $stats['resolved_issues'] ?></div>
          <div class="stat-label">Resolved Issues</div>
        </div>
      </div>
      <div class="col-sm-6 col-lg-3">
        <div class="card stat-card bg-gov-white p-3">
          <i class="bi bi-hourglass-split stat-icon"></i>
          <div class="stat-value"><?= $stats['pending_actions'] ?></div>
          <div class="stat-label">Pending Actions</div>
        </div>
      </div>
      <div class="col-sm-6 col-lg-3">
        <div class="card stat-card bg-gov-teal-dark p-3">
          <i class="bi bi-clipboard-check stat-icon"></i>
          <div class="stat-value"><?= $stats['completed_actions'] ?></div>
          <div class="stat-label">Completed Actions</div>
        </div>
      </div>
      <div class="col-sm-6 col-lg-3">
        <div class="card stat-card bg-gov-dark-blue-dark p-3">
          <i class="bi bi-flag stat-icon"></i>
          <div class="stat-value"><?= $stats['high_priority'] ?></div>
          <div class="stat-label">High Priority Issues</div>
        </div>
      </div>
    </div>

    <!-- ===== CHARTS ===== -->
    <div class="row g-3 mb-4">
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header"><i class="bi bi-bar-chart"></i> Hearings Trend (Last 6 Months)</div>
          <div class="card-body"><canvas id="hearingsTrendChart" height="180"></canvas></div>
        </div>
      </div>
      <div class="col-lg-3">
        <div class="card h-100">
          <div class="card-header"><i class="bi bi-pie-chart"></i> Hearing Status</div>
          <div class="card-body"><canvas id="hearingStatusChart" height="180"></canvas></div>
        </div>
      </div>
      <div class="col-lg-3">
        <div class="card h-100">
          <div class="card-header"><i class="bi bi-flag"></i> Issue Priority</div>
          <div class="card-body"><canvas id="priorityChart" height="180"></canvas></div>
        </div>
      </div>
    </div>

    <div class="row g-3 mb-4">
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header"><i class="bi bi-chat-square-text"></i> Feedback by Category</div>
          <div class="card-body"><canvas id="feedbackChart" height="180"></canvas></div>
        </div>
      </div>

      <div class="col-lg-3">
        <div class="card h-100">
          <div class="card-header"><i class="bi bi-calendar-week"></i> Upcoming Hearings</div>
          <div class="list-group list-group-flush">
            <?php if (empty($upcomingList)): ?>
              <div class="list-group-item text-muted small">No upcoming hearings scheduled.</div>
            <?php endif; ?>
            <?php foreach ($upcomingList as $h): ?>
              <div class="list-group-item">
                <div class="fw-semibold small"><?= e($h['title']) ?></div>
                <div class="text-muted small">
                  <i class="bi bi-calendar3"></i> <?= formatDate($h['hearing_date']) ?>
                  &middot; <?= formatTime($h['hearing_time']) ?>
                </div>
                <div class="text-muted small"><i class="bi bi-geo-alt"></i> <?= e($h['venue'] ?: 'TBA') ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="col-lg-3">
        <div class="card h-100">
          <div class="card-header"><i class="bi bi-clock-history"></i> Recent Activities</div>
          <div class="list-group list-group-flush">
            <?php if (empty($recentActivity)): ?>
              <div class="list-group-item text-muted small">No recent activity.</div>
            <?php endif; ?>
            <?php foreach ($recentActivity as $log): ?>
              <div class="list-group-item">
                <div class="small fw-semibold"><?= e($log['action']) ?></div>
                <div class="text-muted small"><?= e($log['full_name'] ?? 'System') ?> &middot; <?= formatDateTime($log['created_at']) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <?php if ($aiSummary): ?>
    <div class="row g-3 mb-4">
      <div class="col-lg-4">
        <div class="card h-100">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-robot"></i> AI Sentiment Overview</span>
            <a href="<?= e(APP_URL) ?>/modules/feedback/ai_analytics.php" class="small">View Analytics &rarr;</a>
          </div>
          <div class="card-body">
            <div class="d-flex justify-content-around text-center">
              <div><div class="fs-4">🟢 <?= $aiSummary['totals']['Positive'] ?></div><div class="small text-muted">Positive</div></div>
              <div><div class="fs-4">🟡 <?= $aiSummary['totals']['Neutral'] ?></div><div class="small text-muted">Neutral</div></div>
              <div><div class="fs-4">🔴 <?= $aiSummary['totals']['Negative'] ?></div><div class="small text-muted">Negative</div></div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-lg-8">
        <div class="card h-100">
          <div class="card-header"><i class="bi bi-flag text-danger"></i> Feedback Flagged for Review by AI</div>
          <div class="list-group list-group-flush">
            <?php if (empty($aiSummary['flagged'])): ?>
              <div class="list-group-item text-muted small">No feedback currently flagged — nice and calm out there.</div>
            <?php endif; ?>
            <?php foreach ($aiSummary['flagged'] as $fl): ?>
              <a href="<?= e(APP_URL) ?>/modules/feedback/index.php" class="list-group-item list-group-item-action">
                <div class="d-flex justify-content-between">
                  <span class="small fw-semibold"><?= e($fl['name']) ?> — <?= e($fl['subject'] ?: '(no subject)') ?></span>
                  <span class="badge bg-danger">🔴 <?= round($fl['confidence_score']) ?>%</span>
                </div>
                <div class="text-muted small"><?= formatDateTime($fl['analyzed_at']) ?></div>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<?php
$extraJs = [];
include __DIR__ . '/layouts/footer.php';
?>
<script>
// ---- Hearings Trend ----
new Chart(document.getElementById('hearingsTrendChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode(array_column($hearingsTrend, 'ym')) ?>,
    datasets: [{
      label: 'Hearings',
      data: <?= json_encode(array_map('intval', array_column($hearingsTrend, 'total'))) ?>,
      borderColor: '#FFD700',
      backgroundColor: 'rgba(255, 215, 0, 0.08)',
      fill: true,
      tension: 0.35,
      pointBackgroundColor: '#FFD700',
      pointBorderColor: '#ffffff',
      pointBorderWidth: 2
    }]
  },
  options: { 
    plugins: { legend: { display: false } }, 
    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
    responsive: true,
    maintainAspectRatio: false
  }
});

// ---- Hearing Status Doughnut ----
new Chart(document.getElementById('hearingStatusChart'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode(array_column($hearingStatusData, 'status')) ?>,
    datasets: [{
      data: <?= json_encode(array_map('intval', array_column($hearingStatusData, 'total'))) ?>,
      backgroundColor: ['#0a1628', '#0d2137', '#00838f', '#1a365d', '#2d6a9f']
    }]
  },
  options: { 
    plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } },
    responsive: true,
    maintainAspectRatio: false
  }
});

// ---- Issue Priority Pie ----
new Chart(document.getElementById('priorityChart'), {
  type: 'pie',
  data: {
    labels: ['Low', 'Medium', 'High', 'Critical'],
    datasets: [{
      data: [
        <?= (int)$priorityChart['Low'] ?>,
        <?= (int)$priorityChart['Medium'] ?>,
        <?= (int)$priorityChart['High'] ?>,
        <?= (int)$priorityChart['Critical'] ?>
      ],
      backgroundColor: [
        '#00838f',
        '#1a365d',
        '#0d2137',
        '#b91c1c'
      ]
    }]
  },
  options: {
    plugins: {
      legend: {
        position: 'bottom',
        labels: {
          boxWidth: 10,
          font: { size: 11 }
        }
      }
    },
    responsive: true,
    maintainAspectRatio: false
  }
});

// ---- Feedback by Category Bar ----
new Chart(document.getElementById('feedbackChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_column($feedbackCatData, 'name')) ?>,
    datasets: [{
      label: 'Feedback',
      data: <?= json_encode(array_map('intval', array_column($feedbackCatData, 'total'))) ?>,
      backgroundColor: [
        '#0a1628',
        '#0d2137',
        '#122a45',
        '#1a365d',
        '#1e4a7a',
        '#2d6a9f'
      ],
      borderRadius: 6
    }]
  },
  options: { 
    plugins: { legend: { display: false } }, 
    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
    responsive: true,
    maintainAspectRatio: false
  }
});
</script>