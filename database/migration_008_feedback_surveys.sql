USE `legislative_management_db`;

ALTER TABLE `feedback`
    ADD COLUMN IF NOT EXISTS `reply_text` TEXT NULL,
    ADD COLUMN IF NOT EXISTS `replied_at` DATETIME NULL,
    ADD COLUMN IF NOT EXISTS `replied_by` INT NULL;

CREATE TABLE IF NOT EXISTS `feedback_history` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `feedback_id` INT NOT NULL,
    `action` VARCHAR(100) NOT NULL,
    `previous_status` VARCHAR(50) DEFAULT NULL,
    `new_status` VARCHAR(50) DEFAULT NULL,
    `details` TEXT DEFAULT NULL,
    `changed_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_feedback_history_feedback` (`feedback_id`,`created_at`),
    CONSTRAINT `fk_feedback_history_feedback`
        FOREIGN KEY (`feedback_id`) REFERENCES `feedback` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_feedback_history_user`
        FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `survey_history` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `survey_id` INT NOT NULL,
    `action` VARCHAR(100) NOT NULL,
    `details` TEXT DEFAULT NULL,
    `changed_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_survey_history_survey` (`survey_id`,`created_at`),
    CONSTRAINT `fk_survey_history_survey`
        FOREIGN KEY (`survey_id`) REFERENCES `surveys` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_survey_history_user`
        FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `lph_schema_migrations` (`migration_key`,`description`)
VALUES
('008_feedback_surveys',
 'Completes public feedback review/reply workflow and normalized survey management.')
ON DUPLICATE KEY UPDATE `description`=VALUES(`description`);

SELECT
    EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='feedback_history') AS feedback_history_ok,
    EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='survey_history') AS survey_history_ok,
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='feedback' AND column_name='reply_text') AS feedback_reply_ok;
