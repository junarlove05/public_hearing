<?php
declare(strict_types=1);

/**
 * includes/AI/CEFAIAnalysisManager.php
 * ------------------------------------------------------------------
 * Ollama AI analysis manager for Citizen Portal / CEPFMS citizen
 * Feedback and Complaint records stored in cef_submissions.
 *
 * Proposals are intentionally outside this AI sentiment scope.
 * Official CEPFMS/LPH statuses are never changed automatically.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/ai_config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../activity_log.php';
require_once __DIR__ . '/AIServiceFactory.php';

class CEFAIAnalysisManager
{
    private const AUTOMATED_ACK_SENDER = 'LPH AI Automated Acknowledgement';
    private const AUTOMATED_ACK_MESSAGE = 'This is an automated message: Your complaint has been received. A staff member will contact you soon.';

    public static function tablesExist(): bool
    {
        static $cached = null;
        if ($cached !== null) return $cached;

        try {
            $analysis = db()->query("SHOW TABLES LIKE 'lph_cef_ai_analysis'")->fetchColumn();
            $history = db()->query("SHOW TABLES LIKE 'lph_cef_ai_analysis_history'")->fetchColumn();
            $cached = !empty($analysis) && !empty($history);
        } catch (Throwable $e) {
            $cached = false;
        }

        return $cached;
    }

    public static function isEligible(int $submissionId): array
    {
        try {
            $stmt = db()->prepare(
                "SELECT id,submission_type,source_channel,status,deleted_at
                 FROM cef_submissions
                 WHERE id=:id
                 LIMIT 1"
            );
            $stmt->execute([':id' => $submissionId]);
            $row = $stmt->fetch();

            if (!$row) {
                return ['eligible'=>false,'scope'=>null,'reason'=>'Citizen Portal submission was not found.'];
            }

            if (!empty($row['deleted_at'])) {
                return ['eligible'=>false,'scope'=>null,'reason'=>'Deleted submissions are not analyzed.'];
            }

            $type = (string)($row['submission_type'] ?? '');
            if (!in_array($type, ['Feedback','Complaint'], true)) {
                return [
                    'eligible'=>false,
                    'scope'=>null,
                    'reason'=>'AI sentiment analysis is limited to citizen Feedback and Complaint records.'
                ];
            }

            return [
                'eligible'=>true,
                'scope'=>$type === 'Complaint' ? 'Complaint' : 'Citizen Feedback',
                'reason'=>'',
            ];
        } catch (Throwable $e) {
            error_log('CEF AI eligibility failed: ' . $e->getMessage());
            return ['eligible'=>false,'scope'=>null,'reason'=>'Unable to verify citizen submission eligibility.'];
        }
    }

    public static function queueIfEligible(int $submissionId): array
    {
        if (!AI_ENABLED || !self::tablesExist()) {
            return ['queued'=>false,'eligible'=>false,'reason'=>'CEF AI tables are not ready.'];
        }

        $eligibility = self::isEligible($submissionId);
        if (empty($eligibility['eligible'])) {
            return [
                'queued'=>false,
                'eligible'=>false,
                'reason'=>(string)($eligibility['reason'] ?? '')
            ];
        }

        try {
            $stmt = db()->prepare(
                "INSERT INTO lph_cef_ai_analysis
                    (submission_id,analysis_scope,status,review_status,analysis_version,created_at)
                 VALUES
                    (:submission_id,:scope,'pending','Pending',1,NOW())
                 ON DUPLICATE KEY UPDATE
                    analysis_scope=VALUES(analysis_scope)"
            );
            $stmt->execute([
                ':submission_id'=>$submissionId,
                ':scope'=>$eligibility['scope'],
            ]);

            return [
                'queued'=>true,
                'eligible'=>true,
                'scope'=>$eligibility['scope'],
                'reason'=>'',
            ];
        } catch (Throwable $e) {
            error_log('CEF AI queue failed: ' . $e->getMessage());
            return [
                'queued'=>false,
                'eligible'=>true,
                'scope'=>$eligibility['scope'],
                'reason'=>'Citizen submission exists, but could not be added to the AI queue.',
            ];
        }
    }

    public static function analyzeAndStore(int $submissionId): array
    {
        if (!AI_ENABLED) {
            return ['success'=>false,'error'=>'AI analysis is disabled in configuration.'];
        }

        if (!self::tablesExist()) {
            return [
                'success'=>false,
                'error'=>'Citizen Portal AI tables are not set up. Run migration_009_lph_cef_sentiment_bridge_fixed.sql.'
            ];
        }

        $eligibility = self::isEligible($submissionId);
        if (empty($eligibility['eligible'])) {
            return ['success'=>false,'error'=>(string)($eligibility['reason'] ?? 'Submission is not eligible.')];
        }

        $context = self::loadContext($submissionId);
        if (!$context) {
            return ['success'=>false,'error'=>'Citizen Portal submission was not found.'];
        }

        self::archiveCurrentAnalysis($submissionId, currentUserId() ?: null, 'Re-analysis');
        self::upsertPending($submissionId, (string)$eligibility['scope']);

        $result = AIServiceFactory::make()->analyzeFeedback($context['prompt_text']);
        self::logRequest($submissionId, $context, $result);

        if (!empty($result['success'])) {
            self::storeSuccess($submissionId, (string)$eligibility['scope'], $result);
            $result['automated_message'] = self::sendAutomatedComplaintAcknowledgement(
                $submissionId,
                $context
            );
        } else {
            self::storeFailure($submissionId, (string)$eligibility['scope'], $result);
        }

        return $result;
    }

    public static function getAnalysis(int $submissionId): ?array
    {
        if (!self::tablesExist()) return null;

        try {
            $stmt = db()->prepare(
                "SELECT ai.*,u.full_name reviewed_by_name
                 FROM lph_cef_ai_analysis ai
                 LEFT JOIN users u ON u.id=ai.reviewed_by
                 WHERE ai.submission_id=:id
                 LIMIT 1"
            );
            $stmt->execute([':id'=>$submissionId]);
            $row = $stmt->fetch();
            if (!$row) return null;

            $row['keywords'] = self::decodeJsonList($row['keywords'] ?? null);
            $row['risk_keywords'] = self::decodeJsonList($row['risk_keywords'] ?? null);
            $row['flagged_for_review'] = (int)($row['flagged_for_review'] ?? 0);
            return $row;
        } catch (Throwable $e) {
            error_log('CEF AI result read failed: ' . $e->getMessage());
            return null;
        }
    }

    public static function getHistory(int $submissionId): array
    {
        if (!self::tablesExist()) return [];

        try {
            $stmt = db()->prepare(
                "SELECT h.*,u.full_name reviewed_by_name,au.full_name archived_by_name
                 FROM lph_cef_ai_analysis_history h
                 LEFT JOIN users u ON u.id=h.reviewed_by
                 LEFT JOIN users au ON au.id=h.archived_by
                 WHERE h.submission_id=:id
                 ORDER BY h.analysis_version DESC,h.archived_at DESC,h.id DESC"
            );
            $stmt->execute([':id'=>$submissionId]);
            $rows = $stmt->fetchAll();

            foreach ($rows as &$row) {
                $row['keywords'] = self::decodeJsonList($row['keywords'] ?? null);
                $row['risk_keywords'] = self::decodeJsonList($row['risk_keywords'] ?? null);
            }
            unset($row);

            return $rows;
        } catch (Throwable $e) {
            error_log('CEF AI history read failed: ' . $e->getMessage());
            return [];
        }
    }

    public static function setReviewDecision(
        int $submissionId,
        string $decision,
        string $notes,
        ?int $reviewedBy,
        ?string $overrideSentiment = null,
        ?string $overrideUrgency = null
    ): array {
        if (!self::tablesExist()) {
            return ['success'=>false,'error'=>'Citizen Portal AI tables are not ready.'];
        }

        $allowed = ['Pending','Accepted','Manual Review','Corrected','Dismissed'];
        if (!in_array($decision, $allowed, true)) {
            return ['success'=>false,'error'=>'Invalid AI review decision.'];
        }

        try {
            $stmt = db()->prepare(
                "SELECT status,sentiment,urgency_level
                 FROM lph_cef_ai_analysis
                 WHERE submission_id=:id
                 LIMIT 1"
            );
            $stmt->execute([':id'=>$submissionId]);
            $current = $stmt->fetch();

            if (!$current || ($current['status'] ?? '') !== 'completed') {
                return ['success'=>false,'error'=>'Analyze this citizen submission before reviewing the AI result.'];
            }

            $finalSentiment = $current['sentiment'];
            if ($overrideSentiment && in_array($overrideSentiment, ['Positive','Neutral','Negative'], true)) {
                $finalSentiment = $overrideSentiment;
            }

            $finalUrgency = $current['urgency_level'];
            if ($overrideUrgency && in_array($overrideUrgency, ['Low','Medium','High','Critical'], true)) {
                $finalUrgency = $overrideUrgency;
            }

            if (($finalSentiment !== $current['sentiment'] || $finalUrgency !== $current['urgency_level']) && $decision === 'Accepted') {
                $decision = 'Corrected';
            }

            $update = db()->prepare(
                "UPDATE lph_cef_ai_analysis SET
                    sentiment=:sentiment,
                    urgency_level=:urgency,
                    review_status=:review_status,
                    review_notes=:review_notes,
                    reviewed_by=:reviewed_by,
                    reviewed_at=NOW()
                 WHERE submission_id=:submission_id"
            );
            $update->execute([
                ':sentiment'=>$finalSentiment,
                ':urgency'=>$finalUrgency,
                ':review_status'=>$decision,
                ':review_notes'=>$notes !== '' ? $notes : null,
                ':reviewed_by'=>$reviewedBy,
                ':submission_id'=>$submissionId,
            ]);

            logActivity(
                $reviewedBy,
                'Review Citizen Portal AI Analysis',
                "CEF submission #{$submissionId} AI result verified as {$decision}. " .
                'Sentiment: ' . (string)$finalSentiment .
                '; urgency: ' . (string)$finalUrgency . '.'
            );

            return [
                'success'=>true,
                'message'=>'Citizen Portal AI review decision saved.',
                'analysis'=>self::getAnalysis($submissionId),
            ];
        } catch (Throwable $e) {
            error_log('CEF AI review failed: ' . $e->getMessage());
            return ['success'=>false,'error'=>'Unable to save the Citizen Portal AI review decision.'];
        }
    }

    private static function loadContext(int $submissionId): ?array
    {
        $stmt = db()->prepare(
            "SELECT
                s.id,
                s.reference_number,
                s.submission_type,
                s.citizen_name,
                s.title,
                s.summary,
                s.details,
                s.priority_level,
                s.source_channel,
                s.status,
                s.moderation_status,
                c.name category_name,
                cf.feedback_kind,
                cf.service_area,
                cf.desired_outcome,
                cf.citizen_rating,
                cc.affected_service,
                cc.incident_datetime,
                cc.urgency_level citizen_reported_urgency
             FROM cef_submissions s
             LEFT JOIN cef_categories c ON c.id=s.category_id
             LEFT JOIN cef_feedback_submissions cf ON cf.submission_id=s.id
             LEFT JOIN cef_complaints cc ON cc.submission_id=s.id
             WHERE s.id=:id
               AND s.deleted_at IS NULL
               AND s.submission_type IN ('Feedback','Complaint')
             LIMIT 1"
        );
        $stmt->execute([':id'=>$submissionId]);
        $row = $stmt->fetch();
        if (!$row) return null;

        $scope = ($row['submission_type'] ?? '') === 'Complaint'
            ? 'Complaint'
            : 'Citizen Feedback';

        $lines = [
            'Source system: Citizen Portal / CEPFMS',
            'Source channel: ' . ((string)($row['source_channel'] ?? 'Citizen Portal')),
            'Reference: ' . ((string)($row['reference_number'] ?? '')),
            'Submission scope: ' . $scope,
            'Submission type: ' . ((string)($row['submission_type'] ?? '')),
            'Category: ' . ((string)($row['category_name'] ?? 'Uncategorized')),
            'Subject: ' . ((string)($row['title'] ?? '')),
            'Summary supplied by citizen: ' . ((string)($row['summary'] ?? '')),
            'Message/details: ' . ((string)($row['details'] ?? '')),
            'Portal priority selected by citizen: ' . ((string)($row['priority_level'] ?? 'Normal')),
        ];

        if ($scope === 'Complaint') {
            $lines[] = 'Citizen-selected complaint urgency: ' . ((string)($row['citizen_reported_urgency'] ?? 'Normal'));
            $lines[] = 'Affected service: ' . ((string)($row['affected_service'] ?? ''));
            $lines[] = 'Incident date/time: ' . ((string)($row['incident_datetime'] ?? ''));
            $lines[] = 'Important instruction: independently assess AI urgency from the actual complaint, impact and keywords. The citizen-selected urgency is supporting context, not the required final AI urgency.';
        } else {
            $lines[] = 'Feedback kind: ' . ((string)($row['feedback_kind'] ?? 'General Feedback'));
            $lines[] = 'Service area: ' . ((string)($row['service_area'] ?? ''));
            $lines[] = 'Desired outcome: ' . ((string)($row['desired_outcome'] ?? ''));
            $lines[] = 'Citizen rating: ' . ((string)($row['citizen_rating'] ?? ''));
        }

        return [
            'analysis_scope'=>$scope,
            'reference_number'=>(string)($row['reference_number'] ?? ''),
            'submission_type'=>(string)($row['submission_type'] ?? ''),
            'submission_status'=>(string)($row['status'] ?? ''),
            'source_channel'=>(string)($row['source_channel'] ?? ''),
            'prompt_text'=>implode("\n", $lines),
        ];
    }

    private static function sendAutomatedComplaintAcknowledgement(
        int $submissionId,
        array $context
    ): array {
        if (($context['submission_type'] ?? '') !== 'Complaint') {
            return ['status'=>'not_applicable','sent'=>false];
        }

        if (in_array(
            (string)($context['submission_status'] ?? ''),
            ['Closed','Rejected','Duplicate','Withdrawn'],
            true
        )) {
            return ['status'=>'ticket_closed','sent'=>false];
        }

        try {
            $pdo = db();
            if (!$pdo->query("SHOW TABLES LIKE 'cef_followups'")->fetchColumn()) {
                return ['status'=>'chat_unavailable','sent'=>false];
            }

            $stmt = $pdo->prepare(
                "INSERT INTO cef_followups
                    (submission_id,response_id,direction,sender_user_id,sender_name,
                     message,public_visible,created_at)
                 SELECT
                    s.id,NULL,'Council to Citizen',NULL,:sender_insert,
                    :message,1,NOW()
                 FROM cef_submissions s
                 WHERE s.id=:submission_id
                   AND s.deleted_at IS NULL
                   AND s.submission_type='Complaint'
                   AND s.status NOT IN ('Closed','Rejected','Duplicate','Withdrawn')
                   AND NOT EXISTS (
                       SELECT 1
                       FROM cef_followups f
                       WHERE f.submission_id=s.id
                         AND f.direction='Council to Citizen'
                         AND f.sender_name=:sender_match
                   )"
            );
            $stmt->execute([
                ':sender_insert'=>self::AUTOMATED_ACK_SENDER,
                ':message'=>self::AUTOMATED_ACK_MESSAGE,
                ':submission_id'=>$submissionId,
                ':sender_match'=>self::AUTOMATED_ACK_SENDER,
            ]);

            if ($stmt->rowCount() === 1) {
                logActivity(
                    currentUserId() ?: null,
                    'Citizen Complaint Automated Acknowledgement',
                    'Automated chat acknowledgement sent for CEF submission #' . $submissionId . '.'
                );
                return ['status'=>'sent','sent'=>true];
            }

            return ['status'=>'already_sent','sent'=>false];
        } catch (Throwable $e) {
            error_log('CEF automated acknowledgement failed: ' . $e->getMessage());
            return ['status'=>'failed','sent'=>false];
        }
    }

    private static function upsertPending(int $submissionId, string $scope): void
    {
        $stmt = db()->prepare(
            "INSERT INTO lph_cef_ai_analysis
                (submission_id,analysis_scope,status,review_status,analysis_version,created_at)
             VALUES
                (:submission_id,:scope,'pending','Pending',1,NOW())
             ON DUPLICATE KEY UPDATE
                analysis_scope=VALUES(analysis_scope),
                analysis_version=CASE
                    WHEN status IN ('completed','failed') OR analyzed_at IS NOT NULL
                    THEN analysis_version+1
                    ELSE analysis_version
                END,
                status='pending',
                error_message=NULL,
                review_status='Pending',
                review_notes=NULL,
                reviewed_by=NULL,
                reviewed_at=NULL"
        );
        $stmt->execute([
            ':submission_id'=>$submissionId,
            ':scope'=>$scope,
        ]);
    }

    private static function archiveCurrentAnalysis(
        int $submissionId,
        ?int $archivedBy,
        string $reason
    ): void {
        if (!self::tablesExist()) return;

        try {
            $stmt = db()->prepare(
                "SELECT *
                 FROM lph_cef_ai_analysis
                 WHERE submission_id=:id
                 LIMIT 1"
            );
            $stmt->execute([':id'=>$submissionId]);
            $row = $stmt->fetch();
            if (!$row) return;

            $hasResult = !empty($row['analyzed_at'])
                || in_array((string)($row['status'] ?? ''), ['completed','failed'], true);
            if (!$hasResult) return;

            $ins = db()->prepare(
                "INSERT INTO lph_cef_ai_analysis_history
                    (
                        submission_id,analysis_id,analysis_version,
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
                        :submission_id,:analysis_id,:analysis_version,
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
                ':submission_id'=>$submissionId,
                ':analysis_id'=>$row['id'] ?? null,
                ':analysis_version'=>(int)($row['analysis_version'] ?? 1),
                ':analysis_scope'=>$row['analysis_scope'] ?? null,
                ':sentiment'=>$row['sentiment'] ?? null,
                ':confidence_score'=>$row['confidence_score'] ?? null,
                ':urgency_level'=>$row['urgency_level'] ?? null,
                ':urgency_score'=>$row['urgency_score'] ?? null,
                ':keyword_score'=>$row['keyword_score'] ?? null,
                ':summary'=>$row['summary'] ?? null,
                ':keywords'=>$row['keywords'] ?? null,
                ':risk_keywords'=>$row['risk_keywords'] ?? null,
                ':recommended_category'=>$row['recommended_category'] ?? null,
                ':suggested_response'=>$row['suggested_response'] ?? null,
                ':flagged_for_review'=>(int)($row['flagged_for_review'] ?? 0),
                ':review_status'=>$row['review_status'] ?? null,
                ':review_notes'=>$row['review_notes'] ?? null,
                ':reviewed_by'=>$row['reviewed_by'] ?? null,
                ':reviewed_at'=>$row['reviewed_at'] ?? null,
                ':analysis_status'=>$row['status'] ?? null,
                ':error_message'=>$row['error_message'] ?? null,
                ':model_used'=>$row['model_used'] ?? null,
                ':raw_response'=>$row['raw_response'] ?? null,
                ':analyzed_at'=>$row['analyzed_at'] ?? null,
                ':archived_reason'=>$reason,
                ':archived_by'=>$archivedBy,
            ]);
        } catch (Throwable $e) {
            error_log('CEF AI history archive failed: ' . $e->getMessage());
        }
    }

    private static function storeSuccess(int $submissionId, string $scope, array $result): void
    {
        $urgency = (string)($result['urgency_level'] ?? 'Low');
        $sentiment = (string)($result['sentiment'] ?? 'Neutral');

        $flagged = 0;
        if (
            AI_FLAG_NEGATIVE_HIGH_URGENCY
            && $sentiment === AI_NEGATIVE_SENTIMENT_VALUE
            && in_array($urgency, ['High','Critical'], true)
        ) {
            $flagged = 1;
        }
        if ($urgency === 'Critical') $flagged = 1;

        $stmt = db()->prepare(
            "UPDATE lph_cef_ai_analysis SET
                analysis_scope=:scope,
                sentiment=:sentiment,
                confidence_score=:confidence,
                urgency_level=:urgency_level,
                urgency_score=:urgency_score,
                keyword_score=:keyword_score,
                summary=:summary,
                keywords=:keywords,
                risk_keywords=:risk_keywords,
                recommended_category=:recommended_category,
                suggested_response=:suggested_response,
                flagged_for_review=:flagged,
                status='completed',
                error_message=NULL,
                model_used=:model,
                raw_response=:raw_response,
                analyzed_at=NOW()
             WHERE submission_id=:submission_id"
        );

        $stmt->execute([
            ':scope'=>$scope,
            ':sentiment'=>$sentiment,
            ':confidence'=>$result['confidence_score'] ?? null,
            ':urgency_level'=>$urgency,
            ':urgency_score'=>$result['urgency_score'] ?? null,
            ':keyword_score'=>$result['keyword_score'] ?? null,
            ':summary'=>$result['summary'] ?? null,
            ':keywords'=>json_encode($result['keywords'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':risk_keywords'=>json_encode($result['risk_keywords'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':recommended_category'=>$result['recommended_category'] ?? null,
            ':suggested_response'=>$result['suggested_response'] ?? null,
            ':flagged'=>$flagged,
            ':model'=>$result['model_used'] ?? null,
            ':raw_response'=>mb_substr((string)($result['raw_response'] ?? ''), 0, 60000),
            ':submission_id'=>$submissionId,
        ]);

        if ($flagged) {
            logActivity(
                null,
                'Citizen Portal AI Flag',
                "CEF submission #{$submissionId} flagged for staff review: {$sentiment}, {$urgency} urgency."
            );
        }
    }

    private static function storeFailure(int $submissionId, string $scope, array $result): void
    {
        $stmt = db()->prepare(
            "UPDATE lph_cef_ai_analysis SET
                analysis_scope=:scope,
                status='failed',
                error_message=:error_message,
                raw_response=:raw_response
             WHERE submission_id=:submission_id"
        );
        $stmt->execute([
            ':scope'=>$scope,
            ':error_message'=>(string)($result['error'] ?? 'Unknown AI error'),
            ':raw_response'=>mb_substr((string)($result['raw_response'] ?? ''), 0, 60000),
            ':submission_id'=>$submissionId,
        ]);
    }

    private static function logRequest(int $submissionId, array $context, array $result): void
    {
        try {
            $stmt = db()->prepare(
                "INSERT INTO ai_request_logs
                    (
                        feedback_id,source_type,source_record_id,
                        provider,model_used,request_payload,response_payload,
                        http_status,success,error_message,duration_ms,created_at
                    )
                 VALUES
                    (
                        NULL,'CEF Submission',:source_record_id,
                        :provider,:model_used,:request_payload,:response_payload,
                        :http_status,:success,:error_message,:duration_ms,NOW()
                    )"
            );

            $requestPayload = json_encode([
                'reference_number'=>$context['reference_number'] ?? null,
                'analysis_scope'=>$context['analysis_scope'] ?? null,
                'submission_type'=>$context['submission_type'] ?? null,
                'source_channel'=>$context['source_channel'] ?? null,
                'text'=>$context['prompt_text'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $stmt->execute([
                ':source_record_id'=>$submissionId,
                ':provider'=>(string)($result['provider'] ?? AI_PROVIDER),
                ':model_used'=>$result['model_used'] ?? OLLAMA_MODEL,
                ':request_payload'=>mb_substr((string)$requestPayload, 0, 60000),
                ':response_payload'=>mb_substr((string)($result['raw_response'] ?? ''), 0, 60000),
                ':http_status'=>$result['http_status'] ?? null,
                ':success'=>!empty($result['success']) ? 1 : 0,
                ':error_message'=>$result['error'] ?? null,
                ':duration_ms'=>$result['duration_ms'] ?? null,
            ]);
        } catch (Throwable $e) {
            error_log('CEF AI request log failed: ' . $e->getMessage());
        }
    }

    private static function decodeJsonList(mixed $value): array
    {
        if (is_array($value)) return $value;
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }
}
