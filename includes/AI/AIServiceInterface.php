<?php
/**
 * includes/AI/AIServiceInterface.php
 * ------------------------------------------------------------------
 * Contract every AI provider integration must implement. The rest of
 * the app (AIAnalysisManager and everything above it) only ever talks
 * to this interface — never to Ollama, OpenAI, or any other provider
 * directly. To add a new provider later, implement this interface in
 * a new class and register it in AIServiceFactory.php; nothing else
 * needs to change.
 * ------------------------------------------------------------------
 */

interface AIServiceInterface
{
    /**
     * Analyze a piece of feedback text and return structured sentiment
     * analysis results.
     *
     * @param string $text The feedback message to analyze.
     * @return array{
     *   success: bool,
     *   sentiment: ?string,           'Positive'|'Neutral'|'Negative'
     *   confidence_score: ?float,     0.0–100.0
     *   summary: ?string,             one-sentence summary
     *   keywords: string[],           extracted keywords
     *   recommended_category: ?string,
     *   suggested_response: ?string,  drafted response for staff
     *   raw_response: string,         raw provider response (for logging)
     *   error: ?string,               human-readable error if success=false
     *   http_status: ?int,
     *   duration_ms: int,
     *   model_used: ?string,
     *   provider: string
     * }
     */
    public function analyzeFeedback(string $text): array;

    /**
     * Lightweight health check — is the AI provider reachable and ready
     * right now? Used to show a clear status on the AI Analytics page
     * and to fail fast with a friendly message instead of waiting for a
     * full request to time out.
     *
     * @return array{available: bool, message: string}
     */
    public function healthCheck(): array;
}
