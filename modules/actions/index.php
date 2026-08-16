<?php
/**
 * modules/actions/index.php
 * ------------------------------------------------------------------
 * Response & Action Tracking Module - main listing page.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireRole([ROLE_ADMIN, ROLE_STAFF, ROLE_COMMITTEE]);

$pageTitle  = 'Response & Action Tracking';
$activeMenu = 'actions';
$pdo = db();

$issues = $pdo->query("SELECT id, title FROM hearing_issues WHERE status NOT IN ('Closed') ORDER BY created_at DESC")->fetchAll();

// Upcoming-deadline notifications (next 7 days, not yet completed/cancelled).
$upcoming = $pdo->query(
    "SELECT id, title, deadline, status FROM hearing_actions
     WHERE deadline IS NOT NULL AND deadline BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
     AND status NOT IN ('Completed', 'Cancelled')
     ORDER BY deadline ASC LIMIT 5"
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
        --ac-indigo: #6366F1;
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

    /* Alert - Upcoming Deadlines */
    .alert-warning {
        background: #FFFBEB;
        color: #92400E;
        border: none;
        border-left: 4px solid var(--ac-amber);
        border-radius: 12px;
        padding: 0.75rem 1.25rem;
    }

    .alert-warning i {
        color: var(--ac-amber);
    }

    .alert-warning a {
        color: var(--ac-amber) !important;
        font-weight: 500;
        transition: color 0.3s ease;
    }

    .alert-warning a:hover {
        color: var(--ac-dark-900) !important;
        text-decoration: underline;
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
  <?php include __DIR__ . '/../../layouts/sidebar.php'; ?>

  <div class="main-content">
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
    <div class="alert alert-warning d-flex align-items-start gap-2 no-print">
      <i class="bi bi-bell fs-5"></i>
      <div>
        <strong>Upcoming Deadlines:</strong>
        <?php foreach ($upcoming as $u): ?>
          <a href="view.php?id=<?= (int)$u['id'] ?>" class="ms-2 text-decoration-none"><?= e($u['title']) ?> (due <?= formatDate($u['deadline']) ?>)</a><?= $u !== end($upcoming) ? ',' : '' ?>
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
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form id="actionForm" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="id" id="ac_id" value="0">
        <div class="modal-header">
          <h5 class="modal-title" id="actionModalTitle"><i class="bi bi-plus-circle"></i> Create Action</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Title <span class="text-danger">*</span></label>
              <input type="text" name="title" id="ac_title" class="form-control" required maxlength="255">
            </div>
            <div class="col-12">
              <label class="form-label">Description</label>
              <textarea name="description" id="ac_description" class="form-control" rows="3"></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label">Linked Issue</label>
              <select name="issue_id" id="ac_issue" class="form-select">
                <option value="">-- None --</option>
                <?php foreach ($issues as $i): ?>
                  <option value="<?= (int)$i['id'] ?>"><?= e($i['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Deadline</label>
              <input type="date" name="deadline" id="ac_deadline" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">Status</label>
              <select name="status" id="ac_status" class="form-select">
                <?php foreach (['Pending', 'On Going', 'Completed', 'Cancelled'] as $s): ?>
                  <option value="<?= e($s) ?>"><?= e($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Assign Office <span class="text-muted small">(optional)</span></label>
              <input type="text" name="assigned_office" id="ac_office" class="form-control" placeholder="e.g. Budget Office">
            </div>
            <div class="col-12">
              <label class="form-label">Attach Documents</label>
              <input type="file" name="documents[]" class="form-control" multiple accept=".pdf,.doc,.docx,.png,.jpg,.jpeg">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Save Action</button>
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