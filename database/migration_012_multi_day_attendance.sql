-- ======================================================================
-- LPH Subsystem #7: Multi-Day Hearing Attendance Tracking & Daily Closure
-- Migration: 012_multi_day_attendance
-- ======================================================================

USE `legislative_management_db`;

-- 1. Add attendance_date to attendance table if not exists
ALTER TABLE `attendance`
    ADD COLUMN IF NOT EXISTS `attendance_date` DATE NULL AFTER `hearing_id`;

-- 2. Backfill existing attendance records with valid dates
UPDATE `attendance` a
JOIN `hearings` h ON h.id = a.hearing_id
SET a.attendance_date = COALESCE(DATE(a.checked_in_at), DATE(a.created_at), h.hearing_date)
WHERE a.attendance_date IS NULL OR a.attendance_date = '0000-00-00';

UPDATE `attendance`
SET `attendance_date` = CURRENT_DATE
WHERE `attendance_date` IS NULL OR `attendance_date` = '0000-00-00';

-- 3. Ensure attendance_date is NOT NULL
ALTER TABLE `attendance`
    MODIFY COLUMN `attendance_date` DATE NOT NULL;

-- 4. Satisfy foreign key before dropping old unique key
CREATE INDEX IF NOT EXISTS `idx_attendance_stakeholder`
    ON `attendance` (`stakeholder_id`);

-- 5. Create new date-specific unique index
CREATE UNIQUE INDEX IF NOT EXISTS `uq_attendance_stakeholder_hearing_date`
    ON `attendance` (`stakeholder_id`, `hearing_id`, `attendance_date`);

-- 6. Drop old single-hearing unique index
ALTER TABLE `attendance`
    DROP INDEX IF EXISTS `uq_attendance_stakeholder_hearing`;

CREATE INDEX IF NOT EXISTS `idx_attendance_hearing_date`
    ON `attendance` (`hearing_id`, `attendance_date`);

-- 7. Track session dates and administrative closure status per day
CREATE TABLE IF NOT EXISTS `hearing_session_days` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `hearing_id` INT NOT NULL,
    `session_date` DATE NOT NULL,
    `day_number` INT NOT NULL DEFAULT 1,
    `is_closed` TINYINT(1) NOT NULL DEFAULT 0,
    `closed_at` DATETIME NULL,
    `closed_by` INT NULL,
    `notes` VARCHAR(255) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `idx_hearing_session_date` (`hearing_id`, `session_date`),
    KEY `idx_hearing_session_days_hearing` (`hearing_id`),
    CONSTRAINT `fk_hearing_session_days_hearing`
        FOREIGN KEY (`hearing_id`) REFERENCES `hearings` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 8. Add session_date column to logs if not exists
ALTER TABLE `attendance_logs`
    ADD COLUMN IF NOT EXISTS `session_date` DATE NULL AFTER `registration_id`;

ALTER TABLE `attendance_event_history`
    ADD COLUMN IF NOT EXISTS `session_date` DATE NULL AFTER `registration_id`;

-- 9. Register migration in lph_schema_migrations
INSERT INTO `lph_schema_migrations` (`migration_key`, `description`)
VALUES
('012_multi_day_attendance', 'Enables independent daily attendance tracking and automatic/manual daily closure for multi-day hearings.')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
