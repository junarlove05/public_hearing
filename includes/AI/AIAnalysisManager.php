<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/ai_config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../activity_log.php';
require_once __DIR__ . '/AIServiceFactory.php';

class AIAnalysisManager
{
    public static function tablesExist(): bool
    {
        static $cached = null;
        if ($cached !== null) return $cached;

        try {
            $ai = db()->query("SHOW TABLES LIKE 'feedback_ai_analysis'")->fetchColumn();
            $logs = db()->query("SHOW TABLES LIKE 'ai_request_logs'")->fetchColumn();
            $cached = !empty($ai) && !empty($logs);
        } catch (Throwable $e) {
            $cached = false;
        }

        return $cached;
    }

    /**
     * AI scope is intentionally limited to citizen/public feedback and
     * Complaint-category submissions.
     */
    public static function reviewFeaturesReady(): bool
    {
        static $cached = null;
        if ($cached !== null) return $cached;

        try {
            $history = db()->query("SHOW TABLES LIKE 'feedback_ai_analysis_history'")->fetchColumn();
            $reviewColumn = db()->query(
                "SHOW COLUMNS FROM feedback_ai_analysis LIKE 'review_status'"
            )->fetchColumn();
            $versionColumn = db()->query(
                "SHOW COLUMNS FROM feedback_ai_analysis LIKE 'analysis_version'"
            )->fetchColumn();

            $cached = !empty($history) && !empty($reviewColumn) && !empty($versionColumn);
        } catch (Throwable $e) {
            $cached = false;
        }

        return $cached;
    }

    public static function getHistory(int $feedbackId): array
    {
        if (!self::reviewFeaturesReady()) return [];

        try {
            $stmt = db()->prepare(
                "SELECT h.*,u.full_name reviewed_by_name,au.full_name archived_by_name
                 FROM feedback_ai_analysis_history h
                 LEFT JOIN users u ON u.id=h.reviewed_by
                 LEFT JOIN users au ON au.id=h.archived_by
                 WHERE h.feedback_id=:id
                 ORDER BY h.analysis_version DESC,h.archived_at DESC,h.id DESC"
            );
            $stmt->execute([':id' => $feedbackId]);
            $rows = $stmt->fetchAll();

            foreach ($rows as &$row) {
                $row['keywords'] = self::decodeJsonList($row['keywords'] ?? null);
                $row['risk_keywords'] = self::decodeJsonList($row['risk_keywords'] ?? null);
            }
            unset($row);

            return $rows;
        } catch (Throwable $e) {
            error_log('AI history read failed: ' . $e->getMessage());
            return [];
        }
    }

    public static function setReviewDecision(
        int $feedbackId,
        string $decision,
        string $notes,
        ?int $reviewedBy
    ): array {
        if (!self::reviewFeaturesReady()) {
            return [
                'success' => false,
                'error' => 'AI review/history migration has not been installed yet.',
            ];
        }

        $allowed = ['Pending','Accepted','Manual Review','Dismissed'];
        if (!in_array($decision, $allowed, true)) {
            return ['success' => false, 'error' => 'Invalid AI review decision.'];
        }

        try {
            $stmt = db()->prepare(
                "SELECT id,status,sentiment,urgency_level
                 FROM feedback_ai_analysis
                 WHERE feedback_id=:id
                 LIMIT 1"
            );
            $stmt->execute([':id' => $feedbackId]);
            $current = $stmt->fetch();

            if (!$current || ($current['status'] ?? '') !== 'completed') {
                return [
                    'success' => false,
                    'error' => 'Complete the AI analysis before reviewing its result.',
                ];
            }

            $update = db()->prepare(
                "UPDATE feedback_ai_analysis SET
                    review_status=:review_status,
                    review_notes=:review_notes,
                    reviewed_by=:reviewed_by,
                    reviewed_at=NOW()
                 WHERE feedback_id=:fid"
            );
            $update->execute([
                ':review_status' => $decision,
                ':review_notes' => $notes !== '' ? $notes : null,
                ':reviewed_by' => $reviewedBy,
                ':fid' => $feedbackId,
            ]);

            logActivity(
                $reviewedBy,
                'Review AI Analysis',
                "Feedback #{$feedbackId} AI result marked {$decision}. " .
                "Sentiment: " . (string)($current['sentiment'] ?? '-') .
                "; urgency: " . (string)($current['urgency_level'] ?? '-') . '.'
            );

            return [
                'success' => true,
                'message' => 'AI review decision saved.',
                'analysis' => self::getAnalysisForFeedback($feedbackId),
            ];
        } catch (Throwable $e) {
            error_log('AI review decision error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Unable to save the AI review decision.'];
        }
    }

