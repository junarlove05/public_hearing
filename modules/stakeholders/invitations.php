<?php
/**
 * modules/stakeholders/invitations.php
 * ------------------------------------------------------------------
 * Invitation CRUD (Module 2, part 2 of 3). Includes single-invite
 * creation, bulk invite (handed off from index.php's checkbox
 * selection via ?bulk=1), mark-as-sent, print/download, and an
 * email-ready template preview.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireRole([ROLE_ADMIN, ROLE_STAFF]);

$pageTitle  = 'Invitations';
$activeMenu = 'stakeholders';
$activeTab  = 'invitations';
$pdo = db();

$stakeholders = $pdo->query("SELECT id, full_name, email FROM stakeholders WHERE status = 'Approved' OR status = 'Pending' ORDER BY full_name")->fetchAll();
$hearings = $pdo->query("SELECT id, title, hearing_date FROM hearings WHERE status IN ('Upcoming','Ongoing') ORDER BY hearing_date")->fetchAll();

include __DIR__ . '/../../layouts/header.php';
?>
<style>
    /* Invitations - Sidebar Color Scheme (Slate/Dark Gray + Amber) */
    /* ONLY COLORS CHANGED - NO SIZE ADJUSTMENTS */
    :root {
        --inv-primary: #111827;
        --inv-primary-light: #1F2937;
        --inv-accent: #FBBF24;
        --inv-accent-dark: #D97706;
        --inv-white: #FFFFFF;
        --inv-gray-50: #F8FAFC;
        --inv-gray-100: #F1F5F9;
        --inv-gray-200: #E2E8F0;
        --inv-gray-300: #CBD5E1;
        --inv-gray-400: #94A3B8;
        --inv-gray-500: #64748B;
        --inv-gray-600: #475569;
        --inv-success: #10B981;
        --inv-danger: #EF4444;
        --inv-info: #06B6D4;
    }

    /* Breadcrumb Bar - Color Only */
    .breadcrumb-bar {
        background: var(--inv-white);
        border-left: 5px solid var(--inv-accent);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
    }

    .breadcrumb-bar h5 {
        color: var(--inv-primary);
    }

    .breadcrumb-bar h5 i {
        color: var(--inv-accent);
    }

    .breadcrumb-bar .text-muted {
        color: var(--inv-gray-500) !important;
    }

    /* Buttons - Color Only */
    .btn-primary {
        background: linear-gradient(135deg, var(--inv-primary) 0%, var(--inv-primary-light) 100%);
        border: 1px solid rgba(251, 191, 36, 0.15);
        color: var(--inv-white);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    }

    .btn-primary i {
        color: var(--inv-accent);
    }

    .btn-primary:hover {
        border-color: var(--inv-accent);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.25);
        color: var(--inv-white);
    }

    .btn-outline-secondary {
        border: 2px solid var(--inv-gray-200);
        color: var(--inv-gray-600);
        background: transparent;
    }

    .btn-outline-secondary:hover {
        background: var(--inv-gray-50);
        border-color: var(--inv-accent);
        color: var(--inv-primary);
    }

    .btn-outline-secondary i {
        color: var(--inv-accent);
    }

    .btn-outline-primary {
        border: 2px solid var(--inv-primary);
        color: var(--inv-primary);
        background: transparent;
    }

    .btn-outline-primary:hover {
        background: var(--inv-primary);
        color: var(--inv-white);
    }

    .btn-outline-primary i {
        color: var(--inv-accent);
    }

    /* Cards - Color Only */
    .card {
        border: 1px solid var(--inv-gray-100);
        background: var(--inv-white);
        box-shadow: 0 2px 20px rgba(0, 0, 0, 0.05);
    }

    .card-body {
        background: var(--inv-white);
    }

    /* Form Controls - Color Only */
    .form-control,
    .form-select {
        border: 2px solid var(--inv-gray-200);
        background: var(--inv-gray-50);
        color: var(--inv-primary);
    }

    .form-control:focus,
    .form-select:focus {
        border-color: var(--inv-accent);
        box-shadow: 0 0 0 4px rgba(251, 191, 36, 0.15);
        background: var(--inv-white);
    }

    .form-control::placeholder {
        color: var(--inv-gray-400);
    }

    /* Modal - Color Only */
    .modal-content {
        border: 1px solid var(--inv-gray-100);
        box-shadow: 0 25px 70px rgba(0, 0, 0, 0.25);
    }

    .modal-header {
        background: linear-gradient(135deg, var(--inv-primary) 0%, var(--inv-primary-light) 100%);
        color: var(--inv-white);
        border-bottom: 4px solid var(--inv-accent);
    }

    .modal-header .modal-title {
        color: var(--inv-white);
    }

    .modal-header .modal-title i {
        color: var(--inv-accent);
        background: rgba(251, 191, 36, 0.15);
    }

    .modal-body {
        background: var(--inv-gray-50);
    }

    .modal-footer {
        background: var(--inv-white);
        border-top: 1px solid var(--inv-gray-100);
    }

    .modal-footer .btn-secondary {
        background: var(--inv-gray-100);
        color: var(--inv-gray-600);
    }

    .modal-footer .btn-secondary:hover {
        background: var(--inv-gray-200);
    }

    /* Form labels - Color Only */
    .modal-body .form-label {
        color: var(--inv-primary);
    }

    .modal-body .form-label .text-danger {
        color: var(--inv-danger);
    }

    .modal-body .form-text {
        color: var(--inv-gray-500);
    }

    /* Table - Color Only */
    #invitationsTableWrap {
        background: var(--inv-white);
    }

    .table thead th {
        background: linear-gradient(135deg, var(--inv-primary) 0%, var(--inv-primary-light) 100%);
        color: var(--inv-white) !important;
        border-bottom: 4px solid var(--inv-accent);
    }

    .table thead th i {
        color: var(--inv-accent);
    }

    .table thead th,
    .table thead th *,
    .table thead th span,
    .table thead th div {
        color: var(--inv-white) !important;
    }

    .table tbody td {
        color: var(--inv-primary);
        border-bottom: 1px solid var(--inv-gray-100);
    }

    .table tbody tr:hover {
        background: #FFFBEB;
    }

    /* Badges - Color Only */
    .badge.bg-success {
        background: var(--inv-success) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
    }

    .badge.bg-warning {
        background: var(--inv-accent) !important;
        color: var(--inv-primary);
        box-shadow: 0 2px 8px rgba(251, 191, 36, 0.3);
    }

    .badge.bg-danger {
        background: var(--inv-danger) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
    }

    .badge.bg-secondary {
        background: var(--inv-gray-400) !important;
        color: white;
    }

    /* Action buttons - Color Only */
    .btn-action.edit {
        color: var(--inv-accent-dark);
        background: rgba(217, 119, 6, 0.08);
    }

    .btn-action.edit:hover {
        background: var(--inv-accent-dark);
        color: white;
        box-shadow: 0 4px 12px rgba(217, 119, 6, 0.3);
    }

    .btn-action.delete {
        color: var(--inv-danger);
        background: rgba(239, 68, 68, 0.08);
    }

    .btn-action.delete:hover {
        background: var(--inv-danger);
        color: white;
        box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
    }

    .btn-action.view {
        color: var(--inv-accent);
        background: rgba(251, 191, 36, 0.08);
    }

    .btn-action.view:hover {
        background: var(--inv-accent);
        color: var(--inv-primary);
        box-shadow: 0 4px 12px rgba(251, 191, 36, 0.3);
    }

    /* Tabs - Color Only */
    .nav-tabs {
        border-bottom: 2px solid var(--inv-gray-200);
    }

    .nav-tabs .nav-link {
        color: var(--inv-gray-500);
    }

    .nav-tabs .nav-link:hover {
        color: var(--inv-primary);
        background: var(--inv-gray-50);
    }

    .nav-tabs .nav-link.active {
        color: var(--inv-primary);
        background: var(--inv-white);
        border-bottom: 3px solid var(--inv-accent);
    }

    .nav-tabs .nav-link i {
        color: var(--inv-accent);
    }

    .nav-tabs .nav-link .badge {
        background: var(--inv-gray-200);
        color: var(--inv-gray-600);
    }

    .nav-tabs .nav-link.active .badge {
        background: var(--inv-accent);
        color: var(--inv-primary);
    }

    /* Pagination - Color Only */
    .pagination .page-link {
        color: var(--inv-primary);
        border-color: var(--inv-gray-200);
    }

    .pagination .page-link:hover {
        background: var(--inv-accent);
        color: var(--inv-primary);
        border-color: var(--inv-accent);
        box-shadow: 0 4px 12px rgba(251, 191, 36, 0.2);
    }

    .pagination .page-item.active .page-link {
        background: linear-gradient(135deg, var(--inv-primary) 0%, var(--inv-primary-light) 100%);
        border-color: var(--inv-accent);
        color: var(--inv-white);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    }

    /* Modal text - Color Only */
    #bulkResult,
    #emailBody,
    #emailSubject {
        color: var(--inv-primary);
    }

    .form-text {
        color: var(--inv-gray-500);
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
        <button type="button" class="btn btn-outline-primary btn-sm" id="btnBulkInviteOpen"><i class="bi bi-send"></i> Bulk Invite</button>
        <button type="button" class="btn btn-primary btn-sm" id="btnAddInvitation"><i class="bi bi-envelope-plus"></i> New Invitation</button>
      </div>
    </div>

    <?php include __DIR__ . '/tabs.php'; ?>

    <div class="card mb-3 no-print">
      <div class="card-body">
        <div class="row g-2">
          <div class="col-md-5">
            <input type="text" class="form-control form-control-sm" id="searchInput" placeholder="Search stakeholder name, email, or invitation code...">
          </div>
          <div class="col-md-3">
            <select class="form-select form-select-sm" id="statusFilter">
              <option value="">All Status</option>
              <option value="Pending">Pending</option>
              <option value="Sent">Sent</option>
            </select>
          </div>
          <div class="col-md-4">
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
      <div id="invitationsTableWrap">
        <?php include __DIR__ . '/table_invitations.php'; ?>
      </div>
    </div>
  </div>
</div>

<!-- ===== New Single Invitation Modal ===== -->
<div class="modal fade" id="invitationModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="invitationForm">
        <?= csrfField() ?>
        <input type="hidden" name="id" value="0">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-envelope-plus"></i> New Invitation</h5>
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
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Create Invitation</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ===== Bulk Invite Modal ===== -->
<div class="modal fade" id="bulkInviteModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="bulkInviteForm">
        <?= csrfField() ?>
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-send"></i> Bulk Invite</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="small text-muted mb-2">
            <span id="bulkCountLabel">0</span> stakeholder(s) selected from the Stakeholders tab.
          </p>
          <div class="mb-3">
            <label class="form-label">Hearing <span class="text-danger">*</span></label>
            <select name="hearing_id" id="bulkHearingSelect" class="form-select" required>
              <option value="">-- Select Hearing --</option>
              <?php foreach ($hearings as $h): ?>
                <option value="<?= (int)$h['id'] ?>"><?= e($h['title']) ?> (<?= formatDate($h['hearing_date']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div id="bulkResult"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary" id="btnDoBulkInvite"><i class="bi bi-send"></i> Send Invitations</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ===== Email Template Preview Modal ===== -->
<div class="modal fade" id="emailTemplateModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-envelope"></i> Email-Ready Template</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="form-label small text-muted">Subject</label>
        <input type="text" class="form-control mb-3" id="emailSubject" readonly>
        <label class="form-label small text-muted">Body</label>
        <textarea class="form-control" id="emailBody" rows="12" readonly></textarea>
        <div class="form-text">Copy this into your organization's email client or mail-merge tool to send.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary" id="btnCopyEmail"><i class="bi bi-clipboard"></i> Copy to Clipboard</button>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = [APP_URL . '/assets/js/invitations.js'];
include __DIR__ . '/../../layouts/footer.php';
?>