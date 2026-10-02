<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';
require_once __DIR__ . '/../../includes/mailer.php';

requireLogin();
if (!canManage()) jsonResponse(false, 'You do not have permission to update invitations.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$pdo = db();
$id = (int)($_POST['id'] ?? 0);
$status = clean($_POST['status'] ?? '');
$allowed = ['Pending','Sent','Accepted','Declined','Expired','Cancelled'];

if ($id <= 0 || !in_array($status, $allowed, true)) {
    jsonResponse(false, 'Invalid invitation update.');
}

$stmt = $pdo->prepare(
    'SELECT i.*, 
            s.full_name, s.email, s.organization, s.user_id AS stakeholder_user_id,
            h.title AS hearing_title, h.venue, h.hearing_date, h.hearing_time, h.reference_number,
            (SELECT code_value FROM qr_codes q WHERE q.stakeholder_id = s.id LIMIT 1) AS qr_code
     FROM invitations i
     JOIN stakeholders s ON s.id = i.stakeholder_id
     LEFT JOIN hearings h ON h.id = i.hearing_id
     WHERE i.id = :id'
);
$stmt->execute([':id' => $id]);
$inv = $stmt->fetch();
if (!$inv) jsonResponse(false, 'Invitation not found.');

$old = $inv['status'];
$sentAt = $inv['sent_at'];
$respondedAt = $inv['responded_at'];

if ($status === 'Sent' && empty($sentAt)) $sentAt = date('Y-m-d H:i:s');
if (in_array($status, ['Accepted', 'Declined'], true)) $respondedAt = date('Y-m-d H:i:s');

try {
    $pdo->beginTransaction();
    $up = $pdo->prepare(
        'UPDATE invitations
         SET status = :status, sent_at = :sent_at, responded_at = :responded_at, updated_at = NOW()
         WHERE id = :id'
    );
    $up->execute([
        ':status'       => $status,
        ':sent_at'      => $sentAt,
        ':responded_at' => $respondedAt,
        ':id'           => $id
    ]);

    // Synchronize registrations table so Hearing Information and Attendance reflect true state
    $sid = (int)$inv['stakeholder_id'];
    $hid = (int)$inv['hearing_id'];

    if ($hid > 0) {
        $regCheck = $pdo->prepare('SELECT id, registration_status FROM registrations WHERE stakeholder_id = :sid AND hearing_id = :hid LIMIT 1');
        $regCheck->execute([':sid' => $sid, ':hid' => $hid]);
        $reg = $regCheck->fetch();

        $targetRegStatus = match($status) {
            'Accepted' => 'Approved',
            'Declined' => 'Declined',
            'Cancelled' => 'Cancelled',
            default => 'Pending'
        };

        $appBy = ($targetRegStatus === 'Approved') ? currentUserId() : null;
        $appAt = ($targetRegStatus === 'Approved') ? date('Y-m-d H:i:s') : null;

        if ($reg) {
            $regId = (int)$reg['id'];
            $pdo->prepare(
                'UPDATE registrations
                 SET registration_status = :status,
                     approved_by = :app_by,
                     approved_at = :app_at,
                     rejection_reason = NULL,
                     updated_at = NOW()
                 WHERE id = :id'
            )->execute([
                ':status' => $targetRegStatus,
                ':app_by' => $appBy,
                ':app_at' => $appAt,
                ':id'     => $regId
            ]);
        } else {
            $regCode = lphUniqueCode($pdo, 'registrations', 'registration_code', 'REG');
            $pdo->prepare(
                'INSERT INTO registrations
                 (stakeholder_id, hearing_id, registration_code, registration_status, attendance_type, approved_by, approved_at, registered_at, updated_at)
                 VALUES (:sid, :hid, :code, :status, "Invited", :app_by, :app_at, NOW(), NOW())'
            )->execute([
                ':sid'    => $sid,
                ':hid'    => $hid,
                ':code'   => $regCode,
                ':status' => $targetRegStatus,
                ':app_by' => $appBy,
                ':app_at' => $appAt
            ]);
            $regId = (int)$pdo->lastInsertId();
        }

        // When approved, ensure QR credential exists ONLY IF stakeholder account is Verified
        if ($targetRegStatus === 'Approved') {
            $stkCheck = $pdo->prepare('SELECT status FROM stakeholders WHERE id = :sid LIMIT 1');
            $stkCheck->execute([':sid' => $sid]);
            $stkStatus = $stkCheck->fetchColumn();

            if (in_array($stkStatus, ['Verified', 'Approved', 'Active'], true)) {
                $existingQr = $pdo->prepare('SELECT id FROM qr_codes WHERE stakeholder_id = :sid LIMIT 1');
                $existingQr->execute([':sid' => $sid]);
                if (!$existingQr->fetch()) {
                    $qr = 'STK-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                    $pdo->prepare(
                        'INSERT INTO qr_codes
                         (stakeholder_id, code_value, created_at, status)
                         VALUES (:sid, :code, NOW(), "Active")'
                    )->execute([
                        ':sid'  => $sid,
                        ':code' => $qr
                    ]);
                }
            }
        }
    }

    lphHistory(
        $pdo,
        'invitation',
        $id,
        'Status Change',
        $old,
        $status,
        "Invitation for {$inv['full_name']} changed from {$old} to {$status}."
    );
    logActivity(
        currentUserId(),
        'Update Invitation',
        "Invitation {$inv['invitation_code']} status {$old} -> {$status}."
    );

    $pdo->commit();

    // Dispatch official invitation email when status is Approved (Accepted) or Sent
    $emailSent = false;
    $emailMsg = '';
    $customEmail = strtolower(trim((string)($_POST['email'] ?? '')));
    if ($customEmail !== '' && filter_var($customEmail, FILTER_VALIDATE_EMAIL)) {
        if ($customEmail !== strtolower((string)$inv['email'])) {
            $sid = (int)$inv['stakeholder_id'];
            $pdo->prepare("UPDATE stakeholders SET email = :email, updated_at = NOW() WHERE id = :sid")
                ->execute([':email' => $customEmail, ':sid' => $sid]);
            if (!empty($inv['stakeholder_user_id'])) {
                $pdo->prepare("UPDATE users SET email = :email, updated_at = NOW() WHERE id = :uid")
                    ->execute([':email' => $customEmail, ':uid' => (int)$inv['stakeholder_user_id']]);
            }
            $inv['email'] = $customEmail;
        }
    }
    if (in_array($status, ['Accepted', 'Sent'], true) && !empty($inv['email'])) {
        $mailResult = lphSendInvitationEmail($inv);
        $emailSent = !empty($mailResult['ok']);
        $emailMsg = $mailResult['message'] ?? '';
    }

    $displayStatus = match($status) {
        'Accepted' => 'Approved',
        'Declined' => 'Declined',
        'Cancelled' => 'Cancelled',
        default => $status
    };

    if ($status === 'Accepted') {
        if ($emailSent) {
            $responseMsg = "Invitation approved and official invitation email delivered to {$inv['email']}.";
        } elseif (!empty($inv['email'])) {
            $responseMsg = "Invitation approved. (Email notice: " . ($emailMsg ?: "Gmail SMTP not configured. Click Gmail Setup to configure") . ").";
        } else {
            $responseMsg = "Invitation approved. (No email address listed for {$inv['full_name']}).";
        }
    } else {
        if ($emailSent) {
            $responseMsg = "Invitation marked as {$displayStatus}. Official invitation email delivered to {$inv['email']}.";
        } elseif (!empty($emailMsg)) {
            $responseMsg = "Invitation marked as {$displayStatus}. (Email notice: {$emailMsg})";
        } else {
            $responseMsg = "Invitation marked as {$displayStatus}.";
        }
    }

    jsonResponse(true, $responseMsg, ['email_sent' => $emailSent, 'email' => $inv['email'] ?? '']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(false, APP_DEBUG ? $e->getMessage() : 'Unable to update invitation.');
}
