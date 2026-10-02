<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';

requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$pdo = db();
$hearingSessionKey = trim((string)($_POST['hearing_session_key'] ?? ''));
$sessionDayId = (int)($_POST['session_day_id'] ?? 0);
$sessionDate = trim((string)($_POST['session_date'] ?? ''));
$hearingId = (int)($_POST['hearing_id'] ?? 0);

if ($hearingSessionKey !== '') {
    $parts = explode('_', $hearingSessionKey);
    if (isset($parts[0]) && is_numeric($parts[0])) $hearingId = (int)$parts[0];
    if (isset($parts[1]) && is_numeric($parts[1])) $sessionDayId = (int)$parts[1];
    if (isset($parts[2]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $parts[2])) $sessionDate = $parts[2];
}

// Resolve session date if missing
if ($sessionDate === '' && $sessionDayId > 0) {
    $sStmt = $pdo->prepare('SELECT session_date FROM hearing_session_days WHERE id = :id');
    $sStmt->execute([':id' => $sessionDayId]);
    $sessionDate = (string)($sStmt->fetchColumn() ?: '');
}
if ($sessionDate === '' && $hearingId > 0) {
    $hStmt = $pdo->prepare('SELECT hearing_date FROM hearings WHERE id = :id');
    $hStmt->execute([':id' => $hearingId]);
    $sessionDate = (string)($hStmt->fetchColumn() ?: '');
}

$attendanceType = clean($_POST['attendance_type'] ?? 'On-site');
if (!in_array($attendanceType, ['On-site', 'Online', 'Hybrid'], true)) {
    jsonResponse(false, 'Invalid attendance type.');
}

$stakeholderIds = [];
if (!empty($_POST['stakeholder_ids']) && is_array($_POST['stakeholder_ids'])) {
    $stakeholderIds = array_map('intval', $_POST['stakeholder_ids']);
} elseif (!empty($_POST['stakeholder_id'])) {
    $stakeholderIds = [(int)$_POST['stakeholder_id']];
}
$stakeholderIds = array_values(array_unique(array_filter($stakeholderIds, fn($id) => $id > 0)));

if (empty($stakeholderIds) || $hearingId <= 0) {
    jsonResponse(false, 'Please select a target hearing and at least one stakeholder.');
}

$availability = lphRegistrationAvailability($pdo, $hearingId);
if (!$availability['ok']) {
    jsonResponse(false, $availability['message']);
}

// Fetch all selected stakeholders
$placeholders = implode(',', array_fill(0, count($stakeholderIds), '?'));
$stkStmt = $pdo->prepare("SELECT id, full_name, status FROM stakeholders WHERE id IN ($placeholders)");
$stkStmt->execute($stakeholderIds);
$stakeholders = $stkStmt->fetchAll(PDO::FETCH_ASSOC);

$stkMap = [];
foreach ($stakeholders as $s) {
    $stkMap[(int)$s['id']] = $s;
}

// Verify that all exist and are Verified
foreach ($stakeholderIds as $sid) {
    if (!isset($stkMap[$sid])) {
        jsonResponse(false, "Stakeholder #{$sid} does not exist.");
    }
    if (($stkMap[$sid]['status'] ?? '') !== 'Verified') {
        jsonResponse(false, 'Only verified stakeholders can be registered for hearings. "' . ($stkMap[$sid]['full_name'] ?? 'Stakeholder') . '" has status: ' . ($stkMap[$sid]['status'] ?? 'unverified') . '.');
    }
}

// Check existing registrations for this specific hearing session day
if ($sessionDayId > 0) {
    $dupStmt = $pdo->prepare("SELECT stakeholder_id FROM registrations WHERE hearing_id = ? AND session_day_id = ? AND stakeholder_id IN ($placeholders)");
    $dupStmt->execute(array_merge([$hearingId, $sessionDayId], $stakeholderIds));
} elseif ($sessionDate !== '') {
    $dupStmt = $pdo->prepare("SELECT stakeholder_id FROM registrations WHERE hearing_id = ? AND session_date = ? AND stakeholder_id IN ($placeholders)");
    $dupStmt->execute(array_merge([$hearingId, $sessionDate], $stakeholderIds));
} else {
    $dupStmt = $pdo->prepare("SELECT stakeholder_id FROM registrations WHERE hearing_id = ? AND session_day_id IS NULL AND session_date IS NULL AND stakeholder_id IN ($placeholders)");
    $dupStmt->execute(array_merge([$hearingId], $stakeholderIds));
}
$existingIds = $dupStmt->fetchAll(PDO::FETCH_COLUMN);
$existingSet = array_flip(array_map('intval', $existingIds));

