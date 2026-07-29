<?php
/**
 * reports/export_csv.php
 * ------------------------------------------------------------------
 * Exports any report type + date range as a downloadable CSV file
 * (see includes/functions.php::outputCsv()).
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

logActivity(currentUserId(), 'Export', 'Exported ' . $report['title'] . ' CSV');

outputCsv(
    str_replace(' ', '-', strtolower($report['title'])) . '.csv',
    $report['headers'],
    $report['rows']
);
