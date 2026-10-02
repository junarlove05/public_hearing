-- database/migration_009_citizen_portal_cef_ai_bridge.sql
-- LPH / PHCMS Ollama AI bridge for Citizen Portal / CEPFMS records.
--
-- Source of citizen records confirmed in the latest Citizen Portal code:
--   cef_submissions
--   cef_feedback_submissions
--   cef_complaints
--
-- Only Feedback and Complaint submissions are in AI scope.
-- Proposal records are intentionally excluded.
--
-- This keeps CEPFMS/Citizen Portal records in their original tables.
-- LPH stores only the AI analysis in a separate source-specific table,
-- avoiding duplicate citizen submissions inside LPH feedback.

USE `legislative_management_db`;

CREATE TABLE IF NOT EXISTS `cef_ai_analysis` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `submission_id` BIGINT NOT NULL,

    `analysis_scope` VARCHAR(30) NOT NULL DEFAULT 'Citizen Feedback',
    `sentiment` VARCHAR(20) DEFAULT NULL,
    `confidence_score` DECIMAL(5,2) DEFAULT NULL,

    `urgency_level` VARCHAR(20) DEFAULT NULL,
    `urgency_score` DECIMAL(5,2) DEFAULT NULL,
    `keyword_score` DECIMAL(5,2) DEFAULT NULL,

    `summary` TEXT DEFAULT NULL,
    `keywords` JSON DEFAULT NULL,
    `risk_keywords` JSON DEFAULT NULL,

    `recommended_category` VARCHAR(150) DEFAULT NULL,
    `suggested_response` TEXT DEFAULT NULL,

    `flagged_for_review` TINYINT(1) NOT NULL DEFAULT 0,

    `review_status` VARCHAR(30) NOT NULL DEFAULT 'Pending',
    `review_notes` TEXT DEFAULT NULL,
    `reviewed_by` INT DEFAULT NULL,
    `reviewed_at` DATETIME DEFAULT NULL,
    `analysis_version` INT NOT NULL DEFAULT 1,

    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    `error_message` TEXT DEFAULT NULL,

    `model_used` VARCHAR(100) DEFAULT NULL,
    `raw_response` LONGTEXT DEFAULT NULL,
    `analyzed_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cef_ai_submission` (`submission_id`),
    KEY `idx_cef_ai_scope` (`analysis_scope`),
    KEY `idx_cef_ai_sentiment` (`sentiment`),
    KEY `idx_cef_ai_urgency` (`urgency_level`),
    KEY `idx_cef_ai_flagged` (`flagged_for_review`),
    KEY `idx_cef_ai_review` (`review_status`),
    KEY `idx_cef_ai_status` (`status`),
    KEY `idx_cef_ai_analyzed` (`analyzed_at`),

    CONSTRAINT `fk_cef_ai_submission`
        FOREIGN KEY (`submission_id`)
        REFERENCES `cef_submissions` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `cef_ai_analysis_history` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `submission_id` BIGINT NOT NULL,
    `analysis_id` BIGINT UNSIGNED DEFAULT NULL,
    `analysis_version` INT NOT NULL DEFAULT 1,

    `analysis_scope` VARCHAR(30) DEFAULT NULL,
    `sentiment` VARCHAR(20) DEFAULT NULL,
    `confidence_score` DECIMAL(5,2) DEFAULT NULL,
    `urgency_level` VARCHAR(20) DEFAULT NULL,
    `urgency_score` DECIMAL(5,2) DEFAULT NULL,
    `keyword_score` DECIMAL(5,2) DEFAULT NULL,

    `summary` TEXT DEFAULT NULL,
    `keywords` JSON DEFAULT NULL,
    `risk_keywords` JSON DEFAULT NULL,
    `recommended_category` VARCHAR(150) DEFAULT NULL,
    `suggested_response` TEXT DEFAULT NULL,

    `flagged_for_review` TINYINT(1) NOT NULL DEFAULT 0,
    `review_status` VARCHAR(30) DEFAULT NULL,
    `review_notes` TEXT DEFAULT NULL,
    `reviewed_by` INT DEFAULT NULL,
    `reviewed_at` DATETIME DEFAULT NULL,

    `analysis_status` VARCHAR(20) DEFAULT NULL,
    `error_message` TEXT DEFAULT NULL,
    `model_used` VARCHAR(100) DEFAULT NULL,
    `raw_response` LONGTEXT DEFAULT NULL,
    `analyzed_at` DATETIME DEFAULT NULL,

    `archived_reason` VARCHAR(50) NOT NULL DEFAULT 'Re-analysis',
    `archived_by` INT DEFAULT NULL,
    `archived_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_cef_ai_history_submission` (`submission_id`),
    KEY `idx_cef_ai_history_version` (`submission_id`,`analysis_version`),
    KEY `idx_cef_ai_history_archived` (`archived_at`),

    CONSTRAINT `fk_cef_ai_history_submission`
        FOREIGN KEY (`submission_id`)
        REFERENCES `cef_submissions` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_general_ci;

-- Extend the existing shared AI request log so CEF/Portal requests can be
-- audited without incorrectly pointing them at the LPH feedback table.
ALTER TABLE `ai_request_logs`
    ADD COLUMN IF NOT EXISTS `source_type`
        VARCHAR(40) NOT NULL DEFAULT 'LPH Feedback'
        AFTER `feedback_id`,
    ADD COLUMN IF NOT EXISTS `source_record_id`
        BIGINT DEFAULT NULL
        AFTER `source_type`;

-- Queue all current Citizen Portal / CEPFMS Feedback and Complaint records.
-- Existing analysis rows are preserved.
INSERT IGNORE INTO `cef_ai_analysis`
(
    `submission_id`,
    `analysis_scope`,
    `status`,
    `review_status`,
    `analysis_version`,
    `created_at`
)
SELECT
    s.`id`,
    CASE
        WHEN s.`submission_type`='Complaint' THEN 'Complaint'
        ELSE 'Citizen Feedback'
    END,
    'pending',
    'Pending',
    1,
    NOW()
FROM `cef_submissions` s
WHERE s.`deleted_at` IS NULL
  AND s.`submission_type` IN ('Feedback','Complaint');

-- Verification
SHOW TABLES LIKE 'cef_ai_analysis';
SHOW TABLES LIKE 'cef_ai_analysis_history';
SHOW COLUMNS FROM `ai_request_logs` LIKE 'source_type';
SHOW COLUMNS FROM `ai_request_logs` LIKE 'source_record_id';

SELECT
    s.`reference_number`,
    s.`submission_type`,
    s.`title`,
    s.`source_channel`,
    ai.`status` AS `ai_status`
FROM `cef_submissions` s
JOIN `cef_ai_analysis` ai ON ai.`submission_id`=s.`id`
WHERE s.`deleted_at` IS NULL
  AND s.`submission_type` IN ('Feedback','Complaint')
ORDER BY s.`created_at` DESC;
