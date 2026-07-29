<?php
/**
 * modules/attendance/ajax_checkin.php
 * ------------------------------------------------------------------
 * Called by the QR scanner on index.php each time a code is decoded.
 * Looks up the stakeholder via qr_codes.code_value, then inserts or
 * updates their `attendance` row for the selected hearing. Status is
 * auto-determined as Present or Late based on the hearing's scheduled
 * start time (15-minute grace period), matching the "Late Attendance"
 * requirement without needing manual staff judgement for the common case.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!(canManage() || hasRole([ROLE_COMMITTEE]))) {
    jsonResponse(false, 'You do not have permission to record attendance.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$code      = clean($_POST['code'] ?? '');
$hearingId = (int)($_POST['hearing_id'] ?? 0);

if ($code === '' || $hearingId <= 0) {
    jsonResponse(false, 'Missing QR code or hearing selection.');
}

$pdo = db();

try {
    $stmt = $pdo->prepare(
        'SELECT s.id, s.full_name, s.status AS stakeholder_status
         FROM qr_codes q JOIN stakeholders s ON s.id = q.stakeholder_id
         WHERE q.code_value = :code LIMIT 1'
    );
    $stmt->execute([':code' => $code]);
    $stakeholder = $stmt->fetch();

    if (!$stakeholder) {
        jsonResponse(false, 'QR code not recognized. This stakeholder may not be registered in the system.');
    }

    $hearingStmt = $pdo->prepare('SELECT hearing_date, hearing_time, title FROM hearings WHERE id = :id');
    $hearingStmt->execute([':id' => $hearingId]);
    $hearing = $hearingStmt->fetch();
    if (!$hearing) jsonResponse(false, 'Selected hearing not found.');

    $scheduledStart = strtotime($hearing['hearing_date'] . ' ' . $hearing['hearing_time']);
    $graceSeconds = 15 * 60;
    $status = (time() > $scheduledStart + $graceSeconds) ? 'Late' : 'Present';

    // Has this stakeholder already checked in to this hearing?
    $existing = $pdo->prepare('SELECT id, status FROM attendance WHERE stakeholder_id = :sid AND hearing_id = :hid LIMIT 1');
    $existing->execute([':sid' => $stakeholder['id'], ':hid' => $hearingId]);
    $existingRow = $existing->fetch();

    if ($existingRow) {
        jsonResponse(false, $stakeholder['full_name'] . ' has already been checked in (' . $existingRow['status'] . ').', [
            'already_checked_in' => true, 'full_name' => $stakeholder['full_name'],
        ]);
    }

    $insert = $pdo->prepare(
        'INSERT INTO attendance (stakeholder_id, hearing_id, status, checked_in_at, created_at)
         VALUES (:sid, :hid, :status, NOW(), NOW())'
    );
    $insert->execute([':sid' => $stakeholder['id'], ':hid' => $hearingId, ':status' => $status]);

    $logStmt = $pdo->prepare(
        'INSERT INTO attendance_logs (stakeholder_id, hearing_id, action, notes, created_at)
         VALUES (:sid, :hid, :action, :notes, NOW())'
    );
    $logStmt->execute([
        ':sid' => $stakeholder['id'], ':hid' => $hearingId, ':action' => 'QR Check-in',
        ':notes' => 'Checked in via QR scan, marked ' . $status . '.',
    ]);

    logActivity(currentUserId(), 'Insert', 'Attendance recorded for ' . $stakeholder['full_name'] . ' (hearing #' . $hearingId . ', ' . $status . ')');

    jsonResponse(true, $stakeholder['full_name'] . ' checked in successfully (' . $status . ').', [
        'full_name' => $stakeholder['full_name'], 'status' => $status,
    ]);

} catch (PDOException $e) {
    error_log('QR check-in error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while recording attendance.');
}
