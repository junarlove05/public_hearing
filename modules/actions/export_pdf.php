<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/SimplePdf.php';
requireRole([ROLE_ADMIN, ROLE_STAFF]);

$pdo = db();
$rows = $pdo->query(
    "SELECT a.title, i.title AS issue_title, a.status, a.deadline, a.created_at,
            COALESCE(o.name, u.full_name) AS current_office
     FROM hearing_actions a
     LEFT JOIN hearing_issues i ON i.id = a.issue_id
     LEFT JOIN offices o ON o.id = a.assigned_office_id
     LEFT JOIN users u ON u.id = a.assigned_user_id
     ORDER BY a.created_at DESC"
)->fetchAll();

logActivity(currentUserId(), 'Export', 'Exported actions report PDF');
$pdf = new SimplePdf('Response & Action Log Report', 'Generated ' . date('F j, Y g:i A') . '  |  Total: ' . count($rows));
$tableRows = array_map(fn($r) => [
    $r['title'], $r['issue_title'] ?: '-', $r['current_office'] ?: 'Unassigned',
    $r['deadline'] ? formatDate($r['deadline']) : '-', $r['status'],
], $rows);
if (empty($tableRows)) $tableRows[] = ['No actions found.', '', '', '', ''];
$pdf->addTable(['Title','Linked Issue','Assigned Office','Deadline','Status'], [140,110,100,70,60], $tableRows);
$pdf->output('action-log-report.pdf');
