<?php
/**
 * modules/issues/export_pdf.php
 * ------------------------------------------------------------------
 * Exports the full issues list as a downloadable PDF.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/SimplePdf.php';
requireRole([ROLE_ADMIN, ROLE_STAFF]);

$pdo = db();
$rows = $pdo->query(
    "SELECT i.title, ic.name AS category_name, i.assigned_office, i.priority, i.status, i.created_at
     FROM issues i LEFT JOIN issue_categories ic ON ic.id = i.category_id
     ORDER BY i.created_at DESC"
)->fetchAll();

logActivity(currentUserId(), 'Export', 'Exported issues report PDF');

$pdf = new SimplePdf('Issue Log Report', 'Generated ' . date('F j, Y g:i A') . '  |  Total: ' . count($rows));

$tableRows = array_map(fn($r) => [
    $r['title'], $r['category_name'] ?: '-', $r['assigned_office'] ?: 'Unassigned', $r['priority'], $r['status'], formatDate($r['created_at']),
], $rows);

if (empty($tableRows)) $tableRows[] = ['No issues found.', '', '', '', '', ''];

$pdf->addTable(
    ['Title', 'Category', 'Assigned Office', 'Priority', 'Status', 'Logged'],
    [150, 90, 110, 60, 70, 60],
    $tableRows
);

$pdf->output('issue-log-report.pdf');
