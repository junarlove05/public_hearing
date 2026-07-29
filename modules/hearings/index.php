<?php
/**
 * modules/hearings/index.php
 * ------------------------------------------------------------------
 * Hearing Schedule Module - main listing page.
 * Search, filter (status/type/committee/date range), sort, paginate
 * (all live via AJAX -> ajax_search.php), plus Create/Edit modal and
 * Delete (via app.js's generic data-confirm-delete wiring).
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pageTitle  = 'Hearing Schedule';
$activeMenu = 'hearings';
$pdo = db();

$hearingTypes = $pdo->query('SELECT id, name FROM hearing_types ORDER BY name')->fetchAll();
$committees   = $pdo->query('SELECT id, name FROM committees ORDER BY name')->fetchAll();

include __DIR__ . '/../../layouts/header.php';
?>
<style>
    /* ============================================================
       HEARING SCHEDULE - Coastal Blue Theme
       Colors: Midnight Blue, White, Gold Accents
       ============================================================ */
    :root {
        --hs-midnight-dark: #0A1628;
        --hs-midnight: #0F2137;
        --hs-midnight-blue: #1A3A5C;
        --hs-midnight-soft: #2C5282;
        --hs-midnight-pale: #4A7EB5;
        --hs-midnight-lighter: #6B9BC7;
        --hs-white: #FFFFFF;
        --hs-off-white: #F5F8FA;
        --hs-gray-100: #F1F5F9;
        --hs-gray-200: #E2E8F0;
        --hs-gray-300: #CBD5E1;
        --hs-gray-400: #94A3B8;
        --hs-gray-500: #64748B;
        --hs-gray-600: #475569;
        --hs-gold: #F5C842;
        --hs-gold-light: #F7D95A;
        --hs-gold-dark: #D4A820;
        --hs-success: #10B981;
        --hs-danger: #EF4444;
        --hs-info: #06B6D4;
        --hs-gradient-start: #0A1628;
        --hs-gradient-end: #1A3A5C;
    }

    /* Breadcrumb Bar */
    .breadcrumb-bar {
        background: var(--hs-white);
        padding: 1.25rem 1.75rem;
        border-radius: 16px;
        margin-bottom: 1.5rem;
        border-left: 5px solid var(--hs-gold);
        box-shadow: 0 4px 20px rgba(10, 22, 40, 0.06);
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
        color: var(--hs-midnight);
        font-weight: 800;
        font-size: 1.1rem;
        letter-spacing: -0.3px;
    }

    .breadcrumb-bar h5 i {
        color: var(--hs-gold);
        background: rgba(245, 200, 66, 0.1);
        padding: 0.4rem;
        border-radius: 10px;
        margin-right: 0.5rem;
    }

    .breadcrumb-bar .text-muted {
        color: var(--hs-gray-500) !important;
        font-weight: 400;
    }

    /* Buttons - Coastal Blue Style */
    .btn-primary {
        background: linear-gradient(135deg, var(--hs-midnight-dark) 0%, var(--hs-midnight-blue) 100%);
        border: none;
        color: var(--hs-white);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        font-weight: 600;
        padding: 0.45rem 1.25rem;
        border-radius: 10px;
        box-shadow: 0 2px 8px rgba(10, 22, 40, 0.2);
        position: relative;
        overflow: hidden;
        border: 1px solid rgba(245, 200, 66, 0.15);
    }

    .btn-primary::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(245, 200, 66, 0.1), transparent);
        transition: left 0.5s ease;
    }

    .btn-primary:hover::before {
        left: 100%;
    }

    .btn-primary:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 25px rgba(10, 22, 40, 0.3);
        color: var(--hs-white);
        border-color: var(--hs-gold);
    }

    .btn-primary:active {
        transform: translateY(0);
    }

    .btn-primary i {
        color: var(--hs-gold);
        margin-right: 0.3rem;
    }

    .btn-outline-secondary {
        border: 2px solid var(--hs-gray-200);
        color: var(--hs-gray-600);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        border-radius: 10px;
        font-weight: 500;
        background: transparent;
        padding: 0.4rem 1rem;
    }

    .btn-outline-secondary:hover {
        background: var(--hs-off-white);
        border-color: var(--hs-gold);
        color: var(--hs-midnight);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(10, 22, 40, 0.05);
    }

    .btn-outline-secondary i {
        color: var(--hs-gold);
    }

    /* Cards */
    .card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 2px 20px rgba(10, 22, 40, 0.05);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        overflow: hidden;
        background: var(--hs-white);
        border: 1px solid var(--hs-gray-100);
    }

    .card:hover {
        box-shadow: 0 8px 30px rgba(10, 22, 40, 0.08);
        transform: translateY(-2px);
    }

    .card-body {
        padding: 1.25rem 1.5rem;
        background: var(--hs-white);
    }

    /* Form Controls */
    .form-control,
    .form-select {
        border: 2px solid var(--hs-gray-200);
        border-radius: 10px;
        padding: 0.5rem 1rem;
        font-size: 0.875rem;
        transition: all 0.3s ease;
        background: var(--hs-off-white);
        color: var(--hs-midnight);
        font-weight: 500;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: var(--hs-gold);
        box-shadow: 0 0 0 4px rgba(245, 200, 66, 0.15);
        background: var(--hs-white);
    }

    .form-control::placeholder {
        color: var(--hs-gray-400);
        font-weight: 400;
    }

    .form-control-sm,
    .form-select-sm {
        font-size: 0.8rem;
        padding: 0.4rem 0.75rem;
    }

    /* Modal - Coastal Blue */
    .modal-content {
        border: none;
        border-radius: 20px;
        box-shadow: 0 25px 70px rgba(10, 22, 40, 0.25);
        overflow: hidden;
        border: 1px solid var(--hs-gray-100);
    }

    .modal-header {
        background: linear-gradient(135deg, var(--hs-midnight-dark) 0%, var(--hs-midnight-blue) 100%);
        color: var(--hs-white);
        padding: 1.25rem 1.75rem;
        border-bottom: 4px solid var(--hs-gold);
        position: relative;
        overflow: hidden;
    }

    .modal-header::after {
        content: '';
        position: absolute;
        top: -50%;
        right: -20%;
        width: 200px;
        height: 200px;
        background: radial-gradient(circle, rgba(245, 200, 66, 0.08) 0%, transparent 70%);
        border-radius: 50%;
    }

    .modal-header .modal-title {
        font-weight: 700;
        color: var(--hs-white);
        font-size: 1.15rem;
        position: relative;
        z-index: 1;
    }

    .modal-header .modal-title i {
        color: var(--hs-gold);
        margin-right: 0.6rem;
        background: rgba(245, 200, 66, 0.15);
        padding: 0.3rem 0.5rem;
        border-radius: 8px;
    }

    .modal-header .btn-close {
        filter: brightness(0) invert(1);
        opacity: 0.7;
        transition: all 0.3s ease;
        position: relative;
        z-index: 1;
    }

    .modal-header .btn-close:hover {
        opacity: 1;
        transform: rotate(90deg);
    }

    .modal-body {
        padding: 1.75rem;
        background: var(--hs-off-white);
    }

    .modal-footer {
        background: var(--hs-white);
        padding: 1rem 1.75rem;
        border-top: 1px solid var(--hs-gray-100);
    }

    .modal-footer .btn-secondary {
        background: var(--hs-gray-100);
        border: none;
        color: var(--hs-gray-600);
        border-radius: 10px;
        padding: 0.5rem 1.5rem;
        font-weight: 500;
        transition: all 0.3s ease;
    }

    .modal-footer .btn-secondary:hover {
        background: var(--hs-gray-200);
        transform: translateY(-2px);
    }

    /* Form labels in modal */
    .modal-body .form-label {
        font-weight: 600;
        color: var(--hs-midnight);
        font-size: 0.85rem;
        margin-bottom: 0.4rem;
    }

    .modal-body .form-label .text-danger {
        color: var(--hs-danger);
    }

    .modal-body .form-text {
        color: var(--hs-gray-500);
        font-size: 0.75rem;
        margin-top: 0.25rem;
    }

    /* Table - Coastal Blue */
    #hearingsTableWrap {
        background: var(--hs-white);
        border-radius: 16px;
        overflow: hidden;
    }

    .table {
        margin-bottom: 0;
    }

    .table thead th {
        background: linear-gradient(135deg, var(--hs-midnight-dark) 0%, var(--hs-midnight-blue) 100%);
        color: var(--hs-white) !important;
        border-bottom: 4px solid var(--hs-gold);
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
        color: var(--hs-gold);
        margin-right: 0.4rem;
        font-size: 0.9rem;
    }

    /* Ensure ALL header text is white */
    .table thead th,
    .table thead th *,
    .table thead th span,
    .table thead th div {
        color: var(--hs-white) !important;
    }

    .table thead th a {
        color: var(--hs-white) !important;
        text-decoration: none;
    }

    .table thead th a:hover {
        color: var(--hs-gold) !important;
    }

    .table tbody td {
        padding: 0.85rem 1.25rem;
        vertical-align: middle;
        color: var(--hs-midnight);
        border-bottom: 1px solid var(--hs-gray-100);
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
        background: var(--hs-success) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
    }

    .badge.bg-warning {
        background: var(--hs-gold) !important;
        color: var(--hs-midnight-dark);
        box-shadow: 0 2px 8px rgba(245, 200, 66, 0.3);
    }

    .badge.bg-info {
        background: var(--hs-info) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(6, 182, 212, 0.3);
    }

    .badge.bg-danger {
        background: var(--hs-danger) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
    }

    .badge.bg-secondary {
        background: var(--hs-gray-400) !important;
        color: white;
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
        color: var(--hs-gold-dark);
        background: rgba(212, 168, 32, 0.08);
    }

    .btn-action.edit:hover {
        background: var(--hs-gold-dark);
        color: white;
        box-shadow: 0 4px 12px rgba(212, 168, 32, 0.3);
    }

    .btn-action.delete {
        color: var(--hs-danger);
        background: rgba(239, 68, 68, 0.08);
    }

    .btn-action.delete:hover {
        background: var(--hs-danger);
        color: white;
        box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
    }

    .btn-action.view {
        color: var(--hs-gold);
        background: rgba(245, 200, 66, 0.08);
    }

    .btn-action.view:hover {
        background: var(--hs-gold);
        color: var(--hs-midnight-dark);
        box-shadow: 0 4px 12px rgba(245, 200, 66, 0.3);
    }

    /* Pagination */
    .pagination .page-link {
        color: var(--hs-midnight);
        border-color: var(--hs-gray-200);
        transition: all 0.3s ease;
        font-weight: 500;
        border-radius: 8px;
        margin: 0 2px;
    }

    .pagination .page-link:hover {
        background: var(--hs-gold);
        color: var(--hs-midnight-dark);
        border-color: var(--hs-gold);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(245, 200, 66, 0.2);
    }

    .pagination .page-item.active .page-link {
        background: linear-gradient(135deg, var(--hs-midnight-dark) 0%, var(--hs-midnight-blue) 100%);
        border-color: var(--hs-gold);
        color: var(--hs-white);
        box-shadow: 0 4px 15px rgba(10, 22, 40, 0.2);
    }

    /* Empty state */
    .empty-state {
        text-align: center;
        padding: 4rem 2rem;
        color: var(--hs-gray-500);
    }

    .empty-state i {
        font-size: 4rem;
        color: var(--hs-gold);
        margin-bottom: 1rem;
        display: block;
        opacity: 0.5;
    }

    .empty-state h5 {
        color: var(--hs-midnight);
        font-weight: 700;
        font-size: 1.2rem;
    }

    .empty-state p {
        color: var(--hs-gray-400);
    }

    /* Loading */
    .spinner-border {
        color: var(--hs-gold) !important;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .breadcrumb-bar {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 1rem 1.25rem;
        }

        .breadcrumb-bar .d-flex.gap-2 {
            width: 100%;
            flex-wrap: wrap;
        }

        .breadcrumb-bar .d-flex.gap-2 .btn {
            flex: 1;
            min-width: 100px;
            font-size: 0.8rem;
            padding: 0.3rem 0.8rem;
        }

        .modal-dialog {
            margin: 0.5rem;
        }

        .modal-body {
            padding: 1.25rem;
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
        <h5 class="mb-0"><i class="bi bi-calendar-event"></i> Hearing Schedule</h5>
        <small class="text-muted">Manage public hearings and consultation sessions</small>
      </div>
      <div class="d-flex gap-2 no-print">
        <a href="calendar.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-calendar3"></i> Calendar View</a>
        <a href="print.php" target="_blank" id="printScheduleLink" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer"></i> Print Schedule</a>
        <?php if (canManage()): ?>
        <button type="button" class="btn btn-primary btn-sm" id="btnAddHearing"><i class="bi bi-plus-circle"></i> Add Hearing</button>
        <?php endif; ?>
      </div>
    </div>

    <!-- ===== Filters ===== -->
    <div class="card mb-3 no-print">
      <div class="card-body">
        <form id="filterForm" class="row g-2">
          <div class="col-md-3">
            <input type="text" class="form-control form-control-sm" name="search" id="searchInput"
                   placeholder="Search title, venue, description...">
          </div>
          <div class="col-md-2">
            <select class="form-select form-select-sm" name="status">
              <option value="">All Status</option>
              <?php foreach (['Upcoming', 'Ongoing', 'Completed', 'Cancelled'] as $s): ?>
                <option value="<?= e($s) ?>"><?= e($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <select class="form-select form-select-sm" name="hearing_type_id">
              <option value="">All Types</option>
              <?php foreach ($hearingTypes as $t): ?>
                <option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <select class="form-select form-select-sm" name="committee_id">
              <option value="">All Committees</option>
              <?php foreach ($committees as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <input type="date" class="form-control form-control-sm" name="date_from" title="From date">
          </div>
          <div class="col-md-1">
            <input type="date" class="form-control form-control-sm" name="date_to" title="To date">
          </div>
        </form>
      </div>
    </div>

    <!-- ===== Table ===== -->
    <div class="card">
      <div id="hearingsTableWrap">
        <?php include __DIR__ . '/table.php'; ?>
      </div>
    </div>
  </div>
</div>

<!-- ===== Create / Edit Modal ===== -->
<?php if (canManage()): ?>
<div class="modal fade" id="hearingModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form id="hearingForm" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="id" id="hearing_id" value="0">
        <div class="modal-header">
          <h5 class="modal-title" id="hearingModalTitle"><i class="bi bi-calendar-plus"></i> Add Hearing</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Title <span class="text-danger">*</span></label>
              <input type="text" name="title" id="f_title" class="form-control" required maxlength="255">
            </div>
            <div class="col-md-6">
              <label class="form-label">Hearing Type</label>
              <select name="hearing_type_id" id="f_type" class="form-select">
                <option value="">-- Select Type --</option>
                <?php foreach ($hearingTypes as $t): ?>
                  <option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Committee</label>
              <select name="committee_id" id="f_committee" class="form-select">
                <option value="">-- Select Committee --</option>
                <?php foreach ($committees as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Date <span class="text-danger">*</span></label>
              <input type="date" name="hearing_date" id="f_date" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Time <span class="text-danger">*</span></label>
              <input type="time" name="hearing_time" id="f_time" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="status" id="f_status" class="form-select">
                <?php foreach (['Upcoming', 'Ongoing', 'Completed', 'Cancelled'] as $s): ?>
                  <option value="<?= e($s) ?>"><?= e($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Venue</label>
              <input type="text" name="venue" id="f_venue" class="form-control" maxlength="255">
            </div>
            <div class="col-12">
              <label class="form-label">Description</label>
              <textarea name="description" id="f_description" class="form-control" rows="3"></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Attach Documents</label>
              <input type="file" name="documents[]" class="form-control" multiple
                     accept=".pdf,.doc,.docx,.png,.jpg,.jpeg">
              <div class="form-text">PDF, DOC, DOCX, PNG, JPG, JPEG. You can select multiple files.</div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="btnSaveHearing"><i class="bi bi-check-circle"></i> Save Hearing</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$extraJs = [APP_URL . '/assets/js/hearings.js'];
include __DIR__ . '/../../layouts/footer.php';
?>