<?php
/**
 * includes/AI/AIAnalysisManager.php
 * ------------------------------------------------------------------
 * The single entry point the rest of the app uses for AI sentiment
 * analysis. Handles:
 *   - calling the configured AI provider (via AIServiceFactory)
 *   - persisting results to feedback_ai_analysis
 *   - logging every request/response to ai_request_logs + a flat file
 *   - flagging negative feedback for review
 *   - graceful degradation if the AI tables/migration haven't been set up
 *
 * This class is the boundary between "core feedback business logic"
 * (modules/feedback/*) and "AI integration" (includes/AI/*) — feedback
 * submission and management work identically whether or not AI is
 * enabled, configured, or even installed.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/ai_config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../activity_log.php';
require_once __DIR__ . '/AIServiceFactory.php';

class AIAnalysisManager
{
    /**
     * Whether the AI feature's database tables exist. Cached per-request.
     * If false, every method below no-ops safely instead of throwing —
     * the app works fine without the optional migration having been run.
     */
    public static function tablesExist(): bool
    {
        static $cached = null;
        if ($cached !== null) return $cached;

        try {
            $stmt = db()->query("SHOW TABLES LIKE 'feedback_ai_analysis'");
            $cached = $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            $cached = false;
        }
        return $cached;
    }

    /**
     * Run AI analysis on a feedback entry and persist the result.
     * Always safe to call — never throws, and failures are recorded
     * (status='failed') rather than propagated, so a slow/unavailable AI
     * service never breaks the feedback submission flow that calls this.
     *
     * @return array The raw result from the AI service (see AIServiceInterface).
     */
    public static function analyzeAndStore(int $feedbackId, string $feedbackText): array
    {
        if (!AI_ENABLED) {
            return ['success' => false, 'error' => 'AI analysis is disabled in configuration.'];
        }
        if (!self::tablesExist()) {
            self::logToFile("Skipped analysis for feedback #$feedbackId: AI database tables not set up. Run database/migration_002_ai_sentiment_analysis.sql.");
            return ['success' => false, 'error' => 'AI database tables are not set up yet.'];
        }

        $pdo = db();

        // Mark as pending immediately so the UI can show "Analyzing..." if the
        // request is still in flight when the admin checks.
        self::upsertPending($feedbackId);

        $service = AIServiceFactory::make();
        $result = $service->analyzeFeedback($feedbackText);

        self::logRequest($feedbackId, $result);

        if ($result['success']) {
            self::storeSuccess($feedbackId, $result);
        } else {
            self::storeFailure($feedbackId, $result);
        }

        return $result;
    }

    /** Fetch the stored AI analysis for one feedback entry, or null if none exists. */
    public static function getAnalysisForFeedback(int $feedbackId): ?array
    {
        if (!self::tablesExist()) return null;

        $stmt = db()->prepare('SELECT * FROM feedback_ai_analysis WHERE feedback_id = :id');
        $stmt->execute([':id' => $feedbackId]);
        $row = $stmt->fetch();
        if (!$row) return null;

        $row['keywords'] = json_decode($row['keywords'] ?? '[]', true) ?: [];
        return $row;
    }

    /* ---------------------------------------------------------------
     * Internals
     * --------------------------------------------------------------- */

    private static function upsertPending(int $feedbackId): void
    {
        $pdo = db();
        $stmt = $pdo->prepare(
            'INSERT INTO feedback_ai_analysis (feedback_id, status, created_at)
             VALUES (:fid, \'pending\', NOW())
             ON DUPLICATE KEY UPDATE status = \'pending\', error_message = NULL'
        );
        $stmt->execute([':fid' => $feedbackId]);
    }

    private static function storeSuccess(int $feedbackId, array $result): void
    {
        $pdo = db();
        $flagged = ($result['sentiment'] === AI_NEGATIVE_SENTIMENT_VALUE) ? 1 : 0;

        $stmt = $pdo->prepare(
            'UPDATE feedback_ai_analysis SET
                sentiment = :sentiment,
                confidence_score = :confidence,
                summary = :summary,
                keywords = :keywords,
                recommended_category = :category,
                suggested_response = :response,
                flagged_for_review = :flagged,
                status = \'completed\',
                error_message = NULL,
                model_used = :model,
                raw_response = :raw,
                analyzed_at = NOW()
             WHERE feedback_id = :fid'
        );
        $stmt->execute([
            ':sentiment' => $result['sentiment'],
            ':confidence' => $result['confidence_score'],
            ':summary' => $result['summary'],
            ':keywords' => json_encode($result['keywords'], JSON_UNESCAPED_UNICODE),
            ':category' => $result['recommended_category'],
            ':response' => $result['suggested_response'],
            ':flagged' => $flagged,
            ':model' => $result['model_used'] ?? null,
            ':raw' => mb_substr($result['raw_response'] ?? '', 0, 60000), // guard against absurdly large payloads
            ':fid' => $feedbackId,
        ]);

        if ($flagged) {
            logActivity(null, 'AI Flag', "Feedback #$feedbackId flagged for review by AI (Negative sentiment, {$result['confidence_score']}% confidence).");
        }

        self::logToFile("Feedback #$feedbackId analyzed: {$result['sentiment']} ({$result['confidence_score']}%) in {$result['duration_ms']}ms.");
    }

    private static function storeFailure(int $feedbackId, array $result): void
    {
        $pdo = db();
        $stmt = $pdo->prepare(
            "UPDATE feedback_ai_analysis SET status = 'failed', error_message = :err, raw_response = :raw WHERE feedback_id = :fid"
        );
        $stmt->execute([
            ':err' => $result['error'] ?? 'Unknown error',
            ':raw' => mb_substr($result['raw_response'] ?? '', 0, 60000),
            ':fid' => $feedbackId,
        ]);

        self::logToFile("Feedback #$feedbackId analysis FAILED: " . ($result['error'] ?? 'unknown error'));
    }

    private static function logRequest(int $feedbackId, array $result): void
    {
        if (!self::tablesExist()) return; // ai_request_logs ships in the same migration

        try {
            $stmt = db()->prepare(
                'INSERT INTO ai_request_logs
                    (feedback_id, provider, model_used, response_payload, http_status, success, error_message, duration_ms, created_at)
                 VALUES (:fid, :provider, :model, :response, :status, :success, :error, :duration, NOW())'
            );
            $stmt->execute([
                ':fid' => $feedbackId,
                ':provider' => $result['provider'] ?? 'ollama',
                ':model' => $result['model_used'] ?? null,
                ':response' => mb_substr($result['raw_response'] ?? '', 0, 60000),
                ':status' => $result['http_status'] ?? null,
                ':success' => $result['success'] ? 1 : 0,
                ':error' => $result['error'] ?? null,
                ':duration' => $result['duration_ms'] ?? null,
            ]);
        } catch (Throwable $e) {
            error_log('AI request log insert failed: ' . $e->getMessage());
        }
    }

    /** Lightweight flat-file logger for quick `tail -f logs/ai.log` debugging. */
    private static function logToFile(string $line): void
    {
        try {
            $logDir = dirname(AI_LOG_FILE);
            if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
            @file_put_contents(AI_LOG_FILE, '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            // Never let logging itself break anything.
        }
    }
}
