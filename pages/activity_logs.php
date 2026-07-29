<?php
/**
 * pages/activity_logs.php
 * ------------------------------------------------------------------
 * Full activity log / audit trail viewer. Administrator-only (see
 * sidebar.php and requireRole below). Every Login, Logout, Insert,
 * Update, Delete, Upload, Download, Print, and Export action across
 * the whole system funnels into activity_logs via includes/activity_log.php,
 * and this page is the searchable/filterable window onto that data.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_ADMIN]);

$pageTitle  = 'Activity Logs';
$activeMenu = 'activity_logs';
$pdo = db();

$users = $pdo->query('SELECT id, full_name FROM users ORDER BY full_name')->fetchAll();
$actions = $pdo->query('SELECT DISTINCT action FROM activity_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);

$totalLogs = (int)$pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();
$todayLogs = (int)$pdo->query('SELECT COUNT(*) FROM activity_logs WHERE DATE(created_at) = CURDATE()')->fetchColumn();

include __DIR__ . '/../layouts/header.php';
?>
<style>
    /* Activity Logs - Dark Theme */
    :root {
        --al-dark-900: #0F172A;
        --al-dark-800: #1E293B;
        --al-dark-700: #334155;
        --al-amber: #F59E0B;
        --al-amber-light: #FBBF24;
        --al-white: #FFFFFF;
        --al-gray-100: #F1F5F9;
        --al-gray-200: #E2E8F0;
        --al-gray-300: #CBD5E1;
        --al-gray-400: #94A3B8;
        --al-gray-500: #64748B;
        --al-gray-600: #475569;
        --al-emerald: #10B981;
        --al-rose: #F43F5E;
        --al-violet: #8B5CF6;
        --al-cyan: #06B6D4;
        --al-orange: #F97316;
        --al-teal: #14B8A6;
        --al-indigo: #6366F1;
    }

    /* Breadcrumb Bar */
    .breadcrumb-bar {
        background: var(--al-white);
        border-left: 4px solid var(--al-amber);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
        padding: 1rem 1.5rem;
        border-radius: 12px;
        margin-bottom: 1.5rem;
    }

    .breadcrumb-bar h5 {
        color: var(--al-dark-900);
        font-weight: 700;
    }

    .breadcrumb-bar h5 i {
        color: var(--al-amber);
    }

    .breadcrumb-bar .text-muted {
        color: var(--al-gray-500) !important;
    }

    /* Buttons */
    .btn-outline-secondary {
        border: 2px solid var(--al-gray-200);
        color: var(--al-gray-600);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        border-radius: 10px;
        font-weight: 500;
        background: transparent;
        padding: 0.35rem 0.9rem;
        font-size: 0.8rem;
    }

    .btn-outline-secondary:hover {
        background: var(--al-gray-100);
        border-color: var(--al-amber);
        color: var(--al-dark-900);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    }

    .btn-outline-secondary i {
        color: var(--al-amber);
    }

    /* Cards */
    .card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 2px 15px rgba(0, 0, 0, 0.06);
        background: var(--al-white);
        transition: all 0.3s ease;
        overflow: hidden;
    }

    .card:hover {
        box-shadow: 0 4px 25px rgba(0, 0, 0, 0.1);
    }

    .card-body {
        padding: 1.25rem 1.5rem;
        background: var(--al-white);
    }

    /* Form Controls */
    .form-control,
    .form-select {
        border: 2px solid var(--al-gray-200);
        border-radius: 10px;
        padding: 0.5rem 1rem;
        font-size: 0.875rem;
        transition: all 0.3s ease;
        background: var(--al-gray-100);
        color: var(--al-dark-900);
        font-weight: 500;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: var(--al-amber);
        box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.12);
        background: var(--al-white);
    }

    .form-control::placeholder {
        color: var(--al-gray-400);
        font-weight: 400;
    }

    .form-control-sm,
    .form-select-sm {
        font-size: 0.8rem;
        padding: 0.4rem 0.75rem;
    }

    /* Table */
    #logsTableWrap {
        background: var(--al-white);
        border-radius: 16px;
        overflow: hidden;
    }

    .table {
        margin-bottom: 0;
    }

    .table thead th {
        background: linear-gradient(135deg, var(--al-dark-900) 0%, var(--al-dark-800) 100%);
        color: var(--al-white) !important;
        border-bottom: 4px solid var(--al-amber);
        font-weight: 600;
        padding: 0.85rem 1.25rem;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        border-color: transparent;
        position: sticky;
        top: 0;
        z-index: 10;
    }

    .table thead th i {
        color: var(--al-amber);
        margin-right: 0.4rem;
        font-size: 0.9rem;
    }

    .table thead th,
    .table thead th *,
    .table thead th span,
    .table thead th div {
        color: var(--al-white) !important;
    }

    .table tbody td {
        padding: 0.85rem 1.25rem;
        vertical-align: middle;
        color: var(--al-dark-900);
        border-bottom: 1px solid var(--al-gray-200);
        font-size: 0.9rem;
        transition: background 0.2s ease;
    }

    .table tbody tr {
        transition: all 0.2s ease;
    }

    .table tbody tr:hover {
        background: #FFFBEB;
        transform: scale(1.002);
    }

    .table tbody tr:last-child td {
        border-bottom: none;
    }

    /* Action badges in table */
    .table .badge {
        font-weight: 600;
        padding: 0.3rem 0.8rem;
        border-radius: 20px;
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .badge.bg-success {
        background: var(--al-emerald) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
    }

    .badge.bg-warning {
        background: var(--al-amber) !important;
        color: var(--al-dark-900);
        box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
    }

    .badge.bg-danger {
        background: var(--al-rose) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(244, 63, 94, 0.3);
    }

    .badge.bg-info {
        background: var(--al-cyan) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(6, 182, 212, 0.3);
    }

    .badge.bg-secondary {
        background: var(--al-gray-500) !important;
        color: white;
    }

    .badge.bg-primary {
        background: var(--al-indigo) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(99, 102, 241, 0.3);
    }

    .badge.bg-dark {
        background: var(--al-dark-700) !important;
        color: white;
    }

    /* Pagination */
    .pagination .page-link {
        color: var(--al-dark-900);
        border-color: var(--al-gray-200);
        transition: all 0.3s ease;
        font-weight: 500;
        border-radius: 8px;
        margin: 0 2px;
    }

    .pagination .page-link:hover {
        background: var(--al-amber);
        color: var(--al-dark-900);
        border-color: var(--al-amber);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(245, 158, 11, 0.2);
    }

    .pagination .page-item.active .page-link {
        background: linear-gradient(135deg, var(--al-dark-900) 0%, var(--al-dark-800) 100%);
        border-color: var(--al-amber);
        color: var(--al-white);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    }

    /* Responsive */
    @media (max-width: 768px) {
        .breadcrumb-bar {
            flex-direction: column;
            gap: 0.5rem;
            align-items: flex-start;
            padding: 1rem;
        }
        .table thead th,
        .table tbody td {
            padding: 0.6rem 0.8rem;
            font-size: 0.8rem;
        }
    }

    @media (max-width: 576px) {
        .breadcrumb-bar h5 {
            font-size: 0.95rem;
        }
        .breadcrumb-bar .text-muted {
            font-size: 0.75rem;
        }
        .card-body {
            padding: 0.75rem;
        }
        .form-control,
        .form-select {
            font-size: 0.75rem;
            padding: 0.3rem 0.6rem;
        }
        .table thead th,
        .table tbody td {
            padding: 0.4rem 0.6rem;
            font-size: 0.7rem;
        }
    }
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
  <?php include __DIR__ . '/../layouts/sidebar.php'; ?>

  <div class="main-content">
    <div class="breadcrumb-bar d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <h5 class="mb-0"><i class="bi bi-clock-history"></i> Activity Logs</h5>
        <small class="text-muted"><?= $totalLogs ?> total entries &middot; <?= $todayLogs ?> today</small>
      </div>
      <div class="d-flex gap-2 no-print">
        <a href="<?= e(APP_URL) ?>/reports/export_pdf.php?type=activity_logs" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
        <a href="<?= e(APP_URL) ?>/reports/export_excel.php?type=activity_logs" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-excel"></i> Excel</a>
        <a href="<?= e(APP_URL) ?>/reports/export_csv.php?type=activity_logs" class="btn btn-outline-secondary btn-sm"><i class="bi bi-filetype-csv"></i> CSV</a>
      </div>
    </div>

    <div class="card mb-3 no-print">
      <div class="card-body">
        <div class="row g-2">
          <div class="col-md-3">
            <input type="text" class="form-control form-control-sm" id="searchInput" placeholder="Search details or user...">
          </div>
          <div class="col-md-3">
            <select class="form-select form-select-sm" id="userFilter">
              <option value="">All Users</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= e($u['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <select class="form-select form-select-sm" id="actionFilter">
              <option value="">All Actions</option>
              <?php foreach ($actions as $a): ?>
                <option value="<?= e($a) ?>"><?= e($a) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <input type="date" class="form-control form-control-sm" id="dateFrom" title="From date">
          </div>
          <div class="col-md-2">
            <input type="date" class="form-control form-control-sm" id="dateTo" title="To date">
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div id="logsTableWrap">
        <?php include __DIR__ . '/activity_logs_table.php'; ?>
      </div>
    </div>
  </div>
</div>
<?php
$extraJs = [APP_URL . '/assets/js/activity-logs.js'];
include __DIR__ . '/../layouts/footer.php';
?>