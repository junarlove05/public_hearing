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
require_once __DIR__ . '/../../includes/lph_module_helpers.php';
requireLogin();

if (!isAdmin()) {
    jsonResponse(false, 'Attendance recording (Time In / Time Out) is restricted to System Administrators.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$code        = clean($_POST['code'] ?? '');
$hearingId   = (int)($_POST['hearing_id'] ?? 0);
$sessionDate = clean($_POST['session_date'] ?? '');

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
        $stmtReg = $pdo->prepare(
            'SELECT s.id, s.full_name, s.status AS stakeholder_status
             FROM registrations r JOIN stakeholders s ON s.id = r.stakeholder_id
             WHERE r.registration_code = :code LIMIT 1'
        );
        $stmtReg->execute([':code' => $code]);
        $stakeholder = $stmtReg->fetch();
    }

    if (!$stakeholder) {
        $stmtInv = $pdo->prepare(
            'SELECT s.id, s.full_name, s.status AS stakeholder_status
             FROM invitations i JOIN stakeholders s ON s.id = i.stakeholder_id
             WHERE i.invitation_code = :code LIMIT 1'
        );
        $stmtInv->execute([':code' => $code]);
        $stakeholder = $stmtInv->fetch();
    }

    if (!$stakeholder) {
        jsonResponse(false, 'QR code not recognized. This stakeholder may not be registered in the system.');
    }

    $hearingStmt = $pdo->prepare('SELECT id, hearing_date, end_date, hearing_time, title, status FROM hearings WHERE id = :id');
    $hearingStmt->execute([':id' => $hearingId]);
    $hearing = $hearingStmt->fetch();
    if (!$hearing) jsonResponse(false, 'Selected hearing not found.');

    if ($sessionDate === '') {
        $todayStr = date('Y-m-d');
        $startDate = $hearing['hearing_date'];
        $endDate = !empty($hearing['end_date']) ? $hearing['end_date'] : $startDate;
        if ($todayStr >= $startDate && $todayStr <= $endDate) {
            $sessionDate = $todayStr;
        } else {
            $sessionDate = $startDate;
        }
    }

    $dayInfo = lphGetHearingDayInfo($pdo, $hearingId, $sessionDate, $hearing);
    if (!$dayInfo['valid']) {
        jsonResponse(false, 'The selected date (' . htmlspecialchars($sessionDate) . ') is not part of the schedule for this hearing.');
    }

    if (lphIsDayAttendanceClosed($pdo, $hearingId, $sessionDate, $hearing)) {
        $blockReason = lphGetDayAttendanceBlockReason($pdo, $hearingId, $sessionDate, $hearing);
        jsonResponse(false, $blockReason ?: 'Attendance for this date is closed.');
    }

    // Enforce approved invitation requirement
    $invStmt = $pdo->prepare(
        'SELECT id, status FROM invitations 
         WHERE stakeholder_id = :sid AND hearing_id = :hid 
           AND (session_date = :sdate OR session_date IS NULL) 
         LIMIT 1'
    );
    $invStmt->execute([':sid' => $stakeholder['id'], ':hid' => $hearingId, ':sdate' => $sessionDate]);
    $inv = $invStmt->fetch();
    if (!$inv || !in_array($inv['status'], ['Accepted', 'Approved'], true)) {
        jsonResponse(false, 'Attendance check-in denied: Stakeholder "' . htmlspecialchars($stakeholder['full_name']) . '" does not have an approved invitation for this hearing session.');
    }

    $scheduledStart = strtotime($sessionDate . ' ' . $hearing['hearing_time']);
    $graceSeconds = 15 * 60;
    $status = (time() > $scheduledStart + $graceSeconds) ? 'Late' : 'Present';

    // Has this stakeholder already checked in to this hearing on this session date?
    $existing = $pdo->prepare('SELECT id, status FROM attendance WHERE stakeholder_id = :sid AND hearing_id = :hid AND attendance_date = :adate LIMIT 1');
    $existing->execute([':sid' => $stakeholder['id'], ':hid' => $hearingId, ':adate' => $sessionDate]);
    $existingRow = $existing->fetch();

    if ($existingRow) {
        jsonResponse(false, $stakeholder['full_name'] . ' has already been checked in for ' . $sessionDate . ' (' . $existingRow['status'] . ').', [
            'already_checked_in' => true, 'full_name' => $stakeholder['full_name'], 'session_date' => $sessionDate
        ]);
    }

    $insert = $pdo->prepare(
        'INSERT INTO attendance (stakeholder_id, hearing_id, attendance_date, status, checked_in_at, created_at)
         VALUES (:sid, :hid, :adate, :status, NOW(), NOW())'
    );
    $insert->execute([':sid' => $stakeholder['id'], ':hid' => $hearingId, ':adate' => $sessionDate, ':status' => $status]);

    $logStmt = $pdo->prepare(
        'INSERT INTO attendance_logs (stakeholder_id, hearing_id, session_date, action, notes, created_at)
         VALUES (:sid, :hid, :sdate, :action, :notes, NOW())'
    );
    $logStmt->execute([
        ':sid' => $stakeholder['id'], ':hid' => $hearingId, ':sdate' => $sessionDate, ':action' => 'QR Check-in',
        ':notes' => 'Checked in via QR scan for ' . $sessionDate . ', marked ' . $status . '.',
    ]);

    logActivity(currentUserId(), 'Insert', 'Attendance recorded for ' . $stakeholder['full_name'] . ' (hearing #' . $hearingId . ', ' . $sessionDate . ', ' . $status . ')');

    jsonResponse(true, $stakeholder['full_name'] . ' checked in successfully for ' . $sessionDate . ' (' . $status . ').', [
        'full_name' => $stakeholder['full_name'], 'status' => $status, 'session_date' => $sessionDate
    ]);

} catch (PDOException $e) {
    error_log('QR check-in error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while recording attendance.');
}
