<?php
/**
 * modules/attendance/ajax_stats.php
 * ------------------------------------------------------------------
 * Returns Present / Late / Absent / Registered counts for a hearing,
 * used to refresh the stat cards on index.php after each check-in.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$hearingId = (int)($_GET['hearing_id'] ?? 0);
if ($hearingId <= 0) jsonResponse(false, 'Invalid hearing id.');

$pdo = db();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM registrations WHERE hearing_id = :id');
$stmt->execute([':id' => $hearingId]);
$registered = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT status, COUNT(*) AS total FROM attendance WHERE hearing_id = :id GROUP BY status");
$stmt->execute([':id' => $hearingId]);
$byStatus = ['Present' => 0, 'Late' => 0, 'Absent' => 0];
foreach ($stmt->fetchAll() as $row) {
    $byStatus[$row['status']] = (int)$row['total'];
}

jsonResponse(true, '', [
    'registered' => $registered,
    'present'    => $byStatus['Present'],
    'late'       => $byStatus['Late'],
    'absent'     => $byStatus['Absent'],
    'total_checked_in' => $byStatus['Present'] + $byStatus['Late'],
]);
