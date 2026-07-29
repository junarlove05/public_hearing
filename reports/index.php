<?php
/**
 * reports/index.php
 * ------------------------------------------------------------------
 * Reports hub: one card per report type (Hearings, Attendance,
 * Stakeholders, Feedback, Issues, Actions, Activity Logs). Each links
 * to view.php with the selected date range, where Print/PDF/Excel/CSV
 * are all available.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_ADMIN, ROLE_STAFF]);
require_once __DIR__ . '/report_data.php';

$pageTitle  = 'Reports';
$activeMenu = 'reports';

$pdo = db();
$quickCounts = [
    'hearings'      => (int)$pdo->query('SELECT COUNT(*) FROM hearings')->fetchColumn(),
    'attendance'    => (int)$pdo->query('SELECT COUNT(*) FROM attendance')->fetchColumn(),
    'stakeholders'  => (int)$pdo->query('SELECT COUNT(*) FROM stakeholders')->fetchColumn(),
    'feedback'      => (int)$pdo->query('SELECT COUNT(*) FROM feedback')->fetchColumn(),
    'issues'        => (int)$pdo->query('SELECT COUNT(*) FROM issues')->fetchColumn(),
    'actions'       => (int)$pdo->query('SELECT COUNT(*) FROM actions')->fetchColumn(),
    'activity_logs' => (int)$pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn(),
];

include __DIR__ . '/../layouts/header.php';
?>
<style>
    /* ============================================================
       REPORTS - Coastal Blue Theme
       Colors: Midnight Blue, White, Gold Accents
       ============================================================ */
    :root {
        --rp-midnight-dark: #0A1628;
        --rp-midnight: #0F2137;
        --rp-midnight-blue: #1A3A5C;
        --rp-midnight-soft: #2C5282;
        --rp-midnight-pale: #4A7EB5;
        --rp-midnight-lighter: #6B9BC7;
        --rp-white: #FFFFFF;
        --rp-off-white: #F5F8FA;
        --rp-gray-100: #F1F5F9;
        --rp-gray-200: #E2E8F0;
        --rp-gray-300: #CBD5E1;
        --rp-gray-400: #94A3B8;
        --rp-gray-500: #64748B;
        --rp-gray-600: #475569;
        --rp-gold: #F5C842;
        --rp-gold-light: #F7D95A;
        --rp-gold-dark: #D4A820;
        --rp-emerald: #10B981;
        --rp-rose: #F43F5E;
        --rp-violet: #8B5CF6;
        --rp-cyan: #06B6D4;
        --rp-orange: #F97316;
        --rp-teal: #14B8A6;
        --rp-indigo: #6366F1;
    }

    /* ===== MAIN CONTENT ===== */
    .main-content {
        padding: 0.5rem 1.5rem 1.5rem 1.5rem;
        margin-top: 20px;
        min-height: calc(100vh - 72px);
        background: var(--rp-off-white);
    }

    /* ===== BREADCRUMB BAR ===== */
    .breadcrumb-bar {
        background: var(--rp-white);
        border-left: 4px solid var(--rp-gold);
        box-shadow: 0 2px 15px rgba(10, 22, 40, 0.06);
        padding: 1.25rem 1.75rem;
        border-radius: 16px;
        margin-bottom: 1.5rem;
        position: relative;
        overflow: hidden;
    }

    .breadcrumb-bar::after {
        content: '';
        position: absolute;
        top: 0;
        right: 0;
        width: 200px;
        height: 100%;
        background: linear-gradient(135deg, transparent 0%, rgba(245, 200, 66, 0.05) 100%);
        pointer-events: none;
    }

    .breadcrumb-bar h5 {
        color: var(--rp-midnight);
        font-weight: 800;
        font-size: 1.1rem;
        letter-spacing: -0.3px;
        margin-bottom: 0.1rem;
    }

    .breadcrumb-bar h5 i {
        color: var(--rp-gold);
        background: rgba(245, 200, 66, 0.1);
        padding: 0.4rem;
        border-radius: 10px;
        margin-right: 0.5rem;
    }

    .breadcrumb-bar .text-muted {
        color: var(--rp-gray-500) !important;
        font-weight: 400;
        font-size: 0.85rem;
    }

    /* ===== DATE RANGE CARD ===== */
    .date-range-card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 2px 20px rgba(10, 22, 40, 0.05);
        overflow: hidden;
        background: var(--rp-white);
        border: 1px solid rgba(10, 22, 40, 0.06);
        transition: all 0.3s ease;
        margin-bottom: 1.5rem;
    }

    .date-range-card:hover {
        box-shadow: 0 4px 30px rgba(10, 22, 40, 0.08);
        transform: translateY(-2px);
    }

    .date-range-card .card-body {
        padding: 1.25rem 1.5rem;
        background: var(--rp-white);
    }

    /* ===== FORM CONTROLS ===== */
    .form-label {
        color: var(--rp-midnight);
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 0.4rem;
    }

    .form-control {
        border: 2px solid var(--rp-gray-200);
        border-radius: 10px;
        padding: 0.5rem 1rem;
        font-size: 0.875rem;
        transition: all 0.3s ease;
        background: var(--rp-off-white);
        color: var(--rp-midnight);
        font-weight: 500;
    }

    .form-control:focus {
        border-color: var(--rp-gold);
        box-shadow: 0 0 0 4px rgba(245, 200, 66, 0.12);
        background: var(--rp-white);
    }

    .form-control::placeholder {
        color: var(--rp-gray-400);
        font-weight: 400;
    }

    .form-control-sm {
        font-size: 0.8rem;
        padding: 0.4rem 0.75rem;
    }

    .form-text {
        color: var(--rp-gray-500) !important;
        font-size: 0.8rem;
        margin-top: 0.5rem;
    }

    /* ===== BUTTONS ===== */
    .btn-outline-secondary {
        border: 2px solid var(--rp-gray-200);
        color: var(--rp-gray-600);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        border-radius: 10px;
        font-weight: 500;
        background: transparent;
        padding: 0.4rem 1rem;
        font-size: 0.8rem;
    }

    .btn-outline-secondary:hover {
        background: var(--rp-off-white);
        border-color: var(--rp-gold);
        color: var(--rp-midnight);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(10, 22, 40, 0.05);
    }

    .btn-outline-secondary i {
        color: var(--rp-gold);
    }

    .btn-outline-primary {
        border: 2px solid var(--rp-midnight);
        color: var(--rp-midnight);
        background: transparent;
        border-radius: 10px;
        font-weight: 600;
        padding: 0.5rem 1rem;
        font-size: 0.85rem;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        width: 100%;
        position: relative;
        overflow: hidden;
    }

    .btn-outline-primary::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(245, 200, 66, 0.08), transparent);
        transition: left 0.5s ease;
    }

    .btn-outline-primary:hover::before {
        left: 100%;
    }

    .btn-outline-primary:hover {
        background: linear-gradient(135deg, var(--rp-midnight-dark) 0%, var(--rp-midnight-blue) 100%);
        color: var(--rp-white);
        transform: translateY(-3px);
        box-shadow: 0 8px 25px rgba(10, 22, 40, 0.2);
        border-color: var(--rp-gold);
    }

    .btn-outline-primary i {
        color: var(--rp-gold);
        margin-right: 0.3rem;
        transition: color 0.3s ease;
    }

    .btn-outline-primary:hover i {
        color: var(--rp-white);
    }

    /* ===== REPORT CARDS ===== */
    .report-card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 2px 20px rgba(10, 22, 40, 0.05);
        background: var(--rp-white);
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        overflow: hidden;
        height: 100%;
        border: 1px solid rgba(10, 22, 40, 0.06);
        position: relative;
    }

    .report-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, var(--rp-gold-dark), var(--rp-gold), var(--rp-gold-light));
        opacity: 0;
        transition: opacity 0.3s ease;
    }

    .report-card:hover::before {
        opacity: 1;
    }

    .report-card:hover {
        box-shadow: 0 8px 35px rgba(10, 22, 40, 0.12);
        transform: translateY(-6px);
        border-color: rgba(245, 200, 66, 0.15);
    }

    .report-card .card-body {
        padding: 1.5rem;
        background: var(--rp-white);
        display: flex;
        flex-direction: column;
        height: 100%;
    }

    /* Card Icon Wrapper */
    .report-card .icon-wrapper {
        width: 52px;
        height: 52px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        transition: all 0.3s ease;
        margin-bottom: 0.75rem;
    }

    .report-card:hover .icon-wrapper {
        transform: scale(1.05) rotate(-3deg);
    }

    .report-card .icon-wrapper .bi {
        font-size: 1.8rem;
    }

    /* Icon Colors - Coastal Blue Theme */
    .report-card .icon-wrapper.bg-gold { background: rgba(245, 200, 66, 0.12); }
    .report-card .icon-wrapper.bg-gold .bi { color: var(--rp-gold); }

    .report-card .icon-wrapper.bg-midnight { background: rgba(10, 22, 40, 0.08); }
    .report-card .icon-wrapper.bg-midnight .bi { color: var(--rp-midnight); }

    .report-card .icon-wrapper.bg-midnight-blue { background: rgba(26, 58, 92, 0.10); }
    .report-card .icon-wrapper.bg-midnight-blue .bi { color: var(--rp-midnight-blue); }

    .report-card .icon-wrapper.bg-midnight-soft { background: rgba(44, 82, 130, 0.10); }
    .report-card .icon-wrapper.bg-midnight-soft .bi { color: var(--rp-midnight-soft); }

    .report-card .icon-wrapper.bg-midnight-pale { background: rgba(74, 126, 181, 0.10); }
    .report-card .icon-wrapper.bg-midnight-pale .bi { color: var(--rp-midnight-pale); }

    .report-card .icon-wrapper.bg-cyan { background: rgba(6, 182, 212, 0.12); }
    .report-card .icon-wrapper.bg-cyan .bi { color: var(--rp-cyan); }

    .report-card .icon-wrapper.bg-rose { background: rgba(244, 67, 94, 0.12); }
    .report-card .icon-wrapper.bg-rose .bi { color: var(--rp-rose); }

    .report-card .icon-wrapper.bg-emerald { background: rgba(16, 185, 129, 0.12); }
    .report-card .icon-wrapper.bg-emerald .bi { color: var(--rp-emerald); }

    .report-card .icon-wrapper.bg-orange { background: rgba(249, 115, 22, 0.12); }
    .report-card .icon-wrapper.bg-orange .bi { color: var(--rp-orange); }

    .report-card .icon-wrapper.bg-violet { background: rgba(139, 92, 246, 0.12); }
    .report-card .icon-wrapper.bg-violet .bi { color: var(--rp-violet); }

    .report-card .report-title {
        font-weight: 700;
        color: var(--rp-midnight);
        font-size: 1rem;
        margin-bottom: 0.25rem;
    }

    .report-card .report-count {
        color: var(--rp-gray-500);
        font-size: 0.8rem;
        margin-bottom: 0.75rem;
    }

    .report-card .report-count strong {
        color: var(--rp-midnight);
        font-weight: 700;
    }

    /* ===== RESPONSIVE ===== */
    @media (max-width: 768px) {
        .breadcrumb-bar {
            padding: 1rem 1.25rem;
        }

        .breadcrumb-bar h5 {
            font-size: 0.95rem;
        }

        .date-range-card .card-body {
            padding: 1rem;
        }

        .report-card .card-body {
            padding: 1.25rem;
        }

        .report-card .icon-wrapper {
            width: 44px;
            height: 44px;
        }

        .report-card .icon-wrapper .bi {
            font-size: 1.5rem;
        }
    }

    @media (max-width: 576px) {
        .main-content {
            padding: 0.25rem 0.5rem 0.75rem 0.5rem;
        }

        .breadcrumb-bar {
            padding: 0.75rem 1rem;
        }

        .breadcrumb-bar h5 {
            font-size: 0.85rem;
        }

        .breadcrumb-bar .text-muted {
            font-size: 0.7rem;
        }

        .date-range-card .card-body {
            padding: 0.75rem;
        }

        .report-card .card-body {
            padding: 0.75rem;
        }

        .report-card .icon-wrapper {
            width: 36px;
            height: 36px;
        }

        .report-card .icon-wrapper .bi {
            font-size: 1.2rem;
        }

        .report-card .report-title {
            font-size: 0.85rem;
        }

        .report-card .report-count {
            font-size: 0.7rem;
        }

        .form-control {
            font-size: 0.75rem;
            padding: 0.3rem 0.6rem;
        }

        .btn-outline-secondary,
        .btn-outline-primary {
            font-size: 0.7rem;
            padding: 0.3rem 0.6rem;
        }
    }

    /* ============================================
       MAIN CONTENT - Adjust based on sidebar state
       ============================================ */
    .main-content {
        margin-left: 260px !important;
        transition: margin-left 0.3s ease !important;
        padding: 20px !important;
        min-height: calc(100vh - 72px) !important;
        margin-top: 10px !important;
        width: auto !important;
        max-width: calc(100% - 260px) !important;
    }

    .main-content.sidebar-collapsed {
        margin-left: 72px !important;
        max-width: calc(100% - 72px) !important;
    }

    @media (max-width: 992px) {
        .main-content {
            margin-left: 0 !important;
            max-width: 100% !important;
            padding: 15px !important;
        }
    }