    public static function getEligibility(int $feedbackId): array
    {
        try {
            $stmt = db()->prepare(
                "SELECT
                    f.id,
                    f.user_id,
                    f.stakeholder_id,
                    fc.name AS category_name,
                    r.name AS submitter_role
                 FROM feedback f
                 LEFT JOIN feedback_categories fc ON fc.id=f.category_id
                 LEFT JOIN users u ON u.id=f.user_id
                 LEFT JOIN roles r ON r.id=u.role_id
                 WHERE f.id=:id
                 LIMIT 1"
            );
            $stmt->execute([':id' => $feedbackId]);
            $row = $stmt->fetch();

            if (!$row) {
                return [
                    'eligible' => false,
                    'scope' => null,
                    'reason' => 'Feedback record was not found.',
                ];
            }

            $category = trim((string)($row['category_name'] ?? ''));
            $role = trim((string)($row['submitter_role'] ?? ''));

            $isComplaint = strcasecmp($category, 'Complaint') === 0;
            $isCitizenRole = in_array(
                $role,
                [
                    defined('ROLE_STAKEHOLDER') ? ROLE_STAKEHOLDER : 'Registered Stakeholder',
                    defined('ROLE_PUBLIC') ? ROLE_PUBLIC : 'Public User',
                ],
                true
            );

            /*
             * user_id NULL covers a genuinely public form submission.
             * stakeholder_id covers registered hearing stakeholders.
             */
            $isCitizenSubmission =
                empty($row['user_id'])
                || !empty($row['stakeholder_id'])
                || $isCitizenRole;

            if (!$isComplaint && !$isCitizenSubmission) {
                return [
                    'eligible' => false,
                    'scope' => null,
                    'reason' => 'AI analysis is limited to citizen feedback and Complaint submissions.',
                ];
            }

            return [
                'eligible' => true,
                'scope' => $isComplaint ? 'Complaint' : 'Citizen Feedback',
                'reason' => '',
            ];
        } catch (Throwable $e) {
            error_log('AI eligibility check failed: ' . $e->getMessage());

            return [
                'eligible' => false,
                'scope' => null,
                'reason' => 'Unable to verify whether this feedback is eligible for AI analysis.',
            ];
        }
    }

    /**
     * Queue a newly submitted eligible record without making the citizen
     * wait for Ollama. The actual analysis can be run from the Feedback
     * review modal or the AI Analytics "Analyze Next 3" control.
     */
    public static function queueIfEligible(int $feedbackId): array
    {
        if (!AI_ENABLED || !self::tablesExist()) {
            return [
                'queued' => false,
                'eligible' => false,
                'reason' => 'AI is disabled or the AI tables are not ready.',
            ];
        }

        $eligibility = self::getEligibility($feedbackId);

        if (empty($eligibility['eligible'])) {
            return [
                'queued' => false,
                'eligible' => false,
                'reason' => (string)($eligibility['reason'] ?? ''),
            ];
        }

        try {
            $stmt = db()->prepare(
                "INSERT INTO feedback_ai_analysis
                    (feedback_id,analysis_scope,status,created_at)
                 VALUES
                    (:fid,:scope,'pending',NOW())
                 ON DUPLICATE KEY UPDATE
                    analysis_scope=VALUES(analysis_scope)"
            );
            $stmt->execute([
                ':fid' => $feedbackId,
                ':scope' => $eligibility['scope'],
            ]);

            return [
                'queued' => true,
                'eligible' => true,
                'scope' => $eligibility['scope'],
                'reason' => '',
            ];
        } catch (Throwable $e) {
            error_log('AI queue insert failed: ' . $e->getMessage());

            return [
                'queued' => false,
                'eligible' => true,
                'scope' => $eligibility['scope'],
                'reason' => 'The feedback was saved, but it could not be added to the AI queue.',
            ];
        }
    }

