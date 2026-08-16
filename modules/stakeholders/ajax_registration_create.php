<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';

requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$pdo = db();
$stakeholderId = (int)($_POST['stakeholder_id'] ?? 0);
$hearingId = (int)($_POST['hearing_id'] ?? 0);
$attendanceType = clean($_POST['attendance_type'] ?? 'On-site');

if (!in_array($attendanceType,['On-site','Online','Hybrid'],true)) {
    jsonResponse(false,'Invalid attendance type.');
}

if ($stakeholderId <= 0 || $hearingId <= 0) jsonResponse(false,'Stakeholder and hearing are required.');

$availability = lphRegistrationAvailability($pdo,$hearingId);
if (!$availability['ok']) jsonResponse(false,$availability['message']);

$s = $pdo->prepare("SELECT * FROM stakeholders WHERE id=:id AND status IN ('Pending','Verified')");
$s->execute([':id'=>$stakeholderId]);
$stakeholder = $s->fetch();
if (!$stakeholder) jsonResponse(false,'Stakeholder is inactive or does not exist.');

$dup = $pdo->prepare(
    'SELECT id FROM registrations WHERE stakeholder_id=:sid AND hearing_id=:hid LIMIT 1'
);
$dup->execute([':sid'=>$stakeholderId, ':hid'=>$hearingId]);
if ($dup->fetch()) jsonResponse(false,'This stakeholder is already registered for the hearing.');

try {
    $pdo->beginTransaction();

    $code = lphUniqueCode($pdo,'registrations','registration_code','REG');
    $status = canManage() ? 'Approved' : 'Pending';
    $approvedBy = canManage() ? currentUserId() : null;
    $approvedAt = canManage() ? date('Y-m-d H:i:s') : null;

    $stmt = $pdo->prepare(
        'INSERT INTO registrations
         (stakeholder_id,hearing_id,registered_at,registration_code,registration_status,
          attendance_type,approved_by,approved_at,rejection_reason,updated_at)
         VALUES (:sid,:hid,NOW(),:code,:status,:atype,:approved_by,:approved_at,NULL,NOW())'
    );
    $stmt->execute([
        ':sid'=>$stakeholderId, ':hid'=>$hearingId, ':code'=>$code, ':status'=>$status,
        ':atype'=>$attendanceType, ':approved_by'=>$approvedBy, ':approved_at'=>$approvedAt
    ]);

    $registrationId = (int)$pdo->lastInsertId();

    if ($status === 'Approved') {
        $qr = lphUniqueCode($pdo,'qr_codes','code_value','QR');
        $expiresAt = null;
        if (!empty($availability['hearing']['hearing_date'])) {
            $expiresAt = date(
                'Y-m-d H:i:s',
                strtotime(
                    $availability['hearing']['hearing_date'].' 23:59:59'
                )
            );
        }

        $pdo->prepare(
            'INSERT INTO qr_codes
             (stakeholder_id,registration_id,hearing_id,code_value,created_at,status,expires_at,used_at)
             VALUES (:sid,:rid,:hid,:code,NOW(),"Active",:expires,NULL)'
        )->execute([
            ':sid'=>$stakeholderId, ':rid'=>$registrationId, ':hid'=>$hearingId,
            ':code'=>$qr, ':expires'=>$expiresAt
        ]);
    }

    lphHistory($pdo,'registration',$registrationId,'Create',null,$status,
        "Registration created for {$stakeholder['full_name']}.");
    logActivity(currentUserId(),'Create Registration',
        "Registered {$stakeholder['full_name']} for hearing #{$hearingId}.");

    $pdo->commit();
    jsonResponse(true,'Registration created successfully.',['id'=>$registrationId]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Registration create error: '.$e->getMessage());
    jsonResponse(false, APP_DEBUG ? $e->getMessage() : 'Unable to create registration.');
}
