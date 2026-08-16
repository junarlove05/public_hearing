<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}
requireCsrf();

$pdo = db();

$hearingId = (int)($_POST['hearing_id'] ?? 0) ?: null;
$legislativeItemId = (int)($_POST['legislative_item_id'] ?? 0) ?: null;
$name = clean($_POST['name'] ?? '');
$email = strtolower(clean($_POST['email'] ?? ''));
$categoryId = (int)($_POST['category_id'] ?? 0) ?: null;
$subject = clean($_POST['subject'] ?? '');
$message = trim((string)($_POST['message'] ?? ''));
$position = clean($_POST['feedback_position'] ?? 'Comment');
$isAnonymous = isset($_POST['is_anonymous']) ? 1 : 0;
$requestedVisibility = clean($_POST['visibility'] ?? 'Internal');

$manager = canManage() || currentRole() === ROLE_COMMITTEE;
$visibility = $manager && in_array($requestedVisibility, ['Public','Internal','Restricted'], true)
    ? $requestedVisibility
    : 'Internal';

$errors = [];

if ($name === '') $errors[] = 'Name is required.';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'A valid email address is required.';
}
if ($message === '') $errors[] = 'Feedback message is required.';
if (!in_array($position, ['Support','Oppose','Neutral','Comment'], true)) {
    $errors[] = 'Invalid feedback position.';
}

if ($hearingId) {
    $q = $pdo->prepare('SELECT COUNT(*) FROM hearings WHERE id=:id');
    $q->execute([':id'=>$hearingId]);
    if ((int)$q->fetchColumn()===0) $errors[]='Selected hearing does not exist.';
}

if ($legislativeItemId) {
    $q = $pdo->prepare('SELECT COUNT(*) FROM legislative_items WHERE id=:id AND deleted_at IS NULL');
    $q->execute([':id'=>$legislativeItemId]);
    if ((int)$q->fetchColumn()===0) $errors[]='Selected legislative item does not exist.';
}

if ($categoryId) {
    $q = $pdo->prepare('SELECT COUNT(*) FROM feedback_categories WHERE id=:id');
    $q->execute([':id'=>$categoryId]);
    if ((int)$q->fetchColumn()===0) $errors[]='Selected feedback category does not exist.';
}

if ($errors) jsonResponse(false, implode(' ', $errors));

$stakeholderId = null;
$stakeholder = $pdo->prepare('SELECT id FROM stakeholders WHERE LOWER(email)=LOWER(:email) LIMIT 1');
$stakeholder->execute([':email'=>$email]);
if ($row = $stakeholder->fetch()) $stakeholderId = (int)$row['id'];

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'INSERT INTO feedback
         (hearing_id,legislative_item_id,stakeholder_id,user_id,name,email,category_id,
          subject,message,status,submitted_at,feedback_position,is_anonymous,visibility,
          validated_by,validated_at,updated_at)
         VALUES
         (:hearing,:item,:stakeholder,:user,:name,:email,:category,:subject,:message,
          "New",NOW(),:position,:anonymous,:visibility,NULL,NULL,NOW())'
    );

    $stmt->execute([
        ':hearing'=>$hearingId,
        ':item'=>$legislativeItemId,
        ':stakeholder'=>$stakeholderId,
        ':user'=>currentUserId(),
        ':name'=>$name,
        ':email'=>$email,
        ':category'=>$categoryId,
        ':subject'=>$subject ?: null,
        ':message'=>$message,
        ':position'=>$position,
        ':anonymous'=>$isAnonymous,
        ':visibility'=>$visibility,
    ]);

    $id = (int)$pdo->lastInsertId();

    lphHistory(
        $pdo, 'feedback', $id, 'Create', null, 'New',
        'Feedback submitted through the Public Feedback module.'
    );

    logActivity(
        currentUserId(),
        'Submit Feedback',
        'Submitted feedback #' . $id . ($hearingId ? " for hearing #{$hearingId}" : '')
    );

    $pdo->commit();

    jsonResponse(
        true,
        'Feedback submitted successfully. It is now available for review.',
        ['id'=>$id]
    );
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Feedback submit error: '.$e->getMessage());

    jsonResponse(
        false,
        APP_DEBUG ? $e->getMessage() : 'Unable to submit feedback.'
    );
}
