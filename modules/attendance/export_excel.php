<?php
/**
 * modules/attendance/export_excel.php
 * ------------------------------------------------------------------
 * Exports the attendance sheet for a hearing as an Excel-openable
 * file (see includes/functions.php::outputExcel()).
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$hearingId = (int)($_GET['hearing_id'] ?? 0);
$pdo = db();

$hearingStmt = $pdo->prepare('SELECT title FROM hearings WHERE id = :id');
$hearingStmt->execute([':id' => $hearingId]);
$hearing = $hearingStmt->fetch();
if (!$hearing) die('Hearing not found.');

$stmt = $pdo->prepare(
    "SELECT s.full_name, s.email, s.organization, a.status, a.checked_in_at
     FROM attendance a JOIN stakeholders s ON s.id = a.stakeholder_id
     WHERE a.hearing_id = :id ORDER BY s.full_name"
);
$stmt->execute([':id' => $hearingId]);
$rows = $stmt->fetchAll();

logActivity(currentUserId(), 'Export', 'Exported attendance Excel for hearing #' . $hearingId);

$tableRows = array_map(fn($r) => [
    $r['full_name'], $r['email'], $r['organization'] ?: '-', $r['status'],
    $r['checked_in_at'] ? formatDateTime($r['checked_in_at']) : '-',
], $rows);

outputExcel(
    'attendance-hearing-' . $hearingId . '.xls',
    'Attendance Sheet - ' . $hearing['title'],
    ['Full Name', 'Email', 'Organization', 'Status', 'Checked In'],
    $tableRows
);
