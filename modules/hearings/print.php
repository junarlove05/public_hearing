<?php
/**
 * modules/hearings/print.php
 * ------------------------------------------------------------------
 * Print-friendly hearing schedule. Accepts the same filters as the
 * main index (status, type, committee, date range) via query string
 * so a filtered view can be printed. Opens in a new tab from index.php.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pdo = db();

$statusFil    = clean($_GET['status'] ?? '');
$typeFil      = (int)($_GET['hearing_type_id'] ?? 0);
$committeeFil = (int)($_GET['committee_id'] ?? 0);
$dateFrom     = clean($_GET['date_from'] ?? '');
$dateTo       = clean($_GET['date_to'] ?? '');

$where = [];
$params = [];
if ($statusFil !== '') { $where[] = 'h.status = :status'; $params[':status'] = $statusFil; }
if ($typeFil > 0) { $where[] = 'h.hearing_type_id = :type_id'; $params[':type_id'] = $typeFil; }
if ($committeeFil > 0) { $where[] = 'h.committee_id = :committee_id'; $params[':committee_id'] = $committeeFil; }
if ($dateFrom !== '') { $where[] = 'h.hearing_date >= :date_from'; $params[':date_from'] = $dateFrom; }
if ($dateTo !== '') { $where[] = 'h.hearing_date <= :date_to'; $params[':date_to'] = $dateTo; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare(
    "SELECT h.*, ht.name AS type_name, c.name AS committee_name
     FROM hearings h
     LEFT JOIN hearing_types ht ON ht.id = h.hearing_type_id
     LEFT JOIN committees c ON c.id = h.committee_id
     $whereSql
     ORDER BY h.hearing_date ASC, h.hearing_time ASC"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Hearing Schedule - Print</title>
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
  <div class="text-muted">Hearing Schedule Report</div>
  <div class="small text-muted">Generated on <?= date('F j, Y g:i A') ?></div>
</div>

<table class="table table-bordered table-sm">
  <thead>
    <tr>
      <th>#</th>
      <th>Title</th>
      <th>Type</th>
      <th>Committee</th>
      <th>Date</th>
      <th>Time</th>
      <th>Venue</th>
      <th>Status</th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($rows)): ?>
      <tr><td colspan="8" class="text-center text-muted">No hearings match the selected filters.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $i => $row): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= e($row['title']) ?></td>
        <td><?= e($row['type_name'] ?? '-') ?></td>
        <td><?= e($row['committee_name'] ?? '-') ?></td>
        <td><?= formatDate($row['hearing_date']) ?></td>
        <td><?= formatTime($row['hearing_time']) ?></td>
        <td><?= e($row['venue'] ?: '-') ?></td>
        <td><?= e($row['status']) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<p class="text-muted small mt-4">Total: <?= count($rows) ?> hearing(s)</p>
</body>
</html>
