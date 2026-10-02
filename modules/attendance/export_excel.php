<?php
/**
 * modules/attendance/export_excel.php
 * ------------------------------------------------------------------
 * Exports the attendance sheet for a hearing as an Excel-openable
 * file with multi-day session date support.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$hearingId = (int)($_GET['hearing_id'] ?? 0);
$date = clean($_GET['date'] ?? $_GET['session_date'] ?? '');
$pdo = db();

$hearingStmt = $pdo->prepare('SELECT title, hearing_date, end_date FROM hearings WHERE id = :id');
$hearingStmt->execute([':id' => $hearingId]);
$hearing = $hearingStmt->fetch();
if (!$hearing) die('Hearing not found.');

if ($date !== '') {
    $stmt = $pdo->prepare(
        "SELECT s.full_name, s.email, s.organization, a.status, a.checked_in_at, a.checked_out_at, a.attendance_date
         FROM attendance a JOIN stakeholders s ON s.id = a.stakeholder_id
         WHERE a.hearing_id = :id AND a.attendance_date = :adate ORDER BY s.full_name"
    );
    $stmt->execute([':id' => $hearingId, ':adate' => $date]);
    $titleSuffix = ' - ' . formatDate($date);
    $fileSuffix = '-' . $date;
} else {
    $stmt = $pdo->prepare(
        "SELECT s.full_name, s.email, s.organization, a.status, a.checked_in_at, a.checked_out_at, a.attendance_date
         FROM attendance a JOIN stakeholders s ON s.id = a.stakeholder_id
         WHERE a.hearing_id = :id ORDER BY a.attendance_date ASC, s.full_name ASC"
    );
    $stmt->execute([':id' => $hearingId]);
    $titleSuffix = '';
    $fileSuffix = '';
}
$rows = $stmt->fetchAll();

logActivity(currentUserId(), 'Export', 'Exported attendance Excel for hearing #' . $hearingId . ($date ? ' (' . $date . ')' : ''));

$tableRows = array_map(fn($r) => [
    $r['full_name'],
    $r['email'],
    $r['organization'] ?: '-',
    $r['attendance_date'],
    $r['status'],
    $r['checked_in_at'] ? date('h:i A', strtotime($r['checked_in_at'])) : '-',
    $r['checked_out_at'] ? date('h:i A', strtotime($r['checked_out_at'])) : '-',
], $rows);

outputExcel(
    'attendance-hearing-' . $hearingId . $fileSuffix . '.xls',
    'Attendance Sheet - ' . $hearing['title'] . $titleSuffix,
    ['Full Name', 'Email', 'Organization', 'Session Date', 'Status', 'Time In', 'Time Out'],
    $tableRows
);
