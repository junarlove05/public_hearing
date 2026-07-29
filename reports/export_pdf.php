<?php
/**
 * reports/export_pdf.php
 * ------------------------------------------------------------------
 * Exports any report type + date range as a downloadable PDF using
 * SimplePdf.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/SimplePdf.php';
requireRole([ROLE_ADMIN, ROLE_STAFF]);
require_once __DIR__ . '/report_data.php';

$type     = clean($_GET['type'] ?? '');
$dateFrom = clean($_GET['date_from'] ?? '');
$dateTo   = clean($_GET['date_to'] ?? '');

$report = getReportData($type, $dateFrom ?: null, $dateTo ?: null);
if (!$report) die('Unknown report type.');

logActivity(currentUserId(), 'Export', 'Exported ' . $report['title'] . ' PDF');

$subtitle = 'Generated ' . date('F j, Y g:i A');
if ($dateFrom || $dateTo) {
    $subtitle .= '  |  Range: ' . ($dateFrom ? formatDate($dateFrom) : 'Beginning') . ' to ' . ($dateTo ? formatDate($dateTo) : 'Now');
}

$pdf = new SimplePdf($report['title'], $subtitle);

$colCount = count($report['headers']);
// Distribute page width evenly across columns (Letter width minus margins ≈ 540pt).
$colWidth = (int)floor(540 / max(1, $colCount));
$widths = array_fill(0, $colCount, $colWidth);

$rows = $report['rows'];
if (empty($rows)) {
    $rows[] = array_pad(['No records found.'], $colCount, '');
}

$pdf->addTable($report['headers'], $widths, $rows);
$pdf->output(str_replace(' ', '-', strtolower($report['title'])) . '.pdf');
