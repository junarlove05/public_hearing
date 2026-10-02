<?php
/**
 * modules/issues/view.php
 * ------------------------------------------------------------------
 * Full detail view for a single issue: info, combined timeline
 * (issue_history notes + issue_assignments reassignments, merged
 * chronologically), add-note form, and assign-office form.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireRole([ROLE_ADMIN, ROLE_STAFF, ROLE_COMMITTEE]);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$id = (int)($_GET['id'] ?? 0);
$pdo = db();

$stmt = $pdo->prepare(
    'SELECT
        i.*,
        ic.name AS category_name,
        h.title AS hearing_title,
        o.name AS assigned_office,
        u.full_name AS assigned_user_name,
        u.email AS assigned_user_email,
        cs.reference_number AS cef_reference_number
     FROM hearing_issues i
     LEFT JOIN hearing_issue_categories ic
        ON ic.id = i.category_id
     LEFT JOIN hearings h
        ON h.id = i.hearing_id
     LEFT JOIN offices o
        ON o.id = i.assigned_office_id
     LEFT JOIN users u
        ON u.id = i.assigned_user_id
     LEFT JOIN cef_submissions cs
        ON cs.id = i.cef_submission_id
     WHERE i.id = :id'
);
$stmt->execute([':id' => $id]);
$issue = $stmt->fetch();

if (!$issue) {
    setFlash('danger', 'Issue not found.');
    redirect(APP_URL . '/modules/issues/index.php');
}

$isAdmin = (function_exists('isAdmin') && isAdmin()) || (isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === 1);
$currentUid = (int)(currentUserId() ?? 0);
$isAssignedUser = ($currentUid > 0 && (int)($issue['assigned_user_id'] ?? 0) === $currentUid);
$canManageIssue = $isAdmin || canManage() || $isAssignedUser;

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

$officesList = $pdo->query(
    "SELECT id, name, code FROM offices WHERE status = 'Active' ORDER BY name ASC"
)->fetchAll();

$categories = $pdo->query(
    "SELECT id, name FROM hearing_issue_categories ORDER BY name ASC"
)->fetchAll();

$hearings = $pdo->query(
    "SELECT id, title FROM hearings ORDER BY hearing_date DESC, hearing_time DESC"
)->fetchAll();

// Fallback: direct POST processing for status & note update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unified_update'])) {
    requireCsrf();
    $postStatus = clean($_POST['status'] ?? '');
    $postNote = clean($_POST['note'] ?? '');
    $postResolution = trim((string)($_POST['resolution_summary'] ?? ''));
    if ($postResolution === '' && $postNote !== '') {
        $postResolution = $postNote;
    }

    $currentUid = (int)(currentUserId() ?? 0);
    $isAssigned = ((int)($issue['assigned_user_id'] ?? 0) === $currentUid && $currentUid > 0);
    if (!canManage() && !$isAdmin && !$isAssigned) {
        setFlash('danger', 'You do not have permission to update this issue.');
        redirect(APP_URL . '/modules/issues/view.php?id=' . $id);
    }

    $allowed = ['Open', 'In Progress', 'Resolved', 'Closed'];
    if (in_array($postStatus, $allowed, true)) {
        $statusChanged = ($issue['status'] !== $postStatus);

        if (in_array($postStatus, ['Resolved', 'Closed'], true) && $postResolution === '' && $postNote === '') {
            $postResolution = "Marked as {$postStatus} by " . (currentUser()['full_name'] ?? 'Assigned Staff');
            $postNote = $postResolution;
        }

        $resolvedAt = in_array($postStatus, ['Resolved', 'Closed'], true) ? ($issue['resolved_at'] ?: date('Y-m-d H:i:s')) : null;
        $resolvedBy = in_array($postStatus, ['Resolved', 'Closed'], true) ? ($issue['resolved_by'] ?: currentUserId()) : null;
        $closedAt   = ($postStatus === 'Closed') ? ($issue['closed_at'] ?: date('Y-m-d H:i:s')) : null;

        $pdo->prepare(
            'UPDATE hearing_issues
             SET status = :status,
                 resolution_summary = CASE WHEN :summary <> "" THEN :summary_val ELSE resolution_summary END,
                 resolved_by = :resolved_by,
                 resolved_at = :resolved_at,
                 closed_at = :closed_at,
                 updated_at = NOW()
             WHERE id = :id'
        )->execute([
            ':status'      => $postStatus,
            ':summary'     => $postResolution,
            ':summary_val' => $postResolution ?: null,
            ':resolved_by' => $resolvedBy,
            ':resolved_at' => $resolvedAt,
            ':closed_at'   => $closedAt,
            ':id'          => $id,
        ]);

        if (!in_array($postStatus, ['Resolved', 'Closed'], true)) {
            $pdo->prepare('UPDATE hearing_issues SET closed_at = NULL, resolved_at = NULL WHERE id = :id')->execute([':id' => $id]);
        }

        if ($statusChanged && $postNote !== '') {
            $hist = "Status changed from {$issue['status']} to {$postStatus}. Note: {$postNote}";
        } elseif ($statusChanged) {
            $hist = "Status changed from {$issue['status']} to {$postStatus}." . ($postResolution !== '' ? ' Resolution: ' . $postResolution : '');
        } else {
            $hist = $postNote;
        }

        if ($hist !== '') {
            $pdo->prepare(
                'INSERT INTO hearing_issue_history (issue_id, note, created_by, created_at)
                 VALUES (:id, :note, :user, NOW())'
            )->execute([
                ':id'   => $id,
                ':note' => $hist,
                ':user' => currentUserId(),
            ]);
        }

        logActivity(currentUserId(), 'Issue Workflow', "{$issue['reference_number']} status {$issue['status']} -> {$postStatus}." . ($postNote !== '' ? " Note: {$postNote}" : ''));

        setFlash('success', 'Issue status and progress note updated successfully.');
        redirect(APP_URL . '/modules/issues/view.php?id=' . $id . '&_t=' . time());
    }
}

// Merge issue_history notes and issue_assignments into one chronological timeline.
$historyStmt = $pdo->prepare(
    'SELECT
        ih.note,
        ih.created_at,
        u.full_name
     FROM hearing_issue_history ih
     LEFT JOIN users u ON u.id = ih.created_by
     WHERE ih.issue_id = :id
     ORDER BY ih.created_at DESC'
);

$historyStmt->execute([':id' => $id]);

$historyRows = array_map(
    function (array $row): array {
        $author = $row['full_name']
            ? ' — ' . $row['full_name']
            : '';

        return [
            'type' => 'note',
            'text' => $row['note'] . $author,
            'at'   => $row['created_at'],
        ];
    },
    $historyStmt->fetchAll()
);

$assignStmt = $pdo->prepare(
    'SELECT
        ia.assigned_at,
        o.name AS office_name,
        u.full_name AS assigned_user_name
     FROM hearing_issue_assignments ia
     LEFT JOIN offices o
        ON o.id = ia.assigned_office_id
     LEFT JOIN users u
        ON u.id = ia.assigned_user_id
     WHERE ia.issue_id = :id
     ORDER BY ia.assigned_at DESC'
);

$assignStmt->execute([':id' => $id]);

$assignRows = array_map(
    function (array $row): array {
        $assignedTo =
            $row['office_name']
            ?: $row['assigned_user_name']
            ?: 'Unassigned';

        return [
            'type' => 'assignment',
            'text' => 'Assigned to ' . $assignedTo,
            'at'   => $row['assigned_at'],
        ];
    },
    $assignStmt->fetchAll()
);

$timeline = array_merge($historyRows, $assignRows);
usort($timeline, fn($a, $b) => strtotime($b['at']) <=> strtotime($a['at']));

// Linked actions (Response & Action Tracking module, built next).
$actionsStmt = $pdo->prepare(
    'SELECT
        id,
        title,
        status,
        deadline
     FROM hearing_actions
     WHERE issue_id = :id
     ORDER BY created_at DESC'
);

$actionsStmt->execute([':id' => $id]);
$linkedActions = $actionsStmt->fetchAll();

$pageTitle  = $issue['title'];
$activeMenu = 'issues';

include __DIR__ . '/../../layouts/header.php';
?>
<style>
    /* Issue View - Dark Theme */
    :root {
        --iv-dark-900: #0F172A;
        --iv-dark-800: #1E293B;
        --iv-dark-700: #334155;
        --iv-amber: #F59E0B;
        --iv-amber-light: #FBBF24;
        --iv-white: #FFFFFF;
        --iv-gray-100: #F1F5F9;
        --iv-gray-200: #E2E8F0;
        --iv-gray-300: #CBD5E1;
        --iv-gray-400: #94A3B8;
        --iv-gray-500: #64748B;
        --iv-gray-600: #475569;
        --iv-emerald: #10B981;
        --iv-rose: #F43F5E;
        --iv-violet: #8B5CF6;
        --iv-cyan: #06B6D4;
        --iv-orange: #F97316;
        --iv-teal: #14B8A6;
        --iv-indigo: #0F2137;
    }

    /* Breadcrumb Bar */
    .breadcrumb-bar {
        background: var(--iv-white);
        border-left: 4px solid var(--iv-amber);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
        padding: 1rem 1.5rem;
        border-radius: 12px;
        margin-bottom: 1.5rem;
    }

    .breadcrumb-bar h5 {
        color: var(--iv-dark-900);
        font-weight: 700;
    }

    .breadcrumb-bar h5 i {
        color: var(--iv-amber);
    }

    .breadcrumb-bar .text-muted {
        color: var(--iv-gray-500) !important;
    }

    .breadcrumb-bar a {
        color: var(--iv-amber) !important;
        text-decoration: none;
        font-weight: 500;
        transition: color 0.3s ease;
    }

    .breadcrumb-bar a:hover {
        color: var(--iv-dark-900) !important;
        text-decoration: underline;
    }

    /* Buttons */
    .btn-primary {
        background: linear-gradient(135deg, var(--iv-dark-900) 0%, var(--iv-dark-800) 100%);
        border: 1px solid rgba(245, 158, 11, 0.15);
        color: var(--iv-white);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        border-radius: 10px;
        font-weight: 600;
        padding: 0.45rem 1.25rem;
        transition: all 0.3s ease;
    }

    .btn-primary i {
        color: var(--iv-amber);
    }

    .btn-primary:hover {
        border-color: var(--iv-amber);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.25);
        color: var(--iv-white);
        transform: translateY(-2px);
    }

    .btn-primary.btn-sm {
        padding: 0.3rem 0.8rem;
        font-size: 0.8rem;
    }

    /* Cards */
    .card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 2px 15px rgba(0, 0, 0, 0.06);
        background: var(--iv-white);
        transition: all 0.3s ease;
        overflow: hidden;
    }

    .card:hover {
        box-shadow: 0 4px 25px rgba(0, 0, 0, 0.1);
    }

    .card-header {
        background: linear-gradient(135deg, var(--iv-dark-900) 0%, var(--iv-dark-800) 100%);
        color: var(--iv-white);
        font-weight: 600;
        padding: 0.75rem 1.25rem;
        border-bottom: 3px solid var(--iv-amber);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .card-header i {
        color: var(--iv-amber);
        font-size: 1.1rem;
    }

    .card-body {
        padding: 1.25rem;
        background: var(--iv-white);
    }

    /* Form Controls */
    .form-control,
    .form-select {
        border: 2px solid var(--iv-gray-200);
        border-radius: 10px;
        padding: 0.5rem 1rem;
        font-size: 0.875rem;
        transition: all 0.3s ease;
        background: var(--iv-gray-100);
        color: var(--iv-dark-900);
        font-weight: 500;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: var(--iv-amber);
        box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.12);
        background: var(--iv-white);
    }

    .form-control::placeholder {
        color: var(--iv-gray-400);
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
        color: var(--iv-gray-500) !important;
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .table td {
        color: var(--iv-dark-900);
        font-weight: 500;
    }

    /* Badges - Priority & Status */
    .badge {
        font-weight: 600;
        padding: 0.3rem 0.8rem;
        border-radius: 20px;
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .badge.bg-success {
        background: var(--iv-emerald) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
    }

    .badge.bg-warning {
        background: var(--iv-amber) !important;
        color: var(--iv-dark-900);
        box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
    }

    .badge.bg-danger {
        background: var(--iv-rose) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(244, 63, 94, 0.3);
    }

    .badge.bg-info {
        background: var(--iv-cyan) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(6, 182, 212, 0.3);
    }

    .badge.bg-secondary {
        background: var(--iv-gray-500) !important;
        color: white;
    }

    .badge.bg-priority-high {
        background: var(--iv-rose) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(244, 63, 94, 0.3);
    }

    .badge.bg-priority-medium {
        background: var(--iv-amber) !important;
        color: var(--iv-dark-900);
        box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
    }

    .badge.bg-priority-low {
        background: var(--iv-emerald) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
    }

    /* List Group / Timeline */
    .list-group-item {
        border: none;
        border-bottom: 1px solid var(--iv-gray-200);
        padding: 0.75rem 1.25rem;
        transition: all 0.3s ease;
        background: transparent;
        color: var(--iv-dark-900);
    }

    .list-group-item:hover {
        background: #FFFBEB;
        transform: translateX(4px);
    }

    .list-group-item:last-child {
        border-bottom: none;
    }

    .list-group-item .text-muted {
        color: var(--iv-gray-500) !important;
    }

    .list-group-item i.bi-diagram-3 {
        color: var(--iv-amber) !important;
    }

    .list-group-item i.bi-chat-left-text {
        color: var(--iv-gray-400) !important;
    }

    .list-group-item .small {
        color: var(--iv-dark-900);
        font-weight: 500;
    }

    /* Linked Actions */
    .list-group-item a {
        color: var(--iv-dark-900) !important;
        font-weight: 600;
        transition: color 0.3s ease;
    }

    .list-group-item a:hover {
        color: var(--iv-amber) !important;
        text-decoration: underline;
    }

    .list-group-item .text-muted.small {
        color: var(--iv-gray-500) !important;
        font-weight: 400;
    }

    /* Description */
    .card-body .table td {
        line-height: 1.6;
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
        }
        .col-lg-5 .card {
            margin-bottom: 1rem;
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
    }
</style>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../layouts/sidebar.php'; ?>

  <div class="main-content">
    <?php include __DIR__ . '/../../layouts/top_controls.php'; ?>
    <div class="breadcrumb-bar d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <div>
        <a href="index.php" class="text-decoration-none small no-print"><i class="bi bi-arrow-left"></i> Back to Issue Logging</a>
        <h5 class="mb-0 mt-1"><i class="bi bi-exclamation-triangle"></i> <?= e($issue['title']) ?></h5>
      </div>
      <div class="d-flex gap-2 align-items-center no-print">
        <?php if ($canManageIssue): ?>
          <button type="button" class="btn btn-primary btn-sm fw-semibold shadow-sm" data-bs-toggle="modal" data-bs-target="#updateIssueModal">
            <i class="bi bi-pencil-square me-1"></i> Edit Issue
          </button>
        <?php endif; ?>
        <?= priorityBadge($issue['priority']) ?>
        <?= statusBadge($issue['status']) ?>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-lg-7">
        <div class="card mb-3">
          <div class="card-header"><i class="bi bi-info-circle"></i> Issue Details</div>
          <div class="card-body">
            <table class="table table-borderless mb-0">
              <tr><th class="text-muted small" style="width:30%;">Reference No.</th><td><span class="badge bg-secondary"><?= e($issue['reference_number']) ?></span></td></tr>
              <tr><th class="text-muted small">Category</th><td><?= e($issue['category_name'] ?? '-') ?></td></tr>
              <tr><th class="text-muted small">Related Hearing</th><td><?= e($issue['hearing_title'] ?? '-') ?></td></tr>
              <tr>
                <th class="text-muted small">Assigned To</th>
                <td>
                  <?php if (!empty($issue['assigned_user_name'])): ?>
                    <span class="badge bg-primary text-white"><i class="bi bi-person-fill me-1"></i> <?= e($issue['assigned_user_name']) ?></span>
                    <?php if (!empty($issue['assigned_office'])): ?>
                      <span class="badge bg-light text-dark border ms-1"><i class="bi bi-building me-1"></i> <?= e($issue['assigned_office']) ?></span>
                    <?php endif; ?>
                  <?php elseif (!empty($issue['assigned_office'])): ?>
                    <span class="badge bg-light text-dark border"><i class="bi bi-building me-1"></i> <?= e($issue['assigned_office']) ?></span>
                  <?php else: ?>
                    <span class="text-muted fst-italic">Unassigned</span>
                  <?php endif; ?>
                </td>
              </tr>
              <tr><th class="text-muted small">Priority</th><td><?= priorityBadge($issue['priority']) ?></td></tr>
              <tr><th class="text-muted small">Status</th><td><?= statusBadge($issue['status']) ?></td></tr>
              <?php if (!empty($issue['cef_reference_number'])): ?>
                <tr>
                  <th class="text-muted small">Citizen Portal</th>
                  <td>
                    <a href="<?= e(APP_URL) ?>/modules/feedback/cef_ai_review.php?id=<?= (int)$issue['cef_submission_id'] ?>" class="badge bg-primary text-white text-decoration-none">
                      <i class="bi bi-person-lines-fill me-1"></i> <?= e($issue['cef_reference_number']) ?>
                    </a>
                  </td>
                </tr>
              <?php endif; ?>
              <tr><th class="text-muted small">Logged</th><td><?= formatDateTime($issue['created_at']) ?></td></tr>
              <?php if (!empty($issue['due_at'])): ?>
                <tr><th class="text-muted small">Due Date</th><td><?= formatDateTime($issue['due_at']) ?></td></tr>
              <?php endif; ?>
              <?php if (!empty($issue['resolution_summary'])): ?>
                <tr>
                  <th class="text-muted small">Resolution</th>
                  <td><div class="alert alert-success py-2 px-3 mb-0 small"><?= nl2br(e($issue['resolution_summary'])) ?></div></td>
                </tr>
              <?php endif; ?>
              <tr><th class="text-muted small">Description</th><td><?= nl2br(e($issue['description'])) ?></td></tr>
            </table>
          </div>
        </div>

        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-link-45deg"></i> Linked Response &amp; Action Records</span>
            <?php if (canManage()): ?>
              <a href="<?= e(APP_URL) ?>/modules/actions/index.php" class="btn btn-primary btn-sm no-print">
                <i class="bi bi-arrow-up-right-square me-1"></i> Response &amp; Action Tracking
              </a>
            <?php endif; ?>
          </div>
          <div class="list-group list-group-flush">
            <?php if (empty($linkedActions)): ?>
              <div class="list-group-item text-muted small">No action records linked to this issue yet.</div>
            <?php else: ?>
              <?php foreach ($linkedActions as $a): ?>
                <div class="list-group-item d-flex justify-content-between align-items-center">
                  <a href="<?= e(APP_URL) ?>/modules/actions/view.php?id=<?= (int)$a['id'] ?>" class="text-decoration-none fw-semibold">
                    <i class="bi bi-arrow-right-circle text-primary me-1"></i><?= e($a['title']) ?>
                  </a>
                  <div class="d-flex gap-2 align-items-center">
                    <?php if ($a['deadline']): ?><span class="text-muted small">Due <?= formatDate($a['deadline']) ?></span><?php endif; ?>
                    <?= statusBadge($a['status']) ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="col-lg-5">
        <!-- 1. Assign Issue Card (Admin / Staff) -->
        <?php if ($isAdmin || canManage()): ?>
        <div class="card mb-3 no-print shadow-sm" style="border-top: 3.5px solid #0F2137;">
          <div class="card-header bg-white d-flex align-items-center justify-content-between py-2.5">
            <div class="d-flex align-items-center gap-2">
              <i class="bi bi-diagram-3 text-primary fs-5"></i>
              <span class="fw-bold text-dark">Assign Issue</span>
            </div>
            <span class="badge bg-secondary"><?= $isAdmin ? 'Administrator' : 'Staff' ?></span>
          </div>
          <div class="card-body p-3">
            <form id="assignForm">
              <?= csrfField() ?>
              <input type="hidden" name="id" value="<?= (int)$issue['id'] ?>">

              <div class="mb-2">
                <label class="form-label small fw-semibold text-secondary mb-1">
                  <i class="bi bi-person-check text-primary me-1"></i> Assign to Staff / Person
                </label>
                <select name="assigned_user_id" id="assignUserSelect" class="form-select form-select-sm" required>
                  <option value="">— Select Staff / Committee Member —</option>
                  <?php foreach ($assignableUsers as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" 
                            data-office-id="<?= (int)($u['office_id'] ?? 0) ?>"
                            <?= ((int)($issue['assigned_user_id'] ?? 0) === (int)$u['id']) ? 'selected' : '' ?>>
                      <?= e($u['full_name']) ?> (<?= e($u['role_name'] ?: 'Staff') ?><?= $u['office_name'] ? ' · ' . e($u['office_name']) : '' ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text text-muted" style="font-size: 0.72rem;">
                  <i class="bi bi-shield-check text-success"></i> Administrators cannot be assigned to issues.
                </div>
              </div>

              <div class="mb-2">
                <label class="form-label small fw-semibold text-secondary mb-1">
                  <i class="bi bi-building text-primary me-1"></i> Assigned Office
                </label>
                <select name="assigned_office_id" id="assignOfficeSelect" class="form-select form-select-sm">
                  <option value="">— Select Office —</option>
                  <?php foreach ($officesList as $o): ?>
                    <option value="<?= (int)$o['id'] ?>" <?= ((int)($issue['assigned_office_id'] ?? 0) === (int)$o['id']) ? 'selected' : '' ?>>
                      <?= e($o['name']) ?><?= $o['code'] ? ' (' . e($o['code']) . ')' : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="mb-2">
                <label class="form-label small fw-semibold text-secondary mb-1">Remarks / Instructions</label>
                <input type="text" name="remarks" class="form-control form-control-sm" placeholder="e.g. Assigned to investigate and take action">
              </div>

              <button type="submit" class="btn btn-primary btn-sm w-100 shadow-sm mt-2" id="btnSaveAssign">
                <i class="bi bi-send me-1"></i> Save Assignment
              </button>
            </form>
          </div>
        </div>
        <?php endif; ?>

        <!-- 2. Update Status & Progress Card -->
        <?php if ($canManageIssue): ?>
        <div class="card mb-3 no-print shadow-sm">
          <div class="card-header bg-white d-flex align-items-center justify-content-between py-2.5">
            <div class="d-flex align-items-center gap-2">
              <i class="bi bi-check2-circle text-success fs-5"></i>
              <span class="fw-bold text-dark">Update Status &amp; Notes</span>
            </div>
          </div>
          <div class="card-body p-3">
            <form id="unifiedUpdateForm">
              <?= csrfField() ?>
              <input type="hidden" name="id" value="<?= (int)$issue['id'] ?>">
              <div class="mb-2">
                <label class="form-label small fw-semibold text-secondary mb-1">Status</label>
                <select name="status" id="unifiedStatusSelect" class="form-select form-select-sm">
                  <?php foreach (['Open', 'In Progress', 'Resolved', 'Closed'] as $s): ?>
                    <option value="<?= e($s) ?>" <?= ($issue['status'] === $s) ? 'selected' : '' ?>><?= e($s) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-2">
                <label class="form-label small fw-semibold text-secondary mb-1" id="unifiedNoteLabel">
                  <i class="bi bi-chat-left-text text-primary me-1"></i> Progress Note / Status Remarks
                </label>
                <textarea name="note" id="unifiedNoteInput" class="form-control form-control-sm" rows="3" placeholder="Enter progress updates, actions taken, or remarks..."></textarea>
              </div>
              <button type="submit" class="btn btn-primary btn-sm w-100 shadow-sm" id="btnSaveUnified">
                <i class="bi bi-save me-1"></i> Update Issue
              </button>
            </form>
          </div>
        </div>
        <?php endif; ?>

        <!-- 3. Issue History Timeline -->
        <div class="card shadow-sm">
          <div class="card-header bg-white"><i class="bi bi-clock-history"></i> Issue History</div>
          <div class="list-group list-group-flush" id="timelineList">
            <?php if (empty($timeline)): ?>
              <div class="list-group-item text-muted small">No history logged yet.</div>
            <?php else: ?>
              <?php foreach ($timeline as $t): ?>
                <div class="list-group-item">
                  <div class="d-flex align-items-start gap-2">
                    <i class="bi <?= $t['type'] === 'assignment' ? 'bi-diagram-3 text-warning' : 'bi-chat-left-text text-primary' ?>"></i>
                    <div>
                      <div class="small"><?= e($t['text']) ?></div>
                      <div class="text-muted" style="font-size:11px;"><?= formatDateTime($t['at']) ?></div>
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

<!-- Full Update Issue Modal (Admin & Managers) -->
<?php if ($canManageIssue): ?>
<div class="modal fade" id="updateIssueModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: 800px;">
    <div class="modal-content border-0 shadow">
      <form id="updateIssueForm">
        <?= csrfField() ?>
        <input type="hidden" name="id" value="<?= (int)$issue['id'] ?>">
        <div class="modal-header py-3 px-4 bg-light border-bottom">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-pencil-square fs-5 text-warning"></i>
            <h5 class="modal-title fw-bold text-dark mb-0">Edit Issue: <?= e($issue['reference_number']) ?></h5>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label small fw-semibold text-secondary mb-1">Title <span class="text-danger">*</span></label>
              <input type="text" name="title" class="form-control" value="<?= e($issue['title']) ?>" required maxlength="255">
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold text-secondary mb-1">Description <span class="text-danger">*</span></label>
              <textarea name="description" class="form-control" rows="3" required><?= e($issue['description']) ?></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Category</label>
              <select name="category_id" class="form-select">
                <option value="">-- Select Category --</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int)$c['id'] ?>" <?= ((int)$issue['category_id'] === (int)$c['id']) ? 'selected' : '' ?>>
                    <?= e($c['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Related Hearing</label>
              <select name="hearing_id" class="form-select">
                <option value="">-- None --</option>
                <?php foreach ($hearings as $h): ?>
                  <option value="<?= (int)$h['id'] ?>" <?= ((int)$issue['hearing_id'] === (int)$h['id']) ? 'selected' : '' ?>>
                    <?= e($h['title']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Priority</label>
              <select name="priority" class="form-select">
                <?php foreach (['Low', 'Medium', 'High', 'Critical'] as $p): ?>
                  <option value="<?= e($p) ?>" <?= ($issue['priority'] === $p) ? 'selected' : '' ?>><?= e($p) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Status</label>
              <select name="status" id="modalStatusSelect" class="form-select">
                <?php foreach (['Open', 'In Progress', 'Resolved', 'Closed'] as $s): ?>
                  <option value="<?= e($s) ?>" <?= ($issue['status'] === $s) ? 'selected' : '' ?>><?= e($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Assigned Office</label>
              <select name="assigned_office_id" id="modalOfficeSelect" class="form-select">
                <option value="">-- Unassigned Office --</option>
                <?php foreach ($officesList as $office): ?>
                  <option value="<?= (int)$office['id'] ?>" <?= ((int)$issue['assigned_office_id'] === (int)$office['id']) ? 'selected' : '' ?>>
                    <?= e($office['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">
                <i class="bi bi-person-check text-primary me-1"></i> Assigned Staff / Person
              </label>
              <select name="assigned_user_id" id="modalUserSelect" class="form-select">
                <option value="">-- Unassigned Staff --</option>
                <?php foreach ($assignableUsers as $u): ?>
                  <option value="<?= (int)$u['id'] ?>" 
                          data-office-id="<?= (int)($u['office_id'] ?? 0) ?>"
                          <?= ((int)$issue['assigned_user_id'] === (int)$u['id']) ? 'selected' : '' ?>>
                    <?= e($u['full_name']) ?> (<?= e($u['role_name'] ?: 'Staff') ?><?= $u['office_name'] ? ' · ' . e($u['office_name']) : '' ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="form-text text-muted" style="font-size: 0.72rem;">
                <i class="bi bi-shield-check text-success"></i> Administrators cannot be assigned to issues.
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Due Date</label>
              <input type="datetime-local" name="due_at" class="form-control" 
                     value="<?= !empty($issue['due_at']) ? date('Y-m-d\TH:i', strtotime($issue['due_at'])) : '' ?>">
            </div>
            <div class="col-12" id="modalResolutionWrap" style="<?= in_array($issue['status'], ['Resolved', 'Closed'], true) ? '' : 'display:none;' ?>">
              <label class="form-label small fw-semibold text-secondary mb-1">Resolution Summary</label>
              <textarea name="resolution_summary" class="form-control" rows="2" placeholder="Describe the resolution or actions completed..."><?= e($issue['resolution_summary'] ?? '') ?></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer py-2.5 px-4 bg-light border-top d-flex justify-content-end gap-2">
          <button type="button" class="btn btn-light border px-3" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary px-4 fw-semibold shadow-sm"><i class="bi bi-check-circle me-1"></i> Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Auto-sync office dropdown when user is selected in Assign form or Modal
  function syncUserOffice(userElId, officeElId) {
    var userEl = document.getElementById(userElId);
    var officeEl = document.getElementById(officeElId);
    if (userEl && officeEl) {
      userEl.addEventListener('change', function () {
        var opt = this.options[this.selectedIndex];
        var offId = opt ? opt.dataset.officeId : null;
        if (offId && parseInt(offId, 10) > 0 && !officeEl.value) {
          officeEl.value = offId;
        }
      });
    }
  }
  syncUserOffice('assignUserSelect', 'assignOfficeSelect');
  syncUserOffice('modalUserSelect', 'modalOfficeSelect');
});
</script>

<?php
$extraJs = [APP_URL . '/assets/js/issue-view.js'];
include __DIR__ . '/../../layouts/footer.php';
?>
