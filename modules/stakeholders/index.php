<?php
/**
 * modules/stakeholders/index.php
 * ------------------------------------------------------------------
 * Stakeholder CRUD (Module 2, part 1 of 3). Search/filter/sort/
 * paginate via AJAX, Create/Edit modal, CSV bulk import, and
 * checkbox multi-select that feeds "Bulk Invite" on invitations.php.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireRole([ROLE_ADMIN, ROLE_STAFF]);

$pageTitle  = 'Stakeholders';
$activeMenu = 'stakeholders';
$activeTab  = 'stakeholders';
$pdo = db();

$categories = $pdo->query('SELECT id, name FROM stakeholder_categories ORDER BY name')->fetchAll();

include __DIR__ . '/../../layouts/header.php';
?>
<style>
    /* Stakeholders - Sidebar Color Scheme (Slate/Dark Gray + Amber) */
    /* ONLY COLORS CHANGED - NO SIZE ADJUSTMENTS */
    :root {
        --st-primary: #111827;
        --st-primary-light: #1F2937;
        --st-accent: #FBBF24;
        --st-accent-dark: #D97706;
        --st-white: #FFFFFF;
        --st-gray-50: #F8FAFC;
        --st-gray-100: #F1F5F9;
        --st-gray-200: #E2E8F0;
        --st-gray-300: #CBD5E1;
        --st-gray-400: #94A3B8;
        --st-gray-500: #64748B;
        --st-gray-600: #475569;
        --st-success: #10B981;
        --st-danger: #EF4444;
        --st-info: #06B6D4;
    }

    /* Breadcrumb Bar - Color Only */
    .breadcrumb-bar {
        background: var(--st-white);
        border-left: 5px solid var(--st-accent);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
    }

    .breadcrumb-bar h5 {
        color: var(--st-primary);
    }

    .breadcrumb-bar h5 i {
        color: var(--st-accent);
    }

    .breadcrumb-bar .text-muted {
        color: var(--st-gray-500) !important;
    }

    /* Buttons - Color Only */
    .btn-primary {
        background: linear-gradient(135deg, var(--st-primary) 0%, var(--st-primary-light) 100%);
        border: 1px solid rgba(251, 191, 36, 0.15);
        color: var(--st-white);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    }

    .btn-primary i {
        color: var(--st-accent);
    }

    .btn-primary:hover {
        border-color: var(--st-accent);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.25);
        color: var(--st-white);
    }

    .btn-outline-secondary {
        border: 2px solid var(--st-gray-200);
        color: var(--st-gray-600);
        background: transparent;
    }

    .btn-outline-secondary:hover {
        background: var(--st-gray-50);
        border-color: var(--st-accent);
        color: var(--st-primary);
    }

    .btn-outline-secondary i {
        color: var(--st-accent);
    }

    .btn-outline-primary {
        border: 2px solid var(--st-primary);
        color: var(--st-primary);
        background: transparent;
    }

    .btn-outline-primary:hover {
        background: var(--st-primary);
        color: var(--st-white);
    }

    .btn-outline-primary i {
        color: var(--st-accent);
    }

    /* Cards - Color Only */
    .card {
        border: 1px solid var(--st-gray-100);
        background: var(--st-white);
        box-shadow: 0 2px 20px rgba(0, 0, 0, 0.05);
    }

    .card-body {
        background: var(--st-white);
    }

    /* Form Controls - Color Only */
    .form-control,
    .form-select {
        border: 2px solid var(--st-gray-200);
        background: var(--st-gray-50);
        color: var(--st-primary);
    }

    .form-control:focus,
    .form-select:focus {
        border-color: var(--st-accent);
        box-shadow: 0 0 0 4px rgba(251, 191, 36, 0.15);
        background: var(--st-white);
    }

    .form-control::placeholder {
        color: var(--st-gray-400);
    }

    /* Modal - Color Only */
    .modal-content {
        border: 1px solid var(--st-gray-100);
        box-shadow: 0 25px 70px rgba(0, 0, 0, 0.25);
    }

    .modal-header {
        background: linear-gradient(135deg, var(--st-primary) 0%, var(--st-primary-light) 100%);
        color: var(--st-white);
        border-bottom: 4px solid var(--st-accent);
    }

    .modal-header .modal-title {
        color: var(--st-white);
    }

    .modal-header .modal-title i {
        color: var(--st-accent);
        background: rgba(251, 191, 36, 0.15);
    }

    .modal-body {
        background: var(--st-gray-50);
    }

    .modal-footer {
        background: var(--st-white);
        border-top: 1px solid var(--st-gray-100);
    }

    .modal-footer .btn-secondary {
        background: var(--st-gray-100);
        color: var(--st-gray-600);
    }

    .modal-footer .btn-secondary:hover {
        background: var(--st-gray-200);
    }

    /* Form labels - Color Only */
    .modal-body .form-label {
        color: var(--st-primary);
    }

    .modal-body .form-label .text-danger {
        color: var(--st-danger);
    }

    .modal-body .form-text {
        color: var(--st-gray-500);
    }

    /* Table - Color Only */
    #stakeholdersTableWrap {
        background: var(--st-white);
    }

    .table thead th {
        background: linear-gradient(135deg, var(--st-primary) 0%, var(--st-primary-light) 100%);
        color: var(--st-white) !important;
        border-bottom: 4px solid var(--st-accent);
    }

    .table thead th i {
        color: var(--st-accent);
    }

    .table thead th,
    .table thead th *,
    .table thead th span,
    .table thead th div {
        color: var(--st-white) !important;
    }

    .table tbody td {
        color: var(--st-primary);
        border-bottom: 1px solid var(--st-gray-100);
    }

    .table tbody tr:hover {
        background: #FFFBEB;
    }

    /* Checkbox - Color Only */
    .form-check-input {
        border: 2px solid var(--st-gray-300);
    }

    .form-check-input:checked {
        background-color: var(--st-primary);
        border-color: var(--st-accent);
    }

    .form-check-input:focus {
        border-color: var(--st-accent);
        box-shadow: 0 0 0 4px rgba(251, 191, 36, 0.15);
    }

    /* Badges - Color Only */
    .badge.bg-success {
        background: var(--st-success) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
    }

    .badge.bg-warning {
        background: var(--st-accent) !important;
        color: var(--st-primary);
        box-shadow: 0 2px 8px rgba(251, 191, 36, 0.3);
    }

    .badge.bg-danger {
        background: var(--st-danger) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
    }

    .badge.bg-secondary {
        background: var(--st-gray-400) !important;
        color: white;
    }

    /* Action buttons - Color Only */
    .btn-action.edit {
        color: var(--st-accent-dark);
        background: rgba(217, 119, 6, 0.08);
    }

    .btn-action.edit:hover {
        background: var(--st-accent-dark);
        color: white;
        box-shadow: 0 4px 12px rgba(217, 119, 6, 0.3);
    }

    .btn-action.delete {
        color: var(--st-danger);
        background: rgba(239, 68, 68, 0.08);
    }

    .btn-action.delete:hover {
        background: var(--st-danger);
        color: white;
        box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
    }

    .btn-action.view {
        color: var(--st-accent);
        background: rgba(251, 191, 36, 0.08);
    }

    .btn-action.view:hover {
        background: var(--st-accent);
        color: var(--st-primary);
        box-shadow: 0 4px 12px rgba(251, 191, 36, 0.3);
    }

    /* Tabs - Color Only */
    .nav-tabs {
        border-bottom: 2px solid var(--st-gray-200);
    }

    .nav-tabs .nav-link {
        color: var(--st-gray-500);
    }

    .nav-tabs .nav-link:hover {
        color: var(--st-primary);
        background: var(--st-gray-50);
    }

    .nav-tabs .nav-link.active {
        color: var(--st-primary);
        background: var(--st-white);
        border-bottom: 3px solid var(--st-accent);
    }

    .nav-tabs .nav-link i {
        color: var(--st-accent);
    }

    .nav-tabs .nav-link .badge {
        background: var(--st-gray-200);
        color: var(--st-gray-600);
    }

    .nav-tabs .nav-link.active .badge {
        background: var(--st-accent);
        color: var(--st-primary);
    }

    /* Pagination - Color Only */
    .pagination .page-link {
        color: var(--st-primary);
        border-color: var(--st-gray-200);
    }

    .pagination .page-link:hover {
        background: var(--st-accent);
        color: var(--st-primary);
        border-color: var(--st-accent);
        box-shadow: 0 4px 12px rgba(251, 191, 36, 0.2);
    }

    .pagination .page-item.active .page-link {
        background: linear-gradient(135deg, var(--st-primary) 0%, var(--st-primary-light) 100%);
        border-color: var(--st-accent);
        color: var(--st-white);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    }

    /* Import/CSV specific */
    #importResult {
        color: var(--st-primary);
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
  <?php include __DIR__ . '/../../layouts/sidebar.php'; ?>

  <div class="main-content">
    <div class="breadcrumb-bar d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <h5 class="mb-0"><i class="bi bi-people"></i> Stakeholder Invitation &amp; Registration</h5>
        <small class="text-muted">Manage stakeholders, invitations, and hearing registrations</small>
      </div>
      <div class="d-flex gap-2 no-print">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnImportCsv"><i class="bi bi-upload"></i> Import CSV</button>
        <button type="button" class="btn btn-primary btn-sm" id="btnAddStakeholder"><i class="bi bi-person-plus"></i> Add Stakeholder</button>
      </div>
    </div>

    <?php include __DIR__ . '/tabs.php'; ?>

    <!-- ===== Filters + bulk action bar ===== -->
    <div class="card mb-3 no-print">
      <div class="card-body">
        <div class="row g-2 align-items-center">
          <div class="col-md-4">
            <input type="text" class="form-control form-control-sm" id="searchInput" name="search" placeholder="Search name, email, organization...">
          </div>
          <div class="col-md-3">
            <select class="form-select form-select-sm" id="statusFilter" name="status">
              <option value="">All Status</option>
              <option value="Pending">Pending</option>
              <option value="Approved">Approved</option>
              <option value="Rejected">Rejected</option>
            </select>
          </div>
          <div class="col-md-3">
            <select class="form-select form-select-sm" id="categoryFilter" name="category_id">
              <option value="">All Categories</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2 text-end">
            <button type="button" class="btn btn-outline-primary btn-sm w-100" id="btnBulkInvite" disabled>
              <i class="bi bi-send"></i> Bulk Invite (<span id="selectedCount">0</span>)
            </button>
          </div>
        </div>
      </div>
    </div>

    <form id="filterForm" class="d-none">
      <input type="hidden" name="search"><input type="hidden" name="status"><input type="hidden" name="category_id">
    </form>

    <div class="card">
      <div id="stakeholdersTableWrap">
        <?php include __DIR__ . '/table.php'; ?>
      </div>
    </div>
  </div>