    public static function analyzeAndStore(int $feedbackId, string $feedbackText = ''): array
    {
        if (!AI_ENABLED) {
            return ['success' => false, 'error' => 'AI analysis is disabled in configuration.'];
        }

        if (!self::tablesExist()) {
            self::logToFile(
                "Skipped feedback #{$feedbackId}: AI tables missing. " .
                "Run migration_006_lph_ai_sentiment_urgency_fixed.sql."
            );
            return ['success' => false, 'error' => 'AI database tables are not set up yet.'];
        }

        $eligibility = self::getEligibility($feedbackId);
        if (empty($eligibility['eligible'])) {
            return [
                'success' => false,
                'error' => (string)($eligibility['reason'] ?? 'This feedback is not eligible for AI analysis.'),
            ];
        }

        $context = self::loadFeedbackContext($feedbackId, $feedbackText);
        if (!$context) {
            return ['success' => false, 'error' => 'Feedback record was not found.'];
        }

        /*
         * Preserve the previous result before a re-analysis. This lets
         * staff compare earlier AI decisions instead of silently losing them.
         */
        if (self::reviewFeaturesReady()) {
            self::archiveCurrentAnalysis(
                $feedbackId,
                currentUserId() ?: null,
                'Re-analysis'
            );
        }

        self::upsertPending($feedbackId, $context['analysis_scope']);

        $result = AIServiceFactory::make()->analyzeFeedback($context['prompt_text']);
        self::logRequest($feedbackId, $context, $result);

        if (!empty($result['success'])) {
            self::storeSuccess($feedbackId, $context['analysis_scope'], $result);
        } else {
            self::storeFailure($feedbackId, $context['analysis_scope'], $result);
        }

        return $result;
    }

    public static function getAnalysisForFeedback(int $feedbackId): ?array
    {
        if (!self::tablesExist()) return null;

        try {
            $stmt = db()->prepare(
                'SELECT * FROM feedback_ai_analysis WHERE feedback_id = :id LIMIT 1'
            );
            $stmt->execute([':id' => $feedbackId]);
            $row = $stmt->fetch();

            if (!$row) return null;

            $row['keywords'] = self::decodeJsonList($row['keywords'] ?? null);
            $row['risk_keywords'] = self::decodeJsonList($row['risk_keywords'] ?? null);
            $row['flagged_for_review'] = (int)($row['flagged_for_review'] ?? 0);

            return $row;
        } catch (Throwable $e) {
            error_log('AI analysis read failed: ' . $e->getMessage());
            return null;
        }
    }

    private static function loadFeedbackContext(int $feedbackId, string $fallbackText): ?array
    {
        $stmt = db()->prepare(
            "SELECT f.subject,f.message,f.feedback_position,fc.name AS category_name
             FROM feedback f
             LEFT JOIN feedback_categories fc ON fc.id=f.category_id
             WHERE f.id=:id
             LIMIT 1"
        );
        $stmt->execute([':id' => $feedbackId]);
        $row = $stmt->fetch();

        if (!$row) return null;

        $message = trim((string)($row['message'] ?? ''));
        if ($message === '') $message = trim($fallbackText);

        $category = trim((string)($row['category_name'] ?? ''));
        $scope = strcasecmp($category, 'Complaint') === 0
            ? 'Complaint'
            : 'Citizen Feedback';

        $promptText =
            "Submission scope: {$scope}\n" .
            "Category: " . ($category !== '' ? $category : 'Uncategorized') . "\n" .
            "Citizen position: " . ((string)($row['feedback_position'] ?? 'Comment')) . "\n" .
            "Subject: " . ((string)($row['subject'] ?? '')) . "\n" .
            "Message: {$message}";

        return [
            'analysis_scope' => $scope,
            'category_name' => $category,
            'prompt_text' => $promptText,
        ];
    }

    private static function upsertPending(int $feedbackId, string $scope): void
    {
        if (self::reviewFeaturesReady()) {
            $stmt = db()->prepare(
                "INSERT INTO feedback_ai_analysis
                    (
                        feedback_id,analysis_scope,status,review_status,
                        analysis_version,created_at
                    )
                 VALUES
                    (:fid,:scope,'pending','Pending',1,NOW())
                 ON DUPLICATE KEY UPDATE
                    analysis_scope=VALUES(analysis_scope),
                    status='pending',
                    error_message=NULL,
                    review_status='Pending',
                    review_notes=NULL,
                    reviewed_by=NULL,
                    reviewed_at=NULL,
                    analysis_version=analysis_version+1"
            );
        } else {
            $stmt = db()->prepare(
                "INSERT INTO feedback_ai_analysis
                    (feedback_id,analysis_scope,status,created_at)
                 VALUES
                    (:fid,:scope,'pending',NOW())
                 ON DUPLICATE KEY UPDATE
                    analysis_scope=VALUES(analysis_scope),
                    status='pending',
                    error_message=NULL"
            );
        }

        $stmt->execute([':fid' => $feedbackId, ':scope' => $scope]);
    }

