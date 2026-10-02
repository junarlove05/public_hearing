<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';
require_once __DIR__ . '/../../config/ai_config.php';
require_once __DIR__ . '/../../includes/AI/AIServiceFactory.php';

requireLogin();
if (!canManage()) {
    jsonResponse(false, 'Only authorized personnel can configure AI settings.');
}

$action = trim((string)($_GET['action'] ?? ($_POST['action'] ?? 'get')));
$pdo = db();

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `lph_settings` (
        `setting_key` VARCHAR(100) PRIMARY KEY,
        `setting_value` TEXT,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $t) {}

if ($action === 'get') {
    $geminiKey = '';
    $geminiModel = defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-1.5-flash';
    $provider = defined('AI_PROVIDER') ? AI_PROVIDER : 'gemini';

    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM lph_settings WHERE setting_key = 'gemini_api_key' LIMIT 1");
        $stmt->execute();
        $storedKey = $stmt->fetchColumn();
        if ($storedKey) $geminiKey = (string)$storedKey;

        $stmt = $pdo->prepare("SELECT setting_value FROM lph_settings WHERE setting_key = 'gemini_model' LIMIT 1");
        $stmt->execute();
        $storedModel = $stmt->fetchColumn();
        if ($storedModel) $geminiModel = (string)$storedModel;

        $stmt = $pdo->prepare("SELECT setting_value FROM lph_settings WHERE setting_key = 'ai_provider' LIMIT 1");
        $stmt->execute();
        $storedProvider = $stmt->fetchColumn();
        if ($storedProvider) $provider = (string)$storedProvider;
    } catch (Throwable $e) {}

    if (empty($geminiKey) && defined('GEMINI_API_KEY')) {
        $geminiKey = (string)GEMINI_API_KEY;
    }

    $maskedKey = '';
    if (!empty($geminiKey)) {
        $len = strlen($geminiKey);
        $maskedKey = $len > 8 ? substr($geminiKey, 0, 4) . str_repeat('•', max(4, $len - 8)) . substr($geminiKey, -4) : '••••••••';
    }

    jsonResponse(true, 'AI settings loaded.', [
        'ai_provider' => $provider,
        'gemini_model' => $geminiModel,
        'has_api_key' => !empty($geminiKey),
        'masked_api_key' => $maskedKey,
        'ollama_url' => defined('OLLAMA_BASE_URL') ? OLLAMA_BASE_URL : 'http://127.0.0.1:11434',
        'ollama_model' => defined('OLLAMA_MODEL') ? OLLAMA_MODEL : 'llama3.2:1b'
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}
requireCsrf();

if ($action === 'save') {
    $provider = trim((string)($_POST['ai_provider'] ?? 'gemini'));
    $apiKey = trim((string)($_POST['gemini_api_key'] ?? ''));
    $model = trim((string)($_POST['gemini_model'] ?? 'gemini-1.5-flash'));

    if (!in_array($provider, ['gemini', 'ollama'], true)) {
        $provider = 'gemini';
    }

    $upsert = $pdo->prepare("INSERT INTO lph_settings (setting_key, setting_value) 
        VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = :v2");

    $upsert->execute([':k' => 'ai_provider', ':v' => $provider, ':v2' => $provider]);
    $upsert->execute([':k' => 'gemini_model', ':v' => $model, ':v2' => $model]);

    if ($apiKey !== '' && !str_contains($apiKey, '•')) {
        $upsert->execute([':k' => 'gemini_api_key', ':v' => $apiKey, ':v2' => $apiKey]);
    }

    logActivity(currentUserId(), 'AI Settings Updated', "Updated AI configuration: provider={$provider}, model={$model}");

    jsonResponse(true, 'AI configuration saved successfully!');
}

if ($action === 'test') {
    try {
        $service = AIServiceFactory::make();
        $health = $service->healthCheck();
        jsonResponse($health['available'], $health['message'], $health);
    } catch (Throwable $e) {
        jsonResponse(false, 'AI service test error: ' . $e->getMessage());
    }
}

jsonResponse(false, 'Invalid action specified.');
