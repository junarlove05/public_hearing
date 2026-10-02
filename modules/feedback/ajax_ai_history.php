<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/ai_config.php';
require_once __DIR__ . '/../../includes/AI/AIAnalysisManager.php';

requireLogin();

if (!canManage() && !hasPermission('lph.feedback.review')) {
    jsonResponse(false, 'You do not have permission to view AI history.');
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    jsonResponse(false, 'Invalid feedback id.');
}

if (!AIAnalysisManager::reviewFeaturesReady()) {
    jsonResponse(
        false,
        'AI review/history migration has not been installed yet.'
    );
}

jsonResponse(
    true,
    'AI history loaded.',
    ['history' => AIAnalysisManager::getHistory($id)]
);