    private static function archiveCurrentAnalysis(
        int $feedbackId,
        ?int $archivedBy,
        string $reason
    ): void {
        if (!self::reviewFeaturesReady()) return;

        try {
            $stmt = db()->prepare(
                "SELECT *
                 FROM feedback_ai_analysis
                 WHERE feedback_id=:id
                 LIMIT 1"
            );
            $stmt->execute([':id' => $feedbackId]);
            $row = $stmt->fetch();

            if (!$row) return;

            /*
             * A never-analyzed queue row has no useful result to archive.
             */
            $hasResult =
                !empty($row['analyzed_at'])
                || in_array((string)($row['status'] ?? ''), ['completed','failed'], true);

            if (!$hasResult) return;

            $ins = db()->prepare(
                "INSERT INTO feedback_ai_analysis_history
                    (
                        feedback_id,analysis_id,analysis_version,
                        analysis_scope,sentiment,confidence_score,
                        urgency_level,urgency_score,keyword_score,
                        summary,keywords,risk_keywords,recommended_category,
                        suggested_response,flagged_for_review,
                        review_status,review_notes,reviewed_by,reviewed_at,
                        analysis_status,error_message,model_used,raw_response,
                        analyzed_at,archived_reason,archived_by,archived_at
                    )
                 VALUES
                    (
                        :feedback_id,:analysis_id,:analysis_version,
                        :analysis_scope,:sentiment,:confidence_score,
                        :urgency_level,:urgency_score,:keyword_score,
                        :summary,:keywords,:risk_keywords,:recommended_category,
                        :suggested_response,:flagged_for_review,
                        :review_status,:review_notes,:reviewed_by,:reviewed_at,
                        :analysis_status,:error_message,:model_used,:raw_response,
                        :analyzed_at,:archived_reason,:archived_by,NOW()
                    )"
            );

            $ins->execute([
                ':feedback_id' => $feedbackId,
                ':analysis_id' => $row['id'] ?? null,
                ':analysis_version' => (int)($row['analysis_version'] ?? 1),
                ':analysis_scope' => $row['analysis_scope'] ?? null,
                ':sentiment' => $row['sentiment'] ?? null,
                ':confidence_score' => $row['confidence_score'] ?? null,
                ':urgency_level' => $row['urgency_level'] ?? null,
                ':urgency_score' => $row['urgency_score'] ?? null,
                ':keyword_score' => $row['keyword_score'] ?? null,
                ':summary' => $row['summary'] ?? null,
                ':keywords' => $row['keywords'] ?? null,
                ':risk_keywords' => $row['risk_keywords'] ?? null,
                ':recommended_category' => $row['recommended_category'] ?? null,
                ':suggested_response' => $row['suggested_response'] ?? null,
                ':flagged_for_review' => (int)($row['flagged_for_review'] ?? 0),
                ':review_status' => $row['review_status'] ?? null,
                ':review_notes' => $row['review_notes'] ?? null,
                ':reviewed_by' => $row['reviewed_by'] ?? null,
                ':reviewed_at' => $row['reviewed_at'] ?? null,
                ':analysis_status' => $row['status'] ?? null,
                ':error_message' => $row['error_message'] ?? null,
                ':model_used' => $row['model_used'] ?? null,
                ':raw_response' => $row['raw_response'] ?? null,
                ':analyzed_at' => $row['analyzed_at'] ?? null,
                ':archived_reason' => $reason,
                ':archived_by' => $archivedBy,
            ]);
        } catch (Throwable $e) {
            /*
             * History is important, but a history write failure should not
             * make the existing public-feedback workflow unusable.
             */
            error_log('AI analysis history archive failed: ' . $e->getMessage());
        }
    }