</div>

<!-- ===== Add / Edit Stakeholder Modal ===== -->
<div class="modal fade" id="stakeholderModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="stakeholderForm">
        <?= csrfField() ?>
        <input type="hidden" name="id" id="s_id" value="0">
        <div class="modal-header">
          <h5 class="modal-title" id="stakeholderModalTitle"><i class="bi bi-person-plus"></i> Add Stakeholder</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Full Name <span class="text-danger">*</span></label>
            <input type="text" name="full_name" id="s_full_name" class="form-control" required maxlength="150">
          </div>
          <div class="mb-3">
            <label class="form-label">Email <span class="text-danger">*</span></label>
            <input type="email" name="email" id="s_email" class="form-control" required maxlength="150">
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Phone</label>
              <input type="text" name="phone" id="s_phone" class="form-control" maxlength="50">
            </div>
            <div class="col-md-6">
              <label class="form-label">Category</label>
              <select name="category_id" id="s_category" class="form-select">
                <option value="">-- Select --</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="mb-3 mt-3">
            <label class="form-label">Organization</label>
            <input type="text" name="organization" id="s_organization" class="form-control" maxlength="255">
          </div>
          <div class="mb-3">
            <label class="form-label">Status</label>
            <select name="status" id="s_status" class="form-select">
              <option value="Pending">Pending</option>
              <option value="Approved">Approved</option>
              <option value="Rejected">Rejected</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="btnSaveStakeholder"><i class="bi bi-check-circle"></i> Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ===== CSV Import Modal ===== -->
<div class="modal fade" id="importModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="importForm" enctype="multipart/form-data">
        <?= csrfField() ?>
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-upload"></i> Import Stakeholders from CSV</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="small text-muted">
            CSV must include a header row with columns <code>full_name</code>, <code>email</code>
            (required), and optionally <code>phone</code>, <code>organization</code>, <code>category</code>.
          </p>
          <input type="file" name="csv_file" class="form-control" accept=".csv" required>
          <div id="importResult" class="mt-3"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary" id="btnDoImport"><i class="bi bi-upload"></i> Import</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = [APP_URL . '/assets/js/stakeholders.js'];
include __DIR__ . '/../../layouts/footer.php';
?>