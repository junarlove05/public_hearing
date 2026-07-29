<?php
/**
 * modules/attendance/ajax_delete.php
 * ------------------------------------------------------------------
 * Removes a single attendance record (e.g. correcting a mis-scan).
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!(canManage() || hasRole([ROLE_COMMITTEE]))) {
    jsonResponse(false, 'You do not have permission to perform this action.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) jsonResponse(false, 'Invalid attendance record id.');

$pdo = db();
try {
    $stmt = $pdo->prepare('SELECT stakeholder_id, hearing_id FROM attendance WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonResponse(false, 'Attendance record not found.');

    $del = $pdo->prepare('DELETE FROM attendance WHERE id = :id');
    $del->execute([':id' => $id]);

    $logStmt = $pdo->prepare(
        'INSERT INTO attendance_logs (stakeholder_id, hearing_id, action, notes, created_at)
         VALUES (:sid, :hid, :action, :notes, NOW())'
    );
    $logStmt->execute([
        ':sid' => $row['stakeholder_id'], ':hid' => $row['hearing_id'],
        ':action' => 'Delete', ':notes' => 'Attendance record removed by staff.',
    ]);

    logActivity(currentUserId(), 'Delete', 'Deleted attendance record #' . $id);
    jsonResponse(true, 'Attendance record deleted successfully.');
} catch (PDOException $e) {
    error_log('Attendance delete error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred.');
}
