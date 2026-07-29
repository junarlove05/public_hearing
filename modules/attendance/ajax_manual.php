<?php
/**
 * modules/attendance/ajax_manual.php
 * ------------------------------------------------------------------
 * Manual attendance marking (Present/Absent/Late) for a stakeholder
 * who wasn't scanned via QR — e.g. walk-ins or scanner unavailable.
 * Creates the attendance row if none exists yet for this stakeholder
 * + hearing, otherwise updates the existing one.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!(canManage() || hasRole([ROLE_COMMITTEE]))) {
    jsonResponse(false, 'You do not have permission to record attendance.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$stakeholderId = (int)($_POST['stakeholder_id'] ?? 0);
$hearingId     = (int)($_POST['hearing_id'] ?? 0);
$status        = clean($_POST['status'] ?? '');
$allowedStatus = ['Present', 'Absent', 'Late'];

if ($stakeholderId <= 0 || $hearingId <= 0 || !in_array($status, $allowedStatus, true)) {
    jsonResponse(false, 'Please select a stakeholder, hearing, and a valid status.');
}

$pdo = db();

try {
    $sStmt = $pdo->prepare('SELECT full_name FROM stakeholders WHERE id = :id');
    $sStmt->execute([':id' => $stakeholderId]);
    $stakeholder = $sStmt->fetch();
    if (!$stakeholder) jsonResponse(false, 'Stakeholder not found. Please search and select a valid stakeholder.');

    $hStmt = $pdo->prepare('SELECT id FROM hearings WHERE id = :id');
    $hStmt->execute([':id' => $hearingId]);
    if (!$hStmt->fetch()) jsonResponse(false, 'The selected hearing no longer exists. Please refresh the page and try again.');

    $existing = $pdo->prepare('SELECT id FROM attendance WHERE stakeholder_id = :sid AND hearing_id = :hid LIMIT 1');
    $existing->execute([':sid' => $stakeholderId, ':hid' => $hearingId]);
    $existingRow = $existing->fetch();

    if ($existingRow) {
        $update = $pdo->prepare(
            'UPDATE attendance SET status = :status, checked_in_at = NOW() WHERE id = :id'
        );
        $update->execute([':status' => $status, ':id' => $existingRow['id']]);
        $action = 'Manual Update';
    } else {
        $insert = $pdo->prepare(
            'INSERT INTO attendance (stakeholder_id, hearing_id, status, checked_in_at, created_at)
             VALUES (:sid, :hid, :status, NOW(), NOW())'
        );
        $insert->execute([':sid' => $stakeholderId, ':hid' => $hearingId, ':status' => $status]);
        $action = 'Manual Check-in';
    }

    $logStmt = $pdo->prepare(
        'INSERT INTO attendance_logs (stakeholder_id, hearing_id, action, notes, created_at)
         VALUES (:sid, :hid, :action, :notes, NOW())'
    );
    $logStmt->execute([
        ':sid' => $stakeholderId, ':hid' => $hearingId, ':action' => $action,
        ':notes' => 'Marked ' . $status . ' by ' . (currentUser()['full_name'] ?? 'staff') . '.',
    ]);

    logActivity(currentUserId(), 'Update', $action . ' for ' . $stakeholder['full_name'] . ' (hearing #' . $hearingId . ', ' . $status . ')');
    jsonResponse(true, $stakeholder['full_name'] . ' marked as ' . $status . '.');

} catch (PDOException $e) {
    error_log('Manual attendance error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while recording attendance.');
}
