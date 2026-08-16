-- ============================================================
-- LPH / Subsystem #7 - STEP 1: Source & Database Alignment
-- Target database: legislative_management_db
-- MariaDB 10.4+
--
-- Safe purpose:
-- 1) Add the missing public-feedback reply fields used by the source.
-- 2) Add the missing AI analysis/audit tables already referenced by source.
-- 3) Do NOT rename or duplicate hearing_* issue/action tables.
--    The PHP patch included with this migration is aligned directly to:
--      hearing_issues, hearing_issue_categories,
--      hearing_issue_assignments, hearing_issue_history,
--      hearing_actions, hearing_action_assignments,
--      hearing_action_updates, hearing_action_documents.
-- ============================================================

USE `legislative_management_db`;

START TRANSACTION;

-- ------------------------------------------------------------
-- Feedback reply tracking
-- ------------------------------------------------------------
ALTER TABLE `feedback`
    ADD COLUMN IF NOT EXISTS `reply_text` TEXT NULL AFTER `message`,
    ADD COLUMN IF NOT EXISTS `replied_at` TIMESTAMP NULL AFTER `reply_text`,
    ADD COLUMN IF NOT EXISTS `replied_by` INT NULL AFTER `replied_at`;

-- Add FK only when it is not already present.
SET @fk_feedback_reply_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'feedback'
      AND CONSTRAINT_NAME = 'fk_feedback_replied_by'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @sql_feedback_reply_fk := IF(
    @fk_feedback_reply_exists = 0,
    'ALTER TABLE `feedback` ADD CONSTRAINT `fk_feedback_replied_by` FOREIGN KEY (`replied_by`) REFERENCES `users`(`id`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt_feedback_reply_fk FROM @sql_feedback_reply_fk;
EXECUTE stmt_feedback_reply_fk;
DEALLOCATE PREPARE stmt_feedback_reply_fk;

-- ------------------------------------------------------------
-- AI sentiment / analysis results
-- These tables are referenced by the existing feedback AI source.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `feedback_ai_analysis` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `feedback_id` INT NOT NULL,
    `sentiment` VARCHAR(20) NULL,
    `confidence_score` DECIMAL(5,2) NULL,
    `summary` TEXT NULL,
    `keywords` JSON NULL,
    `recommended_category` VARCHAR(150) NULL,
    `suggested_response` TEXT NULL,
    `flagged_for_review` TINYINT(1) NOT NULL DEFAULT 0,
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    `error_message` TEXT NULL,
    `model_used` VARCHAR(100) NULL,
    `raw_response` LONGTEXT NULL,
    `analyzed_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_feedback_id` (`feedback_id`),
    KEY `idx_sentiment` (`sentiment`),
    KEY `idx_flagged` (`flagged_for_review`),
    KEY `idx_analyzed_at` (`analyzed_at`),
    CONSTRAINT `fk_ai_analysis_feedback`
        FOREIGN KEY (`feedback_id`) REFERENCES `feedback` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `ai_request_logs` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `feedback_id` INT NULL,
    `provider` VARCHAR(50) NOT NULL DEFAULT 'ollama',
    `model_used` VARCHAR(100) NULL,
    `request_payload` LONGTEXT NULL,
    `response_payload` LONGTEXT NULL,
    `http_status` INT NULL,
    `success` TINYINT(1) NOT NULL DEFAULT 0,
    `error_message` TEXT NULL,
    `duration_ms` INT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ai_request_created_at` (`created_at`),
    KEY `idx_ai_request_success` (`success`),
    KEY `idx_ai_request_feedback` (`feedback_id`),
    CONSTRAINT `fk_ai_log_feedback`
        FOREIGN KEY (`feedback_id`) REFERENCES `feedback` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Optional migration tracking table for Subsystem #7
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lph_schema_migrations` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `migration_key` VARCHAR(120) NOT NULL,
    `description` VARCHAR(255) NULL,
    `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_lph_migration_key` (`migration_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `lph_schema_migrations` (`migration_key`, `description`)
VALUES (
    '004_step1_source_database_alignment',
    'Aligned Subsystem #7 source with hearing_* issue/action schema and added feedback reply/AI support tables.'
);

COMMIT;

-- ------------------------------------------------------------
-- Verification output: every value should be 1 except row counts,
-- which may be 0 in a new/unused module.
-- ------------------------------------------------------------
SELECT
    EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hearing_issues') AS hearing_issues_ok,
    EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hearing_actions') AS hearing_actions_ok,
    EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'feedback_ai_analysis') AS feedback_ai_analysis_ok,
    EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_request_logs') AS ai_request_logs_ok,
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'feedback' AND COLUMN_NAME = 'reply_text') AS feedback_reply_text_ok,
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'feedback' AND COLUMN_NAME = 'replied_at') AS feedback_replied_at_ok,
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'feedback' AND COLUMN_NAME = 'replied_by') AS feedback_replied_by_ok;
