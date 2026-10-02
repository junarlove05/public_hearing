<?php
/**
 * modules/attendance/export_pdf.php
 * ------------------------------------------------------------------
 * Exports the attendance sheet for a hearing as a downloadable PDF
 * with multi-day session date support.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/SimplePdf.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';
requireLogin();

$hearingId = (int)($_GET['hearing_id'] ?? 0);
$date = clean($_GET['date'] ?? $_GET['session_date'] ?? '');
$pdo = db();

$hearingStmt = $pdo->prepare('SELECT title, hearing_date, end_date, hearing_time, venue FROM hearings WHERE id = :id');
$hearingStmt->execute([':id' => $hearingId]);
$hearing = $hearingStmt->fetch();
if (!$hearing) die('Hearing not found.');

if ($date !== '') {
    $stmt = $pdo->prepare(
        "SELECT a.status, a.checked_in_at, a.checked_out_at, a.attendance_date, s.full_name, s.email, s.organization
         FROM attendance a JOIN stakeholders s ON s.id = a.stakeholder_id
         WHERE a.hearing_id = :id AND a.attendance_date = :adate ORDER BY s.full_name"
    );
    $stmt->execute([':id' => $hearingId, ':adate' => $date]);
    $dateLabel = formatDate($date);
    $fileSuffix = '-' . $date;
} else {
    $stmt = $pdo->prepare(
        "SELECT a.status, a.checked_in_at, a.checked_out_at, a.attendance_date, s.full_name, s.email, s.organization
         FROM attendance a JOIN stakeholders s ON s.id = a.stakeholder_id
         WHERE a.hearing_id = :id ORDER BY a.attendance_date ASC, s.full_name ASC"
    );
    $stmt->execute([':id' => $hearingId]);
    $dateLabel = formatDate($hearing['hearing_date']);
    $fileSuffix = '';
}
$rows = $stmt->fetchAll();

logActivity(currentUserId(), 'Export', 'Exported attendance PDF for hearing #' . $hearingId . ($date ? ' (' . $date . ')' : ''));

$subtitle = $hearing['title'] . '  |  Session: ' . $dateLabel . ($hearing['venue'] ? '  |  ' . $hearing['venue'] : '');

$pdf = new SimplePdf('Attendance Sheet', $subtitle);

$tableRows = [];
foreach ($rows as $r) {
    $tableRows[] = [
        $r['full_name'],
        $r['organization'] ?: '-',
        $r['attendance_date'],
        $r['status'],
        $r['checked_in_at'] ? date('h:i A', strtotime($r['checked_in_at'])) : '-',
        $r['checked_out_at'] ? date('h:i A', strtotime($r['checked_out_at'])) : '-',
    ];
}
if (empty($tableRows)) {
    $tableRows[] = ['No attendance records for this session.', '', '', '', '', ''];
}

$pdf->addTable(
    ['Participant', 'Organization', 'Date', 'Status', 'Time In', 'Time Out'],
    [130, 110, 80, 60, 80, 80],
    $tableRows
);

$pdf->output('attendance-hearing-' . $hearingId . $fileSuffix . '.pdf');
