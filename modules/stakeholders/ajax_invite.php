<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';

requireLogin();
if (!canManage()) jsonResponse(false, 'You do not have permission to create invitations.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$pdo = db();
$hearingId = (int)($_POST['hearing_id'] ?? 0);
$stakeholderIds = $_POST['stakeholder_ids'] ?? [];
$expiresAt = clean($_POST['expires_at'] ?? '') ?: null;
$remarks = trim((string)($_POST['remarks'] ?? ''));

if ($hearingId <= 0) jsonResponse(false, 'Select a hearing.');
if (!is_array($stakeholderIds)) $stakeholderIds = [$stakeholderIds];
$stakeholderIds = array_values(array_unique(array_filter(array_map('intval', $stakeholderIds))));
if (!$stakeholderIds) jsonResponse(false, 'Select at least one stakeholder.');

$hearingStmt = $pdo->prepare('SELECT id,title,status FROM hearings WHERE id=:id');
$hearingStmt->execute([':id'=>$hearingId]);
$hearing = $hearingStmt->fetch();
if (!$hearing) jsonResponse(false, 'Hearing not found.');
if (in_array($hearing['status'], ['Completed','Cancelled'], true)) {
    jsonResponse(false, 'Invitations cannot be created for a completed or cancelled hearing.');
}

$created = 0;
$skipped = 0;

try {
    $pdo->beginTransaction();

    foreach ($stakeholderIds as $sid) {
        $s = $pdo->prepare("SELECT id,full_name,status FROM stakeholders WHERE id=:id AND status<>'Inactive'");
        $s->execute([':id'=>$sid]);
        $stakeholder = $s->fetch();
        if (!$stakeholder) { $skipped++; continue; }

        $dup = $pdo->prepare(
            "SELECT id FROM invitations
             WHERE stakeholder_id=:sid AND hearing_id=:hid
               AND status IN ('Pending','Sent','Accepted')
             LIMIT 1"
        );
        $dup->execute([':sid'=>$sid, ':hid'=>$hearingId]);
        if ($dup->fetch()) { $skipped++; continue; }

        $code = lphUniqueCode($pdo, 'invitations', 'invitation_code', 'INV');

        $stmt = $pdo->prepare(
            'INSERT INTO invitations
             (stakeholder_id,hearing_id,status,invitation_code,sent_at,created_at,
              invited_by,responded_at,expires_at,remarks,updated_at)
             VALUES
             (:sid,:hid,"Pending",:code,NULL,NOW(),:user,NULL,:expires,:remarks,NOW())'
        );
        $stmt->execute([
            ':sid'=>$sid, ':hid'=>$hearingId, ':code'=>$code,
            ':user'=>currentUserId(), ':expires'=>$expiresAt, ':remarks'=>$remarks ?: null
        ]);
        $invitationId = (int)$pdo->lastInsertId();

        lphHistory($pdo, 'invitation', $invitationId, 'Create', null, 'Pending',
            'Invitation created for '.$stakeholder['full_name'].' to '.$hearing['title'].'.');
        $created++;
    }

    logActivity(
        currentUserId(),
        'Create Hearing Invitations',
        "Created {$created} invitation(s) for hearing {$hearing['title']}; {$skipped} skipped."
    );

    $pdo->commit();
    jsonResponse(true, "Created {$created} invitation(s). {$skipped} skipped because they were invalid or already invited.");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Invitation create error: '.$e->getMessage());
    jsonResponse(false, APP_DEBUG ? $e->getMessage() : 'Unable to create invitations.');
}
