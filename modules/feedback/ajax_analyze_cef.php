<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/ai_config.php';
require_once __DIR__ . '/../../includes/AI/CEFAIAnalysisManager.php';

requireLogin();

if (!canManage() && !hasPermission('lph.feedback.review')) {
    jsonResponse(false, 'You do not have permission to run Citizen Portal AI analysis.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

requireCsrf();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) jsonResponse(false, 'Invalid Citizen Portal submission id.');

$result = CEFAIAnalysisManager::analyzeAndStore($id);
$stored = CEFAIAnalysisManager::getAnalysis($id);

if (empty($result['success'])) {
    jsonResponse(
        false,
        'AI analysis failed: ' . (string)($result['error'] ?? 'Unknown error'),
        ['ai_analysis'=>$stored]
    );
}

logActivity(
    currentUserId(),
    'Citizen Portal AI Analysis',
    'Analyzed CEF submission #' . $id
);

$automatedMessage = $result['automated_message'] ?? ['status'=>'not_applicable','sent'=>false];
$successMessage = 'Citizen Portal sentiment and urgency analysis completed.';
if (!empty($automatedMessage['sent'])) {
    $successMessage .= ' An automated acknowledgement was sent to the citizen chat.';
} elseif (($automatedMessage['status'] ?? '') === 'already_sent') {
    $successMessage .= ' The citizen acknowledgement had already been sent.';
} elseif (($automatedMessage['status'] ?? '') === 'failed') {
    $successMessage .= ' The automated citizen acknowledgement could not be sent; the AI result was still saved.';
}

jsonResponse(
    true,
    $successMessage,
    ['ai_analysis'=>$stored,'automated_message'=>$automatedMessage]
);
