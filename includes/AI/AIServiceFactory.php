<?php
/**
 * includes/AI/AIServiceFactory.php
 * ------------------------------------------------------------------
 * Returns the currently-configured AI provider (see AI_PROVIDER in
 * config/ai_config.php). This is the ONLY place in the entire codebase
 * that knows which concrete class implements AIServiceInterface —
 * everything else (AIAnalysisManager, and everything above it) depends
 * only on the interface.
 *
 * TO ADD A NEW PROVIDER (e.g. OpenAI):
 *   1. Create includes/AI/OpenAIService.php implementing AIServiceInterface.
 *   2. Add a case for it below.
 *   3. Set AI_PROVIDER = 'openai' in config/ai_config.php.
 * No other file needs to change.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/AIServiceInterface.php';
require_once __DIR__ . '/OllamaService.php';

class AIServiceFactory
{
    private static ?AIServiceInterface $instance = null;

    public static function make(): AIServiceInterface
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        switch (AI_PROVIDER) {
            case 'ollama':
            default:
                self::$instance = new OllamaService();
                break;
            // case 'openai': self::$instance = new OpenAIService(); break;
            // case 'gemini': self::$instance = new GeminiService(); break;
        }

        return self::$instance;
    }
}
