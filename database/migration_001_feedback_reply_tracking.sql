-- database/migration_001_feedback_reply_tracking.sql
-- ---------------------------------------------------------------------
-- OPTIONAL, ONE-TIME MIGRATION — run this once against
-- legislative_public_hearing_db if you want the public "track my
-- feedback / view admin reply" feature to work.
--
-- WHY THIS IS NEEDED: the original `feedback` table has no column to
-- store a reply's text, timestamp, or author — replies were only being
-- recorded as free-text entries in `activity_logs`, which is fine for
-- an internal admin audit trail but unsuitable (and insecure) to expose
-- on a public-facing feedback-status page: activity_logs holds
-- unrelated internal activity across the whole system, and matching a
-- specific feedback entry's reply out of that log via text search would
-- be fragile and could leak unrelated data. These three columns let the
-- public page query a specific, secure reply for one feedback record.
--
-- This does NOT remove or change any existing column — it only adds
-- new nullable columns, so all existing functionality keeps working
-- exactly as before whether or not you run this migration (the code
-- checks whether these columns exist and degrades gracefully if not —
-- see includes/functions.php::feedbackTableHasReplyColumns()).
-- ---------------------------------------------------------------------

ALTER TABLE feedback
  ADD COLUMN reply_text TEXT NULL AFTER message,
  ADD COLUMN replied_at TIMESTAMP NULL AFTER reply_text,
  ADD COLUMN replied_by INT NULL AFTER replied_at,
  ADD CONSTRAINT fk_feedback_replied_by FOREIGN KEY (replied_by) REFERENCES users(id) ON DELETE SET NULL;
