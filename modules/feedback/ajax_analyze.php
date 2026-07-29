<?php
/**
 * modules/feedback/ajax_analyze.php
 * ------------------------------------------------------------------
 * Manually triggers (or re-runs) AI sentiment analysis for a single
 * feedback entry. Used by the "Analyze Now" / "Re-analyze" buttons in
 * the view/reply modal — useful when the automatic analysis at
 * submission time failed (Ollama wasn't running yet, timed out, etc.)
 * or when staff want to regenerate the AI's suggestions.
 *
 * This is a synchronous request (no fastcgi_finish_request() trick
 * here, since it's an explicit admin action where seeing a brief
 * loading state is expected and fine) — it may take several seconds
 * to tens of seconds depending on local hardware.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/ai_config.php';
require_once __DIR__ . '/../../includes/AI/AIAnalysisManager.php';

if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) jsonResponse(false, 'Invalid feedback id.');

if (!AI_ENABLED) {
    jsonResponse(false, 'AI analysis is currently disabled in system configuration.');
}
if (!AIAnalysisManager::tablesExist()) {
    jsonResponse(false, 'AI database tables are not set up yet. Run database/migration_002_ai_sentiment_analysis.sql first.');
}

$stmt = db()->prepare('SELECT message FROM feedback WHERE id = :id');
$stmt->execute([':id' => $id]);
$feedback = $stmt->fetch();
if (!$feedback) jsonResponse(false, 'Feedback not found.');

$result = AIAnalysisManager::analyzeAndStore($id, $feedback['message']);

if (!$result['success']) {
    jsonResponse(false, 'AI analysis failed: ' . ($result['error'] ?? 'unknown error'), [
        'ai_analysis' => AIAnalysisManager::getAnalysisForFeedback($id),
    ]);
}

logActivity(currentUserId(), 'Update', "Manually triggered AI analysis for feedback #$id");

jsonResponse(true, 'AI analysis completed successfully.', [
    'ai_analysis' => AIAnalysisManager::getAnalysisForFeedback($id),
]);
