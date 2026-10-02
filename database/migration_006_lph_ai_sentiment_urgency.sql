-- database/migration_006_lph_ai_sentiment_urgency.sql
-- LPH / PHCMS - Ollama AI sentiment foundation
-- Shared database: legislative_management_db
--
-- Purpose:
--   Store AI analysis for citizen feedback and complaint-type feedback.
--   Complaint records are identified from feedback.category_id where
--   feedback_categories.name = 'Complaint'.
--
-- AI result:
--   sentiment      = Positive | Neutral | Negative
--   urgency_level  = Low | Medium | High | Critical
--   urgency_score  = 0.00 - 100.00
--   keywords       = JSON array of important words/phrases
--   risk_keywords  = JSON array of urgency/problem keywords
--
-- The AI result is advisory only. It does not automatically change
-- feedback status, issue priority, or official responses.

USE legislative_management_db;

CREATE TABLE IF NOT EXISTS feedback_ai_analysis (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    feedback_id INT NOT NULL,

    analysis_scope VARCHAR(30) NOT NULL DEFAULT 'Citizen Feedback',
    sentiment VARCHAR(20) DEFAULT NULL,
    confidence_score DECIMAL(5,2) DEFAULT NULL,

    urgency_level VARCHAR(20) DEFAULT NULL,
    urgency_score DECIMAL(5,2) DEFAULT NULL,
    keyword_score DECIMAL(5,2) DEFAULT NULL,

    summary TEXT DEFAULT NULL,
    keywords JSON DEFAULT NULL,
    risk_keywords JSON DEFAULT NULL,

    recommended_category VARCHAR(150) DEFAULT NULL,
    suggested_response TEXT DEFAULT NULL,

    flagged_for_review TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    error_message TEXT DEFAULT NULL,

    model_used VARCHAR(100) DEFAULT NULL,
    raw_response LONGTEXT DEFAULT NULL,
    analyzed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_feedback_ai_feedback (feedback_id),
    KEY idx_feedback_ai_scope (analysis_scope),
    KEY idx_feedback_ai_sentiment (sentiment),
    KEY idx_feedback_ai_urgency (urgency_level),
    KEY idx_feedback_ai_flagged (flagged_for_review),
    KEY idx_feedback_ai_status (status),
    KEY idx_feedback_ai_analyzed (analyzed_at),

    CONSTRAINT fk_feedback_ai_feedback
      FOREIGN KEY (feedback_id) REFERENCES feedback(id)
      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Upgrade compatibility for an older feedback_ai_analysis table.
ALTER TABLE feedback_ai_analysis
    ADD COLUMN IF NOT EXISTS analysis_scope VARCHAR(30) NOT NULL DEFAULT 'Citizen Feedback' AFTER feedback_id,
    ADD COLUMN IF NOT EXISTS urgency_level VARCHAR(20) DEFAULT NULL AFTER confidence_score,
    ADD COLUMN IF NOT EXISTS urgency_score DECIMAL(5,2) DEFAULT NULL AFTER urgency_level,
    ADD COLUMN IF NOT EXISTS keyword_score DECIMAL(5,2) DEFAULT NULL AFTER urgency_score,
    ADD COLUMN IF NOT EXISTS risk_keywords JSON DEFAULT NULL AFTER keywords,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

CREATE TABLE IF NOT EXISTS ai_request_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    feedback_id INT DEFAULT NULL,
    provider VARCHAR(50) NOT NULL DEFAULT 'ollama',
    model_used VARCHAR(100) DEFAULT NULL,

    request_payload LONGTEXT DEFAULT NULL,
    response_payload LONGTEXT DEFAULT NULL,

    http_status INT DEFAULT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    error_message TEXT DEFAULT NULL,
    duration_ms INT DEFAULT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_ai_logs_feedback (feedback_id),
    KEY idx_ai_logs_created (created_at),
    KEY idx_ai_logs_success (success),

    CONSTRAINT fk_ai_logs_feedback
      FOREIGN KEY (feedback_id) REFERENCES feedback(id)
      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Optional migration marker if the table already exists in the shared DB.
-- This statement is intentionally guarded so the migration is safe even
-- when lph_schema_migrations is not part of the installation.
SET @has_migration_table = (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'lph_schema_migrations'
);

-- Verification
SELECT
    'feedback_ai_analysis' AS table_name,
    COUNT(*) AS exists_count
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'feedback_ai_analysis';

SELECT
    'ai_request_logs' AS table_name,
    COUNT(*) AS exists_count
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'ai_request_logs';
