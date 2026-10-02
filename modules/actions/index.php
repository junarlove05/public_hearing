<?php
/**
 * modules/actions/index.php
 * ------------------------------------------------------------------
 * Response & Action Tracking Module - main listing page.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';
requireRole([ROLE_ADMIN, ROLE_STAFF, ROLE_COMMITTEE]);

$pageTitle  = 'Response & Action Tracking';
$activeMenu = 'actions';
$pdo = db();
lphEnsureActionsSchema($pdo);

$issues = $pdo->query("SELECT id, title FROM hearing_issues WHERE status NOT IN ('Closed') ORDER BY created_at DESC")->fetchAll();

// Upcoming-deadline notifications (next 7 days, not yet completed/cancelled).
$upcoming = $pdo->query(
    "SELECT a.id, a.title, a.deadline, a.status, o.name AS office_name,
            DATEDIFF(a.deadline, CURDATE()) AS days_left
     FROM hearing_actions a
     LEFT JOIN offices o ON o.id = a.assigned_office_id
     WHERE a.deadline IS NOT NULL AND a.deadline BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
       AND a.status NOT IN ('Completed', 'Cancelled')
     ORDER BY a.deadline ASC LIMIT 6"
)->fetchAll();

include __DIR__ . '/../../layouts/header.php';
?>
<style>
    /* Actions - Dark Cards, Gray Labels, Colored Icons */
    :root {
        --ac-dark-900: #0F172A;
        --ac-dark-800: #1E293B;
        --ac-dark-700: #334155;
        --ac-amber: #F59E0B;
        --ac-amber-light: #FBBF24;
        --ac-white: #FFFFFF;
        --ac-gray-100: #F1F5F9;
        --ac-gray-200: #E2E8F0;
        --ac-gray-300: #CBD5E1;
        --ac-gray-400: #94A3B8;
        --ac-gray-500: #64748B;
        --ac-gray-600: #475569;
        --ac-emerald: #10B981;
        --ac-rose: #F43F5E;
        --ac-violet: #8B5CF6;
        --ac-cyan: #06B6D4;
        --ac-orange: #F97316;
        --ac-teal: #14B8A6;
        --ac-indigo: #0F2137;
    }

    /* Breadcrumb Bar */
    .breadcrumb-bar {
        background: var(--ac-white);
        border-left: 4px solid var(--ac-amber);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
        padding: 1rem 1.5rem;
        border-radius: 12px;
        margin-bottom: 1.5rem;
    }

    .breadcrumb-bar h5 {
        color: var(--ac-dark-900);
        font-weight: 700;
    }

    .breadcrumb-bar h5 i {
        color: var(--ac-amber);
    }

    .breadcrumb-bar .text-muted {
        color: var(--ac-gray-500) !important;
    }

    /* Alert - Upcoming Deadlines Premium Card */
    .upcoming-deadlines-card {
        background: linear-gradient(135deg, #FFFDF5 0%, #FEF9C3 100%);
        border: 1px solid #FDE047;
        border-radius: 14px;
        padding: 1rem 1.25rem;
        box-shadow: 0 2px 10px rgba(245, 158, 11, 0.08);
        position: relative;
        overflow: hidden;
    }

    .upcoming-deadlines-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
        background: linear-gradient(to bottom, #F59E0B, #D97706);
    }

    .deadline-icon-pill {
        width: 36px;
        height: 36px;
        background: #FEF3C7;
        color: #D97706;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        box-shadow: 0 2px 6px rgba(217, 119, 6, 0.15);
    }

    .bg-amber-subtle {
        background: #FEF3C7 !important;
    }
    .text-amber {
        color: #B45309 !important;
    }

    .upcoming-deadlines-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
        gap: 0.75rem;
        margin-top: 0.5rem;
    }

    .deadline-item-card {
        background: #FFFFFF;
        border: 1px solid #FDE68A;
        border-radius: 10px;
        padding: 0.75rem 0.9rem;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        text-decoration: none !important;
        transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
        position: relative;
    }

    .deadline-item-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(217, 119, 6, 0.14);
        border-color: #F59E0B;
    }

    .deadline-item-card.due-urgent {
        border-left: 3.5px solid #EF4444;
    }

    .deadline-item-card.due-soon {
        border-left: 3.5px solid #F59E0B;
    }

    .deadline-item-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        margin-bottom: 0.4rem;
    }

    .deadline-badge {
        font-size: 0.7rem;
        font-weight: 700;
        padding: 0.2rem 0.55rem;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .badge-urgent {
        background: #FEE2E2;
        color: #B91C1C;
    }

    .badge-soon {
        background: #FEF3C7;
        color: #92400E;
    }

    .deadline-date-text {
        font-size: 0.75rem;
        color: #64748B;
        font-weight: 500;
    }

    .deadline-item-title {
        font-size: 0.85rem;
        font-weight: 600;
        color: #1E293B;
        line-height: 1.35;
        margin-bottom: 0.5rem;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .deadline-item-card:hover .deadline-item-title {
        color: #B45309;
    }

    .deadline-item-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-size: 0.75rem;
        padding-top: 0.4rem;
        border-top: 1px dashed #F1F5F9;
        margin-top: auto;
    }

    .deadline-office {
        color: #64748B;
        font-weight: 500;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 65%;
    }

    .deadline-status-pill {
        font-size: 0.68rem;
        font-weight: 600;
        padding: 0.15rem 0.5rem;
        border-radius: 4px;
    }

    .deadline-status-pill.status-pending {
        background: #FEF3C7;
        color: #92400E;
    }

    .deadline-status-pill.status-in-progress,
    .deadline-status-pill.status-on-going {
        background: #DBEAFE;
        color: #1E40AF;
    }

    /* Buttons */
    .btn-primary {
        background: linear-gradient(135deg, var(--ac-dark-900) 0%, var(--ac-dark-800) 100%);
        border: 1px solid rgba(245, 158, 11, 0.15);
        color: var(--ac-white);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        border-radius: 10px;
        font-weight: 600;
        padding: 0.45rem 1.25rem;
        transition: all 0.3s ease;
    }

    .btn-primary i {
        color: var(--ac-amber);
    }

    .btn-primary:hover {
        border-color: var(--ac-amber);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.25);
        color: var(--ac-white);
        transform: translateY(-2px);
    }

    .btn-outline-secondary {
        border: 2px solid var(--ac-gray-200);
        color: var(--ac-gray-600);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        border-radius: 10px;
        font-weight: 500;
        background: transparent;
        padding: 0.4rem 1rem;
    }

    .btn-outline-secondary:hover {
        background: var(--ac-gray-100);
        border-color: var(--ac-amber);
        color: var(--ac-dark-900);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    }

    .btn-outline-secondary i {
        color: var(--ac-amber);
    }

    /* Cards */
    .card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 2px 15px rgba(0, 0, 0, 0.06);
        background: var(--ac-white);
        transition: all 0.3s ease;
        overflow: hidden;
    }

    .card:hover {
        box-shadow: 0 4px 25px rgba(0, 0, 0, 0.1);
    }

    .card-body {
        padding: 1.25rem 1.5rem;
        background: var(--ac-white);
    }

    /* Form Controls */
    .form-control,
    .form-select {
        border: 2px solid var(--ac-gray-200);
        border-radius: 10px;
        padding: 0.5rem 1rem;
        font-size: 0.875rem;
        transition: all 0.3s ease;
        background: var(--ac-gray-100);
        color: var(--ac-dark-900);
        font-weight: 500;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: var(--ac-amber);
        box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.12);
        background: var(--ac-white);
    }

    .form-control::placeholder {
        color: var(--ac-gray-400);
        font-weight: 400;
    }

    .form-control-sm,
    .form-select-sm {
        font-size: 0.8rem;
        padding: 0.4rem 0.75rem;
    }

    /* Table */
    #actionsTableWrap {
        background: var(--ac-white);
        border-radius: 16px;
        overflow: hidden;
    }

    .table {
        margin-bottom: 0;
    }

    .table thead th {
        background: linear-gradient(135deg, var(--ac-dark-900) 0%, var(--ac-dark-800) 100%);
        color: var(--ac-white) !important;
        border-bottom: 4px solid var(--ac-amber);
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
        color: var(--ac-amber);
        margin-right: 0.4rem;
        font-size: 0.9rem;
    }

    .table thead th,
    .table thead th *,
    .table thead th span,
    .table thead th div {
        color: var(--ac-white) !important;
    }

    .table tbody td {
        padding: 0.85rem 1.25rem;
        vertical-align: middle;
        color: var(--ac-dark-900);
        border-bottom: 1px solid var(--ac-gray-200);
        font-size: 0.9rem;
        transition: background 0.2s ease;
    }

    .table tbody tr {
        transition: background-color 0.15s ease;
    }

    .table tbody tr:hover {
        background: #FFFBEB;
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
        background: var(--ac-emerald) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
    }

    .badge.bg-warning {
        background: var(--ac-amber) !important;
        color: var(--ac-dark-900);
        box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
    }

    .badge.bg-danger {
        background: var(--ac-rose) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(244, 63, 94, 0.3);
    }

    .badge.bg-info {
        background: var(--ac-cyan) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(6, 182, 212, 0.3);
    }

    .badge.bg-secondary {
        background: var(--ac-gray-500) !important;
        color: white;
    }

    /* Status Badges */
    .badge.bg-status-pending {
        background: var(--ac-amber) !important;
        color: var(--ac-dark-900);
        box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
    }

    .badge.bg-status-ongoing {
        background: var(--ac-cyan) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(6, 182, 212, 0.3);
    }

    .badge.bg-status-completed {
        background: var(--ac-emerald) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
    }

    .badge.bg-status-cancelled {
        background: var(--ac-gray-500) !important;
        color: white;
    }

    /* Modal */
    .modal-content {
        border: none;
        border-radius: 20px;
        box-shadow: 0 25px 70px rgba(0, 0, 0, 0.25);
        overflow: hidden;
        border: 1px solid var(--ac-gray-200);
    }

    .modal-header {
        background: linear-gradient(135deg, var(--ac-dark-900) 0%, var(--ac-dark-800) 100%);
        color: var(--ac-white);
        padding: 1.25rem 1.75rem;
        border-bottom: 4px solid var(--ac-amber);
    }

    .modal-header .modal-title {
        color: var(--ac-white);
        font-weight: 700;
    }

    .modal-header .modal-title i {
        color: var(--ac-amber);
        margin-right: 0.6rem;
        background: rgba(245, 158, 11, 0.15);
        padding: 0.3rem 0.5rem;
        border-radius: 8px;
    }

    .modal-header .btn-close {
        filter: brightness(0) invert(1);
        opacity: 0.7;
        transition: all 0.3s ease;
    }

    .modal-header .btn-close:hover {
        opacity: 1;
        transform: rotate(90deg);
    }

    .modal-body {
        padding: 1.75rem;
        background: var(--ac-gray-100);
    }

    .modal-footer {
        background: var(--ac-white);
        padding: 1rem 1.75rem;
        border-top: 1px solid var(--ac-gray-200);
    }

    .modal-footer .btn-secondary {
        background: var(--ac-gray-200);
        border: none;
        color: var(--ac-dark-900);
        border-radius: 10px;
        padding: 0.5rem 1.5rem;
        font-weight: 500;
        transition: all 0.3s ease;
    }

    .modal-footer .btn-secondary:hover {
        background: var(--ac-gray-300);
        transform: translateY(-2px);
    }

    .modal-body .form-label {
        font-weight: 600;
        color: var(--ac-dark-900);
        font-size: 0.85rem;
    }

    .modal-body .form-label .text-danger {
        color: var(--ac-rose);
    }

    .modal-body .form-label .text-muted {
        color: var(--ac-gray-500) !important;
    }

    /* Action buttons */
    .btn-action {
        padding: 0.25rem 0.6rem;
        border-radius: 8px;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        border: none;
        font-size: 0.85rem;
        margin: 0 0.15rem;
    }

    .btn-action:hover {
        transform: scale(1.15);
    }

    .btn-action.edit {
        color: var(--ac-amber);
        background: rgba(245, 158, 11, 0.08);
    }

    .btn-action.edit:hover {
        background: var(--ac-amber);
        color: var(--ac-dark-900);
        box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
    }

    .btn-action.delete {
        color: var(--ac-rose);
        background: rgba(244, 63, 94, 0.08);
    }

    .btn-action.delete:hover {
        background: var(--ac-rose);
        color: white;
        box-shadow: 0 4px 12px rgba(244, 63, 94, 0.3);
    }

    .btn-action.view {
        color: var(--ac-cyan);
        background: rgba(6, 182, 212, 0.08);
    }

    .btn-action.view:hover {
        background: var(--ac-cyan);
        color: white;
        box-shadow: 0 4px 12px rgba(6, 182, 212, 0.3);
    }

    /* Pagination */
    .pagination .page-link {
        color: var(--ac-dark-900);
        border-color: var(--ac-gray-200);
        transition: all 0.3s ease;
        font-weight: 500;
        border-radius: 8px;
        margin: 0 2px;
    }

    .pagination .page-link:hover {
        background: var(--ac-amber);
        color: var(--ac-dark-900);
        border-color: var(--ac-amber);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(245, 158, 11, 0.2);
    }

    .pagination .page-item.active .page-link {
        background: linear-gradient(135deg, var(--ac-dark-900) 0%, var(--ac-dark-800) 100%);
        border-color: var(--ac-amber);
        color: var(--ac-white);
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
        .modal-body {
            padding: 1.25rem;
        }
        .alert-warning {
            flex-direction: column;
            gap: 0.5rem;
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
        .btn-action {
            padding: 0.15rem 0.4rem;
            font-size: 0.7rem;
        }
    }
    /* ============================================
   MAIN CONTENT - Adjust based on sidebar state
   ============================================ */

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

body.sidebar-collapsed .main-content,
body.sidebar-collapsed .orlms-main-content,
.main-content.sidebar-collapsed {
    margin-left: 74px !important;
}

@media (max-width: 1050px) {
    .main-content,
    .orlms-main-content {
        margin-left: 0 !important;
        padding-left: 1rem !important;
        padding-right: 1rem !important;
    }
}
</style>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../layouts/sidebar.php'; ?>

  <div class="main-content">
    <?php include __DIR__ . '/../../layouts/top_controls.php'; ?>
    <div class="breadcrumb-bar d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <h5 class="mb-0"><i class="bi bi-list-check"></i> Response &amp; Action Tracking</h5>
        <small class="text-muted">Track office responses and remediation actions for logged issues</small>
      </div>
      <div class="d-flex gap-2 no-print">
        <a href="report.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-bar-chart"></i> Reports</a>
        <a href="print.php" target="_blank" id="printActionsLink" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer"></i> Print</a>
        <?php if (canManage()): ?>
        <button type="button" class="btn btn-primary btn-sm" id="btnAddAction"><i class="bi bi-plus-circle"></i> Create Action</button>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($upcoming)): ?>
    <div class="upcoming-deadlines-card mb-3 no-print">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2 pb-2 border-bottom border-warning-subtle">
        <div class="d-flex align-items-center gap-2">
          <div class="deadline-icon-pill">
            <i class="bi bi-bell-fill"></i>
          </div>
          <div>
            <h6 class="mb-0 fw-bold text-dark d-flex align-items-center gap-2">
              Upcoming Deadlines
              <span class="badge rounded-pill bg-amber-subtle text-amber fw-semibold"><?= count($upcoming) ?> due soon</span>
            </h6>
            <small class="text-muted">Mga aksyon na kailangang matapos sa susunod na 7 araw</small>
          </div>
        </div>
      </div>

      <div class="upcoming-deadlines-grid">
        <?php foreach ($upcoming as $u): 
          $days = (int)($u['days_left'] ?? 0);
          $dueText = match($days) {
              0 => 'Due Today',
              1 => 'Due Tomorrow',
              default => "Due in {$days} days"
          };
          $urgencyClass = $days <= 2 ? 'due-urgent' : 'due-soon';
          $statusSlug = strtolower(str_replace(' ', '-', (string)$u['status']));
        ?>
          <a href="view.php?id=<?= (int)$u['id'] ?>" class="deadline-item-card <?= $urgencyClass ?>">
            <div class="deadline-item-header">
              <span class="deadline-badge <?= $days <= 2 ? 'badge-urgent' : 'badge-soon' ?>">
                <i class="bi bi-clock-history me-1"></i> <?= $dueText ?>
              </span>
              <span class="deadline-date-text">
                <i class="bi bi-calendar3 me-1"></i> <?= formatDate($u['deadline']) ?>
              </span>
            </div>
            <div class="deadline-item-title">
              <?= e($u['title']) ?>
            </div>
            <div class="deadline-item-footer">
              <span class="deadline-office" title="<?= e($u['office_name'] ?: 'Assigned Office') ?>">
                <i class="bi bi-building me-1"></i> <?= e($u['office_name'] ?: 'Assigned Office') ?>
              </span>
              <span class="deadline-status-pill status-<?= e($statusSlug) ?>">
                <?= e($u['status']) ?>
              </span>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card mb-3 no-print">
      <div class="card-body">
        <form id="filterForm" class="row g-2">
          <div class="col-md-5">
            <input type="text" class="form-control form-control-sm" name="search" id="searchInput" placeholder="Search title or description...">
          </div>
          <div class="col-md-3">
            <select class="form-select form-select-sm" name="status">
              <option value="">All Status</option>
              <?php foreach (['Pending', 'On Going', 'Completed', 'Cancelled'] as $s): ?>
                <option value="<?= e($s) ?>"><?= e($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <select class="form-select form-select-sm" name="issue_id">
              <option value="">All Issues</option>
              <?php foreach ($issues as $i): ?>
                <option value="<?= (int)$i['id'] ?>"><?= e($i['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>
      </div>
    </div>

    <div class="card">
      <div id="actionsTableWrap">
        <?php include __DIR__ . '/table.php'; ?>
      </div>
    </div>
  </div>
</div>

<?php if (canManage()): ?>
<div class="modal fade" id="actionModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: 820px;">
    <div class="modal-content border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
      <form id="actionForm" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="id" id="ac_id" value="0">
        <div class="modal-header py-3 px-4 bg-light border-bottom">
          <div class="d-flex align-items-center gap-2.5">
            <div class="rounded-circle d-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary" style="width: 36px; height: 36px;">
              <i class="bi bi-check2-circle fs-5"></i>
            </div>
            <div>
              <h5 class="modal-title fw-bold text-dark mb-0" id="actionModalTitle" style="font-size: 1.05rem;">Create Action</h5>
              <small class="text-muted" style="font-size: 0.8rem;">Track follow-up task, deadline, and assigned personnel</small>
            </div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label small fw-semibold text-secondary mb-1">Title <span class="text-danger">*</span></label>
              <input type="text" name="title" id="ac_title" class="form-control" placeholder="Action task or directive title" required maxlength="255">
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold text-secondary mb-1">Description</label>
              <textarea name="description" id="ac_description" class="form-control" rows="3" placeholder="Action task directives and details..."></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Linked Issue</label>
              <select name="issue_id" id="ac_issue" class="form-select">
                <option value="">-- None --</option>
                <?php foreach ($issues as $i): ?>
                  <option value="<?= (int)$i['id'] ?>"><?= e($i['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Deadline</label>
              <input type="date" name="deadline" id="ac_deadline" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Status</label>
              <select name="status" id="ac_status" class="form-select">
                <?php foreach (['Pending', 'On Going', 'Completed', 'Cancelled'] as $s): ?>
                  <option value="<?= e($s) ?>"><?= e($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Assign Office <span class="text-muted small">(optional)</span></label>
              <input type="text" name="assigned_office" id="ac_office" class="form-control" placeholder="e.g. Budget Office">
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold text-secondary mb-1">Attach Documents</label>
              <input type="file" name="documents[]" class="form-control" multiple accept=".pdf,.doc,.docx,.png,.jpg,.jpeg">
            </div>
          </div>
        </div>
        <div class="modal-footer py-2.5 px-4 bg-light border-top d-flex justify-content-end gap-2">
          <button type="button" class="btn btn-light border px-3" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary px-4 fw-semibold shadow-sm"><i class="bi bi-check-circle me-1"></i> Save Action</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$extraJs = [APP_URL . '/assets/js/actions.js'];
include __DIR__ . '/../../layouts/footer.php';
?>