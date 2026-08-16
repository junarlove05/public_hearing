<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';

requireLogin();

$canReview = canManage() || currentRole() === ROLE_COMMITTEE;

if (!$canReview) {
    jsonResponse(false, 'You do not have permission to review feedback.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}
requireCsrf();

$pdo = db();
$id = (int)($_POST['id'] ?? 0);
$status = clean($_POST['status'] ?? '');
$visibility = clean($_POST['visibility'] ?? 'Internal');
$replyText = trim((string)($_POST['reply_text'] ?? ''));

$statuses = ['New','Under Review','Validated','Responded','Rejected','Archived'];

if ($id <= 0 || !in_array($status,$statuses,true)) {
    jsonResponse(false,'Invalid feedback review.');
}
if (!in_array($visibility,['Public','Internal','Restricted'],true)) {
    jsonResponse(false,'Invalid visibility.');
}
if ($status==='Responded' && $replyText==='') {
    jsonResponse(false,'Enter the official response before marking feedback as Responded.');
}

$stmt = $pdo->prepare('SELECT * FROM feedback WHERE id=:id');
$stmt->execute([':id'=>$id]);
$f = $stmt->fetch();

if (!$f) jsonResponse(false,'Feedback not found.');

$validatedBy = $f['validated_by'];
$validatedAt = $f['validated_at'];
$repliedBy = $f['replied_by'] ?? null;
$repliedAt = $f['replied_at'] ?? null;

if ($status==='Validated' || $status==='Responded') {
    $validatedBy = $validatedBy ?: currentUserId();
    $validatedAt = $validatedAt ?: date('Y-m-d H:i:s');
}

if ($replyText !== '') {
    $repliedBy = currentUserId();
    $repliedAt = date('Y-m-d H:i:s');
}

try {
    $pdo->beginTransaction();

    $up = $pdo->prepare(
        'UPDATE feedback
         SET status=:status,visibility=:visibility,validated_by=:validated_by,
             validated_at=:validated_at,reply_text=:reply_text,
             replied_by=:replied_by,replied_at=:replied_at,updated_at=NOW()
         WHERE id=:id'
    );

    $up->execute([
        ':status'=>$status,
        ':visibility'=>$visibility,
        ':validated_by'=>$validatedBy,
        ':validated_at'=>$validatedAt,
        ':reply_text'=>$replyText ?: null,
        ':replied_by'=>$repliedBy,
        ':replied_at'=>$repliedAt,
        ':id'=>$id,
    ]);

    lphHistory(
        $pdo, 'feedback', $id, 'Review',
        $f['status'], $status,
        'Feedback reviewed. Visibility: '.$visibility.
        ($replyText !== '' ? ' Official response recorded.' : '')
    );

    logActivity(
        currentUserId(),
        'Review Feedback',
        "Feedback #{$id} status {$f['status']} -> {$status}."
    );

    $pdo->commit();

    jsonResponse(true,'Feedback review saved.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Feedback review error: '.$e->getMessage());
    jsonResponse(false,APP_DEBUG?$e->getMessage():'Unable to review feedback.');
}
