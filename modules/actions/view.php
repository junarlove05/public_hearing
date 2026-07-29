<?php
/**
 * modules/actions/view.php
 * ------------------------------------------------------------------
 * Full detail view for a single action: info, deadline status,
 * uploaded documents (upload/download/delete), progress updates
 * timeline, and an Assign/Reassign Office form.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireRole([ROLE_ADMIN, ROLE_STAFF, ROLE_COMMITTEE]);

$id = (int)($_GET['id'] ?? 0);
$pdo = db();

$stmt = $pdo->prepare(
    'SELECT a.*, i.title AS issue_title, i.status AS issue_status
     FROM actions a LEFT JOIN issues i ON i.id = a.issue_id
     WHERE a.id = :id'
);
$stmt->execute([':id' => $id]);
$action = $stmt->fetch();

if (!$action) {
    setFlash('danger', 'Action not found.');
    redirect(APP_URL . '/modules/actions/index.php');
}

$documents = $pdo->prepare('SELECT * FROM action_documents WHERE action_id = :id ORDER BY uploaded_at DESC');
$documents->execute([':id' => $id]);
$documents = $documents->fetchAll();

$updates = $pdo->prepare('SELECT * FROM action_updates WHERE action_id = :id ORDER BY created_at DESC');
$updates->execute([':id' => $id]);
$updates = $updates->fetchAll();

$assignments = $pdo->prepare('SELECT * FROM action_assignments WHERE action_id = :id ORDER BY assigned_at DESC');
$assignments->execute([':id' => $id]);
$assignments = $assignments->fetchAll();
$currentOffice = $assignments[0]['assigned_office'] ?? null;

$overdue = $action['deadline'] && $action['deadline'] < date('Y-m-d') && !in_array($action['status'], ['Completed', 'Cancelled'], true);

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
        --av-indigo: #6366F1;
    }

    /* Breadcrumb Bar */
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

    .breadcrumb-bar .text-muted {
        color: var(--av-gray-500) !important;
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

    /* Badges */
    .badge {
        font-weight: 600;
        padding: 0.3rem 0.8rem;
        border-radius: 20px;
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .badge.bg-success {
        background: var(--av-emerald) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
    }

    .badge.bg-warning {
        background: var(--av-amber) !important;
        color: var(--av-dark-900);
        box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
    }

    .badge.bg-danger {
        background: var(--av-rose) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(244, 63, 94, 0.3);
    }

    .badge.bg-info {
        background: var(--av-cyan) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(6, 182, 212, 0.3);
    }

    .badge.bg-secondary {
        background: var(--av-gray-500) !important;
        color: white;
    }

    .badge.bg-status-pending {
        background: var(--av-amber) !important;
        color: var(--av-dark-900);
        box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
    }

    .badge.bg-status-ongoing {
        background: var(--av-cyan) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(6, 182, 212, 0.3);
    }

    .badge.bg-status-completed {
        background: var(--av-emerald) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
    }

    .badge.bg-status-cancelled {
        background: var(--av-gray-500) !important;
        color: white;
    }

    .badge.bg-danger.overdue {
        background: var(--av-rose) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(244, 63, 94, 0.3);
        animation: pulse-overdue 2s ease-in-out infinite;
    }

    @keyframes pulse-overdue {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.7; }
    }

    /* Buttons */
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

    .btn-primary i {
        color: var(--av-amber);
    }

    .btn-primary:hover {
        border-color: var(--av-amber);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.25);
        color: var(--av-white);
        transform: translateY(-2px);
    }

    .btn-primary.btn-sm {
        padding: 0.3rem 0.8rem;
        font-size: 0.8rem;
    }

    .btn-outline-secondary {
        border: 2px solid var(--av-gray-200);
        color: var(--av-gray-600);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        border-radius: 8px;
        font-weight: 500;
        background: transparent;
        padding: 0.25rem 0.6rem;
        font-size: 0.8rem;
    }

    .btn-outline-secondary:hover {
        background: var(--av-gray-100);
        border-color: var(--av-amber);
        color: var(--av-dark-900);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    }

    .btn-outline-secondary i {
        color: var(--av-amber);
    }

    .btn-outline-danger {
        border: 2px solid var(--av-rose);
        color: var(--av-rose);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        border-radius: 8px;
        font-weight: 500;
        background: transparent;
        padding: 0.25rem 0.6rem;
        font-size: 0.8rem;
    }

    .btn-outline-danger:hover {
        background: var(--av-rose);
        color: white;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(244, 63, 94, 0.3);
    }

    /* Cards */
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

    .card-header .badge {
        margin-left: auto;
    }

    .card-body {
        padding: 1.25rem;
        background: var(--av-white);
    }

    /* Form Controls */
    .form-control,
    .form-select {
        border: 2px solid var(--av-gray-200);
        border-radius: 10px;
        padding: 0.5rem 1rem;
        font-size: 0.875rem;
        transition: all 0.3s ease;
        background: var(--av-gray-100);
        color: var(--av-dark-900);
        font-weight: 500;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: var(--av-amber);
        box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.12);
        background: var(--av-white);
    }

    .form-control::placeholder {
        color: var(--av-gray-400);
        font-weight: 400;
    }

    .form-control-sm {
        font-size: 0.8rem;
        padding: 0.4rem 0.75rem;
    }

    /* Table */
    .table {
        margin-bottom: 0;
    }

    .table-borderless td,
    .table-borderless th {
        padding: 0.5rem 0;
    }

    .table-borderless tr:first-child td,
    .table-borderless tr:first-child th {
        padding-top: 0;
    }

    .table-borderless tr:last-child td,
    .table-borderless tr:last-child th {
        padding-bottom: 0;
    }

    .table th.text-muted {
        color: var(--av-gray-500) !important;
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .table td {
        color: var(--av-dark-900);
        font-weight: 500;
    }

    .table td a {
        color: var(--av-amber) !important;
        text-decoration: none;
        font-weight: 600;
        transition: color 0.3s ease;
    }

    .table td a:hover {
        color: var(--av-dark-900) !important;
        text-decoration: underline;
    }

    .table td .text-danger {
        color: var(--av-rose) !important;
    }

    /* List Group */
    .list-group-item {
        border: none;
        border-bottom: 1px solid var(--av-gray-200);
        padding: 0.75rem 1.25rem;
        transition: all 0.3s ease;
        background: transparent;
        color: var(--av-dark-900);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .list-group-item:hover {
        background: #FFFBEB;
        transform: translateX(4px);
    }

    .list-group-item:last-child {
        border-bottom: none;
    }

    .list-group-item .text-muted {
        color: var(--av-gray-500) !important;
    }

    .list-group-item .small {
        color: var(--av-dark-900);
        font-weight: 500;
    }

    .list-group-item .text-muted.small {
        color: var(--av-gray-500) !important;
        font-weight: 400;
    }

    .list-group-item .btn-group {
        flex-shrink: 0;
    }

    .list-group-item i {
        font-size: 1.1rem;
    }

    .list-group-item i.text-danger {
        color: var(--av-rose) !important;
    }

    .list-group-item i.text-primary {
        color: var(--av-amber) !important;
    }

    .list-group-item i.text-success {
        color: var(--av-emerald) !important;
    }

    /* Document icons */
    .bi-file-earmark-pdf.text-danger {
        color: var(--av-rose) !important;
    }

    .bi-file-earmark-word.text-primary {
        color: var(--av-amber) !important;
    }

    .bi-file-earmark-image.text-success {
        color: var(--av-emerald) !important;
    }

    /* Timeline updates */
    .list-group-item .small {
        line-height: 1.5;
    }

    .list-group-item .text-muted {
        font-size: 11px;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .breadcrumb-bar {
            flex-direction: column;
            gap: 0.5rem;
            align-items: flex-start;
            padding: 1rem;
        }
        .card-header {
            font-size: 0.9rem;
            flex-wrap: wrap;
        }
        .col-lg-5 .card {
            margin-bottom: 1rem;
        }
        .list-group-item {
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .list-group-item .btn-group {
            width: 100%;
            justify-content: flex-end;
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
        .table th.text-muted {
            font-size: 0.65rem;
        }
        .table td {
            font-size: 0.8rem;
        }
        .list-group-item {
            padding: 0.5rem 0.75rem;
        }
        .card-body .d-flex.gap-2 {
            flex-wrap: wrap;
        }
        .card-body .d-flex.gap-2 .form-control {
            flex: 1;
            min-width: 120px;
        }
    }
</style>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../layouts/sidebar.php'; ?>

  <div class="main-content">
    <div class="breadcrumb-bar d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <a href="index.php" class="text-decoration-none small no-print"><i class="bi bi-arrow-left"></i> Back to Response &amp; Action Tracking</a>
        <h5 class="mb-0 mt-1"><i class="bi bi-list-check"></i> <?= e($action['title']) ?></h5>
      </div>
      <div class="d-flex gap-2 no-print">
        <?php if ($overdue): ?><span class="badge bg-danger overdue">Overdue</span><?php endif; ?>
        <?= statusBadge($action['status']) ?>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-lg-7">
        <div class="card mb-3">
          <div class="card-header"><i class="bi bi-info-circle"></i> Action Details</div>
          <div class="card-body">
            <table class="table table-borderless mb-0">
              <tr><th class="text-muted small" style="width:30%;">Linked Issue</th>
                  <td><?php if ($action['issue_id']): ?><a href="<?= e(APP_URL) ?>/modules/issues/view.php?id=<?= (int)$action['issue_id'] ?>"><?= e($action['issue_title']) ?></a><?php else: ?>-<?php endif; ?></td></tr>
              <tr><th class="text-muted small">Current Office</th><td id="currentOffice"><?= e($currentOffice ?: 'Unassigned') ?></td></tr>
              <tr><th class="text-muted small">Deadline</th><td><?= $action['deadline'] ? formatDate($action['deadline']) : '-' ?> <?= $overdue ? '<span class="text-danger small fw-semibold">(Overdue)</span>' : '' ?></td></tr>
              <tr><th class="text-muted small">Status</th><td><?= statusBadge($action['status']) ?></td></tr>
              <tr><th class="text-muted small">Created</th><td><?= formatDateTime($action['created_at']) ?></td></tr>
              <tr><th class="text-muted small">Description</th><td><?= nl2br(e($action['description'] ?: '-')) ?></td></tr>
            </table>
          </div>
        </div>

        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-files"></i> Documents</span>
            <span class="badge bg-secondary"><?= count($documents) ?></span>
          </div>
          <div class="list-group list-group-flush">
            <?php if (empty($documents)): ?>
              <div class="list-group-item text-muted small">No documents uploaded.</div>
            <?php endif; ?>
            <?php foreach ($documents as $doc): ?>
              <div class="list-group-item d-flex justify-content-between align-items-center">
                <div><i class="bi <?= $docIcon($doc['file_path']) ?> me-2"></i><?= e($doc['file_name']) ?>
                  <div class="text-muted small">Uploaded <?= formatDateTime($doc['uploaded_at']) ?></div></div>
                <div class="btn-group btn-group-sm no-print">
                  <a href="<?= e(UPLOAD_URL . $doc['file_path']) ?>" target="_blank" class="btn btn-outline-secondary"><i class="bi bi-download"></i></a>
                  <?php if (canManage()): ?>
                  <button type="button" class="btn btn-outline-danger" data-confirm-delete="document &quot;<?= e($doc['file_name']) ?>&quot;"
                          data-delete-url="<?= e(APP_URL) ?>/modules/actions/document_delete.php?id=<?= (int)$doc['id'] ?>"><i class="bi bi-trash"></i></button>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <?php if (canManage()): ?>
          <div class="card-body border-top no-print">
            <form id="uploadForm" enctype="multipart/form-data" class="d-flex gap-2">
              <?= csrfField() ?>
              <input type="hidden" name="id" value="<?= (int)$action['id'] ?>">
              <input type="file" name="documents[]" class="form-control form-control-sm" multiple accept=".pdf,.doc,.docx,.png,.jpg,.jpeg" required>
              <button type="submit" class="btn btn-primary btn-sm text-nowrap"><i class="bi bi-upload"></i> Upload</button>
            </form>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="col-lg-5">
        <?php if (canManage()): ?>
        <div class="card mb-3 no-print">
          <div class="card-header"><i class="bi bi-diagram-3"></i> Assign / Reassign Office</div>
          <div class="card-body">
            <form id="assignForm" class="d-flex gap-2">
              <?= csrfField() ?>
              <input type="hidden" name="id" value="<?= (int)$action['id'] ?>">
              <input type="text" name="assigned_office" class="form-control form-control-sm" placeholder="Office name" required>
              <button type="submit" class="btn btn-primary btn-sm text-nowrap"><i class="bi bi-send"></i> Assign</button>
            </form>
          </div>
        </div>

        <div class="card mb-3 no-print">
          <div class="card-header"><i class="bi bi-pencil-square"></i> Add Progress Update</div>
          <div class="card-body">
            <form id="updateForm">
              <?= csrfField() ?>
              <input type="hidden" name="id" value="<?= (int)$action['id'] ?>">
              <textarea name="update_text" class="form-control form-control-sm mb-2" rows="3" placeholder="Describe progress made..." required></textarea>
              <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-plus-circle"></i> Add Update</button>
            </form>
          </div>
        </div>
        <?php endif; ?>

        <div class="card">
          <div class="card-header"><i class="bi bi-clock-history"></i> Progress Timeline</div>
          <div class="list-group list-group-flush">
            <?php if (empty($updates)): ?>
              <div class="list-group-item text-muted small">No updates yet.</div>
            <?php endif; ?>
            <?php foreach ($updates as $u): ?>
              <div class="list-group-item">
                <div class="small"><?= e($u['update_text']) ?></div>
                <div class="text-muted" style="font-size:11px;"><?= formatDateTime($u['created_at']) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = [APP_URL . '/assets/js/action-view.js'];
include __DIR__ . '/../../layouts/footer.php';
?>