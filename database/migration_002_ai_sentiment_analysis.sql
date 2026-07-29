-- database/migration_002_ai_sentiment_analysis.sql
-- ---------------------------------------------------------------------
-- Adds AI sentiment analysis support for the Public Feedback module.
-- REQUIRED for the Ollama integration to store results (the feedback
-- table itself is NOT modified — analysis lives in its own table,
-- one-to-one with feedback, keeping AI concerns fully separate from
-- core feedback data as required).
--
-- Run this once against legislative_public_hearing_db before using the
-- AI features. Nothing else in the app breaks if you don't run it yet —
-- AIAnalysisManager checks for these tables and disables itself
-- gracefully if they're missing (see includes/AI/AIAnalysisManager.php).
-- ---------------------------------------------------------------------

-- One row per feedback entry holding the latest AI analysis result.
CREATE TABLE IF NOT EXISTS feedback_ai_analysis (
  id INT AUTO_INCREMENT PRIMARY KEY,
  feedback_id INT NOT NULL,
  sentiment VARCHAR(20) NULL,              -- 'Positive' | 'Neutral' | 'Negative'
  confidence_score DECIMAL(5,2) NULL,      -- 0.00–100.00
  summary TEXT NULL,                       -- one-sentence AI summary
  keywords JSON NULL,                      -- array of extracted keywords
  recommended_category VARCHAR(150) NULL,  -- AI-suggested issue category (free text; not FK'd to
                                            -- feedback_categories since the AI may suggest a
                                            -- category that doesn't exist yet as a lookup row)
  suggested_response TEXT NULL,            -- AI-drafted response for staff to review/send
  flagged_for_review TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'pending', -- 'pending' | 'completed' | 'failed'
  error_message TEXT NULL,                 -- populated when status = 'failed'
  model_used VARCHAR(100) NULL,
  raw_response LONGTEXT NULL,              -- full raw AI response, for debugging/audit
  analyzed_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_feedback_id (feedback_id),
  CONSTRAINT fk_ai_analysis_feedback FOREIGN KEY (feedback_id) REFERENCES feedback(id) ON DELETE CASCADE,
  INDEX idx_sentiment (sentiment),
  INDEX idx_flagged (flagged_for_review),
  INDEX idx_analyzed_at (analyzed_at)
);

-- Full audit trail of every AI request/response, for debugging (requirement:
-- "Log all AI requests and responses for debugging"). Kept separate from
-- activity_logs since payloads can be large and this is a different
-- concern (AI service diagnostics vs. user action audit trail).
CREATE TABLE IF NOT EXISTS ai_request_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  feedback_id INT NULL,
  provider VARCHAR(50) NOT NULL DEFAULT 'ollama',
  model_used VARCHAR(100) NULL,
  request_payload LONGTEXT NULL,
  response_payload LONGTEXT NULL,
  http_status INT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  error_message TEXT NULL,
  duration_ms INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ai_log_feedback FOREIGN KEY (feedback_id) REFERENCES feedback(id) ON DELETE SET NULL,
  INDEX idx_created_at (created_at),
  INDEX idx_success (success)
);
