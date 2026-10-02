<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';
require_once __DIR__ . '/../../includes/mailer.php';

requireLogin();
if (!canManage()) jsonResponse(false,'You do not have permission to approve registrations.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false,'Invalid request method.');
requireCsrf();

$pdo = db();
$id = (int)($_POST['id'] ?? 0);
$status = clean($_POST['status'] ?? '');
$reason = trim((string)($_POST['rejection_reason'] ?? ''));

$allowed = ['Approved','Rejected','Cancelled','Pending','Declined'];
if (!in_array($status, $allowed, true)) {
    jsonResponse(false,'Invalid registration status.');
}
if ($status==='Rejected' && $reason==='') jsonResponse(false,'A rejection reason is required.');

$stmt = $pdo->prepare(
    'SELECT r.*,
            s.full_name, s.email, s.organization,
            h.title AS hearing_title, h.venue, h.hearing_date, h.hearing_time, h.reference_number,
            (SELECT code_value FROM qr_codes q WHERE q.stakeholder_id = s.id LIMIT 1) AS qr_code
     FROM registrations r
     JOIN stakeholders s ON s.id=r.stakeholder_id
     LEFT JOIN hearings h ON h.id=r.hearing_id
     WHERE r.id=:id'
);
$stmt->execute([':id'=>$id]);
$r = $stmt->fetch();
if (!$r) jsonResponse(false,'Registration not found.');

if ($status === 'Approved') {
    $availability = lphRegistrationAvailability($pdo,(int)$r['hearing_id']);
    if (!$availability['ok'] && $r['registration_status'] !== 'Approved') {
        jsonResponse(false,$availability['message']);
    }
}

try {
    $pdo->beginTransaction();
    $old = $r['registration_status'];

    $approvedBy = $status==='Approved' ? currentUserId() : null;
    $approvedAt = $status==='Approved' ? date('Y-m-d H:i:s') : null;
    $rejectionReason = $status==='Rejected' ? $reason : null;

    $pdo->prepare(
        'UPDATE registrations
         SET registration_status=:status,approved_by=:approved_by,approved_at=:approved_at,
             rejection_reason=:reason,updated_at=NOW()
         WHERE id=:id'
    )->execute([
        ':status'=>$status, ':approved_by'=>$approvedBy, ':approved_at'=>$approvedAt,
        ':reason'=>$rejectionReason, ':id'=>$id
    ]);

    if ($status === 'Approved') {
        $stkCheck = $pdo->prepare('SELECT status FROM stakeholders WHERE id = :sid LIMIT 1');
        $stkCheck->execute([':sid' => $r['stakeholder_id']]);
        $stkStatus = $stkCheck->fetchColumn();

        if (in_array($stkStatus, ['Verified', 'Approved', 'Active'], true)) {
            $existingQr = $pdo->prepare('SELECT id FROM qr_codes WHERE stakeholder_id = :sid LIMIT 1');
            $existingQr->execute([':sid' => $r['stakeholder_id']]);
            if (!$existingQr->fetch()) {
                $qr = 'STK-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                $pdo->prepare(
                    'INSERT INTO qr_codes
                     (stakeholder_id, code_value, created_at, status)
                     VALUES (:sid, :code, NOW(), "Active")'
                )->execute([
                    ':sid'  => $r['stakeholder_id'],
                    ':code' => $qr
                ]);
            }
        }
    }

    // Two-way sync: keep invitations in sync with registration status
    $targetInvStatus = match($status) {
        'Approved' => 'Accepted',
        'Rejected', 'Declined' => 'Declined',
        'Cancelled' => 'Cancelled',
        default => 'Pending'
    };

    $invCheck = $pdo->prepare('SELECT id, status FROM invitations WHERE stakeholder_id = :sid AND hearing_id = :hid LIMIT 1');
    $invCheck->execute([':sid' => $r['stakeholder_id'], ':hid' => $r['hearing_id']]);
    $invRow = $invCheck->fetch();

    if ($invRow && $invRow['status'] !== $targetInvStatus) {
        $respAt = in_array($targetInvStatus, ['Accepted', 'Declined'], true) ? date('Y-m-d H:i:s') : null;
        $pdo->prepare(
            'UPDATE invitations SET status = :st, responded_at = COALESCE(:resp, responded_at), updated_at = NOW() WHERE id = :id'
        )->execute([
            ':st'   => $targetInvStatus,
            ':resp' => $respAt,
            ':id'   => $invRow['id']
        ]);
    }

    lphHistory($pdo,'registration',$id,'Status Change',$old,$status,
        "Registration for {$r['full_name']} changed from {$old} to {$status}.");
    logActivity(currentUserId(),'Update Registration',
        "Registration {$r['registration_code']} status {$old} -> {$status}.");

    $pdo->commit();

    $emailSent = false;
    $emailMsg = '';
    if ($status === 'Approved' && !empty($r['email'])) {
        $mailResult = lphSendInvitationEmail($r);
        $emailSent = !empty($mailResult['ok']);
        $emailMsg = $mailResult['message'] ?? '';
    }

    $msg = 'Registration status updated.';
    if ($status === 'Approved') {
        if ($emailSent) {
            $msg = "Registration approved and official invitation email delivered to {$r['email']}.";
        } elseif (!empty($r['email'])) {
            $msg = "Registration approved. (Email notice: " . ($emailMsg ?: 'Gmail SMTP not configured. Please configure in Invitations page') . ").";
        }
    }

    jsonResponse(true, $msg, ['email_sent' => $emailSent, 'email' => $r['email'] ?? '']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(false, APP_DEBUG ? $e->getMessage() : 'Unable to update registration.');
}
