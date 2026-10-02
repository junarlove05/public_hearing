-- database/final_validation_ai.sql
-- LPH / PHCMS Ollama AI final validation
-- Does not query information_schema.

USE `legislative_management_db`;

-- Required tables
SHOW TABLES LIKE 'feedback_ai_analysis';
SHOW TABLES LIKE 'feedback_ai_analysis_history';
SHOW TABLES LIKE 'ai_request_logs';

-- Required AI columns
SHOW COLUMNS FROM `feedback_ai_analysis` LIKE 'sentiment';
SHOW COLUMNS FROM `feedback_ai_analysis` LIKE 'urgency_level';
SHOW COLUMNS FROM `feedback_ai_analysis` LIKE 'urgency_score';
SHOW COLUMNS FROM `feedback_ai_analysis` LIKE 'risk_keywords';
SHOW COLUMNS FROM `feedback_ai_analysis` LIKE 'review_status';
SHOW COLUMNS FROM `feedback_ai_analysis` LIKE 'analysis_version';

-- Current AI status
SELECT
    `status`,
    COUNT(*) AS `total`
FROM `feedback_ai_analysis`
GROUP BY `status`
ORDER BY `status`;

-- Sentiment distribution
SELECT
    `sentiment`,
    COUNT(*) AS `total`
FROM `feedback_ai_analysis`
WHERE `status`='completed'
GROUP BY `sentiment`
ORDER BY `sentiment`;

-- Urgency distribution
SELECT
    `urgency_level`,
    COUNT(*) AS `total`
FROM `feedback_ai_analysis`
WHERE `status`='completed'
GROUP BY `urgency_level`
ORDER BY FIELD(`urgency_level`,'Critical','High','Medium','Low');

-- Complaint analysis
SELECT
    COUNT(*) AS `analyzed_complaints`
FROM `feedback_ai_analysis`
WHERE `status`='completed'
  AND `analysis_scope`='Complaint';

-- High / Critical cases requiring attention
SELECT
    `feedback_id`,
    `analysis_scope`,
    `sentiment`,
    `urgency_level`,
    `urgency_score`,
    `flagged_for_review`,
    `review_status`,
    `analysis_version`,
    `analyzed_at`
FROM `feedback_ai_analysis`
WHERE `status`='completed'
  AND (
       `urgency_level` IN ('High','Critical')
       OR `flagged_for_review`=1
  )
ORDER BY
    FIELD(`urgency_level`,'Critical','High','Medium','Low'),
    `analyzed_at` DESC;

-- Data-quality checks: expected result is 0
SELECT COUNT(*) AS `completed_without_sentiment`
FROM `feedback_ai_analysis`
WHERE `status`='completed'
  AND (`sentiment` IS NULL OR TRIM(`sentiment`)='');

SELECT COUNT(*) AS `completed_without_urgency`
FROM `feedback_ai_analysis`
WHERE `status`='completed'
  AND (`urgency_level` IS NULL OR TRIM(`urgency_level`)='');

-- AI request reliability
SELECT
    `success`,
    COUNT(*) AS `total`
FROM `ai_request_logs`
GROUP BY `success`;

SELECT
    `id`,
    `feedback_id`,
    `model_used`,
    `http_status`,
    `error_message`,
    `duration_ms`,
    `created_at`
FROM `ai_request_logs`
WHERE `success`=0
ORDER BY `created_at` DESC
LIMIT 20;

-- Re-analysis history
SELECT
    `feedback_id`,
    COUNT(*) AS `archived_versions`
FROM `feedback_ai_analysis_history`
GROUP BY `feedback_id`
ORDER BY `archived_versions` DESC,`feedback_id`;

-- Staff AI review
SELECT
    `review_status`,
    COUNT(*) AS `total`
FROM `feedback_ai_analysis`
WHERE `status`='completed'
GROUP BY `review_status`
ORDER BY `review_status`;