// Filter out already registered for this specific day
$toRegisterIds = array_values(array_filter($stakeholderIds, fn($id) => !isset($existingSet[$id])));

if (empty($toRegisterIds)) {
    // If all selected are already registered on this exact session, update their attendance type smoothly
    if ($sessionDayId > 0) {
        $upStmt = $pdo->prepare("UPDATE registrations SET attendance_type = :atype, updated_at = NOW() WHERE hearing_id = :hid AND session_day_id = :sdid AND stakeholder_id = :sid");
    } elseif ($sessionDate !== '') {
        $upStmt = $pdo->prepare("UPDATE registrations SET attendance_type = :atype, updated_at = NOW() WHERE hearing_id = :hid AND session_date = :sdate AND stakeholder_id = :sid");
    } else {
        $upStmt = $pdo->prepare("UPDATE registrations SET attendance_type = :atype, updated_at = NOW() WHERE hearing_id = :hid AND stakeholder_id = :sid");
    }
    foreach ($stakeholderIds as $sid) {
        $params = [':atype' => $attendanceType, ':hid' => $hearingId, ':sid' => $sid];
        if ($sessionDayId > 0) $params[':sdid'] = $sessionDayId;
        elseif ($sessionDate !== '') $params[':sdate'] = $sessionDate;
        $upStmt->execute($params);
    }
    jsonResponse(true, "Attendance mode updated to {$attendanceType} for selected stakeholders on this session.", [
        'count' => count($stakeholderIds),
        'names' => array_column($stakeholders, 'full_name')
    ]);
}

try {
    $pdo->beginTransaction();

    $status = canManage() ? 'Approved' : 'Pending';
    $approvedBy = canManage() ? currentUserId() : null;
    $approvedAt = canManage() ? date('Y-m-d H:i:s') : null;

    $insertStmt = $pdo->prepare(
        'INSERT INTO registrations
         (stakeholder_id,hearing_id,session_day_id,session_date,registered_at,registration_code,registration_status,
          attendance_type,approved_by,approved_at,rejection_reason,updated_at)
         VALUES (:sid,:hid,:sdid,:sdate,NOW(),:code,:status,:atype,:approved_by,:approved_at,NULL,NOW())'
    );

    $qrCheck = $pdo->prepare('SELECT id FROM qr_codes WHERE stakeholder_id = :sid LIMIT 1');
    $qrInsert = $pdo->prepare(
        'INSERT INTO qr_codes (stakeholder_id, code_value, created_at, status)
         VALUES (:sid, :code, NOW(), "Active")'
    );

    $registeredCount = 0;
    $registeredNames = [];

    foreach ($toRegisterIds as $sid) {
        $stk = $stkMap[$sid];
        $code = lphUniqueCode($pdo, 'registrations', 'registration_code', 'REG');

        $insertStmt->execute([
            ':sid' => $sid,
            ':hid' => $hearingId,
            ':sdid' => ($sessionDayId > 0 ? $sessionDayId : null),
            ':sdate' => ($sessionDate !== '' ? $sessionDate : null),
            ':code' => $code,
            ':status' => $status,
            ':atype' => $attendanceType,
            ':approved_by' => $approvedBy,
            ':approved_at' => $approvedAt
        ]);

        $registrationId = (int)$pdo->lastInsertId();

        // Ensure active QR code for verified stakeholder
        if ($status === 'Approved') {
            $qrCheck->execute([':sid' => $sid]);
            if (!$qrCheck->fetch()) {
                $qr = 'STK-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                $qrInsert->execute([
                    ':sid' => $sid,
                    ':code' => $qr
                ]);
            }
        }

        lphHistory($pdo, 'registration', $registrationId, 'Create', null, $status,
            "Registration created for {$stk['full_name']}.");
        $registeredCount++;
        $registeredNames[] = $stk['full_name'];
    }

    logActivity(currentUserId(), 'Create Registration',
        "Registered {$registeredCount} stakeholder(s) for hearing #{$hearingId}: " . implode(', ', array_slice($registeredNames, 0, 5)) . ($registeredCount > 5 ? ' and more' : '') . ".");

    $pdo->commit();

    $skippedCount = count($existingIds);
    $msg = $registeredCount === 1 
        ? "Successfully registered {$registeredNames[0]}." 
        : "Successfully registered {$registeredCount} verified stakeholders.";
    if ($skippedCount > 0) {
        $msg .= " ({$skippedCount} already registered were skipped)";
    }

    jsonResponse(true, $msg, ['registered_count' => $registeredCount, 'skipped_count' => $skippedCount]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Registration create error: ' . $e->getMessage());
    jsonResponse(false, APP_DEBUG ? $e->getMessage() : 'Unable to create registration.');
}
