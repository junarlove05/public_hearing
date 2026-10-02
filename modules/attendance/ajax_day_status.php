<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';

requireLogin();
if (!isAdmin()) {
    jsonResponse(false, 'Session day status management is restricted to System Administrators.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

requireCsrf();

$hearingId = (int)($_POST['hearing_id'] ?? 0);
$sessionDate = clean($_POST['session_date'] ?? '');
$mode = (int)($_POST['mode'] ?? 1); // 1 = close, 2 = force open / unlock, 0 = auto

if ($hearingId <= 0 || !$sessionDate || !strtotime($sessionDate)) {
    jsonResponse(false, 'Invalid hearing or session date.');
}

$pdo = db();

$dayInfo = lphGetHearingDayInfo($pdo, $hearingId, $sessionDate);
if (!$dayInfo['valid']) {
    jsonResponse(false, $dayInfo['message']);
}

$ok = lphSetSessionDayClosure($pdo, $hearingId, $sessionDate, $mode, currentUserId(), 'Updated by ' . (currentUser()['full_name'] ?? 'staff'));

if (!$ok) {
    jsonResponse(false, 'Failed to update session day status.');
}

// Log activity
$dayLabel = $dayInfo['day']['day_label'] . ' (' . $dayInfo['day']['formatted_date'] . ')';
$modeText = match($mode) {
    1 => 'Closed attendance for ' . $dayLabel,
    2 => 'Reopened / unlocked attendance for ' . $dayLabel,
    default => 'Reset attendance status to auto for ' . $dayLabel
};

logActivity(currentUserId(), 'Attendance Day Status', "{$modeText} · Hearing #{$hearingId}");

$updatedDayInfo = lphGetHearingDayInfo($pdo, $hearingId, $sessionDate);

jsonResponse(true, $modeText . ' successfully.', [
    'day' => $updatedDayInfo['day']
]);