</style>
<div class="app-wrapper">
  <?php include __DIR__ . '/../layouts/sidebar.php'; ?>

  <div class="main-content">
    <!-- ===== BREADCRUMB BAR ===== -->
    <div class="breadcrumb-bar">
      <h5 class="mb-0"><i class="bi bi-file-earmark-bar-graph"></i> Reports</h5>
      <small class="text-muted">Generate, print, and export reports across every module</small>
    </div>

    <!-- ===== DATE RANGE CARD ===== -->
    <div class="date-range-card">
      <div class="card-body">
        <form id="dateRangeForm" class="row g-3 align-items-end">
          <div class="col-md-4">
            <label class="form-label"><i class="bi bi-calendar3"></i> From Date</label>
            <input type="date" class="form-control form-control-sm" name="date_from" id="date_from">
          </div>
          <div class="col-md-4">
            <label class="form-label"><i class="bi bi-calendar3"></i> To Date</label>
            <input type="date" class="form-control form-control-sm" name="date_to" id="date_to">
          </div>
          <div class="col-md-4 d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm flex-grow-1" id="btnClearDates">
              <i class="bi bi-arrow-counterclockwise"></i> Clear Dates
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnApplyDates">
              <i class="bi bi-check2"></i> Apply
            </button>
          </div>
        </form>
        <div class="form-text mt-2">
          <i class="bi bi-info-circle"></i> Set a date range (optional) before opening a report below — it will carry through to the report, print, and every export.
        </div>
      </div>
    </div>

    <!-- ===== REPORT CARDS ===== -->
    <div class="row g-3">
      <?php 
      $iconBgMap = [
          'hearings' => 'bg-gold',
          'attendance' => 'bg-cyan',
          'stakeholders' => 'bg-midnight-pale',
          'feedback' => 'bg-violet',
          'issues' => 'bg-rose',
          'actions' => 'bg-emerald',
          'activity_logs' => 'bg-orange'
      ];
      foreach (reportTypeMeta() as $type => $meta): 
          $bgClass = $iconBgMap[$type] ?? 'bg-gold';
      ?>
        <div class="col-sm-6 col-lg-4 col-xl-3">
          <div class="card report-card">
            <div class="card-body">
              <div class="d-flex align-items-start gap-3">
                <div class="icon-wrapper <?= e($bgClass) ?>">
                  <i class="bi <?= e($meta['icon']) ?>"></i>
                </div>
                <div class="flex-grow-1">
                  <div class="report-title"><?= e($meta['label']) ?></div>
                  <div class="report-count">
                    <strong><?= number_format($quickCounts[$type]) ?></strong> total record(s)
                  </div>
                </div>
              </div>
              <a href="view.php?type=<?= e($type) ?>" class="btn btn-outline-primary btn-sm mt-3 report-link" data-type="<?= e($type) ?>">
                <i class="bi bi-eye"></i> View Report
              </a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php
$extraJs = [APP_URL . '/assets/js/reports.js'];
include __DIR__ . '/../layouts/footer.php';
?>