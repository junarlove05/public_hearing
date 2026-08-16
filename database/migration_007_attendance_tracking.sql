USE `legislative_management_db`;

ALTER TABLE `attendance_logs`
    ADD COLUMN IF NOT EXISTS `registration_id` INT NULL AFTER `hearing_id`,
    ADD COLUMN IF NOT EXISTS `actor_user_id` INT NULL AFTER `action`,
    ADD COLUMN IF NOT EXISTS `check_in_method` VARCHAR(50) NULL AFTER `actor_user_id`;

CREATE TABLE IF NOT EXISTS `attendance_event_history` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `attendance_id` INT DEFAULT NULL,
    `stakeholder_id` INT NOT NULL,
    `hearing_id` INT NOT NULL,
    `registration_id` INT DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL,
    `previous_status` VARCHAR(50) DEFAULT NULL,
    `new_status` VARCHAR(50) DEFAULT NULL,
    `check_in_method` VARCHAR(50) DEFAULT NULL,
    `remarks` TEXT DEFAULT NULL,
    `actor_user_id` INT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_attendance_event_hearing` (`hearing_id`,`created_at`),
    KEY `idx_attendance_event_stakeholder` (`stakeholder_id`,`created_at`),
    CONSTRAINT `fk_attendance_event_attendance`
        FOREIGN KEY (`attendance_id`) REFERENCES `attendance` (`id`)
        ON DELETE SET NULL,
    CONSTRAINT `fk_attendance_event_stakeholder`
        FOREIGN KEY (`stakeholder_id`) REFERENCES `stakeholders` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_attendance_event_hearing`
        FOREIGN KEY (`hearing_id`) REFERENCES `hearings` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_attendance_event_registration`
        FOREIGN KEY (`registration_id`) REFERENCES `registrations` (`id`)
        ON DELETE SET NULL,
    CONSTRAINT `fk_attendance_event_user`
        FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `lph_schema_migrations` (`migration_key`,`description`)
VALUES
('007_attendance_tracking',
 'Completes manual/QR-ready attendance check-in, checkout and immutable event history.')
ON DUPLICATE KEY UPDATE `description`=VALUES(`description`);

SELECT
    EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='attendance_event_history') AS attendance_event_history_ok;