    private static function storeSuccess(int $feedbackId, string $scope, array $result): void
    {
        $urgency = (string)($result['urgency_level'] ?? 'Low');
        $sentiment = (string)($result['sentiment'] ?? 'Neutral');

        $flagged = 0;
        if (
            AI_FLAG_NEGATIVE_HIGH_URGENCY
            && $sentiment === AI_NEGATIVE_SENTIMENT_VALUE
            && in_array($urgency, ['High', 'Critical'], true)
        ) {
            $flagged = 1;
        }
        if ($urgency === 'Critical') $flagged = 1;

        $stmt = db()->prepare(
            "UPDATE feedback_ai_analysis SET
                analysis_scope=:scope,
                sentiment=:sentiment,
                confidence_score=:confidence,
                urgency_level=:urgency_level,
                urgency_score=:urgency_score,
                keyword_score=:keyword_score,
                summary=:summary,
                keywords=:keywords,
                risk_keywords=:risk_keywords,
                recommended_category=:category,
                suggested_response=:response,
                flagged_for_review=:flagged,
                status='completed',
                error_message=NULL,
                model_used=:model,
                raw_response=:raw,
                analyzed_at=NOW()
             WHERE feedback_id=:fid"
        );

        $stmt->execute([
            ':scope' => $scope,
            ':sentiment' => $sentiment,
            ':confidence' => $result['confidence_score'] ?? null,
            ':urgency_level' => $urgency,
            ':urgency_score' => $result['urgency_score'] ?? null,
            ':keyword_score' => $result['keyword_score'] ?? null,
            ':summary' => $result['summary'] ?? null,
            ':keywords' => json_encode($result['keywords'] ?? [], JSON_UNESCAPED_UNICODE),
            ':risk_keywords' => json_encode($result['risk_keywords'] ?? [], JSON_UNESCAPED_UNICODE),
            ':category' => $result['recommended_category'] ?? null,
            ':response' => $result['suggested_response'] ?? null,
            ':flagged' => $flagged,
            ':model' => $result['model_used'] ?? null,
            ':raw' => mb_substr((string)($result['raw_response'] ?? ''), 0, 60000),
            ':fid' => $feedbackId,
        ]);

        if ($flagged) {
            logActivity(
                null,
                'AI Flag',
                "Feedback #{$feedbackId} flagged: {$sentiment} sentiment, {$urgency} urgency."
            );
        }

        self::logToFile(
            "Feedback #{$feedbackId}: {$scope}; {$sentiment}; urgency {$urgency}; " .
            (string)($result['duration_ms'] ?? 0) . 'ms.'
        );
    }

    private static function storeFailure(int $feedbackId, string $scope, array $result): void
    {
        $stmt = db()->prepare(
            "UPDATE feedback_ai_analysis SET
                analysis_scope=:scope,
                status='failed',
                error_message=:error,
                raw_response=:raw
             WHERE feedback_id=:fid"
        );

        $stmt->execute([
            ':scope' => $scope,
            ':error' => (string)($result['error'] ?? 'Unknown AI error'),
            ':raw' => mb_substr((string)($result['raw_response'] ?? ''), 0, 60000),
            ':fid' => $feedbackId,
        ]);

        self::logToFile(
            "Feedback #{$feedbackId} analysis failed: " .
            (string)($result['error'] ?? 'unknown error')
        );
    }

    private static function logRequest(int $feedbackId, array $context, array $result): void
    {
        try {
            $stmt = db()->prepare(
                "INSERT INTO ai_request_logs
                    (feedback_id,provider,model_used,request_payload,response_payload,
                     http_status,success,error_message,duration_ms,created_at)
                 VALUES
                    (:fid,:provider,:model,:request_payload,:response_payload,
                     :http_status,:success,:error_message,:duration_ms,NOW())"
            );

            $requestPayload = json_encode([
                'analysis_scope' => $context['analysis_scope'],
                'category_name' => $context['category_name'],
                'text' => $context['prompt_text'],
            ], JSON_UNESCAPED_UNICODE);

            $stmt->execute([
                ':fid' => $feedbackId,
                ':provider' => (string)($result['provider'] ?? AI_PROVIDER),
                ':model' => $result['model_used'] ?? OLLAMA_MODEL,
                ':request_payload' => mb_substr((string)$requestPayload, 0, 60000),
                ':response_payload' => mb_substr((string)($result['raw_response'] ?? ''), 0, 60000),
                ':http_status' => $result['http_status'] ?? null,
                ':success' => !empty($result['success']) ? 1 : 0,
                ':error_message' => $result['error'] ?? null,
                ':duration_ms' => $result['duration_ms'] ?? null,
            ]);
        } catch (Throwable $e) {
            error_log('AI request log insert failed: ' . $e->getMessage());
        }
    }

    private static function decodeJsonList(mixed $value): array
    {
        if (is_array($value)) return $value;
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private static function logToFile(string $line): void
    {
        try {
            $dir = dirname(AI_LOG_FILE);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);

            @file_put_contents(
                AI_LOG_FILE,
                '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        } catch (Throwable $e) {
        }
    }
}
