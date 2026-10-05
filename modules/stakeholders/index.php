<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';

requireLogin();
if (!canManage()) {
    setFlash('danger','Stakeholder management is limited to authorized staff.');
    redirect(APP_URL . '/dashboard.php');
}

$pdo = db();
$pageTitle = 'Stakeholders & Invitations';
$activeMenu = 'stakeholders';

$categories = $pdo->query('SELECT id,name FROM stakeholder_categories ORDER BY id ASC')->fetchAll();
$stakeholders = $pdo->query(
    'SELECT s.*,sc.name AS category_name,
        (SELECT code_value FROM qr_codes q WHERE q.stakeholder_id=s.id LIMIT 1) AS code_value,
        (SELECT COUNT(*) FROM invitations i WHERE i.stakeholder_id=s.id) AS invitation_count,
        (SELECT COUNT(*) FROM registrations r WHERE r.stakeholder_id=s.id) AS registration_count
     FROM stakeholders s
     LEFT JOIN stakeholder_categories sc ON sc.id=s.category_id
     ORDER BY s.created_at DESC,s.id DESC'
)->fetchAll();

$stats = $pdo->query(
    "SELECT COUNT(*) total,
      SUM(status='Verified') verified,
      SUM(status='Pending') pending,
      SUM(status='Inactive') inactive
     FROM stakeholders"
)->fetch();

