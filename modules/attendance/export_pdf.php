<?php
/**
 * modules/attendance/export_pdf.php
 * ------------------------------------------------------------------
 * Exports the attendance sheet for a hearing as a downloadable PDF
 * using the dependency-free SimplePdf writer.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/SimplePdf.php';
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

logActivity(currentUserId(), 'Export', 'Exported attendance PDF for hearing #' . $hearingId);

$subtitle = $hearing['title'] . '  |  ' . formatDate($hearing['hearing_date']) . ' ' . formatTime($hearing['hearing_time']) .
            ($hearing['venue'] ? '  |  ' . $hearing['venue'] : '');

$pdf = new SimplePdf('Attendance Sheet', $subtitle);

$tableRows = [];
foreach ($rows as $r) {
    $tableRows[] = [
        $r['full_name'], $r['email'], $r['organization'] ?: '-', $r['status'],
        $r['checked_in_at'] ? formatDateTime($r['checked_in_at']) : '-',
    ];
}
if (empty($tableRows)) {
    $tableRows[] = ['No attendance records for this hearing.', '', '', '', ''];
}

$pdf->addTable(
    ['Full Name', 'Email', 'Organization', 'Status', 'Checked In'],
    [130, 140, 110, 60, 100],
    $tableRows
);

$pdf->output('attendance-hearing-' . $hearingId . '.pdf');
