<?php
/**
 * reports/print.php
 * ------------------------------------------------------------------
 * Print-friendly version of a report (opens in a new tab and calls
 * window.print() automatically, matching the pattern used by every
 * other module's print.php).
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_ADMIN, ROLE_STAFF]);
require_once __DIR__ . '/report_data.php';

$type     = clean($_GET['type'] ?? '');
$dateFrom = clean($_GET['date_from'] ?? '');
$dateTo   = clean($_GET['date_to'] ?? '');

$report = getReportData($type, $dateFrom ?: null, $dateTo ?: null);
if (!$report) die('Unknown report type.');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= e($report['title']) ?> - Print</title>
<link href="<?= e(vendorAsset('bootstrap/bootstrap.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css')) ?>" rel="stylesheet">
<style>
  body { padding: 30px; font-size: 13px; }
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
  <div class="text-muted"><?= e($report['title']) ?></div>
  <div class="small text-muted">
    Generated on <?= date('F j, Y g:i A') ?>
    <?php if ($dateFrom || $dateTo): ?>
      &middot; Range: <?= $dateFrom ? formatDate($dateFrom) : 'Beginning' ?> to <?= $dateTo ? formatDate($dateTo) : 'Now' ?>
    <?php endif; ?>
  </div>
</div>

<table class="table table-bordered table-sm">
  <thead>
    <tr><th>#</th><?php foreach ($report['headers'] as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr>
  </thead>
  <tbody>
    <?php if (empty($report['rows'])): ?>
      <tr><td colspan="<?= count($report['headers']) + 1 ?>" class="text-center text-muted">No records found.</td></tr>
    <?php endif; ?>
    <?php foreach ($report['rows'] as $i => $row): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <?php foreach ($row as $cell): ?><td><?= e((string)$cell) ?></td><?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<p class="text-muted small mt-4">Total: <?= count($report['rows']) ?> record(s)</p>
</body>
</html>
