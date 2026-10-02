<?php
/**
 * modules/attendance/history.php
 * ------------------------------------------------------------------
 * Full attendance history / audit trail across all hearings, backed
 * by the attendance_logs table. Search + filter by hearing, action
 * type, and date range.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireRole([ROLE_ADMIN, ROLE_STAFF, ROLE_COMMITTEE]);

$pageTitle  = 'Attendance History';
$activeMenu = 'attendance';
$pdo = db();

$hearings = $pdo->query('SELECT id, title FROM hearings ORDER BY hearing_date DESC')->fetchAll();
$actions = $pdo->query('SELECT DISTINCT action FROM attendance_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/../../layouts/header.php';
?>
<style>
    /* Attendance History - Dark Cards, Gray Labels, Colored Icons */
    :root {
        --ah-dark-900: #0F172A;
        --ah-dark-800: #1E293B;
        --ah-dark-700: #334155;
        --ah-amber: #F59E0B;
        --ah-amber-light: #FBBF24;
        --ah-white: #FFFFFF;
        --ah-gray-300: #CBD5E1;
        --ah-gray-400: #94A3B8;
        --ah-gray-500: #64748B;
        --ah-gray-600: #475569;
        --ah-emerald: #10B981;
        --ah-rose: #F43F5E;
        --ah-violet: #8B5CF6;
        --ah-cyan: #06B6D4;
        --ah-indigo: #0F2137;
        --ah-orange: #F97316;
        --ah-teal: #14B8A6;
    }

    /* Breadcrumb Bar */
    .breadcrumb-bar {
        background: var(--ah-white);
        border-left: 4px solid var(--ah-amber);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
        padding: 1rem 1.5rem;
        border-radius: 12px;
        margin-bottom: 1.5rem;
    }

    .breadcrumb-bar h5 {
        color: var(--ah-dark-900);
        font-weight: 700;
    }

    .breadcrumb-bar h5 i {
        color: var(--ah-amber);
    }

    .breadcrumb-bar .text-muted {
        color: var(--ah-gray-500) !important;
    }

    .breadcrumb-bar a {
        color: var(--ah-amber) !important;
        text-decoration: none;
        font-weight: 500;
        transition: color 0.3s ease;
    }

    .breadcrumb-bar a:hover {
        color: var(--ah-dark-900) !important;
        text-decoration: underline;
    }

    /* Cards */
    .card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 2px 15px rgba(0, 0, 0, 0.06);
        background: var(--ah-white);
    }

    .card:hover {
        box-shadow: 0 4px 25px rgba(0, 0, 0, 0.1);
    }

    .card-body {
        background: var(--ah-white);
    }

    /* Form Controls */
    .form-control,
    .form-select {
        border: 2px solid var(--ah-gray-300);
        border-radius: 10px;
        padding: 0.5rem 1rem;
        font-size: 0.875rem;
        transition: all 0.3s ease;
        background: #FAFAFA;
        color: var(--ah-dark-900);
        font-weight: 500;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: var(--ah-amber);
        box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.12);
        background: var(--ah-white);
    }

    .form-control::placeholder {
        color: var(--ah-gray-400);
        font-weight: 400;
    }

    .form-control-sm,
    .form-select-sm {
        font-size: 0.8rem;
        padding: 0.4rem 0.75rem;
    }

    /* Table */
    #historyTableWrap {
        background: var(--ah-white);
        border-radius: 16px;
        overflow: hidden;
    }

    .table {
        margin-bottom: 0;
    }

    .table thead th {
        background: linear-gradient(135deg, var(--ah-dark-900) 0%, var(--ah-dark-800) 100%);
        color: var(--ah-white) !important;
        border-bottom: 4px solid var(--ah-amber);
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
        color: var(--ah-amber);
        margin-right: 0.4rem;
        font-size: 0.9rem;
    }

    .table thead th,
    .table thead th *,
    .table thead th span,
    .table thead th div {
        color: var(--ah-white) !important;
    }

    .table tbody td {
        padding: 0.85rem 1.25rem;
        vertical-align: middle;
        color: var(--ah-dark-900);
        border-bottom: 1px solid var(--ah-gray-300);
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

    /* Badges */
    .table .badge {
        font-weight: 600;
        padding: 0.3rem 0.8rem;
        border-radius: 20px;
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .badge.bg-success {
        background: var(--ah-emerald) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
    }

    .badge.bg-warning {
        background: var(--ah-amber) !important;
        color: var(--ah-dark-900);
        box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
    }

    .badge.bg-danger {
        background: var(--ah-rose) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(244, 63, 94, 0.3);
    }

    .badge.bg-info {
        background: var(--ah-cyan) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(6, 182, 212, 0.3);
    }

    .badge.bg-secondary {
        background: var(--ah-gray-500) !important;
        color: white;
    }

    /* Pagination */
    .pagination .page-link {
        color: var(--ah-dark-900);
        border-color: var(--ah-gray-300);
        transition: all 0.3s ease;
        font-weight: 500;
        border-radius: 8px;
        margin: 0 2px;
    }

    .pagination .page-link:hover {
        background: var(--ah-amber);
        color: var(--ah-dark-900);
        border-color: var(--ah-amber);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(245, 158, 11, 0.2);
    }

    .pagination .page-item.active .page-link {
        background: linear-gradient(135deg, var(--ah-dark-900) 0%, var(--ah-dark-800) 100%);
        border-color: var(--ah-amber);
        color: var(--ah-white);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    }

    /* Responsive */
    @media (max-width: 768px) {
        .breadcrumb-bar {
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
</style>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../layouts/sidebar.php'; ?>

  <div class="main-content">
    <div class="breadcrumb-bar d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <a href="index.php" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> Back to Attendance</a>
        <h5 class="mb-0 mt-1"><i class="bi bi-clock-history"></i> Attendance History</h5>
      </div>
    </div>

    <div class="card mb-3 no-print">
      <div class="card-body">
        <div class="row g-2">
          <div class="col-md-3">
            <input type="text" class="form-control form-control-sm" id="searchInput" placeholder="Search stakeholder...">
          </div>
          <div class="col-md-3">
            <select class="form-select form-select-sm" id="hearingFilter">
              <option value="">All Hearings</option>
              <?php foreach ($hearings as $h): ?>
                <option value="<?= (int)$h['id'] ?>"><?= e($h['title']) ?></option>
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
      <div id="historyTableWrap">
        <?php include __DIR__ . '/history_table.php'; ?>
      </div>
    </div>
  </div>
</div>
<?php
$extraJs = [APP_URL . '/assets/js/attendance-history.js'];
include __DIR__ . '/../../layouts/footer.php';
?>