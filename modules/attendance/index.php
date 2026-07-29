<?php
/**
 * modules/attendance/index.php
 * ------------------------------------------------------------------
 * Attendance Tracking Module. Select a hearing, then either scan
 * stakeholder QR codes (camera-based, via html5-qrcode) or mark
 * attendance manually. Live stat cards + searchable/filterable
 * attendance table for the selected hearing.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireRole([ROLE_ADMIN, ROLE_STAFF, ROLE_COMMITTEE]);

$pageTitle  = 'Attendance Tracking';
$activeMenu = 'attendance';
$pdo = db();

$hearings = $pdo->query(
    "SELECT id, title, hearing_date, hearing_time, status FROM hearings ORDER BY hearing_date DESC"
)->fetchAll();

// Default to the requested hearing, or the most recent Upcoming/Ongoing one, or just the first hearing.
$selectedHearingId = (int)($_GET['hearing_id'] ?? 0);
if ($selectedHearingId <= 0 && !empty($hearings)) {
    foreach ($hearings as $h) {
        if (in_array($h['status'], ['Upcoming', 'Ongoing'], true)) { $selectedHearingId = (int)$h['id']; break; }
    }
    if ($selectedHearingId <= 0) $selectedHearingId = (int)$hearings[0]['id'];
}
$_GET['hearing_id'] = $selectedHearingId; // ensure table.php picks it up on initial include

include __DIR__ . '/../../layouts/header.php';
?>
<style>
    /* Attendance - Sidebar Color Scheme (Slate/Dark Gray + Amber) */
    /* ONLY COLORS CHANGED - NO SIZE ADJUSTMENTS */
    :root {
        --att-primary: #111827;
        --att-primary-light: #1F2937;
        --att-accent: #FBBF24;
        --att-accent-dark: #D97706;
        --att-white: #FFFFFF;
        --att-gray-50: #F8FAFC;
        --att-gray-100: #F1F5F9;
        --att-gray-200: #E2E8F0;
        --att-gray-300: #CBD5E1;
        --att-gray-400: #94A3B8;
        --att-gray-500: #64748B;
        --att-gray-600: #475569;
        --att-success: #10B981;
        --att-danger: #EF4444;
        --att-info: #06B6D4;
    }

    /* Breadcrumb Bar - Color Only */
    .breadcrumb-bar {
        background: var(--att-white);
        border-left: 5px solid var(--att-accent);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
    }

    .breadcrumb-bar h5 {
        color: var(--att-primary);
    }

    .breadcrumb-bar h5 i {
        color: var(--att-accent);
    }

    .breadcrumb-bar .text-muted {
        color: var(--att-gray-500) !important;
    }

    /* Buttons - Color Only */
    .btn-primary {
        background: linear-gradient(135deg, var(--att-primary) 0%, var(--att-primary-light) 100%);
        border: 1px solid rgba(251, 191, 36, 0.15);
        color: var(--att-white);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    }

    .btn-primary i {
        color: var(--att-accent);
    }

    .btn-primary:hover {
        border-color: var(--att-accent);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.25);
        color: var(--att-white);
    }

    .btn-outline-secondary {
        border: 2px solid var(--att-gray-200);
        color: var(--att-gray-600);
        background: transparent;
    }

    .btn-outline-secondary:hover {
        background: var(--att-gray-50);
        border-color: var(--att-accent);
        color: var(--att-primary);
    }

    .btn-outline-secondary i {
        color: var(--att-accent);
    }

    .btn-outline-primary {
        border: 2px solid var(--att-primary);
        color: var(--att-primary);
        background: transparent;
    }

    .btn-outline-primary:hover {
        background: var(--att-primary);
        color: var(--att-white);
    }

    .btn-outline-primary i {
        color: var(--att-accent);
    }

    /* Cards - Color Only */
    .card {
        border: 1px solid var(--att-gray-100);
        background: var(--att-white);
        box-shadow: 0 2px 20px rgba(0, 0, 0, 0.05);
    }

    .card-body {
        background: var(--att-white);
    }

    .card-header {
        background: linear-gradient(135deg, var(--att-primary) 0%, var(--att-primary-light) 100%);
        color: var(--att-white);
        border-bottom: 4px solid var(--att-accent);
    }

    .card-header i {
        color: var(--att-accent);
    }

    .card-header .btn-outline-primary {
        border-color: var(--att-white);
        color: var(--att-white);
    }

    .card-header .btn-outline-primary:hover {
        background: var(--att-white);
        color: var(--att-primary);
    }

    /* Form Controls - Color Only */
    .form-control,
    .form-select {
        border: 2px solid var(--att-gray-200);
        background: var(--att-gray-50);
        color: var(--att-primary);
    }

    .form-control:focus,
    .form-select:focus {
        border-color: var(--att-accent);
        box-shadow: 0 0 0 4px rgba(251, 191, 36, 0.15);
        background: var(--att-white);
    }

    .form-control::placeholder {
        color: var(--att-gray-400);
    }

    /* Stat Cards - Color Only */
    .stat-card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
        color: var(--att-white) !important;
        cursor: default;
        padding: 1.25rem 1.5rem !important;
        min-height: 120px;
    }

    .stat-card .stat-icon {
        font-size: 1.75rem;
        opacity: 0.9;
        margin-bottom: 0.5rem;
        display: block;
        color: rgba(255, 255, 255, 0.9);
    }

    .stat-card .stat-value {
        font-size: 2.2rem;
        font-weight: 800;
        line-height: 1.2;
        margin-bottom: 0.25rem;
        color: var(--att-white) !important;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    }

    .stat-card .stat-label {
        font-size: 0.85rem;
        opacity: 0.95;
        font-weight: 500;
        color: rgba(255, 255, 255, 0.95) !important;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
    }

    .bg-gov-blue {
        background: linear-gradient(135deg, #1A56DB 0%, #1E3A8A 100%);
    }

    .bg-gov-teal {
        background: linear-gradient(135deg, #0D9488 0%, #0F766E 100%);
    }

    .bg-gov-amber {
        background: linear-gradient(135deg, #D97706 0%, #B45309 100%);
    }

    .bg-gov-red {
        background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    }

    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2);
    }

    /* Alert - Color Only */
    .alert-info {
        background: #F8FAFC;
        border: 1px solid var(--att-gray-200);
        color: var(--att-primary);
        border-left: 4px solid var(--att-accent);
    }

    /* Modal - Color Only */
    .modal-content {
        border: 1px solid var(--att-gray-100);
        box-shadow: 0 25px 70px rgba(0, 0, 0, 0.25);
    }

    .modal-header {
        background: linear-gradient(135deg, var(--att-primary) 0%, var(--att-primary-light) 100%);
        color: var(--att-white);
        border-bottom: 4px solid var(--att-accent);
    }

    .modal-header .modal-title {
        color: var(--att-white);
    }

    .modal-header .modal-title i {
        color: var(--att-accent);
        background: rgba(251, 191, 36, 0.15);
    }

    .modal-body {
        background: var(--att-gray-50);
    }

    .modal-footer {
        background: var(--att-white);
        border-top: 1px solid var(--att-gray-100);
    }

    .modal-footer .btn-secondary {
        background: var(--att-gray-100);
        color: var(--att-gray-600);
    }

    .modal-footer .btn-secondary:hover {
        background: var(--att-gray-200);
    }

    /* Form labels - Color Only */
    .modal-body .form-label,
    .card-body .form-label {
        color: var(--att-primary);
    }

    .card-body .form-label .text-danger {
        color: var(--att-danger);
    }

    .form-text {
        color: var(--att-gray-500);
    }

    /* Table - Color Only */
    #attendanceTableWrap {
        background: var(--att-white);
    }

    .table thead th {
        background: linear-gradient(135deg, var(--att-primary) 0%, var(--att-primary-light) 100%);
        color: var(--att-white) !important;
        border-bottom: 4px solid var(--att-accent);
    }

    .table thead th i {
        color: var(--att-accent);
    }

    .table thead th,
    .table thead th *,
    .table thead th span,
    .table thead th div {
        color: var(--att-white) !important;
    }

    .table tbody td {
        color: var(--att-primary);
        border-bottom: 1px solid var(--att-gray-100);
    }

    .table tbody tr:hover {
        background: #FFFBEB;
    }

    /* Badges - Color Only */
    .badge.bg-success {
        background: var(--att-success) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
    }

    .badge.bg-warning {
        background: var(--att-accent) !important;
        color: var(--att-primary);
        box-shadow: 0 2px 8px rgba(251, 191, 36, 0.3);
    }

    .badge.bg-danger {
        background: var(--att-danger) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
    }

    .badge.bg-secondary {
        background: var(--att-gray-400) !important;
        color: white;
    }

    /* Action buttons - Color Only */
    .btn-action.edit {
        color: var(--att-accent-dark);
        background: rgba(217, 119, 6, 0.08);
    }

    .btn-action.edit:hover {
        background: var(--att-accent-dark);
        color: white;
        box-shadow: 0 4px 12px rgba(217, 119, 6, 0.3);
    }

    .btn-action.delete {
        color: var(--att-danger);
        background: rgba(239, 68, 68, 0.08);
    }

    .btn-action.delete:hover {
        background: var(--att-danger);
        color: white;
        box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
    }

    .btn-action.view {
        color: var(--att-accent);
        background: rgba(251, 191, 36, 0.08);
    }

    .btn-action.view:hover {
        background: var(--att-accent);
        color: var(--att-primary);
        box-shadow: 0 4px 12px rgba(251, 191, 36, 0.3);
    }

    /* Pagination - Color Only */
    .pagination .page-link {
        color: var(--att-primary);
        border-color: var(--att-gray-200);
    }

    .pagination .page-link:hover {
        background: var(--att-accent);
        color: var(--att-primary);
        border-color: var(--att-accent);
        box-shadow: 0 4px 12px rgba(251, 191, 36, 0.2);
    }

    .pagination .page-item.active .page-link {
        background: linear-gradient(135deg, var(--att-primary) 0%, var(--att-primary-light) 100%);
        border-color: var(--att-accent);
        color: var(--att-white);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    }

    /* QR Reader specific */
    #qrReader {
        background: var(--att-gray-50);
        border-radius: 8px;
        border: 2px solid var(--att-gray-200);
        min-height: 200px;
    }

    #qrReader video {
        border-radius: 8px;
    }

    #scanResult .alert {
        border-radius: 8px;
    }

    #scanResult .alert-success {
        background: #ECFDF5;
        border-color: var(--att-success);
        color: #065F46;
    }

    #scanResult .alert-danger {
        background: #FEF2F2;
        border-color: var(--att-danger);
        color: #991B1B;
    }

    /* Stakeholder search results */
    #stakeholderResults .list-group-item {
        border: 1px solid var(--att-gray-200);
        color: var(--att-primary);
        cursor: pointer;
        transition: all 0.2s ease;
    }

    #stakeholderResults .list-group-item:hover {
        background: #FFFBEB;
        border-color: var(--att-accent);
    }

    /* Responsive - sizes preserved */
    @media (max-width: 768px) {
        .stat-card .stat-value {
            font-size: 1.8rem;
        }

        .stat-card .stat-icon {
            font-size: 1.5rem;
        }

        .stat-card .stat-label {
            font-size: 0.75rem;
        }
    }

    @media (max-width: 576px) {
        .stat-card {
            padding: 1rem !important;
            min-height: 100px;
        }

        .stat-card .stat-value {
            font-size: 1.5rem;
        }

        .stat-card .stat-label {
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
    margin-left: 10px !important;
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
        <h5 class="mb-0"><i class="bi bi-qr-code-scan"></i> Attendance Tracking</h5>
        <small class="text-muted">Scan or manually record stakeholder attendance per hearing</small>
      </div>
      <div class="d-flex gap-2 no-print">
        <a href="history.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-clock-history"></i> History</a>
      </div>
    </div>

    <div class="card mb-3 no-print">
      <div class="card-body">
        <label class="form-label small text-muted mb-1">Select Hearing</label>
        <select id="hearingSelect" class="form-select">
          <?php if (empty($hearings)): ?>
            <option value="">No hearings available</option>
          <?php endif; ?>
          <?php foreach ($hearings as $h): ?>
            <option value="<?= (int)$h['id'] ?>" <?= $selectedHearingId === (int)$h['id'] ? 'selected' : '' ?>>
              <?= e($h['title']) ?> — <?= formatDate($h['hearing_date']) ?> (<?= e($h['status']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <?php if ($selectedHearingId > 0): ?>
    <!-- ===== Stat Cards ===== -->
    <div class="row g-3 mb-3" id="statCards">
      <div class="col-sm-6 col-lg-3">
        <div class="card stat-card bg-gov-blue p-3">
          <i class="bi bi-people stat-icon"></i>
          <div class="stat-value" id="statRegistered">-</div>
          <div class="stat-label">Registered</div>
        </div>
      </div>
      <div class="col-sm-6 col-lg-3">
        <div class="card stat-card bg-gov-teal p-3">
          <i class="bi bi-check-circle stat-icon"></i>
          <div class="stat-value" id="statPresent">-</div>
          <div class="stat-label">Present</div>
        </div>
      </div>
      <div class="col-sm-6 col-lg-3">
        <div class="card stat-card bg-gov-amber p-3">
          <i class="bi bi-clock-history stat-icon"></i>
          <div class="stat-value" id="statLate">-</div>
          <div class="stat-label">Late</div>
        </div>
      </div>
      <div class="col-sm-6 col-lg-3">
        <div class="card stat-card bg-gov-red p-3">
          <i class="bi bi-x-circle stat-icon"></i>
          <div class="stat-value" id="statAbsent">-</div>
          <div class="stat-label">Absent</div>
        </div>
      </div>
    </div>

    <div class="row g-3 mb-3 no-print">
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-camera"></i> QR Code Scanner</span>
            <button class="btn btn-sm btn-outline-primary" id="btnToggleScanner">Start Scanner</button>
          </div>
          <div class="card-body">
            <div id="cameraSelectWrap" class="mb-2 d-none">
              <label class="form-label small text-muted mb-1">Camera</label>
              <select id="cameraSelect" class="form-select form-select-sm"></select>
            </div>
            <div id="qrReader" style="width:100%;"></div>
            <div id="scanResult" class="mt-2"></div>
            <p class="small text-muted mb-2 mt-2">Requires camera access over HTTPS or <code>localhost</code> — most browsers block camera access on a plain HTTP LAN address (e.g. when opening the app from a phone at <code>http://192.168.x.x/...</code>). Point the stakeholder's QR code at the camera to check them in automatically.</p>

            <hr>
            <label class="form-label small fw-semibold"><i class="bi bi-keyboard"></i> Camera unavailable? Enter code manually</label>
            <form id="manualCodeForm" class="d-flex gap-2">
              <input type="text" id="manualCodeInput" class="form-control form-control-sm" placeholder="Paste or type the stakeholder's QR code value" autocomplete="off">
              <button type="submit" class="btn btn-outline-primary btn-sm text-nowrap"><i class="bi bi-check-circle"></i> Check In</button>
            </form>
          </div>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header"><i class="bi bi-pencil-square"></i> Manual Attendance</div>
          <div class="card-body">
            <form id="manualForm">
              <?= csrfField() ?>
              <input type="hidden" name="hearing_id" id="manualHearingId" value="<?= $selectedHearingId ?>">
              <div class="mb-3 position-relative">
                <label class="form-label small">Stakeholder</label>
                <input type="text" id="stakeholderSearch" class="form-control" placeholder="Type name or email to search..." autocomplete="off">
                <input type="hidden" name="stakeholder_id" id="manualStakeholderId">
                <div id="stakeholderResults" class="list-group position-absolute w-100 shadow-sm" style="z-index:20;"></div>
              </div>
              <div class="mb-3">
                <label class="form-label small">Status</label>
                <select name="status" class="form-select" required>
                  <option value="Present">Present</option>
                  <option value="Late">Late</option>
                  <option value="Absent">Absent</option>
                </select>
              </div>
              <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check-circle"></i> Record Attendance</button>
            </form>
          </div>
        </div>
      </div>
    </div>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2 no-print">
      <h6 class="mb-0">Attendance Records</h6>
      <div class="d-flex gap-2">
        <input type="text" class="form-control form-control-sm" id="searchInput" placeholder="Search records...">
        <select class="form-select form-select-sm" id="statusFilter">
          <option value="">All Status</option>
          <option value="Present">Present</option>
          <option value="Late">Late</option>
          <option value="Absent">Absent</option>
        </select>
        <a href="print.php?hearing_id=<?= $selectedHearingId ?>" target="_blank" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer"></i></a>
        <a href="export_pdf.php?hearing_id=<?= $selectedHearingId ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
        <a href="export_excel.php?hearing_id=<?= $selectedHearingId ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-excel"></i> Excel</a>
      </div>
    </div>

    <div class="card">
      <div id="attendanceTableWrap">
        <?php include __DIR__ . '/table.php'; ?>
      </div>
    </div>
    <?php else: ?>
      <div class="alert alert-info">No hearings exist yet. Create one in the Hearing Schedule module first.</div>
    <?php endif; ?>
  </div>
</div>

<script>window.SELECTED_HEARING_ID = <?= (int)$selectedHearingId ?>;</script>
<?php
$extraJs = [APP_URL . '/assets/js/attendance.js'];
include __DIR__ . '/../../layouts/footer.php';
?>