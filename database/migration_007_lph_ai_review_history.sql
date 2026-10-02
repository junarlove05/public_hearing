-- database/migration_007_lph_ai_review_history.sql
-- LPH / PHCMS - AI staff review + re-analysis history
-- Run on legislative_management_db after migration_006.

USE `legislative_management_db`;

-- Staff can accept the AI result, request manual review, or dismiss it.
-- This review is advisory and does NOT change the official feedback status.
ALTER TABLE `feedback_ai_analysis`
    ADD COLUMN IF NOT EXISTS `review_status`
        VARCHAR(30) NOT NULL DEFAULT 'Pending'
        AFTER `flagged_for_review`,
    ADD COLUMN IF NOT EXISTS `review_notes`
        TEXT DEFAULT NULL
        AFTER `review_status`,
    ADD COLUMN IF NOT EXISTS `reviewed_by`
        INT DEFAULT NULL
        AFTER `review_notes`,
    ADD COLUMN IF NOT EXISTS `reviewed_at`
        DATETIME DEFAULT NULL
        AFTER `reviewed_by`,
    ADD COLUMN IF NOT EXISTS `analysis_version`
        INT NOT NULL DEFAULT 1
        AFTER `reviewed_at`;

CREATE TABLE IF NOT EXISTS `feedback_ai_analysis_history` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `feedback_id` INT NOT NULL,
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
    KEY `idx_ai_history_feedback` (`feedback_id`),
    KEY `idx_ai_history_version` (`feedback_id`,`analysis_version`),
    KEY `idx_ai_history_sentiment` (`sentiment`),
    KEY `idx_ai_history_urgency` (`urgency_level`),
    KEY `idx_ai_history_archived` (`archived_at`),

    CONSTRAINT `fk_ai_history_feedback`
        FOREIGN KEY (`feedback_id`)
        REFERENCES `feedback` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_general_ci;

SHOW TABLES LIKE 'feedback_ai_analysis_history';
SHOW COLUMNS FROM `feedback_ai_analysis` LIKE 'review_status';
SHOW COLUMNS FROM `feedback_ai_analysis` LIKE 'analysis_version';
