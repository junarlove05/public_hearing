<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/SimplePdf.php';
requireRole([ROLE_ADMIN, ROLE_STAFF]);

$pdo = db();
$rows = $pdo->query(
    "SELECT i.title,
            ic.name AS category_name,
            COALESCE(o.name, u.full_name) AS assigned_office,
            i.priority,
            i.status,
            i.created_at
     FROM hearing_issues i
     LEFT JOIN hearing_issue_categories ic ON ic.id = i.category_id
     LEFT JOIN offices o ON o.id = i.assigned_office_id
     LEFT JOIN users u ON u.id = i.assigned_user_id
     ORDER BY i.created_at DESC"
)->fetchAll();

logActivity(currentUserId(), 'Export', 'Exported issues report PDF');

$pdf = new SimplePdf('Issue Log Report', 'Generated ' . date('F j, Y g:i A') . '  |  Total: ' . count($rows));
$tableRows = array_map(fn($r) => [
    $r['title'],
    $r['category_name'] ?: '-',
    $r['assigned_office'] ?: 'Unassigned',
    $r['priority'],
    $r['status'],
    formatDate($r['created_at']),
], $rows);
if (empty($tableRows)) $tableRows[] = ['No issues found.', '', '', '', '', ''];
$pdf->addTable(
    ['Title', 'Category', 'Assigned Office', 'Priority', 'Status', 'Logged'],
    [140, 90, 100, 55, 65, 70],
    $tableRows
);
$pdf->output('issue-log-report.pdf');