include __DIR__ . '/../../layouts/header.php';
?>
<link rel="stylesheet" href="<?= e(APP_URL . '/assets/css/lph-complete-modules.css') ?>">
<script src="<?= e(vendorAsset('qrcodejs/qrcode.min.js', 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js')) ?>"></script>
<style>
.stakeholder-scroll-container {
  max-height: 65vh;
  overflow-y: auto;
  overflow-x: auto;
  position: relative;
}
.stakeholder-scroll-container thead th {
  position: sticky;
  top: 0;
  z-index: 10;
  background: #0f2137 !important;
  color: #ffffff !important;
  border-bottom: 3px solid #a97900 !important;
  box-shadow: 0 2px 6px rgba(15, 33, 55, 0.2);
}
.stakeholder-scroll-container::-webkit-scrollbar {
  width: 7px;
  height: 7px;
}
.stakeholder-scroll-container::-webkit-scrollbar-track {
  background: #f1f5f9;
}
.stakeholder-scroll-container::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 4px;
}
.stakeholder-scroll-container::-webkit-scrollbar-thumb:hover {
  background: #94a3b8;
}
.table-highlight-flash {
  animation: stkRowFlash 2.4s cubic-bezier(0.2, 0.8, 0.2, 1);
}
@keyframes stkRowFlash {
  0% { background-color: #d1e7dd !important; transform: scale(1.002); box-shadow: 0 0 0 3px rgba(25, 135, 84, 0.45); }
  40% { background-color: #d1e7dd !important; }
  100% { background-color: transparent !important; transform: scale(1); box-shadow: none; }
}
.btn-quick-verify {
  font-weight: 600;
  transition: all 0.2s ease;
}
.btn-quick-verify:hover {
  transform: translateY(-1px);
  box-shadow: 0 2px 6px rgba(25, 135, 84, 0.25);
}
</style>
<div class="app-wrapper">
<?php include __DIR__ . '/../../layouts/sidebar.php'; ?>
<div class="main-content">

<?php include __DIR__ . '/../../layouts/top_controls.php'; ?>

<div class="lphx-head d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3">
    <div style="flex: 1 1 auto; max-width: 650px;">
        <div class="lphx-eyebrow"><i class="bi bi-people"></i> Step 3</div>
        <h1 class="mb-1">Stakeholder Invitation & Registration</h1>
        <p class="mb-0">Maintain stakeholder records, verification, invitations, registration approval, capacity control and QR-ready attendance credentials.</p>
    </div>
    <div class="d-flex align-items-center flex-nowrap gap-2" style="flex-shrink: 0; white-space: nowrap;">
        <button type="button" class="btn btn-outline-dark btn-sm text-nowrap" id="btnShowRegistrationQr"><i class="bi bi-qr-code-scan me-1"></i> Registration QR Code</button>
        <a href="invitations.php" class="btn btn-outline-secondary btn-sm text-nowrap"><i class="bi bi-envelope-paper me-1"></i> Invitations</a>
        <a href="registrations.php" class="btn btn-outline-secondary btn-sm text-nowrap"><i class="bi bi-person-check me-1"></i> Registrations</a>
        <button class="btn btn-primary btn-sm text-nowrap" id="btnNewStakeholder"><i class="bi bi-person-plus me-1"></i> New Stakeholder</button>
    </div>
</div>

<div class="row g-3 mb-3">
<?php foreach ([
    ['Total',$stats['total'] ?? 0,'bi-people'],
    ['Verified',$stats['verified'] ?? 0,'bi-patch-check'],
    ['Pending',$stats['pending'] ?? 0,'bi-hourglass-split'],
    ['Inactive',$stats['inactive'] ?? 0,'bi-person-x'],
] as [$label,$value,$icon]): ?>
<div class="col-6 col-lg-3"><div class="lphx-stat"><i class="bi <?= e($icon) ?>"></i><div><strong id="stat_count_<?= strtolower($label) ?>"><?= (int)$value ?></strong><small><?= e($label) ?></small></div></div></div>
<?php endforeach; ?>
</div>

<div class="card lphx-card mb-3">
  <div class="card-body p-2.5">
    <div class="row g-2 align-items-center">
      <div class="col-md-5">
        <div class="input-group input-group-sm">
          <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
          <input type="text" id="stakeholderSearchInput" class="form-control border-start-0" placeholder="Search name, office, email, sector...">
        </div>
      </div>
      <div class="col-md-5">
        <select class="form-select form-select-sm" id="stakeholderCategoryFilter">
          <option value="">Lahat ng Department / Kategorya</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= e(preg_replace('/^[A-H]\s*\.?\s*/', '', $c['name'])) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 text-end">
        <button class="btn btn-sm btn-outline-secondary w-100" id="btnResetFilters" type="button"><i class="bi bi-arrow-counterclockwise"></i> Reset</button>
      </div>
    </div>
  </div>
</div>

<div class="card lphx-card">
<div class="card-header d-flex justify-content-between align-items-center">
  <span><i class="bi bi-person-vcard me-1 text-primary"></i> Stakeholder Registry</span>
  <span class="badge bg-light text-dark border" id="stakeholderCountBadge"><?php echo count($stakeholders); ?> record(s)</span>
</div>
<div class="table-responsive stakeholder-scroll-container">
<table class="table table-hover lphx-table mb-0">
<thead><tr><th>Name</th><th>Department / Category</th><th>Contact</th><th>Status</th><th>Activity</th><th class="text-end">Actions</th></tr></thead>
<tbody>
<?php foreach ($stakeholders as $s): 
    $sId = (int)$s['id'];
    $sIsVerified = in_array($s['status'], ['Verified', 'Approved', 'Active'], true);
?>
<?php 
    $sValidIdUrl = !empty($s['valid_id_path']) ? (APP_URL . '/assets/uploads/' . $s['valid_id_path']) : '';
?>
<tr class="stakeholder-table-row" 
    id="stakeholder_row_<?= $sId ?>"
    data-id="<?= $sId ?>"
    data-name="<?= e($s['full_name']) ?>"
    data-status="<?= e($s['status']) ?>"
    data-valid-id-url="<?= e($sValidIdUrl) ?>"
    data-search="<?php echo strtolower($s['full_name'] . ' ' . $s['organization'] . ' ' . $s['phone'] . ' ' . $s['email'] . ' ' . $s['sector'] . ' ' . ($s['category_name'] ?? '')); ?>" 
    data-category-id="<?php echo $s['category_id']; ?>">
    <td class="stakeholder-name-col">
        <strong><?php echo e($s['full_name']); ?></strong>
        <?php if (!empty($sValidIdUrl)): ?>
          <div class="mt-1">
            <button type="button" class="btn btn-xs btn-outline-info py-0 px-1.5 btn-preview-valid-id" 
                    style="font-size: 11px; border-radius: 4px;"
                    data-id="<?= $sId ?>"
                    data-name="<?= e($s['full_name']) ?>"
                    data-status="<?= e($s['status']) ?>"
                    data-org="<?= e($s['organization']) ?>"
                    data-id-url="<?= e($sValidIdUrl) ?>">
              <i class="bi bi-person-vcard text-info me-1"></i>View Valid ID
            </button>
          </div>
        <?php endif; ?>
    </td>
    <td class="stakeholder-org-col">
        <strong><?php echo e($s['organization']); ?></strong>
        <div class="small text-muted stakeholder-sector-label"><i class="bi bi-building me-1"></i><?php echo e($s['sector']); ?></div>
    </td>
    <td class="stakeholder-contact-col">
        <div class="stakeholder-phone-val"><i class="bi bi-telephone text-muted me-1 small"></i><?php echo e($s['phone'] ?: 'Not publicly listed'); ?></div>
        <div class="small text-muted stakeholder-email-val d-flex align-items-center gap-1.5 mt-0.5">
            <i class="bi bi-envelope text-muted me-1 small"></i>
            <span class="stk-email-val-text" id="stk_email_span_<?= $sId ?>"><?php echo e($s['email'] ?: 'Not publicly listed'); ?></span>
            <button type="button" class="btn btn-link btn-sm p-0 text-primary ms-1 btn-quick-change-stk-email" 
                    data-id="<?= $sId ?>" 
                    data-name="<?= e($s['full_name']) ?>" 
                    data-email="<?= e($s['email'] ?: '') ?>" 
                    title="Edit Gmail / Email address for this stakeholder">
                <i class="bi bi-pencil-square" style="font-size:0.85rem;"></i>
            </button>
        </div>
    </td>
    <td class="status-cell">
        <span class="badge <?php echo $sIsVerified ? 'text-bg-success' : ($s['status'] === 'Pending' ? 'text-bg-warning' : 'text-bg-secondary'); ?>">
            <?php if ($sIsVerified): ?>
                <i class="bi bi-patch-check-fill me-1"></i>
            <?php elseif ($s['status'] === 'Pending'): ?>
                <i class="bi bi-hourglass-split me-1"></i>
            <?php endif; ?>
            <?php echo e($s['status']); ?>
        </span>
    </td>
    <td><span class="small"><?php echo (int)$s['invitation_count']; ?> invite(s) · <?php echo (int)$s['registration_count']; ?> registration(s)</span></td>
    <td class="actions-cell text-end text-nowrap">
        <?php if (!empty($s['code_value']) && $sIsVerified): ?>
            <a href="qr.php?id=<?= $sId ?>" class="btn btn-sm btn-outline-dark me-1 btn-qr-action" title="View & Print Attendance QR Pass">
                <i class="bi bi-qr-code"></i>
            </a>
        <?php else: ?>
            <span class="d-inline-block qr-placeholder" tabindex="0" data-bs-toggle="tooltip" title="QR Code is available once account is Verified">
                <button type="button" class="btn btn-sm btn-outline-secondary me-1 opacity-50" disabled style="pointer-events: none;">
                    <i class="bi bi-qr-code"></i>
                </button>
            </span>
        <?php endif; ?>
        <?php if ($s['status'] === 'Pending'): ?>
            <button type="button" class="btn btn-sm btn-outline-success btn-quick-verify me-1" 
                    data-id="<?= $sId ?>" 
                    data-name="<?= e($s['full_name']) ?>" 
                    data-valid-id-url="<?= e($sValidIdUrl) ?>"
                    title="Verify this stakeholder">
                <i class="bi bi-patch-check me-1"></i>Verify
            </button>
        <?php endif; ?>
        <button type="button" class="btn btn-sm btn-outline-primary btn-edit-stakeholder" data-id="<?= $sId ?>" data-row="<?php echo htmlspecialchars(json_encode($s, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">
            <i class="bi bi-pencil-square me-1"></i>Edit
        </button>
    </td>
</tr>
<?php endforeach; ?>
<tr id="noMatchesRow" class="d-none"><td colspan="6" class="text-center py-4 text-muted"><i class="bi bi-search me-1"></i>Walang stakeholder na tumugma sa napiling Department o Search filter.</td></tr>
</tbody>
</table>
</div>
</div>

</div></div>

<div class="modal fade" id="stakeholderModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: 820px;">
    <div class="modal-content border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
      <form id="stakeholderForm">
        <?= csrfField() ?>
        <input type="hidden" name="id" id="s_id" value="0">
        <div class="modal-header py-3 px-4 bg-light border-bottom">
          <div class="d-flex align-items-center gap-2.5">
            <div class="rounded-circle d-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary" style="width: 36px; height: 36px;">
              <i class="bi bi-person-badge fs-5"></i>
            </div>
            <div>
              <h5 class="modal-title fw-bold text-dark mb-0" style="font-size: 1.05rem;">Stakeholder Record</h5>
              <small class="text-muted" style="font-size: 0.8rem;">Manage organization, civic representative, or individual participant</small>
            </div>
          </div>
          <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Full Name <span class="text-danger">*</span></label>
              <input class="form-control" name="full_name" id="s_name" placeholder="Hal. Atty. Eduardo Quintos XIV" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Email <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="email" id="s_email" placeholder="official@manila.gov.ph o Not publicly listed" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Phone</label>
              <input class="form-control" name="phone" id="s_phone" placeholder="(02) 8521-7505 / 0917-xxxxxxx">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Status</label>
              <select class="form-select" name="status" id="s_status">
                <option value="Verified">Verified</option>
                <option value="Pending">Pending</option>
                <option value="Inactive">Inactive</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Organization / Agency / Office <span class="text-danger">*</span></label>
              <input class="form-control" name="organization" id="s_org" placeholder="Hal. Office of the City Administrator, Barangay 659, etc." required>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Sector / Designation / Title</label>
              <input class="form-control" name="sector" id="s_sector" placeholder="e.g. City Administrator, OIC, Barangay Captain, Director">
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold text-secondary mb-1"><i class="bi bi-diagram-3 text-primary me-1"></i>Department / Stakeholder Category <span class="text-danger">*</span></label>
              <select class="form-select" name="category_id" id="s_category" required>
                <option value="">-- Select Department / Category --</option>
                <?php foreach($categories as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= e(preg_replace('/^[A-H]\s*\.?\s*/', '', $c['name'])) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold text-secondary mb-1">Address / Office Location</label>
              <textarea class="form-control" name="address" id="s_address" rows="2" placeholder="e.g. Manila City Hall, Padre Burgos Ave, Ermita, Manila"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer py-2.5 px-4 bg-light border-top d-flex justify-content-end gap-2">
          <button class="btn btn-light border px-3" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary px-4 fw-semibold shadow-sm" type="submit"><i class="bi bi-check2-circle me-1"></i> Save Stakeholder</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ============================================================
     REGISTRATION QR CODE MODAL (Railway / Domain Ready)
     ============================================================ -->
<?php
$defaultRegUrl = rtrim(APP_URL, '/') . '/modules/stakeholders/register.php';
?>
<div class="modal fade" id="registrationQrModal" tabindex="-1" aria-labelledby="regQrModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width: 420px;">
    <div class="modal-content border-0 shadow-lg text-center" style="border-radius: 20px; overflow: hidden;">
      <div class="modal-header text-white justify-content-between py-3 px-4" style="background: #0F2137; border-bottom: 4px solid #c89523;">
        <h5 class="modal-title fw-bold fs-5 mb-0" id="regQrModalLabel">
          <i class="bi bi-qr-code-scan me-2 text-warning"></i>QR REGISTRATION
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4 bg-light">
        <!-- QR Code Canvas Display -->
        <div class="d-inline-block p-3 bg-white border border-2 border-dark rounded-4 shadow-sm mb-3">
          <div id="modalQrCodeHolder" class="d-flex justify-content-center align-items-center" style="min-width: 240px; min-height: 240px;"></div>
        </div>

        <div class="mb-3">
          <span class="badge bg-dark px-3 py-2 text-uppercase fw-bold fs-6" style="letter-spacing: 1px;">QR REGISTRATION</span>
        </div>
        <p class="text-muted small px-2 mb-3">Scan this QR code using a smartphone camera to open and fill out the official stakeholder registration form.</p>

        <div class="d-flex gap-2 justify-content-center flex-wrap">
          <a href="print_registration_qr.php" target="_blank" id="btnPrintStandeeUrl" class="btn btn-dark btn-sm px-3 fw-semibold">
            <i class="bi bi-printer me-1"></i> Print / Download Standee
          </a>
          <a href="<?= e($defaultRegUrl) ?>" target="_blank" id="btnOpenRegUrl" class="btn btn-outline-primary btn-sm px-3 fw-semibold">
            <i class="bi bi-box-arrow-up-right me-1"></i> Open Form
          </a>
          <a href="/legislative/stakeholder_portal/" target="_blank" class="btn btn-warning btn-sm px-3 fw-semibold text-dark shadow-sm">
            <i class="bi bi-person-workspace me-1"></i> Stakeholder Portal
          </a>
        </div>
      </div>
      <div class="modal-footer bg-white py-2 px-3 border-top justify-content-center">
        <button type="button" class="btn btn-sm btn-secondary px-4 rounded-pill" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ============================================================
     VALID ID PREVIEW MODAL
     ============================================================ -->
<div class="modal fade" id="validIdPreviewModal" tabindex="-1" aria-labelledby="idModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 14px; overflow: hidden;">
      <div class="modal-header bg-dark text-white py-2.5 px-3">
        <h6 class="modal-title fw-semibold" id="idModalLabel"><i class="bi bi-person-vcard text-info me-2"></i>Stakeholder Valid ID Verification</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-3 text-center bg-light">
        <div id="validIdDetails" class="mb-2 text-start p-2 bg-white rounded border small"></div>
        <div id="validIdImageContainer" class="d-flex justify-content-center align-items-center" style="min-height: 250px;">
          <!-- Loaded via JS -->
        </div>
      </div>
      <div class="modal-footer py-2 px-3 bg-white border-top d-flex justify-content-between">
        <a id="btnOpenIdOriginal" href="#" target="_blank" class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-arrows-fullscreen me-1"></i> View Full File
        </a>
        <button type="button" class="btn btn-sm btn-primary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: View Assigned Hearings for Stakeholder -->
<div class="modal fade" id="stakeholderHearingsModal" tabindex="-1" aria-labelledby="shModalName" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-3">
            <div class="modal-header border-bottom py-3 px-4 bg-light">
                <div class="d-flex align-items-center gap-3">
                    <div id="shModalAvatar" class="stk-avatar shadow-sm" style="width: 46px; height: 46px; font-size: 1.05rem; flex-shrink: 0;">--</div>
                    <div>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <h5 class="modal-title fw-bold text-dark mb-0" id="shModalName">Assigned Hearings</h5>
                            <span id="shModalBadgeCount" class="badge bg-primary text-white rounded-pill px-2.5 py-1">0 Hearings</span>
                        </div>
                        <div class="small text-muted mt-0.5" id="shModalSubtitle">Viewing assigned hearing schedules for this participant</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="shModalBody">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                    <div class="small text-muted mt-2">Loading assigned hearings...</div>
                </div>
            </div>
            <div class="modal-footer py-2.5 px-4 bg-light border-top d-flex justify-content-between align-items-center">
                <span class="small text-muted" id="shModalFooterInfo"></span>
                <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded',function(){
  const APP_URL = <?= json_encode(rtrim(APP_URL, '/')) ?>;
 const modal=new bootstrap.Modal(document.getElementById('stakeholderModal'));
 const form=document.getElementById('stakeholderForm');
  // Real-time Search & Department Filtering
  const searchInput = document.getElementById('stakeholderSearchInput');
  const catFilter = document.getElementById('stakeholderCategoryFilter');
  const resetBtn = document.getElementById('btnResetFilters');
  const rows = document.querySelectorAll('.stakeholder-table-row');
  const noMatches = document.getElementById('noMatchesRow');
  const countBadge = document.getElementById('stakeholderCountBadge');

  function applyFilters() {
    const q = (searchInput?.value || '').toLowerCase().trim();
    const cat = catFilter?.value || '';
    let visible = 0;

    rows.forEach(r => {
      const matchSearch = !q || (r.dataset.search || '').includes(q);
      const matchCat = !cat || r.dataset.categoryId === cat;
      if (matchSearch && matchCat) {
        r.classList.remove('d-none');
        visible++;
      } else {
        r.classList.add('d-none');
      }
    });

    if (noMatches) {
      if (visible === 0 && rows.length > 0) {
        noMatches.classList.remove('d-none');
      } else {
        noMatches.classList.add('d-none');
      }
    }
    if (countBadge) {
      countBadge.textContent = visible + ' record(s)';
    }
  }

  if (searchInput) searchInput.addEventListener('input', applyFilters);
  if (catFilter) catFilter.addEventListener('change', applyFilters);
  if (resetBtn) {
    resetBtn.addEventListener('click', function() {
      if (searchInput) searchInput.value = '';
      if (catFilter) catFilter.value = '';
      applyFilters();
    });
  }

  const btnNew = document.getElementById('btnNewStakeholder');
  if (btnNew) {
    btnNew.onclick = function(){
      form.reset();
      document.getElementById('s_id').value = '0';
      document.getElementById('s_status').value = 'Verified';
      document.getElementById('s_category').value = '';
      modal.show();
    };
  }

  // ============================================================
  // Registration QR Code Modal & Dynamic Domain/Railway Handler
  // ============================================================
  const regQrModalEl = document.getElementById('registrationQrModal');
  const regQrModal = regQrModalEl ? new bootstrap.Modal(regQrModalEl) : null;
  const qrHolder = document.getElementById('modalQrCodeHolder');
  const regUrlInput = document.getElementById('regQrUrlInput');
  const btnOpenReg = document.getElementById('btnOpenRegUrl');
  const btnPrintStandee = document.getElementById('btnPrintStandeeUrl');
  const btnResetReg = document.getElementById('btnResetRegUrl');
  const defaultUrl = <?= json_encode($defaultRegUrl) ?>;

  function renderRegQr(url) {
    if (!qrHolder) return;
    qrHolder.innerHTML = '';
    const cleanUrl = (url || '').trim() || defaultUrl;
    try {
      new QRCode(qrHolder, {
        text: cleanUrl,
        width: 220,
        height: 220,
        colorDark: "#0F2137",
        colorLight: "#ffffff",
        correctLevel: (typeof QRCode !== 'undefined' && QRCode.CorrectLevel) ? QRCode.CorrectLevel.M : 0
      });
    } catch(err) {
      console.error('QRCode render error:', err);
      qrHolder.innerHTML = '<div class="alert alert-warning small mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Unable to generate QR: ' + escapeHtml(err.message) + '</div>';
    }

    if (btnOpenReg) btnOpenReg.href = cleanUrl;
    if (btnPrintStandee) btnPrintStandee.href = 'print_registration_qr.php?url=' + encodeURIComponent(cleanUrl);
  }

  const btnShowRegQr = document.getElementById('btnShowRegistrationQr');
  if (btnShowRegQr && regQrModal) {
    btnShowRegQr.addEventListener('click', function() {
      renderRegQr(defaultUrl);
      regQrModal.show();
    });
  }

  // ============================================================
  // Valid ID Preview Modal Handler
  // ============================================================
  const validIdModalEl = document.getElementById('validIdPreviewModal');
  const validIdModal = validIdModalEl ? new bootstrap.Modal(validIdModalEl) : null;
  document.addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-preview-valid-id');
    if (!btn || !validIdModal) return;
    e.preventDefault();

    const name = btn.dataset.name || 'Stakeholder';
    const org = btn.dataset.org || '';
    const status = btn.dataset.status || '';
    const idUrl = btn.dataset.idUrl || '';

    const detailsEl = document.getElementById('validIdDetails');
    const containerEl = document.getElementById('validIdImageContainer');
    const origLink = document.getElementById('btnOpenIdOriginal');

    if (detailsEl) {
      detailsEl.innerHTML = `<strong>${escapeHtml(name)}</strong> &bull; <span class="text-muted">${escapeHtml(org)}</span> &bull; Status: <span class="badge ${status === 'Verified' ? 'bg-success' : 'bg-warning'}">${escapeHtml(status)}</span>`;
    }
    if (origLink) origLink.href = idUrl;

    if (containerEl) {
      const ext = idUrl.split('.').pop().toLowerCase();
      if (ext === 'pdf') {
        containerEl.innerHTML = `<iframe src="${idUrl}" style="width: 100%; height: 480px; border: none; border-radius: 8px;"></iframe>`;
      } else {
        containerEl.innerHTML = `<img src="${idUrl}" alt="Valid ID" class="img-fluid rounded border shadow-sm" style="max-height: 480px; object-fit: contain;">`;
      }
    }
    validIdModal.show();
  });

  // In-place row update without page reload (screen stays exactly where the user is!)
  function updateRowInPlace(sid, data) {
    const row = document.getElementById('stakeholder_row_' + sid);
    if (!row) return;

    // 1. Update Name
    if (data.full_name) {
      const nameEl = row.querySelector('.stakeholder-name-col strong');
      if (nameEl) nameEl.textContent = data.full_name;
      row.dataset.name = data.full_name;
    }

    // 2. Update Organization & Sector
    if (data.organization) {
      const orgEl = row.querySelector('.stakeholder-org-col strong');
      if (orgEl) orgEl.textContent = data.organization;
    }
    if (data.sector !== undefined) {
      const secLabel = row.querySelector('.stakeholder-sector-label');
      if (secLabel) {
        secLabel.innerHTML = '<i class="bi bi-building me-1"></i>' + escapeHtml(data.sector || '');
      }
    }

    // 3. Update Contact
    if (data.phone !== undefined) {
      const phEl = row.querySelector('.stakeholder-phone-val');
      if (phEl) phEl.innerHTML = '<i class="bi bi-telephone text-muted me-1 small"></i>' + escapeHtml(data.phone || 'Not publicly listed');
    }
    if (data.email !== undefined) {
      const emText = row.querySelector('#stk_email_span_' + sid) || row.querySelector('.stk-email-val-text');
      if (emText) emText.textContent = data.email || 'Not publicly listed';
      const emBtn = row.querySelector('.btn-quick-change-stk-email');
      if (emBtn) emBtn.dataset.email = data.email || '';
    }

    // 4. Update Status Badge & row data
    if (data.status) {
      row.dataset.status = data.status;
      const isVer = (data.status === 'Verified' || data.status === 'Approved' || data.status === 'Active');
      const isPen = (data.status === 'Pending');
      const statusCell = row.querySelector('.status-cell');
      if (statusCell) {
        const badgeClass = isVer ? 'text-bg-success' : (isPen ? 'text-bg-warning' : 'text-bg-secondary');
        const icon = isVer ? '<i class="bi bi-patch-check-fill me-1"></i>' : (isPen ? '<i class="bi bi-hourglass-split me-1"></i>' : '');
        statusCell.innerHTML = `<span class="badge ${badgeClass}">${icon}${escapeHtml(data.status)}</span>`;
      }
    }

    // 5. Update Actions Cell (QR Button, Quick Verify Button, Edit Button)
    const actionsCell = row.querySelector('.actions-cell');
    if (actionsCell) {
      const isVer = (data.status === 'Verified' || data.status === 'Approved' || data.status === 'Active');
      const qrBtnHtml = (isVer && data.code_value) 
        ? `<a href="qr.php?id=${sid}" class="btn btn-sm btn-outline-dark me-1 btn-qr-action" title="View & Print Attendance QR Pass"><i class="bi bi-qr-code"></i></a>`
        : `<span class="d-inline-block qr-placeholder" tabindex="0" data-bs-toggle="tooltip" title="QR Code is available once account is Verified"><button type="button" class="btn btn-sm btn-outline-secondary me-1 opacity-50" disabled style="pointer-events: none;"><i class="bi bi-qr-code"></i></button></span>`;
      
      const verifyBtnHtml = (data.status === 'Pending')
        ? `<button type="button" class="btn btn-sm btn-outline-success btn-quick-verify me-1" data-id="${sid}" data-name="${escapeHtml(data.full_name || row.dataset.name || '')}" title="Verify this stakeholder immediately"><i class="bi bi-patch-check me-1"></i>Verify</button>`
        : ``;

      const editBtn = actionsCell.querySelector('.btn-edit-stakeholder');
      let updatedDataRow = {};
      if (editBtn && editBtn.dataset.row) {
        try { updatedDataRow = JSON.parse(editBtn.dataset.row); } catch(e){}
      }
      Object.assign(updatedDataRow, data);
      const safeJsonAttr = JSON.stringify(updatedDataRow).replace(/"/g, '&quot;');
      const editBtnHtml = `<button type="button" class="btn btn-sm btn-outline-primary btn-edit-stakeholder" data-id="${sid}" data-row="${safeJsonAttr}"><i class="bi bi-pencil-square me-1"></i>Edit</button>`;

      actionsCell.innerHTML = qrBtnHtml + verifyBtnHtml + editBtnHtml;
    }

    // 6. Update search metadata
    if (data.full_name || data.organization || data.email || data.phone || data.sector) {
      const currentSearch = (row.dataset.search || '');
      const newTerms = [data.full_name, data.organization, data.email, data.phone, data.sector].filter(Boolean).join(' ').toLowerCase();
      row.dataset.search = (currentSearch + ' ' + newTerms).trim();
    }

    // 7. Recount Header Stats
    updateHeaderStats();

    // 8. Flash row visual confirmation
    row.classList.remove('table-highlight-flash');
    void row.offsetWidth; // Force reflow
    row.classList.add('table-highlight-flash');
    setTimeout(() => row.classList.remove('table-highlight-flash'), 2400);

    // 9. Smoothly ensure row stays right in viewport
    row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  // Recalculate summary stat numbers dynamically
  function updateHeaderStats() {
    let total = 0, verified = 0, pending = 0, inactive = 0;
    document.querySelectorAll('.stakeholder-table-row').forEach(r => {
      total++;
      const st = r.dataset.status || '';
      if (st === 'Verified' || st === 'Approved' || st === 'Active') verified++;
      else if (st === 'Pending') pending++;
      else if (st === 'Inactive') inactive++;
    });

    const elTotal = document.getElementById('stat_count_total');
    const elVerified = document.getElementById('stat_count_verified');
    const elPending = document.getElementById('stat_count_pending');
    const elInactive = document.getElementById('stat_count_inactive');

    if (elTotal) elTotal.textContent = total;
    if (elVerified) elVerified.textContent = verified;
    if (elPending) elPending.textContent = pending;
    if (elInactive) elInactive.textContent = inactive;
  }

  function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  // Quick 1-Click Verify handler on table rows (No reload! Stays exactly in place)
  document.addEventListener('click', async function(e) {
    const btn = e.target.closest('.btn-quick-verify');
    if (!btn) return;
    e.preventDefault();

    const sid = btn.dataset.id;
    const name = btn.dataset.name || 'Stakeholder';

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Verifying...';

    try {
      const fd = new FormData();
      fd.append('csrf_token', document.querySelector('[name=csrf_token]')?.value || '');
      fd.append('id', sid);
      fd.append('status', 'Verified');

      const res = await fetch('ajax_status.php', { method: 'POST', body: fd }).then(r => r.json());
      if (res.success) {
        updateRowInPlace(sid, {
          status: 'Verified',
          code_value: res.code_value,
          qr_url: res.qr_url
        });
        appToast('success', `${name} is now Verified! Attendance QR pass issued.`);
      } else {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-patch-check me-1"></i>Verify';
        Swal.fire('Verification Failed', res.message || 'Error updating status.', 'error');
      }
    } catch(err) {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-patch-check me-1"></i>Verify';
      console.error('Verify error:', err);
      Swal.fire('Error', 'Unable to complete verification request.', 'error');
    }
  });

  // 1-Click Inline Edit for Stakeholder Gmail/Email directly in this table
  document.addEventListener('click', async function(e){
    const btn = e.target.closest('.btn-quick-change-stk-email');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();

    const sid = btn.dataset.id;
    const name = btn.dataset.name || 'Stakeholder';
    const currentEmail = btn.dataset.email || '';

    const prompt = await Swal.fire({
      title: 'Edit Stakeholder Email',
      html: `
        <div class="text-start">
          <p class="mb-2 text-muted small">Update the official Gmail / email address for <strong>${escapeHtml(name)}</strong>:</p>
          <label class="form-label small fw-bold text-secondary mb-1">Gmail / Email Address:</label>
          <div class="input-group">
            <span class="input-group-text bg-light"><i class="bi bi-envelope text-primary"></i></span>
            <input type="email" id="swal_stk_email_input" class="form-control font-monospace" value="${escapeHtml(currentEmail)}" placeholder="username@gmail.com" required>
          </div>
          <div class="form-text text-muted small mt-1">This will update the stakeholder record immediately.</div>
        </div>
      `,
      showCancelButton: true,
      confirmButtonText: '<i class="bi bi-save me-1"></i> Save Email',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#1e4a7a',
      focusConfirm: false,
      didOpen: () => {
        const input = document.getElementById('swal_stk_email_input');
        if (input) { input.focus(); input.select(); }
      },
      preConfirm: () => {
        const val = document.getElementById('swal_stk_email_input')?.value.trim();
        if (!val) {
          Swal.showValidationMessage('Email address is required.');
          return false;
        }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
          Swal.showValidationMessage('Please enter a valid email address format (e.g. user@gmail.com).');
          return false;
        }
        return val;
      }
    });

    if (!prompt.isConfirmed || !prompt.value) return;
    const newEmail = prompt.value;

    try {
      const fd = new FormData();
      fd.append('csrf_token', document.querySelector('[name=csrf_token]')?.value || (typeof APP_CSRF_TOKEN !== 'undefined' ? APP_CSRF_TOKEN : ''));
      fd.append('stakeholder_id', sid);
      fd.append('email', newEmail);

      const res = await fetch(APP_URL + '/modules/stakeholders/ajax_update_email.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        body: fd
      }).then(r => r.json());

      if (res.success) {
        updateRowInPlace(sid, { email: newEmail });
        appToast('success', res.message || `Updated email for ${name} to ${newEmail}!`);
      } else {
        Swal.fire('Failed to Save', res.message || 'Error updating email.', 'error');
      }
    } catch(err) {
      console.error('Update email error:', err);
      Swal.fire('Error', 'A network connection error occurred.', 'error');
    }
  });

  // Event delegation for Edit button
  document.addEventListener('click', async function(e){
    const btn = e.target.closest('.btn-edit-stakeholder');
    if (!btn) return;
    
    let r = null;
    if (btn.dataset.row) {
      try {
        r = JSON.parse(btn.dataset.row);
      } catch(err) {
        console.warn('JSON parse error on data-row:', err);
      }
    }
    
    const sid = btn.dataset.id || (r ? r.id : 0);
    if (!r && sid) {
      try {
        const resp = await fetch('ajax_get.php?id=' + sid);
        const data = await resp.json();
        if (data && data.success && data.stakeholder) {
          r = data.stakeholder;
        }
      } catch(err) {
        console.error('Failed to fetch stakeholder via ajax_get:', err);
      }
    }

    if (r) {
      document.getElementById('s_id').value = r.id || 0;
      document.getElementById('s_name').value = r.full_name || '';
      document.getElementById('s_email').value = r.email || '';
      document.getElementById('s_phone').value = r.phone || '';
      document.getElementById('s_org').value = r.organization || '';
      document.getElementById('s_category').value = r.category_id || '';
      document.getElementById('s_sector').value = r.sector || '';
      document.getElementById('s_status').value = r.status || 'Verified';
      document.getElementById('s_address').value = r.address || '';
      modal.show();
    } else {
      console.error('No stakeholder data found for edit.');
    }
  });

  // Edit / Create Form Submission
  form.onsubmit = async function(e){
    e.preventDefault();
    const isNew = (!document.getElementById('s_id').value || document.getElementById('s_id').value === '0');
    const sid = parseInt(document.getElementById('s_id').value || '0', 10);
    const scrollContainer = document.querySelector('.stakeholder-scroll-container');

    const res = await appPostForm(APP_URL + '/modules/stakeholders/ajax_stakeholder_save.php', form);
    if (res.success){
      modal.hide();

      if (isNew && res.qr_url){
        if (scrollContainer) sessionStorage.setItem('stakeholderScrollTop', scrollContainer.scrollTop);
        sessionStorage.setItem('lastActiveStakeholderId', res.id);
        Swal.fire({
          icon: 'success',
          title: 'Stakeholder & QR Pass Created!',
          html: '<p>' + res.message + '</p>' +
                '<div class="p-3 my-2 bg-light rounded border text-center">' +
                '<div class="text-muted small mb-1">Generated Attendance QR Code:</div>' +
                '<span class="badge bg-dark font-monospace fs-6 px-3 py-2"><i class="bi bi-qr-code me-1"></i> ' + (res.code_value || '') + '</span>' +
                '</div>' +
                '<p class="text-muted small">This QR code can be scanned on hearing attendance for instant Time In and Time Out.</p>',
          showCancelButton: true,
          confirmButtonColor: '#1e4a7a',
          confirmButtonText: '<i class="bi bi-qr-code-scan me-1"></i> View & Print QR Pass',
          cancelButtonText: 'Stay on Registry'
        }).then((result)=>{
          if (result.isConfirmed && res.qr_url){
            window.location.href = res.qr_url;
          } else {
            location.reload();
          }
        });
      } else if (isNew) {
        if (scrollContainer) sessionStorage.setItem('stakeholderScrollTop', scrollContainer.scrollTop);
        sessionStorage.setItem('lastActiveStakeholderId', res.id);
        appToast('success', res.message);
        setTimeout(() => location.reload(), 400);
      } else {
        // IN-PLACE UPDATE! Screen stays 100% frozen right where user is looking!
        updateRowInPlace(sid, {
          full_name: document.getElementById('s_name').value.trim(),
          email: document.getElementById('s_email').value.trim(),
          phone: document.getElementById('s_phone').value.trim(),
          organization: document.getElementById('s_org').value.trim(),
          sector: document.getElementById('s_sector').value.trim(),
          status: document.getElementById('s_status').value,
          category_id: document.getElementById('s_category').value,
          code_value: res.code_value,
          qr_url: res.qr_url
        });
        appToast('success', res.message);
      }
    } else if (!res.session_expired){
      Swal.fire('Unable to Save', res.message, 'error');
    }
  };

  // Restore scroll position and last active row if page reloaded
  const container = document.querySelector('.stakeholder-scroll-container');
  const lastActiveSid = sessionStorage.getItem('lastActiveStakeholderId');
  const savedScrollTop = sessionStorage.getItem('stakeholderScrollTop');

  if (lastActiveSid) {
    sessionStorage.removeItem('lastActiveStakeholderId');
    const targetRow = document.getElementById('stakeholder_row_' + lastActiveSid);
    if (targetRow) {
      setTimeout(() => {
        targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
        targetRow.classList.add('table-highlight-flash');
        setTimeout(() => targetRow.classList.remove('table-highlight-flash'), 2400);
      }, 150);
    }
  } else if (savedScrollTop && container) {
    sessionStorage.removeItem('stakeholderScrollTop');
    container.scrollTop = parseInt(savedScrollTop, 10);
  }

  // Remember scroll position before page unload
  window.addEventListener('beforeunload', function() {
    if (container) {
      sessionStorage.setItem('stakeholderScrollTop', container.scrollTop);
    }
  });

  // ==========================================================
  // VIEW ASSIGNED HEARINGS MODAL HANDLER
  // ==========================================================
  const shModalEl = document.getElementById('stakeholderHearingsModal');
  const shModal = shModalEl ? bootstrap.Modal.getOrCreateInstance(shModalEl) : null;

  function escapeHtml(text) {
    if (!text) return '';
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return text.toString().replace(/[&<>"']/g, m => map[m]);
  }

  document.addEventListener('click', async function(e) {
    const btn = e.target.closest('.btn-view-assigned-hearings');
    if (!btn) return;

    const sid = btn.dataset.stakeholderId;
    const name = btn.dataset.name || 'Stakeholder';
    const initials = btn.dataset.initials || 'SH';
    const avatarStyle = btn.dataset.avatarStyle || '';
    const email = btn.dataset.email || '';
    const org = btn.dataset.org || '';

    const modalAvatar = document.getElementById('shModalAvatar');
    const modalName = document.getElementById('shModalName');
    const modalSubtitle = document.getElementById('shModalSubtitle');
    const modalBadgeCount = document.getElementById('shModalBadgeCount');
    const modalBody = document.getElementById('shModalBody');
    const modalFooterInfo = document.getElementById('shModalFooterInfo');

    if (modalAvatar) {
      modalAvatar.textContent = initials;
      modalAvatar.style.cssText = 'width: 46px; height: 46px; font-size: 1.05rem; flex-shrink: 0; ' + avatarStyle;
    }
    if (modalName) modalName.textContent = name;
    if (modalSubtitle) modalSubtitle.textContent = [org, email].filter(Boolean).join(' • ') || 'Viewing assigned hearings';
    if (modalBadgeCount) modalBadgeCount.textContent = 'Loading...';
    if (modalFooterInfo) modalFooterInfo.textContent = '';
    if (modalBody) {
      modalBody.innerHTML = `
        <div class="text-center py-5">
          <div class="spinner-border text-primary" role="status"></div>
          <div class="small text-muted mt-2">Loading assigned hearings for ${escapeHtml(name)}...</div>
        </div>
      `;
    }

    if (shModal) shModal.show();

    try {
      const fetchUrl = 'ajax_stakeholder_hearings.php?stakeholder_id=' + encodeURIComponent(sid);
      const resp = await fetch(fetchUrl);
      const data = await resp.json();

      if (!data.success) {
        if (modalBody) {
          modalBody.innerHTML = `
            <div class="alert alert-danger py-3 px-4 mb-0">
              <i class="bi bi-exclamation-triangle-fill me-2"></i> ${escapeHtml(data.message || 'Error loading hearings.')}
            </div>
          `;
        }
        return;
      }

      const stk = data.stakeholder || {};
      const hearings = data.hearings || [];

      if (modalSubtitle) {
        const subParts = [];
        if (stk.organization) subParts.push(stk.organization);
        if (stk.category_name) subParts.push(stk.category_name);
        if (stk.email) subParts.push(stk.email);
        modalSubtitle.textContent = subParts.join(' • ') || 'Verified Stakeholder';
      }

      if (modalBadgeCount) {
        modalBadgeCount.textContent = `${hearings.length} ${hearings.length === 1 ? 'Hearing' : 'Hearings'} Assigned`;
      }

      if (modalFooterInfo) {
        modalFooterInfo.innerHTML = stk.code_value 
          ? `<i class="bi bi-qr-code me-1 text-primary"></i>QR Credential: <strong class="font-monospace text-dark">${escapeHtml(stk.code_value)}</strong>`
          : '';
      }

      if (hearings.length === 0) {
        modalBody.innerHTML = `
          <div class="text-center py-5 text-muted">
            <i class="bi bi-calendar-x fs-1 text-secondary d-block mb-2"></i>
            <h6 class="fw-bold text-dark mb-1">No Hearings Assigned</h6>
            <p class="small text-muted mb-0">This stakeholder has not been assigned to any public hearings yet.</p>
          </div>
        `;
        return;
      }

      let cardsHtml = `
        <div class="small fw-bold text-uppercase text-secondary mb-3 tracking-wide d-flex align-items-center justify-content-between">
          <span><i class="bi bi-calendar2-check-fill text-primary me-1"></i> Assigned Hearing Sessions (${hearings.length})</span>
          <span class="badge bg-light text-dark border">Stakeholder ID #${stk.id}</span>
        </div>
        <div class="d-flex flex-column gap-3">
      `;

      hearings.forEach(h => {
        const statusBadge = h.hearing_status === 'Upcoming' ? 'success' : (h.hearing_status === 'Ongoing' ? 'warning' : 'secondary');
        const regStatusBadge = h.registration_status === 'Approved' ? 'success' : (h.registration_status === 'Pending' ? 'warning' : 'secondary');
        const formattedDate = h.effective_date ? new Date(h.effective_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'Date TBA';
        const formattedTime = h.hearing_time ? h.hearing_time.substring(0, 5) : '';
        const hearingViewUrl = (typeof APP_URL !== 'undefined' && APP_URL ? APP_URL : '') + '/modules/hearings/view.php?id=' + encodeURIComponent(h.hearing_id);

        cardsHtml += `
          <div class="card border rounded-3 shadow-sm overflow-hidden">
            <div class="card-header bg-light py-2 px-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
              <div class="d-flex align-items-center gap-2">
                <span class="badge bg-primary-subtle text-primary border font-monospace fw-bold">${escapeHtml(h.reference_number || 'PH-REF')}</span>
                <span class="badge text-bg-${statusBadge}">${escapeHtml(h.hearing_status || 'Upcoming')}</span>
                ${h.day_number ? `<span class="badge bg-white text-dark border"><i class="bi bi-calendar-day me-1"></i>Day ${h.day_number}</span>` : ''}
              </div>
              <span class="badge bg-white text-dark border font-monospace small" title="Registration Code">
                <i class="bi bi-hash text-muted"></i>${escapeHtml(h.registration_code || '—')}
              </span>
            </div>
            <div class="card-body p-3">
              <h6 class="fw-bold text-dark mb-1">${escapeHtml(h.hearing_title || 'Public Hearing')}</h6>
              ${h.committee_name ? `<div class="small text-muted mb-2"><i class="bi bi-diagram-3 me-1 text-primary"></i>${escapeHtml(h.committee_name)}</div>` : ''}
              
              <div class="row g-2 small text-secondary bg-light p-2.5 rounded-2 mt-1">
                <div class="col-sm-6">
                  <i class="bi bi-calendar3 me-1 text-primary"></i><strong>Date:</strong> ${formattedDate}
                </div>
                <div class="col-sm-6">
                  <i class="bi bi-clock me-1 text-primary"></i><strong>Time:</strong> ${formattedTime || 'Schedule TBA'}
                </div>
                <div class="col-sm-6">
                  <i class="bi bi-geo-alt me-1 text-primary"></i><strong>Venue:</strong> ${escapeHtml(h.venue || 'Session Hall, Manila City Hall')}
                </div>
                <div class="col-sm-6">
                  <i class="bi bi-person-badge me-1 text-primary"></i><strong>Attendance:</strong> ${escapeHtml(h.attendance_type || 'On-site')}
                </div>
              </div>
            </div>
            <div class="card-footer bg-white py-2 px-3 border-top d-flex align-items-center justify-content-between flex-wrap gap-2">
              <span class="small text-muted">
                <i class="bi bi-shield-check text-${regStatusBadge} me-1"></i>Registration: <strong class="text-dark">${escapeHtml(h.registration_status || 'Approved')}</strong>
              </span>
              <a href="${hearingViewUrl}" target="_blank" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1">
                <span>View Hearing</span>
                <i class="bi bi-box-arrow-up-right small"></i>
              </a>
            </div>
          </div>
        `;
      });

      cardsHtml += `</div>`;
      modalBody.innerHTML = cardsHtml;

    } catch (err) {
      if (modalBody) {
        modalBody.innerHTML = `
          <div class="alert alert-danger py-3 px-4 mb-0">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> Failed to fetch assigned hearings: ${escapeHtml(err.message)}
          </div>
        `;
      }
    }
  });
});
</script>
<?php include __DIR__ . '/../../layouts/footer.php'; ?>
