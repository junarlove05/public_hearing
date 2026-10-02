<?php
declare(strict_types=1);

/**
 * config/ai_config.php
 * Ollama AI configuration for LPH / PHCMS.
 *
 * AI scope:
 * - Citizen feedback stored in the feedback table.
 * - Complaint-category feedback receives stronger urgency/risk weighting.
 *
 * AI results are advisory only.
 */

if (!defined('AI_ENABLED')) define('AI_ENABLED', true);
// Fetch settings from database if available
$dbAiSettings = [];
try {
    if (function_exists('db')) {
        $st = db()->query("SELECT setting_key, setting_value FROM lph_settings WHERE setting_key IN ('ai_provider', 'gemini_api_key', 'gemini_model')");
        if ($st) {
            while ($r = $st->fetch()) {
                $dbAiSettings[$r['setting_key']] = $r['setting_value'];
            }
        }
    }
} catch (Throwable $t) {}

if (!defined('AI_PROVIDER')) {
    $envProvider = getenv('AI_PROVIDER') ?: ($_ENV['AI_PROVIDER'] ?? ($dbAiSettings['ai_provider'] ?? 'gemini'));
    define('AI_PROVIDER', (string)$envProvider);
}

// ---- Google Gemini Settings ---------------------------------------
if (!defined('GEMINI_API_KEY')) {
    $envKey = getenv('GEMINI_API_KEY') ?: ($_ENV['GEMINI_API_KEY'] ?? ($dbAiSettings['gemini_api_key'] ?? ''));
    define('GEMINI_API_KEY', (string)$envKey);
}
if (!defined('GEMINI_MODEL')) {
    $envGeminiModel = getenv('GEMINI_MODEL') ?: ($_ENV['GEMINI_MODEL'] ?? ($dbAiSettings['gemini_model'] ?? 'gemini-1.5-flash'));
    define('GEMINI_MODEL', (string)$envGeminiModel);
}

// ---- Ollama Settings (Fallback / Offline) --------------------------
if (!defined('OLLAMA_BASE_URL')) {
    $envUrl = getenv('OLLAMA_BASE_URL') ?: ($_ENV['OLLAMA_BASE_URL'] ?? 'http://127.0.0.1:11434');
    define('OLLAMA_BASE_URL', rtrim((string)$envUrl, '/'));
}
if (!defined('OLLAMA_MODEL')) {
    $envModel = getenv('OLLAMA_MODEL') ?: ($_ENV['OLLAMA_MODEL'] ?? 'llama3.2:1b');
    define('OLLAMA_MODEL', (string)$envModel);
}
if (!defined('AI_CONNECT_TIMEOUT_SECONDS')) define('AI_CONNECT_TIMEOUT_SECONDS', 5);
if (!defined('AI_REQUEST_TIMEOUT_SECONDS')) define('AI_REQUEST_TIMEOUT_SECONDS', 120);
if (!defined('AI_MAX_RESPONSE_TOKENS')) define('AI_MAX_RESPONSE_TOKENS', 450);
if (!defined('AI_MODEL_KEEP_ALIVE_MINUTES')) define('AI_MODEL_KEEP_ALIVE_MINUTES', 30);

if (!defined('AI_NEGATIVE_SENTIMENT_VALUE')) define('AI_NEGATIVE_SENTIMENT_VALUE', 'Negative');
if (!defined('AI_FLAG_NEGATIVE_HIGH_URGENCY')) define('AI_FLAG_NEGATIVE_HIGH_URGENCY', true);
if (!defined('AI_LOG_FILE')) define('AI_LOG_FILE', __DIR__ . '/../logs/ai.log');
