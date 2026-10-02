<?php
/**
 * modules/issues/index.php
 * ------------------------------------------------------------------
 * Issue Logging Module - main listing page.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireRole([ROLE_ADMIN, ROLE_STAFF, ROLE_COMMITTEE]);

$pageTitle  = 'Issue Logging';
$activeMenu = 'issues';
$pdo = db();

$categories = $pdo->query(
    'SELECT id, name
     FROM hearing_issue_categories
     ORDER BY name'
)->fetchAll();

$hearings = $pdo->query(
    'SELECT id, title
     FROM hearings
     ORDER BY hearing_date DESC, hearing_time DESC'
)->fetchAll();

$offices = $pdo->query(
    "SELECT id, name
     FROM offices
     WHERE status = 'Active'
     ORDER BY name"
)->fetchAll();

// Assignable users: strictly staff/committee members, excluding Administrators and Public users
$assignableUsers = $pdo->query(
    "SELECT u.id, u.full_name, u.username, u.email, r.name AS role_name, o.name AS office_name, u.office_id
     FROM users u
     LEFT JOIN roles r ON r.id = u.role_id
     LEFT JOIN offices o ON o.id = u.office_id
     WHERE u.deleted_at IS NULL AND u.status = 'Active'
       AND LOWER(COALESCE(r.name, '')) NOT LIKE '%admin%'
       AND LOWER(COALESCE(r.name, '')) NOT LIKE '%public%'
       AND LOWER(COALESCE(r.name, '')) NOT LIKE '%stakeholder%'
       AND LOWER(COALESCE(u.username, '')) != 'admin'
       AND u.id != 1
     ORDER BY u.full_name ASC"
)->fetchAll();

include __DIR__ . '/../../layouts/header.php';
?>
<style>
    /* Issues - Dark Cards, Gray Labels, Colored Icons */
    :root {
        --is-dark-900: #0F172A;
        --is-dark-800: #1E293B;
        --is-dark-700: #334155;
        --is-amber: #F59E0B;
        --is-amber-light: #FBBF24;
        --is-white: #FFFFFF;
        --is-gray-100: #F1F5F9;
        --is-gray-200: #E2E8F0;
        --is-gray-300: #CBD5E1;
        --is-gray-400: #94A3B8;
        --is-gray-500: #64748B;
        --is-gray-600: #475569;
        --is-emerald: #10B981;
        --is-rose: #F43F5E;
        --is-violet: #8B5CF6;
        --is-cyan: #06B6D4;
        --is-orange: #F97316;
        --is-teal: #14B8A6;
        --is-indigo: #0F2137;
    }

    /* Breadcrumb Bar */
    .breadcrumb-bar {
        background: var(--is-white);
        border-left: 4px solid var(--is-amber);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
        padding: 1rem 1.5rem;
        border-radius: 12px;
        margin-bottom: 1.5rem;
    }

    .breadcrumb-bar h5 {
        color: var(--is-dark-900);
        font-weight: 700;
    }

    .breadcrumb-bar h5 i {
        color: var(--is-amber);
    }

    .breadcrumb-bar .text-muted {
        color: var(--is-gray-500) !important;
    }

    /* Buttons */
    .btn-primary {
        background: linear-gradient(135deg, var(--is-dark-900) 0%, var(--is-dark-800) 100%);
        border: 1px solid rgba(245, 158, 11, 0.15);
        color: var(--is-white);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        border-radius: 10px;
        font-weight: 600;
        padding: 0.45rem 1.25rem;
        transition: all 0.3s ease;
    }

    .btn-primary i {
        color: var(--is-amber);
    }

    .btn-primary:hover {
        border-color: var(--is-amber);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.25);
        color: var(--is-white);
        transform: translateY(-2px);
    }

    .btn-outline-secondary {
        border: 2px solid var(--is-gray-200);
        color: var(--is-gray-600);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        border-radius: 10px;
        font-weight: 500;
        background: transparent;
        padding: 0.4rem 1rem;
    }

    .btn-outline-secondary:hover {
        background: var(--is-gray-100);
        border-color: var(--is-amber);
        color: var(--is-dark-900);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    }

    .btn-outline-secondary i {
        color: var(--is-amber);
    }

    /* Cards */
    .card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 2px 15px rgba(0, 0, 0, 0.06);
        background: var(--is-white);
        transition: all 0.3s ease;
        overflow: hidden;
    }

    .card:hover {
        box-shadow: 0 4px 25px rgba(0, 0, 0, 0.1);
    }

    .card-body {
        padding: 1.25rem 1.5rem;
        background: var(--is-white);
    }

    /* Form Controls */
    .form-control,
    .form-select {
        border: 2px solid var(--is-gray-200);
        border-radius: 10px;
        padding: 0.5rem 1rem;
        font-size: 0.875rem;
        transition: all 0.3s ease;
        background: var(--is-gray-100);
        color: var(--is-dark-900);
        font-weight: 500;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: var(--is-amber);
        box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.12);
        background: var(--is-white);
    }

    .form-control::placeholder {
        color: var(--is-gray-400);
        font-weight: 400;
    }

    .form-control-sm,
    .form-select-sm {
        font-size: 0.8rem;
        padding: 0.4rem 0.75rem;
    }

    /* Table */
    #issuesTableWrap {
        background: var(--is-white);
        border-radius: 16px;
        overflow: hidden;
    }

    .table {
        margin-bottom: 0;
    }

    .table thead th {
        background: linear-gradient(135deg, var(--is-dark-900) 0%, var(--is-dark-800) 100%);
        color: var(--is-white) !important;
        border-bottom: 4px solid var(--is-amber);
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
        color: var(--is-amber);
        margin-right: 0.4rem;
        font-size: 0.9rem;
    }

    .table thead th,
    .table thead th *,
    .table thead th span,
    .table thead th div {
        color: var(--is-white) !important;
    }

    .table tbody td {
        padding: 0.85rem 1.25rem;
        vertical-align: middle;
        color: var(--is-dark-900);
        border-bottom: 1px solid var(--is-gray-200);
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
        background: var(--is-emerald) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
    }

    .badge.bg-warning {
        background: var(--is-amber) !important;
        color: var(--is-dark-900);
        box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
    }

    .badge.bg-danger {
        background: var(--is-rose) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(244, 63, 94, 0.3);
    }

    .badge.bg-info {
        background: var(--is-cyan) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(6, 182, 212, 0.3);
    }

    .badge.bg-secondary {
        background: var(--is-gray-500) !important;
        color: white;
    }

    /* Priority Badges */
    .badge.bg-priority-high {
        background: var(--is-rose) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(244, 63, 94, 0.3);
    }

    .badge.bg-priority-medium {
        background: var(--is-amber) !important;
        color: var(--is-dark-900);
        box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
    }

    .badge.bg-priority-low {
        background: var(--is-emerald) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
    }

    /* Modal */
    .modal-content {
        border: none;
        border-radius: 20px;
        box-shadow: 0 25px 70px rgba(0, 0, 0, 0.25);
        overflow: hidden;
        border: 1px solid var(--is-gray-200);
    }

    .modal-header {
        background: linear-gradient(135deg, var(--is-dark-900) 0%, var(--is-dark-800) 100%);
        color: var(--is-white);
        padding: 1.25rem 1.75rem;
        border-bottom: 4px solid var(--is-amber);
    }

    .modal-header .modal-title {
        color: var(--is-white);
        font-weight: 700;
    }

    .modal-header .modal-title i {
        color: var(--is-amber);
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
        background: var(--is-gray-100);
    }

    .modal-footer {
        background: var(--is-white);
        padding: 1rem 1.75rem;
        border-top: 1px solid var(--is-gray-200);
    }

    .modal-footer .btn-secondary {
        background: var(--is-gray-200);
        border: none;
        color: var(--is-dark-900);
        border-radius: 10px;
        padding: 0.5rem 1.5rem;
        font-weight: 500;
        transition: all 0.3s ease;
    }

    .modal-footer .btn-secondary:hover {
        background: var(--is-gray-300);
        transform: translateY(-2px);
    }

    .modal-body .form-label {
        font-weight: 600;
        color: var(--is-dark-900);
        font-size: 0.85rem;
    }

    .modal-body .form-label .text-danger {
        color: var(--is-rose);
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
        transform: translateY(-2px);
    }

    .btn-action.edit {
        color: var(--is-amber);
        background: rgba(245, 158, 11, 0.08);
    }

    .btn-action.edit:hover {
        background: var(--is-amber);
        color: var(--is-dark-900);
        box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
    }

    .btn-action.delete {
        color: var(--is-rose);
        background: rgba(244, 63, 94, 0.08);
    }

    .btn-action.delete:hover {
        background: var(--is-rose);
        color: white;
        box-shadow: 0 4px 12px rgba(244, 63, 94, 0.3);
    }

    .btn-action.view {
        color: var(--is-cyan);
        background: rgba(6, 182, 212, 0.08);
    }

    .btn-action.view:hover {
        background: var(--is-cyan);
        color: white;
        box-shadow: 0 4px 12px rgba(6, 182, 212, 0.3);
    }

    /* Pagination */
    .pagination .page-link {
        color: var(--is-dark-900);
        border-color: var(--is-gray-200);
        transition: all 0.3s ease;
        font-weight: 500;
        border-radius: 8px;
        margin: 0 2px;
    }

    .pagination .page-link:hover {
        background: var(--is-amber);
        color: var(--is-dark-900);
        border-color: var(--is-amber);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(245, 158, 11, 0.2);
    }

    .pagination .page-item.active .page-link {
        background: linear-gradient(135deg, var(--is-dark-900) 0%, var(--is-dark-800) 100%);
        border-color: var(--is-amber);
        color: var(--is-white);
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
        <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Issue Logging</h5>
        <small class="text-muted">Track issues raised during hearings and consultations</small>
      </div>
      <div class="d-flex gap-2 no-print">
        <a href="report.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-bar-chart"></i> Reports</a>
        <a href="print.php" target="_blank" id="printIssuesLink" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer"></i> Print</a>
        <?php if (canManage()): ?>
        <button type="button" class="btn btn-primary btn-sm" id="btnAddIssue"><i class="bi bi-plus-circle"></i> Log Issue</button>
        <?php endif; ?>
      </div>
    </div>

    <div class="card mb-3 no-print">
      <div class="card-body">
        <form id="filterForm" class="row g-2">
          <div class="col-md-4">
            <input type="text" class="form-control form-control-sm" name="search" id="searchInput" placeholder="Search title, description, office...">
          </div>
          <div class="col-md-2">
            <select class="form-select form-select-sm" name="status">
              <option value="">All Status</option>
              <?php foreach (['Open', 'In Progress', 'Resolved', 'Closed'] as $s): ?>

                <option value="<?= e($s) ?>"><?= e($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <select class="form-select form-select-sm" name="priority">
              <option value="">All Priority</option>
              <?php foreach (['Critical', 'High', 'Medium', 'Low'] as $p): ?>
                <option value="<?= e($p) ?>"><?= e($p) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <select class="form-select form-select-sm" name="category_id">
              <option value="">All Categories</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>
      </div>
    </div>

    <div class="card">
      <div id="issuesTableWrap">
        <?php include __DIR__ . '/table.php'; ?>
      </div>
    </div>
  </div>
</div>

<?php if (canManage() || (function_exists('isLoggedIn') && isLoggedIn())): ?>
<div class="modal fade" id="issueModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: 820px;">
    <div class="modal-content border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
      <form id="issueForm">
        <?= csrfField() ?>
        <input type="hidden" name="id" id="is_id" value="0">
        <div class="modal-header py-3 px-4 bg-light border-bottom">
          <div class="d-flex align-items-center gap-2.5">
            <div class="rounded-circle d-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary" style="width: 36px; height: 36px;">
              <i class="bi bi-exclamation-triangle fs-5 text-warning"></i>
            </div>
            <div>
              <h5 class="modal-title fw-bold text-dark mb-0" id="issueModalTitle" style="font-size: 1.05rem;">Log Issue</h5>
              <small class="text-muted" style="font-size: 0.8rem;">Record citizen concern, feedback topic, and routing</small>
            </div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label small fw-semibold text-secondary mb-1">Title <span class="text-danger">*</span></label>
              <input type="text" name="title" id="is_title" class="form-control" placeholder="Enter concise issue summary" required maxlength="255">
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold text-secondary mb-1">Description <span class="text-danger">*</span></label>
              <textarea name="description" id="is_description" class="form-control" rows="3" placeholder="Provide full details and background of the issue..." required></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Category</label>
              <select name="category_id" id="is_category" class="form-select">
                <option value="">-- Select Category --</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Related Hearing</label>
              <select name="hearing_id" id="is_hearing" class="form-select">
                <option value="">-- None --</option>
                <?php foreach ($hearings as $h): ?>
                  <option value="<?= (int)$h['id'] ?>"><?= e($h['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Priority</label>
              <select name="priority" id="is_priority" class="form-select">
                <?php foreach (['Low', 'Medium', 'High', 'Critical'] as $p): ?>
                <option value="<?= e($p) ?>" <?= $p === 'Medium' ? 'selected' : '' ?>><?= e($p) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Status</label>
              <select name="status" id="is_status" class="form-select">
                <?php foreach (['Open', 'In Progress', 'Resolved', 'Closed'] as $s): ?>
                <option value="<?= e($s) ?>"><?= e($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Assigned Office</label>
              <select name="assigned_office_id" id="is_office" class="form-select">
                <option value="">-- Unassigned Office --</option>
                <?php foreach ($offices as $office): ?>
                <option value="<?= (int)$office['id'] ?>"><?= e($office['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">
                <i class="bi bi-person-check text-primary me-1"></i> Assigned Staff / Person
              </label>
              <select name="assigned_user_id" id="is_user" class="form-select">
                <option value="">-- Unassigned Staff --</option>
                <?php foreach ($assignableUsers as $u): ?>
                <option value="<?= (int)$u['id'] ?>" data-office-id="<?= (int)($u['office_id'] ?? 0) ?>">
                  <?= e($u['full_name']) ?> (<?= e($u['role_name'] ?: 'Staff') ?><?= $u['office_name'] ? ' · ' . e($u['office_name']) : '' ?>)
                </option>
                <?php endforeach; ?>
              </select>
              <div class="form-text text-muted" style="font-size: 0.72rem;">
                <i class="bi bi-shield-check text-success"></i> Administrators are excluded from assignment.
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Due Date</label>
              <input type="datetime-local" name="due_at" id="is_due_at" class="form-control">
            </div>
          </div>
        </div>
        <div class="modal-footer py-2.5 px-4 bg-light border-top d-flex justify-content-end gap-2">
          <button type="button" class="btn btn-light border px-3" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary px-4 fw-semibold shadow-sm"><i class="bi bi-check-circle me-1"></i> Save Issue</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$extraJs = [APP_URL . '/assets/js/issues.js'];
include __DIR__ . '/../../layouts/footer.php';
?>
