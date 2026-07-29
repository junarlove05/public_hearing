<?php
/**
 * reports/export_excel.php
 * ------------------------------------------------------------------
 * Exports any report type + date range as an Excel-openable file
 * (see includes/functions.php::outputExcel()).
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

logActivity(currentUserId(), 'Export', 'Exported ' . $report['title'] . ' Excel');

$title = $report['title'];
if ($dateFrom || $dateTo) {
    $title .= ' (' . ($dateFrom ?: 'Beginning') . ' to ' . ($dateTo ?: 'Now') . ')';
}

outputExcel(
    str_replace(' ', '-', strtolower($report['title'])) . '.xls',
    $title,
    $report['headers'],
    $report['rows']
);
