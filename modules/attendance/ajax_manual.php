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
require_once __DIR__ . '/../../includes/lph_module_helpers.php';
requireLogin();

if (!isAdmin()) {
    jsonResponse(false, 'Attendance recording (Time In / Time Out) is restricted to System Administrators.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$stakeholderId = (int)($_POST['stakeholder_id'] ?? 0);
$hearingId     = (int)($_POST['hearing_id'] ?? 0);
$status        = clean($_POST['status'] ?? '');
$sessionDate   = clean($_POST['session_date'] ?? '');
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

    $hStmt = $pdo->prepare('SELECT id, title, hearing_date, end_date, status FROM hearings WHERE id = :id');
    $hStmt->execute([':id' => $hearingId]);
    $hearing = $hStmt->fetch();
    if (!$hearing) jsonResponse(false, 'The selected hearing no longer exists. Please refresh the page and try again.');

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
    $invStmt->execute([':sid' => $stakeholderId, ':hid' => $hearingId, ':sdate' => $sessionDate]);
    $inv = $invStmt->fetch();
    if (!$inv || !in_array($inv['status'], ['Accepted', 'Approved'], true)) {
        jsonResponse(false, 'Attendance denied: Stakeholder "' . htmlspecialchars($stakeholder['full_name']) . '" does not have an approved invitation for this hearing session.');
    }

    $existing = $pdo->prepare('SELECT id FROM attendance WHERE stakeholder_id = :sid AND hearing_id = :hid AND attendance_date = :adate LIMIT 1');
    $existing->execute([':sid' => $stakeholderId, ':hid' => $hearingId, ':adate' => $sessionDate]);
    $existingRow = $existing->fetch();

    if ($existingRow) {
        $update = $pdo->prepare(
            'UPDATE attendance SET status = :status, checked_in_at = NOW(), updated_at = NOW() WHERE id = :id'
        );
        $update->execute([':status' => $status, ':id' => $existingRow['id']]);
        $action = 'Manual Update';
    } else {
        $insert = $pdo->prepare(
            'INSERT INTO attendance (stakeholder_id, hearing_id, attendance_date, status, checked_in_at, created_at)
             VALUES (:sid, :hid, :adate, :status, NOW(), NOW())'
        );
        $insert->execute([':sid' => $stakeholderId, ':hid' => $hearingId, ':adate' => $sessionDate, ':status' => $status]);
        $action = 'Manual Check-in';
    }

    $logStmt = $pdo->prepare(
        'INSERT INTO attendance_logs (stakeholder_id, hearing_id, session_date, action, notes, created_at)
         VALUES (:sid, :hid, :sdate, :action, :notes, NOW())'
    );
    $logStmt->execute([
        ':sid' => $stakeholderId, ':hid' => $hearingId, ':sdate' => $sessionDate, ':action' => $action,
        ':notes' => 'Marked ' . $status . ' for ' . $sessionDate . ' by ' . (currentUser()['full_name'] ?? 'staff') . '.',
    ]);

    logActivity(currentUserId(), 'Update', $action . ' for ' . $stakeholder['full_name'] . ' (hearing #' . $hearingId . ', ' . $sessionDate . ', ' . $status . ')');
    jsonResponse(true, $stakeholder['full_name'] . ' marked as ' . $status . ' for ' . $sessionDate . '.');

} catch (PDOException $e) {
    error_log('Manual attendance error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while recording attendance.');
}
