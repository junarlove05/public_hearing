<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';

requireLogin();
if (!canManage()) jsonResponse(false,'You do not have permission to approve registrations.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false,'Invalid request method.');
requireCsrf();

$pdo = db();
$id = (int)($_POST['id'] ?? 0);
$status = clean($_POST['status'] ?? '');
$reason = trim((string)($_POST['rejection_reason'] ?? ''));

if (!in_array($status,['Approved','Rejected','Cancelled','Pending'],true)) {
    jsonResponse(false,'Invalid registration status.');
}
if ($status==='Rejected' && $reason==='') jsonResponse(false,'A rejection reason is required.');

$stmt = $pdo->prepare(
    'SELECT r.*,s.full_name,h.title AS hearing_title,h.hearing_date
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
        $existingQr = $pdo->prepare('SELECT id FROM qr_codes WHERE registration_id=:rid LIMIT 1');
        $existingQr->execute([':rid'=>$id]);

        if (!$existingQr->fetch()) {
            $qr = lphUniqueCode($pdo,'qr_codes','code_value','QR');
            $expiresAt = !empty($r['hearing_date'])
                ? date('Y-m-d 23:59:59',strtotime($r['hearing_date']))
                : null;

            $pdo->prepare(
                'INSERT INTO qr_codes
                 (stakeholder_id,registration_id,hearing_id,code_value,created_at,status,expires_at,used_at)
                 VALUES (:sid,:rid,:hid,:code,NOW(),"Active",:expires,NULL)'
            )->execute([
                ':sid'=>$r['stakeholder_id'], ':rid'=>$id, ':hid'=>$r['hearing_id'],
                ':code'=>$qr, ':expires'=>$expiresAt
            ]);
        }
    }

    lphHistory($pdo,'registration',$id,'Status Change',$old,$status,
        "Registration for {$r['full_name']} changed from {$old} to {$status}.");
    logActivity(currentUserId(),'Update Registration',
        "Registration {$r['registration_code']} status {$old} -> {$status}.");

    $pdo->commit();
    jsonResponse(true,'Registration status updated.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(false, APP_DEBUG ? $e->getMessage() : 'Unable to update registration.');
}
