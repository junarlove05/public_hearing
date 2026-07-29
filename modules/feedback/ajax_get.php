<?php
/**
 * modules/feedback/ajax_get.php
 * ------------------------------------------------------------------
 * Returns a single feedback record with its category name, used to
 * populate the View / Reply modal.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/AI/AIAnalysisManager.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) jsonResponse(false, 'Invalid feedback id.');

$hasReplyColumns = feedbackTableHasReplyColumns();
$replyJoin = $hasReplyColumns ? 'LEFT JOIN users ru ON ru.id = f.replied_by' : '';
$replySelect = $hasReplyColumns ? ', ru.full_name AS replied_by_name' : '';

$stmt = db()->prepare(
    "SELECT f.*, fc.name AS category_name $replySelect
     FROM feedback f
     LEFT JOIN feedback_categories fc ON fc.id = f.category_id
     $replyJoin
     WHERE f.id = :id"
);
$stmt->execute([':id' => $id]);
$feedback = $stmt->fetch();

if (!$feedback) jsonResponse(false, 'Feedback not found.');

// Non-managers may only view their own feedback (matched by account email).
if (!canManage()) {
    $user = currentUser();
    if (!$user || strcasecmp($user['email'], $feedback['email']) !== 0) {
        jsonResponse(false, 'You do not have permission to view this feedback.');
    }
}

jsonResponse(true, '', [
    'feedback' => $feedback,
    'ai_analysis' => AIAnalysisManager::getAnalysisForFeedback($id),
]);
