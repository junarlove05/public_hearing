<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/ai_config.php';
require_once __DIR__ . '/../../includes/AI/AIAnalysisManager.php';

requireLogin();

if (!canManage() && !hasPermission('lph.feedback.review')) {
    jsonResponse(false, 'You do not have permission to run AI analysis.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

requireCsrf();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) jsonResponse(false, 'Invalid feedback id.');

if (!AI_ENABLED) {
    jsonResponse(false, 'AI analysis is currently disabled.');
}

if (!AIAnalysisManager::tablesExist()) {
    jsonResponse(
        false,
        'AI tables are not ready. Run migration_006_lph_ai_sentiment_urgency_fixed.sql first.'
    );
}

$stmt = db()->prepare(
    "SELECT f.id,f.message,fc.name AS category_name
     FROM feedback f
     LEFT JOIN feedback_categories fc ON fc.id=f.category_id
     WHERE f.id=:id
     LIMIT 1"
);
$stmt->execute([':id' => $id]);
$feedback = $stmt->fetch();

if (!$feedback) jsonResponse(false, 'Feedback not found.');

$result = AIAnalysisManager::analyzeAndStore($id, (string)$feedback['message']);
$stored = AIAnalysisManager::getAnalysisForFeedback($id);

if (empty($result['success'])) {
    jsonResponse(
        false,
        'AI analysis failed: ' . (string)($result['error'] ?? 'Unknown error'),
        ['ai_analysis' => $stored]
    );
}

logActivity(
    currentUserId(),
    'AI Feedback Analysis',
    'Analyzed feedback #' . $id . ' (' . (string)($feedback['category_name'] ?? 'Uncategorized') . ')'
);

jsonResponse(
    true,
    'AI sentiment and urgency analysis completed.',
    ['ai_analysis' => $stored]
);
