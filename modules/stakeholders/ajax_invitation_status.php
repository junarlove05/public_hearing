<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';

requireLogin();
if (!canManage()) jsonResponse(false, 'You do not have permission to update invitations.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$pdo = db();
$id = (int)($_POST['id'] ?? 0);
$status = clean($_POST['status'] ?? '');
$allowed = ['Pending','Sent','Accepted','Declined','Expired','Cancelled'];

if ($id <= 0 || !in_array($status,$allowed,true)) jsonResponse(false,'Invalid invitation update.');

$stmt = $pdo->prepare(
    'SELECT i.*,s.full_name,h.title AS hearing_title
     FROM invitations i
     JOIN stakeholders s ON s.id=i.stakeholder_id
     LEFT JOIN hearings h ON h.id=i.hearing_id
     WHERE i.id=:id'
);
$stmt->execute([':id'=>$id]);
$inv = $stmt->fetch();
if (!$inv) jsonResponse(false,'Invitation not found.');

$old = $inv['status'];
$sentAt = $inv['sent_at'];
$respondedAt = $inv['responded_at'];

if ($status === 'Sent' && empty($sentAt)) $sentAt = date('Y-m-d H:i:s');
if (in_array($status,['Accepted','Declined'],true)) $respondedAt = date('Y-m-d H:i:s');

try {
    $pdo->beginTransaction();
    $up = $pdo->prepare(
        'UPDATE invitations
         SET status=:status,sent_at=:sent_at,responded_at=:responded_at,updated_at=NOW()
         WHERE id=:id'
    );
    $up->execute([
        ':status'=>$status, ':sent_at'=>$sentAt, ':responded_at'=>$respondedAt, ':id'=>$id
    ]);

    lphHistory($pdo,'invitation',$id,'Status Change',$old,$status,
        "Invitation for {$inv['full_name']} changed from {$old} to {$status}.");
    logActivity(currentUserId(),'Update Invitation',
        "Invitation {$inv['invitation_code']} status {$old} -> {$status}.");

    $pdo->commit();
    jsonResponse(true,'Invitation status updated.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(false, APP_DEBUG ? $e->getMessage() : 'Unable to update invitation.');
}
