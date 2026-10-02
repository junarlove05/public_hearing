<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/AI/CEFAIAnalysisManager.php';

requireLogin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) jsonResponse(false, 'Invalid Citizen Portal submission id.');

jsonResponse(
    true,
    'Citizen Portal AI history loaded.',
    ['history'=>CEFAIAnalysisManager::getHistory($id)]
);
