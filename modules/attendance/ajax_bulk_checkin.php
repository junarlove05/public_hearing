<?php
declare(strict_types=1);

/**
 * modules/attendance/ajax_bulk_checkin.php
 * ------------------------------------------------------------------
 * Bulk attendance recording (Present/Late/Absent) for multiple stakeholders
 * selected via checkboxes on a hearing session roster.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';
requireLogin();

if (!canManage()) {
    jsonResponse(false, 'Only authorized personnel can perform bulk attendance recording.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}
requireCsrf();

$hearingId = (int)($_POST['hearing_id'] ?? 0);
$sessionDate = clean($_POST['session_date'] ?? '');
$status = clean($_POST['status'] ?? 'Present');
$stakeholderIds = $_POST['stakeholder_ids'] ?? [];

$allowedStatus = ['Present', 'Absent', 'Late'];
if ($hearingId <= 0 || !in_array($status, $allowedStatus, true)) {
    jsonResponse(false, 'Please select a valid hearing and attendance status.');
}

if (!is_array($stakeholderIds) || empty($stakeholderIds)) {
    jsonResponse(false, 'No stakeholders were selected for bulk check-in.');
}

$pdo = db();
$processed = 0;
$skipped = 0;
$currentUser = currentUser();

try {
    $hStmt = $pdo->prepare('SELECT id, title, hearing_date, end_date, status FROM hearings WHERE id = :id');
    $hStmt->execute([':id' => $hearingId]);
    $hearing = $hStmt->fetch();
    if (!$hearing) {
        jsonResponse(false, 'Selected hearing was not found.');
    }

    if ($sessionDate === '') {
        $todayStr = date('Y-m-d');
        $startDate = $hearing['hearing_date'];
        $endDate = !empty($hearing['end_date']) ? $hearing['end_date'] : $startDate;
        $sessionDate = ($todayStr >= $startDate && $todayStr <= $endDate) ? $todayStr : $startDate;
    }

    $dayInfo = lphGetHearingDayInfo($pdo, $hearingId, $sessionDate, $hearing);
    if (!$dayInfo['valid']) {
        jsonResponse(false, 'Selected date (' . htmlspecialchars($sessionDate) . ') is not part of this hearing schedule.');
    }

    if (lphIsDayAttendanceClosed($pdo, $hearingId, $sessionDate, $hearing)) {
        jsonResponse(false, 'Attendance for this session date is closed.');
    }

    $pdo->beginTransaction();

    $checkExisting = $pdo->prepare(
        'SELECT id FROM attendance WHERE stakeholder_id = :sid AND hearing_id = :hid AND attendance_date = :adate LIMIT 1'
    );
    $updateStmt = $pdo->prepare(
        'UPDATE attendance SET status = :status, checked_in_at = NOW(), updated_at = NOW() WHERE id = :id'
    );
    $insertStmt = $pdo->prepare(
        'INSERT INTO attendance (stakeholder_id, hearing_id, attendance_date, status, checked_in_at, created_at)
         VALUES (:sid, :hid, :adate, :status, NOW(), NOW())'
    );
    $logStmt = $pdo->prepare(
        'INSERT INTO attendance_logs (stakeholder_id, hearing_id, session_date, action, notes, created_at)
         VALUES (:sid, :hid, :sdate, :action, :notes, NOW())'
    );

    foreach ($stakeholderIds as $rawSid) {
        $sid = (int)$rawSid;
        if ($sid <= 0) continue;

        $checkExisting->execute([':sid' => $sid, ':hid' => $hearingId, ':adate' => $sessionDate]);
        $row = $checkExisting->fetch();

        if ($row) {
            $updateStmt->execute([':status' => $status, ':id' => $row['id']]);
            $action = 'Bulk Update';
        } else {
            $insertStmt->execute([':sid' => $sid, ':hid' => $hearingId, ':adate' => $sessionDate, ':status' => $status]);
            $action = 'Bulk Check-in';
        }

        $logStmt->execute([
            ':sid' => $sid,
            ':hid' => $hearingId,
            ':sdate' => $sessionDate,
            ':action' => $action,
            ':notes' => 'Bulk marked ' . $status . ' for ' . $sessionDate . ' by ' . ($currentUser['full_name'] ?? 'Staff') . '.'
        ]);

        $processed++;
    }

    $pdo->commit();

    logActivity(
        currentUserId(),
        'Bulk Attendance',
        "Bulk marked {$processed} stakeholder(s) as {$status} for Hearing #{$hearingId} ({$sessionDate})."
    );

    jsonResponse(true, "Successfully recorded attendance: {$processed} marked as {$status}.", [
        'processed' => $processed,
        'status' => $status,
        'session_date' => $sessionDate
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Bulk attendance error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred during bulk attendance recording: ' . $e->getMessage());
}
