<?php
/**
 * modules/attendance/print.php
 * ------------------------------------------------------------------
 * Print-friendly attendance sheet for a single hearing.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$hearingId = (int)($_GET['hearing_id'] ?? 0);
$pdo = db();

$hearingStmt = $pdo->prepare('SELECT title, hearing_date, hearing_time, venue FROM hearings WHERE id = :id');
$hearingStmt->execute([':id' => $hearingId]);
$hearing = $hearingStmt->fetch();

if (!$hearing) die('Hearing not found.');

$stmt = $pdo->prepare(
    "SELECT a.status, a.checked_in_at, s.full_name, s.email, s.organization
     FROM attendance a JOIN stakeholders s ON s.id = a.stakeholder_id
     WHERE a.hearing_id = :id ORDER BY s.full_name"
);
$stmt->execute([':id' => $hearingId]);
$rows = $stmt->fetchAll();

$counts = ['Present' => 0, 'Late' => 0, 'Absent' => 0];
foreach ($rows as $r) { if (isset($counts[$r['status']])) $counts[$r['status']]++; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Attendance Sheet - <?= e($hearing['title']) ?></title>
<link href="<?= e(vendorAsset('bootstrap/bootstrap.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css')) ?>" rel="stylesheet">
<style>
  body { padding: 30px; font-size: 14px; }
  .print-header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #0b3d6e; padding-bottom: 14px; }
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
  <div class="text-muted">Attendance Sheet</div>
</div>

<table class="table table-borderless mb-3">
  <tr><th style="width:20%;">Hearing</th><td><?= e($hearing['title']) ?></td></tr>
  <tr><th>Date / Time</th><td><?= formatDate($hearing['hearing_date']) ?> at <?= formatTime($hearing['hearing_time']) ?></td></tr>
  <tr><th>Venue</th><td><?= e($hearing['venue'] ?: '-') ?></td></tr>
  <tr><th>Summary</th><td>Present: <?= $counts['Present'] ?> &nbsp; Late: <?= $counts['Late'] ?> &nbsp; Absent: <?= $counts['Absent'] ?> &nbsp; Total: <?= count($rows) ?></td></tr>
</table>

<table class="table table-bordered table-sm">
  <thead>
    <tr><th>#</th><th>Full Name</th><th>Email</th><th>Organization</th><th>Status</th><th>Checked In</th></tr>
  </thead>
  <tbody>
    <?php if (empty($rows)): ?>
      <tr><td colspan="6" class="text-center text-muted">No attendance records for this hearing.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $i => $r): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= e($r['full_name']) ?></td>
        <td><?= e($r['email']) ?></td>
        <td><?= e($r['organization'] ?: '-') ?></td>
        <td><?= e($r['status']) ?></td>
        <td><?= $r['checked_in_at'] ? formatDateTime($r['checked_in_at']) : '-' ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</body>
</html>
