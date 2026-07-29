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

$id = (int)($_GET['id'] ?? 0);
$pdo = db();

$stmt = $pdo->prepare(
    'SELECT
        i.*,
        ic.name AS category_name,
        h.title AS hearing_title,
        o.name AS assigned_office
     FROM hearing_issues i
     LEFT JOIN hearing_issue_categories ic
        ON ic.id = i.category_id
     LEFT JOIN hearings h
        ON h.id = i.hearing_id
     LEFT JOIN offices o
        ON o.id = i.assigned_office_id
     WHERE i.id = :id'
);
$stmt->execute([':id' => $id]);
$issue = $stmt->fetch();

if (!$issue) {
    setFlash('danger', 'Issue not found.');
    redirect(APP_URL . '/modules/issues/index.php');
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
        --iv-indigo: #6366F1;
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
    <div class="breadcrumb-bar d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <a href="index.php" class="text-decoration-none small no-print"><i class="bi bi-arrow-left"></i> Back to Issue Logging</a>
        <h5 class="mb-0 mt-1"><i class="bi bi-exclamation-triangle"></i> <?= e($issue['title']) ?></h5>
      </div>
      <div class="d-flex gap-2 no-print">
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
              <tr><th class="text-muted small" style="width:30%;">Category</th><td><?= e($issue['category_name'] ?? '-') ?></td></tr>
              <tr><th class="text-muted small">Related Hearing</th><td><?= e($issue['hearing_title'] ?? '-') ?></td></tr>
              <tr><th class="text-muted small">Assigned Office</th><td id="currentOffice"><?= e($issue['assigned_office'] ?: 'Unassigned') ?></td></tr>
              <tr><th class="text-muted small">Priority</th><td><?= priorityBadge($issue['priority']) ?></td></tr>
              <tr><th class="text-muted small">Status</th><td><?= statusBadge($issue['status']) ?></td></tr>
              <tr><th class="text-muted small">Logged</th><td><?= formatDateTime($issue['created_at']) ?></td></tr>
              <tr><th class="text-muted small">Description</th><td><?= nl2br(e($issue['description'])) ?></td></tr>
            </table>
          </div>
        </div>

        <?php if (!empty($linkedActions)): ?>
        <div class="card">
          <div class="card-header"><i class="bi bi-link-45deg"></i> Linked Response &amp; Action Records</div>
          <div class="list-group list-group-flush">
            <?php foreach ($linkedActions as $a): ?>
              <div class="list-group-item d-flex justify-content-between align-items-center">
                <a href="<?= e(APP_URL) ?>/modules/actions/view.php?id=<?= (int)$a['id'] ?>" class="text-decoration-none"><?= e($a['title']) ?></a>
                <div class="d-flex gap-2 align-items-center">
                  <?php if ($a['deadline']): ?><span class="text-muted small">Due <?= formatDate($a['deadline']) ?></span><?php endif; ?>
                  <?= statusBadge($a['status']) ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <div class="col-lg-5">
        <?php if (canManage()): ?>
        <div class="card mb-3 no-print">
          <div class="card-header"><i class="bi bi-diagram-3"></i> Assign / Reassign</div>
          <div class="card-body">
            <form id="assignForm" class="d-flex gap-2">
              <?= csrfField() ?>
              <input type="hidden" name="id" value="<?= (int)$issue['id'] ?>">
              <input type="text" name="assigned_to" class="form-control form-control-sm" placeholder="Office or person name" required>
              <button type="submit" class="btn btn-primary btn-sm text-nowrap"><i class="bi bi-send"></i> Assign</button>
            </form>
          </div>
        </div>

        <div class="card mb-3 no-print">
          <div class="card-header"><i class="bi bi-pencil-square"></i> Add Note</div>
          <div class="card-body">
            <form id="noteForm">
              <?= csrfField() ?>
              <input type="hidden" name="id" value="<?= (int)$issue['id'] ?>">
              <textarea name="note" class="form-control form-control-sm mb-2" rows="3" placeholder="Add a progress note or update..." required></textarea>
              <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-plus-circle"></i> Add Note</button>
            </form>
          </div>
        </div>
        <?php endif; ?>

        <div class="card">
          <div class="card-header"><i class="bi bi-clock-history"></i> Timeline</div>
          <div class="list-group list-group-flush" id="timelineList">
            <?php if (empty($timeline)): ?>
              <div class="list-group-item text-muted small">No history yet.</div>
            <?php endif; ?>
            <?php foreach ($timeline as $t): ?>
              <div class="list-group-item">
                <div class="d-flex align-items-start gap-2">
                  <i class="bi <?= $t['type'] === 'assignment' ? 'bi-diagram-3' : 'bi-chat-left-text' ?>"></i>
                  <div>
                    <div class="small"><?= e($t['text']) ?></div>
                    <div class="text-muted" style="font-size:11px;"><?= formatDateTime($t['at']) ?></div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = [APP_URL . '/assets/js/issue-view.js'];
include __DIR__ . '/../../layouts/footer.php';
?>