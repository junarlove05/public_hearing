<?php
/**
 * modules/stakeholders/registrations.php
 * ------------------------------------------------------------------
 * Registration CRUD (Module 2, part 3 of 3). Staff-assisted manual
 * registration of a stakeholder to a hearing, plus search/filter and
 * deletion. (Self-service public registration would be exposed via a
 * separate public-facing form outside the authenticated app; this
 * page is the internal management view.)
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireRole([ROLE_ADMIN, ROLE_STAFF]);

$pageTitle  = 'Registrations';
$activeMenu = 'stakeholders';
$activeTab  = 'registrations';
$pdo = db();

$stakeholders = $pdo->query('SELECT id, full_name, email FROM stakeholders ORDER BY full_name')->fetchAll();
$hearings = $pdo->query("SELECT id, title, hearing_date FROM hearings ORDER BY hearing_date DESC")->fetchAll();

include __DIR__ . '/../../layouts/header.php';
?>
<style>
    /* Registrations - Sidebar Color Scheme (Slate/Dark Gray + Amber) */
    /* ONLY COLORS CHANGED - NO SIZE ADJUSTMENTS */
    :root {
        --reg-primary: #111827;
        --reg-primary-light: #1F2937;
        --reg-accent: #FBBF24;
        --reg-accent-dark: #D97706;
        --reg-white: #FFFFFF;
        --reg-gray-50: #F8FAFC;
        --reg-gray-100: #F1F5F9;
        --reg-gray-200: #E2E8F0;
        --reg-gray-300: #CBD5E1;
        --reg-gray-400: #94A3B8;
        --reg-gray-500: #64748B;
        --reg-gray-600: #475569;
        --reg-success: #10B981;
        --reg-danger: #EF4444;
        --reg-info: #06B6D4;
    }

    /* Breadcrumb Bar - Color Only */
    .breadcrumb-bar {
        background: var(--reg-white);
        border-left: 5px solid var(--reg-accent);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
    }

    .breadcrumb-bar h5 {
        color: var(--reg-primary);
    }

    .breadcrumb-bar h5 i {
        color: var(--reg-accent);
    }

    .breadcrumb-bar .text-muted {
        color: var(--reg-gray-500) !important;
    }

    /* Buttons - Color Only */
    .btn-primary {
        background: linear-gradient(135deg, var(--reg-primary) 0%, var(--reg-primary-light) 100%);
        border: 1px solid rgba(251, 191, 36, 0.15);
        color: var(--reg-white);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    }

    .btn-primary i {
        color: var(--reg-accent);
    }

    .btn-primary:hover {
        border-color: var(--reg-accent);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.25);
        color: var(--reg-white);
    }

    .btn-outline-secondary {
        border: 2px solid var(--reg-gray-200);
        color: var(--reg-gray-600);
        background: transparent;
    }

    .btn-outline-secondary:hover {
        background: var(--reg-gray-50);
        border-color: var(--reg-accent);
        color: var(--reg-primary);
    }

    .btn-outline-secondary i {
        color: var(--reg-accent);
    }

    .btn-outline-primary {
        border: 2px solid var(--reg-primary);
        color: var(--reg-primary);
        background: transparent;
    }

    .btn-outline-primary:hover {
        background: var(--reg-primary);
        color: var(--reg-white);
    }

    .btn-outline-primary i {
        color: var(--reg-accent);
    }

    /* Cards - Color Only */
    .card {
        border: 1px solid var(--reg-gray-100);
        background: var(--reg-white);
        box-shadow: 0 2px 20px rgba(0, 0, 0, 0.05);
    }

    .card-body {
        background: var(--reg-white);
    }

    /* Form Controls - Color Only */
    .form-control,
    .form-select {
        border: 2px solid var(--reg-gray-200);
        background: var(--reg-gray-50);
        color: var(--reg-primary);
    }

    .form-control:focus,
    .form-select:focus {
        border-color: var(--reg-accent);
        box-shadow: 0 0 0 4px rgba(251, 191, 36, 0.15);
        background: var(--reg-white);
    }

    .form-control::placeholder {
        color: var(--reg-gray-400);
    }

    /* Modal - Color Only */
    .modal-content {
        border: 1px solid var(--reg-gray-100);
        box-shadow: 0 25px 70px rgba(0, 0, 0, 0.25);
    }

    .modal-header {
        background: linear-gradient(135deg, var(--reg-primary) 0%, var(--reg-primary-light) 100%);
        color: var(--reg-white);
        border-bottom: 4px solid var(--reg-accent);
    }

    .modal-header .modal-title {
        color: var(--reg-white);
    }

    .modal-header .modal-title i {
        color: var(--reg-accent);
        background: rgba(251, 191, 36, 0.15);
    }

    .modal-body {
        background: var(--reg-gray-50);
    }

    .modal-footer {
        background: var(--reg-white);
        border-top: 1px solid var(--reg-gray-100);
    }

    .modal-footer .btn-secondary {
        background: var(--reg-gray-100);
        color: var(--reg-gray-600);
    }

    .modal-footer .btn-secondary:hover {
        background: var(--reg-gray-200);
    }

    /* Form labels - Color Only */
    .modal-body .form-label {
        color: var(--reg-primary);
    }

    .modal-body .form-label .text-danger {
        color: var(--reg-danger);
    }

    .modal-body .form-text {
        color: var(--reg-gray-500);
    }

    /* Table - Color Only */
    #registrationsTableWrap {
        background: var(--reg-white);
    }

    .table thead th {
        background: linear-gradient(135deg, var(--reg-primary) 0%, var(--reg-primary-light) 100%);
        color: var(--reg-white) !important;
        border-bottom: 4px solid var(--reg-accent);
    }

    .table thead th i {
        color: var(--reg-accent);
    }

    .table thead th,
    .table thead th *,
    .table thead th span,
    .table thead th div {
        color: var(--reg-white) !important;
    }

    .table tbody td {
        color: var(--reg-primary);
        border-bottom: 1px solid var(--reg-gray-100);
    }

    .table tbody tr:hover {
        background: #FFFBEB;
    }

    /* Badges - Color Only */
    .badge.bg-success {
        background: var(--reg-success) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
    }

    .badge.bg-warning {
        background: var(--reg-accent) !important;
        color: var(--reg-primary);
        box-shadow: 0 2px 8px rgba(251, 191, 36, 0.3);
    }

    .badge.bg-danger {
        background: var(--reg-danger) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
    }

    .badge.bg-secondary {
        background: var(--reg-gray-400) !important;
        color: white;
    }

    /* Action buttons - Color Only */
    .btn-action.edit {
        color: var(--reg-accent-dark);
        background: rgba(217, 119, 6, 0.08);
    }

    .btn-action.edit:hover {
        background: var(--reg-accent-dark);
        color: white;
        box-shadow: 0 4px 12px rgba(217, 119, 6, 0.3);
    }

    .btn-action.delete {
        color: var(--reg-danger);
        background: rgba(239, 68, 68, 0.08);
    }

    .btn-action.delete:hover {
        background: var(--reg-danger);
        color: white;
        box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
    }

    .btn-action.view {
        color: var(--reg-accent);
        background: rgba(251, 191, 36, 0.08);
    }

    .btn-action.view:hover {
        background: var(--reg-accent);
        color: var(--reg-primary);
        box-shadow: 0 4px 12px rgba(251, 191, 36, 0.3);
    }

    /* Tabs - Color Only */
    .nav-tabs {
        border-bottom: 2px solid var(--reg-gray-200);
    }

    .nav-tabs .nav-link {
        color: var(--reg-gray-500);
    }

    .nav-tabs .nav-link:hover {
        color: var(--reg-primary);
        background: var(--reg-gray-50);
    }

    .nav-tabs .nav-link.active {
        color: var(--reg-primary);
        background: var(--reg-white);
        border-bottom: 3px solid var(--reg-accent);
    }

    .nav-tabs .nav-link i {
        color: var(--reg-accent);
    }

    .nav-tabs .nav-link .badge {
        background: var(--reg-gray-200);
        color: var(--reg-gray-600);
    }

    .nav-tabs .nav-link.active .badge {
        background: var(--reg-accent);
        color: var(--reg-primary);
    }

    /* Pagination - Color Only */
    .pagination .page-link {
        color: var(--reg-primary);
        border-color: var(--reg-gray-200);
    }

    .pagination .page-link:hover {
        background: var(--reg-accent);
        color: var(--reg-primary);
        border-color: var(--reg-accent);
        box-shadow: 0 4px 12px rgba(251, 191, 36, 0.2);
    }

    .pagination .page-item.active .page-link {
        background: linear-gradient(135deg, var(--reg-primary) 0%, var(--reg-primary-light) 100%);
        border-color: var(--reg-accent);
        color: var(--reg-white);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
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
      <div class="no-print">
        <button type="button" class="btn btn-primary btn-sm" id="btnAddRegistration"><i class="bi bi-clipboard-plus"></i> Add Registration</button>
      </div>
    </div>

    <?php include __DIR__ . '/tabs.php'; ?>

    <div class="card mb-3 no-print">
      <div class="card-body">
        <div class="row g-2">
          <div class="col-md-7">
            <input type="text" class="form-control form-control-sm" id="searchInput" placeholder="Search stakeholder name, email, or organization...">
          </div>
          <div class="col-md-5">
            <select class="form-select form-select-sm" id="hearingFilter">
              <option value="">All Hearings</option>
              <?php foreach ($hearings as $h): ?>
                <option value="<?= (int)$h['id'] ?>"><?= e($h['title']) ?> (<?= formatDate($h['hearing_date']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div id="registrationsTableWrap">
        <?php include __DIR__ . '/table_registrations.php'; ?>
      </div>
    </div>
  </div>
</div>

<!-- ===== Add Registration Modal ===== -->
<div class="modal fade" id="registrationModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="registrationForm">
        <?= csrfField() ?>
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-clipboard-plus"></i> Add Registration</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Stakeholder <span class="text-danger">*</span></label>
            <select name="stakeholder_id" class="form-select" required>
              <option value="">-- Select Stakeholder --</option>
              <?php foreach ($stakeholders as $s): ?>
                <option value="<?= (int)$s['id'] ?>"><?= e($s['full_name']) ?> (<?= e($s['email']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Hearing</label>
            <select name="hearing_id" class="form-select">
              <option value="">-- General (no specific hearing) --</option>
              <?php foreach ($hearings as $h): ?>
                <option value="<?= (int)$h['id'] ?>"><?= e($h['title']) ?> (<?= formatDate($h['hearing_date']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Register</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = [APP_URL . '/assets/js/registrations.js'];
include __DIR__ . '/../../layouts/footer.php';
?>