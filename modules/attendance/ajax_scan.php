<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';

requireLogin();

$code = trim((string)($_GET['code'] ?? ''));
$hearingId = (int)($_GET['hearing_id'] ?? 0);

if ($code === '') {
    jsonResponse(false, 'Enter or scan a QR or registration code.');
}

$pdo = db();

// 1. Try finding in qr_codes first (matches both Stakeholder STK- and Registration QR- codes)
$qrStmt = $pdo->prepare(
    'SELECT q.*, s.id AS s_id, s.full_name, s.email, s.organization, s.status AS stakeholder_status
     FROM qr_codes q
     JOIN stakeholders s ON s.id = q.stakeholder_id
     WHERE q.code_value = :code
     LIMIT 1'
);
$qrStmt->execute([':code' => $code]);
$qrRow = $qrStmt->fetch();

$stakeholder = null;
$codeValue = $code;
$registrationId = 0;
$registrationCode = '';

if ($qrRow) {
    if (!empty($qrRow['expires_at']) && time() > strtotime($qrRow['expires_at'])) {
        jsonResponse(false, 'This QR credential has expired.');
    }
    if (isset($qrRow['status']) && $qrRow['status'] === 'Revoked') {
        jsonResponse(false, 'This QR credential has been revoked.');
    }

    $stakeholder = [
        'id' => (int)$qrRow['s_id'],
        'full_name' => $qrRow['full_name'],
        'email' => $qrRow['email'],
        'organization' => $qrRow['organization'],
        'status' => $qrRow['stakeholder_status']
    ];
    $codeValue = $qrRow['code_value'];
    if (!empty($qrRow['registration_id'])) {
        $registrationId = (int)$qrRow['registration_id'];
    }
    if ($hearingId <= 0 && !empty($qrRow['hearing_id'])) {
        $hearingId = (int)$qrRow['hearing_id'];
    }
} else {
    // 2. Try matching direct registration_code (e.g. REG-...)
    $regStmt = $pdo->prepare(
        'SELECT r.*, s.id AS s_id, s.full_name, s.email, s.organization, s.status AS stakeholder_status
         FROM registrations r
         JOIN stakeholders s ON s.id = r.stakeholder_id
         WHERE r.registration_code = :code
         LIMIT 1'
    );
    $regStmt->execute([':code' => $code]);
    $regRow = $regStmt->fetch();

    if ($regRow) {
        $stakeholder = [
            'id' => (int)$regRow['s_id'],
            'full_name' => $regRow['full_name'],
            'email' => $regRow['email'],
            'organization' => $regRow['organization'],
            'status' => $regRow['stakeholder_status']
        ];
        $registrationId = (int)$regRow['id'];
        $registrationCode = $regRow['registration_code'];
        if ($hearingId <= 0 && !empty($regRow['hearing_id'])) {
            $hearingId = (int)$regRow['hearing_id'];
        }
    } else {
        // 3. Try matching invitation code (e.g. INV-...)
        $invStmt = $pdo->prepare(
            'SELECT i.*, s.id AS s_id, s.full_name, s.email, s.organization, s.status AS stakeholder_status
             FROM invitations i
             JOIN stakeholders s ON s.id = i.stakeholder_id
             WHERE i.invitation_code = :code
             LIMIT 1'
        );
        $invStmt->execute([':code' => $code]);
        $invRow = $invStmt->fetch();

        if ($invRow) {
            $stakeholder = [
                'id' => (int)$invRow['s_id'],
                'full_name' => $invRow['full_name'],
                'email' => $invRow['email'],
                'organization' => $invRow['organization'],
                'status' => $invRow['stakeholder_status']
            ];
            if ($hearingId <= 0 && !empty($invRow['hearing_id'])) {
                $hearingId = (int)$invRow['hearing_id'];
            }
            // Fetch stakeholder's QR code value if available
            $qVal = $pdo->prepare('SELECT code_value FROM qr_codes WHERE stakeholder_id=:sid LIMIT 1');
            $qVal->execute([':sid' => $stakeholder['id']]);
            $codeValue = $qVal->fetchColumn() ?: $code;
        }
    }
}

if (!$stakeholder) {
    jsonResponse(false, 'No stakeholder, invitation, or registration matches code "' . htmlspecialchars($code) . '".');
}

if (!in_array($stakeholder['status'], ['Verified', 'Approved', 'Active'], true)) {
    jsonResponse(false, 'Attendance check-in denied: Stakeholder account "' . htmlspecialchars($stakeholder['full_name']) . '" is ' . htmlspecialchars($stakeholder['status']) . '. Only Verified accounts are authorized for QR attendance scanning.');
}

// 3. Resolve Hearing
$hearing = null;
if ($hearingId > 0) {
    $hStmt = $pdo->prepare('SELECT id, reference_number, title, hearing_date, end_date, status FROM hearings WHERE id = :id');
    $hStmt->execute([':id' => $hearingId]);
    $hearing = $hStmt->fetch();
}

if (!$hearing) {
    jsonResponse(false, 'Please select an active hearing session first.');
}

$sessionDate = clean($_GET['session_date'] ?? $_POST['session_date'] ?? '');
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

