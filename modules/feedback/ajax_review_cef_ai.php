<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/AI/CEFAIAnalysisManager.php';

requireLogin();

if (!canManage() && !hasPermission('lph.feedback.review')) {
    jsonResponse(false, 'You do not have permission to review Citizen Portal AI analysis.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

requireCsrf();

$id = (int)($_POST['id'] ?? 0);
$decision = clean($_POST['decision'] ?? '');
$notes = trim((string)($_POST['notes'] ?? ''));

$overrideSentiment = clean($_POST['override_sentiment'] ?? '');
$overrideUrgency = clean($_POST['override_urgency'] ?? '');

if ($id <= 0) jsonResponse(false, 'Invalid Citizen Portal submission id.');

$result = CEFAIAnalysisManager::setReviewDecision(
    $id,
    $decision,
    $notes,
    currentUserId(),
    $overrideSentiment ?: null,
    $overrideUrgency ?: null
);

if (empty($result['success'])) {
    jsonResponse(false, (string)($result['error'] ?? 'Unable to save AI review.'));
}

jsonResponse(
    true,
    (string)($result['message'] ?? 'AI review saved.'),
    ['ai_analysis'=>$result['analysis'] ?? null]
);
