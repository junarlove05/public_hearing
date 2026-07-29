<?php
/**
 * modules/actions/print.php
 * ------------------------------------------------------------------
 * Print-friendly actions list, honoring the same filters as index.php.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireRole([ROLE_ADMIN, ROLE_STAFF, ROLE_COMMITTEE]);

$pdo = db();

$statusFil = clean($_GET['status'] ?? '');
$issueFil  = (int)($_GET['issue_id'] ?? 0);

$where = [];
$params = [];
if ($statusFil !== '') { $where[] = 'a.status = :status'; $params[':status'] = $statusFil; }
if ($issueFil > 0) { $where[] = 'a.issue_id = :issue_id'; $params[':issue_id'] = $issueFil; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare(
    "SELECT a.*, i.title AS issue_title,
            (SELECT aa.assigned_office FROM action_assignments aa WHERE aa.action_id = a.id ORDER BY aa.assigned_at DESC LIMIT 1) AS current_office
     FROM actions a LEFT JOIN issues i ON i.id = a.issue_id
     $whereSql ORDER BY a.created_at DESC"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Action Log - Print</title>
<link href="<?= e(vendorAsset('bootstrap/bootstrap.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css')) ?>" rel="stylesheet">
<style>
  body { padding: 30px; font-size: 14px; }
  .print-header { text-align: center; margin-bottom: 24px; border-bottom: 2px solid #0b3d6e; padding-bottom: 14px; }
  table th { background: #f4f6f9; }
  @media print { .no-print { display: none; } }
</style>
</head>
<body onload="window.print()">
<div class="no-print text-end mb-3">
  <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
</div>

<div class="print-header">
  <h4 class="mb-0"><?= e(APP_NAME) ?></h4>
  <div class="text-muted">Response &amp; Action Log Report</div>
  <div class="small text-muted">Generated on <?= date('F j, Y g:i A') ?></div>
</div>

<table class="table table-bordered table-sm">
  <thead>
    <tr><th>#</th><th>Title</th><th>Linked Issue</th><th>Assigned Office</th><th>Deadline</th><th>Status</th></tr>
  </thead>
  <tbody>
    <?php if (empty($rows)): ?>
      <tr><td colspan="6" class="text-center text-muted">No actions match the selected filters.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $i => $row): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= e($row['title']) ?></td>
        <td><?= e($row['issue_title'] ?? '-') ?></td>
        <td><?= e($row['current_office'] ?: 'Unassigned') ?></td>
        <td><?= $row['deadline'] ? formatDate($row['deadline']) : '-' ?></td>
        <td><?= e($row['status']) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<p class="text-muted small mt-4">Total: <?= count($rows) ?> action(s)</p>
</body>
</html>
