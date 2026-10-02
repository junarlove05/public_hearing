<?php
/**
 * modules/actions/view.php
 * ------------------------------------------------------------------
 * Full detail view for a single action:
 * - Role separation:
 *   - Regular/Assigned users NEVER see the Assign/Reassign card!
 *   - Only Admin sees Assign/Reassign and Admin Verification & Review.
 *   - Only the assigned user can update status and submit progress notes.
 * - Progress updates timeline, document upload, and verification workflow.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';
requireRole([ROLE_ADMIN, ROLE_STAFF, ROLE_COMMITTEE]);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$id = (int)($_GET['id'] ?? 0);
$pdo = db();
lphEnsureActionsSchema($pdo);

$stmt = $pdo->prepare(
    'SELECT a.*,
            i.title AS issue_title,
            i.reference_number AS issue_reference_number,
            i.status AS issue_status,
            o.name AS assigned_office_name,
            o.code AS assigned_office_code,
            au.full_name AS assigned_user_name,
            au.email AS assigned_user_email,
            vu.full_name AS verified_by_name
     FROM hearing_actions a
     LEFT JOIN hearing_issues i ON i.id = a.issue_id
     LEFT JOIN offices o ON o.id = a.assigned_office_id
     LEFT JOIN users au ON au.id = a.assigned_user_id
     LEFT JOIN users vu ON vu.id = a.verified_by
     WHERE a.id = :id'
);
$stmt->execute([':id' => $id]);
$action = $stmt->fetch();

if (!$action) {
    setFlash('danger', 'Action record not found.');
    redirect(APP_URL . '/modules/actions/index.php');
}

$currentUid     = (int)(currentUserId() ?? 0);
$isAdmin        = (function_exists('isAdmin') && isAdmin()) || (isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === 1);
$isAssignedUser = ($currentUid > 0 && (int)($action['assigned_user_id'] ?? 0) === $currentUid);

$usersList = $pdo->query(
    "SELECT u.id, u.full_name, u.username, u.email, r.name AS role_name, o.name AS office_name, u.office_id
     FROM users u
     LEFT JOIN roles r ON r.id = u.role_id
     LEFT JOIN offices o ON o.id = u.office_id
     WHERE u.deleted_at IS NULL AND u.status = 'Active'
       AND LOWER(r.name) NOT LIKE '%public%'
       AND LOWER(r.name) NOT LIKE '%stakeholder%'
     ORDER BY u.full_name ASC"
)->fetchAll();

$officesList = $pdo->query(
    "SELECT id, name, code FROM offices WHERE status = 'Active' ORDER BY name ASC"
)->fetchAll();

$documents = $pdo->prepare('SELECT * FROM hearing_action_documents WHERE action_id = :id ORDER BY uploaded_at DESC');
$documents->execute([':id' => $id]);
$documents = $documents->fetchAll();

$updatesStmt = $pdo->prepare(
    'SELECT hau.*, u.full_name AS updated_by_name
     FROM hearing_action_updates hau
     LEFT JOIN users u ON u.id = hau.updated_by
     WHERE hau.action_id = :id
     ORDER BY hau.created_at DESC'
);
$updatesStmt->execute([':id' => $id]);
$updatesRows = array_map(function ($row) {
    return [
        'type' => 'update',
        'text' => $row['update_text'],
        'author' => $row['updated_by_name'] ?: 'Staff',
        'at' => $row['created_at'],
    ];
}, $updatesStmt->fetchAll());

$assignStmt = $pdo->prepare(
    'SELECT haa.*, o.name AS assigned_office, u.full_name AS assigned_user_name, ab.full_name AS assigned_by_name
     FROM hearing_action_assignments haa
     LEFT JOIN offices o ON o.id = haa.assigned_office_id
     LEFT JOIN users u ON u.id = haa.assigned_user_id
     LEFT JOIN users ab ON ab.id = haa.assigned_by
     WHERE haa.action_id = :id
     ORDER BY haa.assigned_at DESC'
);
$assignStmt->execute([':id' => $id]);
$assignRows = array_map(function ($row) {
    $assignedTo = $row['assigned_user_name'] ?: ($row['assigned_office'] ?: 'Unassigned');
    return [
        'type' => 'assignment',
        'text' => 'Assigned to ' . $assignedTo . ($row['remarks'] ? ' — ' . $row['remarks'] : ''),
        'author' => $row['assigned_by_name'] ?: 'Admin',
        'at' => $row['assigned_at'],
    ];
}, $assignStmt->fetchAll());

$timeline = array_merge($updatesRows, $assignRows);
usort($timeline, fn($a, $b) => strtotime($b['at']) <=> strtotime($a['at']));

$overdue = $action['deadline'] && $action['deadline'] < date('Y-m-d') && !in_array($action['status'], ['Completed', 'Cancelled'], true);

if (!function_exists('actionVerificationBadge')) {
    function actionVerificationBadge(?string $status): string {
        $status = $status ?: 'Pending';
        switch ($status) {
            case 'Verified':
                return '<span class="badge bg-success shadow-sm"><i class="bi bi-patch-check-fill me-1"></i> Verified</span>';
            case 'Returned':
                return '<span class="badge bg-danger shadow-sm"><i class="bi bi-arrow-counterclockwise me-1"></i> Returned for Revision</span>';
            case 'Pending':
            default:
                return '<span class="badge bg-warning text-dark shadow-sm"><i class="bi bi-hourglass-split me-1"></i> Pending Verification</span>';
        }
    }
}

$pageTitle  = $action['title'];
$activeMenu = 'actions';

$docIcon = function (string $path): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return match ($ext) {
        'pdf' => 'bi-file-earmark-pdf text-danger',
        'doc', 'docx' => 'bi-file-earmark-word text-primary',
        'png', 'jpg', 'jpeg' => 'bi-file-earmark-image text-success',
        default => 'bi-file-earmark',
    };
};

include __DIR__ . '/../../layouts/header.php';
?>
<style>
    /* Action View - Dark Theme */
    :root {
        --av-dark-900: #0F172A;
        --av-dark-800: #1E293B;
        --av-dark-700: #334155;
        --av-amber: #F59E0B;
        --av-amber-light: #FBBF24;
        --av-white: #FFFFFF;
        --av-gray-100: #F1F5F9;
        --av-gray-200: #E2E8F0;
        --av-gray-300: #CBD5E1;
        --av-gray-400: #94A3B8;
        --av-gray-500: #64748B;
        --av-gray-600: #475569;
        --av-emerald: #10B981;
        --av-rose: #F43F5E;
        --av-violet: #8B5CF6;
        --av-cyan: #06B6D4;
        --av-orange: #F97316;
        --av-teal: #14B8A6;
        --av-indigo: #0F2137;
    }

    .breadcrumb-bar {
        background: var(--av-white);
        border-left: 4px solid var(--av-amber);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
        padding: 1rem 1.5rem;
        border-radius: 12px;
        margin-bottom: 1.5rem;
    }
    .breadcrumb-bar h5 {
        color: var(--av-dark-900);
        font-weight: 700;
    }
    .breadcrumb-bar h5 i {
        color: var(--av-amber);
    }
    .breadcrumb-bar a {
        color: var(--av-amber) !important;
        text-decoration: none;
        font-weight: 500;
        transition: color 0.3s ease;
    }
    .breadcrumb-bar a:hover {
        color: var(--av-dark-900) !important;
        text-decoration: underline;
    }

    .btn-primary {
        background: linear-gradient(135deg, var(--av-dark-900) 0%, var(--av-dark-800) 100%);
        border: 1px solid rgba(245, 158, 11, 0.15);
        color: var(--av-white);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        border-radius: 10px;
        font-weight: 600;
        padding: 0.45rem 1.25rem;
        transition: all 0.3s ease;
    }
    .btn-primary:hover {
        border-color: var(--av-amber);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.25);
        color: var(--av-white);
        transform: translateY(-2px);
    }

    .card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 2px 15px rgba(0, 0, 0, 0.06);
        background: var(--av-white);
        transition: all 0.3s ease;
        overflow: hidden;
    }
    .card:hover {
        box-shadow: 0 4px 25px rgba(0, 0, 0, 0.1);
    }
    .card-header {
        background: linear-gradient(135deg, var(--av-dark-900) 0%, var(--av-dark-800) 100%);
        color: var(--av-white);
        font-weight: 600;
        padding: 0.75rem 1.25rem;
        border-bottom: 3px solid var(--av-amber);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .card-header i {
        color: var(--av-amber);
        font-size: 1.1rem;
    }
    .card-body {
        padding: 1.25rem;
        background: var(--av-white);
    }

    .form-control,
    .form-select {
        border-radius: 8px;
        border: 1px solid var(--av-gray-200);
        padding: 0.4rem 0.75rem;
        font-size: 0.85rem;
        color: var(--av-dark-900);
    }
    .form-control:focus,
    .form-select:focus {
        border-color: var(--av-amber);
        box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);
    }

    .badge {
        padding: 0.4rem 0.75rem;
        border-radius: 8px;
        font-weight: 600;
        font-size: 0.75rem;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
    }

    .table th.text-muted {
        color: var(--av-gray-500) !important;
        font-weight: 600;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .table td {
        color: var(--av-dark-900);
        font-size: 0.9rem;
        vertical-align: middle;
    }

    .list-group-item {
        border-left: none;
        border-right: none;
        border-color: var(--av-gray-200);
        padding: 0.75rem 1.25rem;
        transition: background-color 0.2s ease;
    }
    .list-group-item:hover {
        background-color: var(--av-gray-100);
    }
    .list-group-item i {
        color: var(--av-amber);
    }
</style>

<div class="app-wrapper">
  <?php include __DIR__ . '/../../layouts/sidebar.php'; ?>

  <div class="main-content">
    <?php include __DIR__ . '/../../layouts/top_controls.php'; ?>

    <div class="breadcrumb-bar d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <div>
        <a href="index.php" class="text-decoration-none small no-print"><i class="bi bi-arrow-left"></i> Back to Response &amp; Action Tracking</a>
        <h5 class="mb-0 mt-1"><i class="bi bi-list-check"></i> <?= e($action['title']) ?></h5>
        <div class="text-muted small mt-0.5">Reference: <span class="badge bg-secondary"><?= e($action['reference_number']) ?></span></div>
      </div>
      <div class="d-flex gap-2 align-items-center no-print">
        <?php if ($overdue): ?><span class="badge bg-danger"><i class="bi bi-alarm me-1"></i> Overdue</span><?php endif; ?>
        <?= statusBadge($action['status']) ?>
        <?= actionVerificationBadge($action['verification_status'] ?? 'Pending') ?>
      </div>
    </div>

    <?php if ($isAssignedUser): ?>
    <!-- Assigned User Exclusive Banner -->
    <div class="alert alert-primary border-primary d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3 shadow-sm bg-primary-subtle">
      <div class="d-flex align-items-center gap-2">
        <i class="bi bi-person-check-fill fs-3 text-primary"></i>
        <div>
          <h6 class="mb-0 fw-bold text-dark">Action Assigned to Your Account</h6>
          <div class="small text-secondary">You are the designated staff member for <strong><?= e($action['reference_number']) ?></strong>. You have authorization to update status and submit progress notes below.</div>
        </div>
      </div>
      <div class="d-flex align-items-center gap-2">
        <span class="badge bg-primary px-3 py-2"><?= e($action['status']) ?></span>
        <?= actionVerificationBadge($action['verification_status'] ?? 'Pending') ?>
      </div>
    </div>

    <?php if (($action['verification_status'] ?? 'Pending') === 'Returned'): ?>
      <div class="alert alert-danger border-danger d-flex align-items-start gap-2 mb-3 shadow-sm">
        <i class="bi bi-exclamation-triangle-fill fs-4 text-danger mt-1"></i>
        <div>
          <h6 class="mb-1 fw-bold text-danger">Action Required: Update Returned for Revision by Admin</h6>
          <div class="small text-dark mb-1">Administrator Remarks: <strong><?= e($action['verification_notes']) ?></strong></div>
          <div class="small text-muted">Please update the status and provide revised progress notes in the form below, then click Save to re-submit for Admin verification.</div>
        </div>
      </div>
    <?php elseif (($action['verification_status'] ?? 'Pending') === 'Pending'): ?>
      <div class="alert alert-warning border-warning d-flex align-items-start gap-2 mb-3 shadow-sm">
        <i class="bi bi-hourglass-split fs-4 text-warning mt-1"></i>
        <div>
          <h6 class="mb-1 fw-bold text-dark">Status Update Submitted — Pending Admin Verification</h6>
          <div class="small text-secondary">Your latest action update (<strong><?= e($action['status']) ?></strong>) has been submitted. The Administrator will review and verify your progress.</div>
        </div>
      </div>
    <?php elseif (($action['verification_status'] ?? 'Pending') === 'Verified'): ?>
      <div class="alert alert-success border-success d-flex align-items-start gap-2 mb-3 shadow-sm">
        <i class="bi bi-patch-check-fill fs-4 text-success mt-1"></i>
        <div>
          <h6 class="mb-1 fw-bold text-success">Action Update Verified &amp; Approved by Admin</h6>
          <div class="small text-secondary">Verified by <?= e($action['verified_by_name'] ?? 'Administrator') ?><?= $action['verified_at'] ? ' on ' . formatDateTime($action['verified_at']) : '' ?>.<?= !empty($action['verification_notes']) ? ' Remarks: ' . e($action['verification_notes']) : '' ?></div>
        </div>
      </div>
    <?php endif; ?>
    <?php endif; ?>

    <div class="row g-3">
      <!-- Left Column: Details & Documents -->
      <div class="col-lg-7">
        <div class="card mb-3">
          <div class="card-header"><i class="bi bi-info-circle"></i> Action Details</div>
          <div class="card-body">
            <table class="table table-borderless mb-0">
              <tr>
                <th class="text-muted small" style="width:30%;">Linked Issue</th>
                <td>
                  <?php if (!empty($action['issue_id'])): ?>
                    <a href="<?= e(APP_URL) ?>/modules/issues/view.php?id=<?= (int)$action['issue_id'] ?>" class="text-decoration-none fw-semibold">
                      <i class="bi bi-exclamation-triangle text-warning me-1"></i><?= e($action['issue_title'] ?: 'View Linked Issue') ?>
                    </a>
                    <?php if (!empty($action['issue_reference_number'])): ?>
                      <span class="badge bg-secondary ms-1"><?= e($action['issue_reference_number']) ?></span>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="text-muted fst-italic">No linked issue</span>
                  <?php endif; ?>
                </td>
              </tr>
              <tr>
                <th class="text-muted small">Assigned Person</th>
                <td>
                  <?php if (!empty($action['assigned_user_name'])): ?>
                    <span class="fw-semibold text-dark"><i class="bi bi-person-fill text-primary me-1"></i><?= e($action['assigned_user_name']) ?></span>
                  <?php else: ?>
                    <span class="text-muted fst-italic">No person assigned</span>
                  <?php endif; ?>
                </td>
              </tr>
              <tr>
                <th class="text-muted small">Assigned Office</th>
                <td><?= e($action['assigned_office_name'] ? $action['assigned_office_name'] . ' (' . $action['assigned_office_code'] . ')' : 'Unassigned') ?></td>
              </tr>
              <tr>
                <th class="text-muted small">Deadline</th>
                <td>
                  <?= $action['deadline'] ? formatDate($action['deadline']) : '-' ?>
                  <?= $overdue ? '<span class="text-danger small fw-semibold ms-1">(Overdue)</span>' : '' ?>
                </td>
              </tr>
              <tr>
                <th class="text-muted small">Status</th>
                <td><?= statusBadge($action['status']) ?></td>
              </tr>
              <tr>
                <th class="text-muted small">Verification</th>
                <td>
                  <div class="d-flex align-items-center gap-2 flex-wrap">
                    <?= actionVerificationBadge($action['verification_status'] ?? 'Pending') ?>
                    <?php if (($action['verification_status'] ?? '') === 'Verified' && !empty($action['verified_by_name'])): ?>
                      <span class="small text-muted">by <?= e($action['verified_by_name']) ?> (<?= formatDateTime($action['verified_at']) ?>)</span>
                    <?php elseif (($action['verification_status'] ?? '') === 'Returned' && !empty($action['verification_notes'])): ?>
                      <span class="small text-danger">Remarks: <?= e($action['verification_notes']) ?></span>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <tr>
                <th class="text-muted small">Created</th>
                <td><?= formatDateTime($action['created_at']) ?></td>
              </tr>
              <tr>
                <th class="text-muted small">Description</th>
                <td><?= nl2br(e($action['description'] ?: '-')) ?></td>
              </tr>
              <?php if (!empty($action['progress_notes'])): ?>
              <tr>
                <th class="text-muted small">Progress Note</th>
                <td>
                  <div class="alert alert-light border py-2 px-3 mb-0 small text-dark">
                    <?= nl2br(e($action['progress_notes'])) ?>
                  </div>
                </td>
              </tr>
              <?php endif; ?>
            </table>
          </div>
        </div>

        <!-- Documents Card -->
        <div class="card mb-3">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-files"></i> Uploaded Documents</span>
            <span class="badge bg-secondary"><?= count($documents) ?></span>
          </div>
          <div class="list-group list-group-flush">
            <?php if (empty($documents)): ?>
              <div class="list-group-item text-muted small">No documents uploaded yet.</div>
            <?php else: ?>
              <?php foreach ($documents as $doc): ?>
                <div class="list-group-item d-flex justify-content-between align-items-center">
                  <div>
                    <i class="bi <?= $docIcon($doc['file_path']) ?> me-2"></i><?= e($doc['file_name']) ?>
                    <div class="text-muted small" style="font-size: 11px;">Uploaded <?= formatDateTime($doc['uploaded_at']) ?></div>
                  </div>
                  <div class="btn-group btn-group-sm no-print">
                    <a href="<?= e(UPLOAD_URL . $doc['file_path']) ?>" target="_blank" class="btn btn-outline-secondary" title="Download">
                      <i class="bi bi-download"></i>
                    </a>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <?php if ($isAdmin || $isAssignedUser): ?>
          <div class="card-body border-top no-print">
            <form id="uploadForm" enctype="multipart/form-data" class="d-flex gap-2">
              <?= csrfField() ?>
              <input type="hidden" name="id" value="<?= (int)$action['id'] ?>">
              <input type="file" name="documents[]" class="form-control form-control-sm" multiple accept=".pdf,.doc,.docx,.png,.jpg,.jpeg" required>
              <button type="submit" class="btn btn-primary btn-sm text-nowrap"><i class="bi bi-upload me-1"></i> Upload</button>
            </form>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Right Column: Role-Separated Workflows -->
      <div class="col-lg-5">
        <?php if ($isAdmin): ?>
        <!-- 1. Assign Card: STRICTLY SHOWN ONLY TO ADMINISTRATORS -->
        <div class="card mb-3 no-print shadow-sm" style="border-top: 3.5px solid #0F2137;">
          <div class="card-header bg-white d-flex align-items-center justify-content-between py-2.5">
            <div class="d-flex align-items-center gap-2">
              <i class="bi bi-diagram-3 text-primary fs-5"></i>
              <span class="fw-bold text-dark">Assign Action</span>
            </div>
            <?php if (!empty($action['assigned_user_id'])): ?>
              <span class="badge bg-secondary"><i class="bi bi-lock-fill me-1"></i>Closed</span>
            <?php else: ?>
              <span class="badge bg-secondary">Admin Only</span>
            <?php endif; ?>
          </div>
          <div class="card-body p-3">
            <?php if (!empty($action['assigned_user_id'])): ?>
              <div class="alert alert-light border border-secondary border-opacity-25 d-flex align-items-start gap-2.5 mb-0 py-2.5 px-3">
                <i class="bi bi-lock-fill text-secondary fs-5 mt-0.5"></i>
                <div>
                  <div class="fw-bold text-dark small">Assignment is Closed</div>
                  <div class="text-secondary small">
                    This action is already assigned to <strong><?= e($action['assigned_user_name']) ?></strong><?php if (!empty($action['assigned_office_name'])): ?> (<?= e($action['assigned_office_name']) ?>)<?php endif; ?>. Further assignment or re-assignment is closed.
                  </div>
                </div>
              </div>
            <?php else: ?>
              <form id="assignForm">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= (int)$action['id'] ?>">

                <div class="mb-2">
                  <label class="form-label small fw-semibold text-secondary mb-1">
                    <i class="bi bi-person-check text-primary me-1"></i> Assign to Person (User Registry)
                  </label>
                  <select name="assigned_user_id" id="assignUserSelect" class="form-select form-select-sm" required>
                    <option value="">— Select Registered User —</option>
                    <?php foreach ($usersList as $u): ?>
                      <option value="<?= (int)$u['id'] ?>" 
                              data-office-id="<?= (int)($u['office_id'] ?? 0) ?>">
                        <?= e($u['full_name']) ?> (<?= e($u['role_name'] ?: 'User') ?><?= $u['office_name'] ? ' · ' . e($u['office_name']) : '' ?>)
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="mb-2">
                  <label class="form-label small fw-semibold text-secondary mb-1">
                    <i class="bi bi-building text-primary me-1"></i> Assigned Office
                  </label>
                  <select name="assigned_office_id" id="assignOfficeSelect" class="form-select form-select-sm">
                    <option value="">— Select Office —</option>
                    <?php foreach ($officesList as $o): ?>
                      <option value="<?= (int)$o['id'] ?>">
                        <?= e($o['name']) ?><?= $o['code'] ? ' (' . e($o['code']) . ')' : '' ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="mb-2">
                  <label class="form-label small fw-semibold text-secondary mb-1">Remarks / Instructions</label>
                  <input type="text" name="remarks" class="form-control form-control-sm" placeholder="e.g. Assigned to lead execution of this action">
                </div>

                <button type="submit" class="btn btn-primary btn-sm w-100 shadow-sm mt-2" id="btnSaveAssign">
                  <i class="bi bi-send me-1"></i> Save Assignment
                </button>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <!-- 2. Admin Verification & Review Card: STRICTLY SHOWN ONLY TO ADMINISTRATORS -->
        <div class="card mb-3 no-print shadow-sm" style="border-top: 3.5px solid #F59E0B;">
          <div class="card-header bg-white d-flex align-items-center justify-content-between py-2.5">
            <div class="d-flex align-items-center gap-2">
              <i class="bi bi-shield-check text-warning fs-5"></i>
              <span class="fw-bold text-dark">Admin Verification &amp; Review</span>
            </div>
            <?= actionVerificationBadge($action['verification_status'] ?? 'Pending') ?>
          </div>
          <div class="card-body p-3">
            <div class="p-2.5 mb-3 bg-light rounded border">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="small text-muted">Assigned Staff:</span>
                <span class="small fw-bold text-dark"><i class="bi bi-person-fill text-primary me-1"></i><?= e($action['assigned_user_name'] ?: 'Unassigned') ?></span>
              </div>
              <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="small text-muted">Current Status:</span>
                <span><?= statusBadge($action['status']) ?></span>
              </div>
              <div class="d-flex justify-content-between align-items-center">
                <span class="small text-muted">Verification State:</span>
                <span><?= actionVerificationBadge($action['verification_status'] ?? 'Pending') ?></span>
              </div>
              <?php if (!empty($action['verified_by_name'])): ?>
              <div class="d-flex justify-content-between align-items-center mt-1 pt-1 border-top">
                <span class="small text-muted" style="font-size:11px;">Last Verified By:</span>
                <span class="small text-secondary" style="font-size:11px;"><?= e($action['verified_by_name']) ?> (<?= formatDateTime($action['verified_at']) ?>)</span>
              </div>
              <?php endif; ?>
            </div>

            <?php if (!empty($action['progress_notes'])): ?>
            <div class="mb-3">
              <label class="form-label small fw-semibold text-secondary mb-1">Staff Note / Progress Update:</label>
              <div class="p-2 bg-white rounded border small text-dark" style="max-height: 100px; overflow-y: auto;">
                <?= nl2br(e($action['progress_notes'])) ?>
              </div>
            </div>
            <?php endif; ?>

            <form id="adminVerifyForm">
              <?= csrfField() ?>
              <input type="hidden" name="id" value="<?= (int)$action['id'] ?>">

              <div class="mb-3">
                <label class="form-label small fw-semibold text-secondary mb-1">
                  <i class="bi bi-chat-square-quote text-primary me-1"></i> Admin Remarks / Verification Notes
                </label>
                <textarea name="verification_notes" id="adminVerificationNotes" class="form-control form-control-sm" rows="2" placeholder="Enter remarks (optional for approval, required for return)..."><?= e($action['verification_notes'] ?? '') ?></textarea>
              </div>

              <div class="d-flex flex-column gap-2 pt-2 border-top">
                <button type="button" class="btn btn-success btn-sm w-100 py-2 fw-semibold shadow-sm" id="btnAdminVerify">
                  <i class="bi bi-patch-check-fill me-1"></i> Verify &amp; Approve Update
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm w-100 py-1.5 fw-semibold" id="btnAdminReturn">
                  <i class="bi bi-arrow-counterclockwise me-1"></i> Return for Revision
                </button>
              </div>
            </form>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($isAssignedUser): ?>
        <!-- 3. Update Status & Progress Note Card: STRICTLY SHOWN ONLY TO THE ASSIGNED USER -->
        <div class="card mb-3 no-print shadow-sm" style="border-top: 3.5px solid #0F2137;">
          <div class="card-header bg-white d-flex align-items-center justify-content-between py-2.5">
            <div class="d-flex align-items-center gap-2">
              <i class="bi bi-pencil-square text-primary fs-5"></i>
              <span class="fw-bold text-dark">Update Status &amp; Progress Note</span>
            </div>
            <?= statusBadge($action['status']) ?>
          </div>
          <div class="card-body p-3">
            <form id="actionUpdateForm">
              <?= csrfField() ?>
              <input type="hidden" name="id" value="<?= (int)$action['id'] ?>">

              <div class="mb-3">
                <label class="form-label small fw-semibold text-secondary mb-1">
                  <i class="bi bi-arrow-repeat text-primary me-1"></i> Action Status
                </label>
                <select name="status" class="form-select form-select-sm" id="actionStatusSelect">
                  <?php foreach (['Pending', 'In Progress', 'Under Review', 'Completed', 'Cancelled'] as $st): ?>
                    <option value="<?= e($st) ?>" <?= $action['status'] === $st ? 'selected' : '' ?>><?= e($st) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="mb-3">
                <label class="form-label small fw-semibold text-secondary mb-1" id="actionNoteLabel">
                  <i class="bi bi-chat-left-text text-primary me-1"></i> Progress Note / Status Remarks
                </label>
                <textarea name="note" id="actionNoteInput" class="form-control form-control-sm" rows="3" placeholder="Enter progress updates, actions taken, or remarks..."><?= e($action['progress_notes'] ?? '') ?></textarea>
                <div class="form-text text-muted" style="font-size: 11px;">Updates will be submitted as Pending Admin Verification upon save.</div>
              </div>

              <div class="pt-3 mt-3 border-top">
                <button type="submit" class="btn btn-primary btn-sm w-100 py-2 fw-semibold shadow-sm" id="btnSaveActionUpdate" style="background:#0F2137; border-color:#0F2137;">
                  <i class="bi bi-check2-circle me-1 fs-6"></i> Save Status &amp; Progress Note
                </button>
              </div>
            </form>
          </div>
        </div>
        <?php endif; ?>

        <!-- 4. Progress Timeline Card -->
        <div class="card">
          <div class="card-header"><i class="bi bi-clock-history"></i> Progress Timeline</div>
          <div class="list-group list-group-flush" id="timelineList">
            <?php if (empty($timeline)): ?>
              <div class="list-group-item text-muted small">No progress updates logged yet.</div>
            <?php else: ?>
              <?php foreach ($timeline as $t): ?>
                <div class="list-group-item">
                  <div class="d-flex align-items-start gap-2">
                    <i class="bi <?= $t['type'] === 'assignment' ? 'bi-diagram-3' : 'bi-chat-left-text' ?>"></i>
                    <div>
                      <div class="small text-dark"><?= e($t['text']) ?></div>
                      <div class="text-muted" style="font-size: 11px;">
                        <?= formatDateTime($t['at']) ?> · <?= e($t['author']) ?>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // 1. Admin Assign Form Handler
  var assignForm = document.getElementById('assignForm');
  if (assignForm) {
    assignForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = document.getElementById('btnSaveAssign');
      var origHtml = btn ? btn.innerHTML : '';
      if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...'; }

      var fd = new FormData(assignForm);
      fetch(window.APP_URL + '/modules/actions/ajax_assign.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
      })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.success) {
          if (window.appToast) appToast('success', data.message || 'Assignment saved successfully.');
          setTimeout(function () {
            window.location.href = window.location.pathname + '?id=<?= (int)$action['id'] ?>&_t=' + Date.now();
          }, 450);
        } else {
          if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
          if (window.Swal) Swal.fire('Error', (data && data.message) ? data.message : 'Failed to save assignment.', 'error');
          else alert((data && data.message) ? data.message : 'Failed to save assignment.');
        }
      })
      .catch(function (err) {
        if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
        console.error(err);
      });
    });
  }

  // 2. Assigned User Status & Note Update Handler
  var updateForm = document.getElementById('actionUpdateForm');
  if (updateForm) {
    updateForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = document.getElementById('btnSaveActionUpdate');
      var origHtml = btn ? btn.innerHTML : '';
      if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...'; }

      var fd = new FormData(updateForm);
      fetch(window.APP_URL + '/modules/actions/ajax_transition.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
      })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.success) {
          if (window.appToast) appToast('success', data.message || 'Update saved successfully.');
          setTimeout(function () {
            window.location.href = window.location.pathname + '?id=<?= (int)$action['id'] ?>&_t=' + Date.now();
          }, 450);
        } else {
          if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
          if (window.Swal) Swal.fire('Notice', (data && data.message) ? data.message : 'Failed to save update.', 'warning');
          else alert((data && data.message) ? data.message : 'Failed to save update.');
        }
      })
      .catch(function (err) {
        if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
        console.error(err);
      });
    });
  }

  // 3. Admin Verification Handler
  var btnVerify = document.getElementById('btnAdminVerify');
  var btnReturn = document.getElementById('btnAdminReturn');
  var vForm = document.getElementById('adminVerifyForm');

  function handleAdminVerify(action) {
    if (!vForm) return;
    var notesInput = document.getElementById('adminVerificationNotes');
    var notesVal = notesInput ? notesInput.value.trim() : '';

    if (action === 'return' && !notesVal) {
      if (window.Swal) {
        Swal.fire('Remarks Required', 'Please enter remarks explaining why the update is returned for revision.', 'warning');
      } else {
        alert('Please enter remarks explaining why the update is returned for revision.');
      }
      if (notesInput) notesInput.focus();
      return;
    }

    var activeBtn = action === 'verify' ? btnVerify : btnReturn;
    var origHtml = activeBtn ? activeBtn.innerHTML : '';
    if (activeBtn) {
      activeBtn.disabled = true;
      activeBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Processing...';
    }

    var fd = new FormData(vForm);
    fd.append('action', action);

    fetch(window.APP_URL + '/modules/actions/ajax_verify.php', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data && data.success) {
        if (window.appToast) appToast('success', data.message || 'Verification completed successfully.');
        setTimeout(function () {
          window.location.href = window.location.pathname + '?id=<?= (int)$action['id'] ?>&_t=' + Date.now();
        }, 450);
      } else {
        if (activeBtn) { activeBtn.disabled = false; activeBtn.innerHTML = origHtml; }
        if (window.Swal) Swal.fire('Error', (data && data.message) ? data.message : 'Verification failed.', 'error');
        else alert((data && data.message) ? data.message : 'Verification failed.');
      }
    })
    .catch(function (err) {
      if (activeBtn) { activeBtn.disabled = false; activeBtn.innerHTML = origHtml; }
      console.error(err);
      alert('An unexpected error occurred during verification.');
    });
  }

  if (btnVerify) {
    btnVerify.addEventListener('click', function () { handleAdminVerify('verify'); });
  }
  if (btnReturn) {
    btnReturn.addEventListener('click', function () { handleAdminVerify('return'); });
  }

  // 4. Document Upload Form
  var uploadForm = document.getElementById('uploadForm');
  if (uploadForm) {
    uploadForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = uploadForm.querySelector('button[type="submit"]');
      if (btn) btn.disabled = true;
      var fd = new FormData(uploadForm);
      fetch(window.APP_URL + '/modules/actions/ajax_upload_document.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
      })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (btn) btn.disabled = false;
        if (data && data.success) {
          if (window.appToast) appToast('success', data.message);
          setTimeout(function () { window.location.reload(); }, 500);
        } else {
          if (window.Swal) Swal.fire('Error', (data && data.message) ? data.message : 'Upload failed.', 'error');
          else alert((data && data.message) ? data.message : 'Upload failed.');
        }
      })
      .catch(function (err) {
        if (btn) btn.disabled = false;
        console.error(err);
      });
    });
  }
});
</script>

<?php
$extraJs = [APP_URL . '/assets/js/action-view.js?v=' . time()];
include __DIR__ . '/../../layouts/footer.php';
?>