// Validate that session date belongs to this hearing
$dayInfo = lphGetHearingDayInfo($pdo, $hearingId, $sessionDate, $hearing);
if (!$dayInfo['valid']) {
    jsonResponse(false, 'The selected date (' . htmlspecialchars($sessionDate) . ') is not part of the schedule for this hearing.');
}

// Enforce daily closure
if (lphIsDayAttendanceClosed($pdo, $hearingId, $sessionDate, $hearing)) {
    $blockReason = lphGetDayAttendanceBlockReason($pdo, $hearingId, $sessionDate, $hearing);
    jsonResponse(false, $blockReason ?: 'Attendance for this date is closed.');
}

// 4. Resolve Invitation & Registration for this Hearing
$invChk = $pdo->prepare(
    'SELECT id, status, session_date, invitation_code 
     FROM invitations 
     WHERE stakeholder_id = :sid AND hearing_id = :hid 
       AND (session_date = :sdate OR session_date IS NULL) 
     LIMIT 1'
);
$invChk->execute([':sid' => $stakeholder['id'], ':hid' => $hearingId, ':sdate' => $sessionDate]);
$invMatch = $invChk->fetch();

if (!$invMatch || !in_array($invMatch['status'], ['Accepted', 'Approved'], true)) {
    $invStatusMsg = $invMatch ? ('invitation status is ' . htmlspecialchars($invMatch['status'])) : 'no invitation record found';
    jsonResponse(false, 'Attendance check-in denied: Stakeholder "' . htmlspecialchars($stakeholder['full_name']) . '" does not have an approved invitation for this hearing session (' . $invStatusMsg . '). Only approved invitees are permitted.');
}

// Stakeholder has an approved invitation: resolve / guarantee their registration record
$regCheck = $pdo->prepare(
    'SELECT * FROM registrations 
     WHERE stakeholder_id = :sid AND hearing_id = :hid 
       AND (session_date = :sdate OR session_date IS NULL) 
     LIMIT 1'
);
$regCheck->execute([':sid' => $stakeholder['id'], ':hid' => $hearingId, ':sdate' => $sessionDate]);
$regForHearing = $regCheck->fetch();

if (!$regForHearing) {
    $newRegCode = lphUniqueCode($pdo, 'registrations', 'registration_code', 'REG');
    $insReg = $pdo->prepare(
        'INSERT INTO registrations
         (stakeholder_id, hearing_id, session_date, registration_code, registration_status, attendance_type, approved_by, approved_at, registered_at)
         VALUES (:sid, :hid, :sdate, :code, "Approved", "Invited", :uid, NOW(), NOW())'
    );
    $insReg->execute([
        ':sid'   => $stakeholder['id'],
        ':hid'   => $hearingId,
        ':sdate' => $invMatch['session_date'] ?: $sessionDate,
        ':code'  => $newRegCode,
        ':uid'   => currentUserId()
    ]);
    $registrationId = (int)$pdo->lastInsertId();
    $registrationCode = $newRegCode;
    $regStatus = 'Approved';
} else {
    $registrationId = (int)$regForHearing['id'];
    $registrationCode = $regForHearing['registration_code'];
    $regStatus = 'Approved';

    if ($regForHearing['registration_status'] !== 'Approved') {
        $pdo->prepare('UPDATE registrations SET registration_status="Approved", approved_by=:uid, approved_at=NOW() WHERE id=:id')
            ->execute([':uid' => currentUserId(), ':id' => $registrationId]);
    }
}

// 5. Look up current Attendance Record for this Hearing and specific Session Date
$attStmt = $pdo->prepare('SELECT * FROM attendance WHERE stakeholder_id = :sid AND hearing_id = :hid AND attendance_date = :adate LIMIT 1');
$attStmt->execute([':sid' => $stakeholder['id'], ':hid' => $hearingId, ':adate' => $sessionDate]);
$attendance = $attStmt->fetch();

$checkedInAt = $attendance['checked_in_at'] ?? null;
$checkedOutAt = $attendance['checked_out_at'] ?? null;
$attStatus = $attendance['status'] ?? 'Not Marked';

// Smart recommended action:
// If not checked in yet -> 'check_in' (Time In)
// If checked in and NOT checked out -> 'check_out' (Time Out)
// If both done -> 'check_out' (allows re-time in or toggle)
if (empty($checkedInAt)) {
    $recommendedAction = 'check_in';
} elseif (empty($checkedOutAt)) {
    $recommendedAction = 'check_out';
} else {
    $recommendedAction = 'locked';
}

jsonResponse(true, 'Stakeholder recognized for ' . $dayInfo['day']['day_label'] . ' (' . $dayInfo['day']['formatted_date'] . ').', [
    'registration' => [
        'registration_id' => $registrationId,
        'stakeholder_id' => $stakeholder['id'],
        'full_name' => $stakeholder['full_name'],
        'email' => $stakeholder['email'],
        'organization' => $stakeholder['organization'],
        'registration_code' => $registrationCode,
        'code_value' => $codeValue,
        'hearing_id' => $hearingId,
        'hearing_title' => $hearing['title'],
        'session_date' => $sessionDate,
        'day_label' => $dayInfo['day']['day_label'],
        'attendance_status' => $attStatus,
        'checked_in_at' => $checkedInAt,
        'checked_out_at' => $checkedOutAt,
        'recommended_action' => $recommendedAction,
    ]
]);
