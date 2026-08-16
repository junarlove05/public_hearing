USE `legislative_management_db`;

ALTER TABLE `hearing_responses`
    ADD COLUMN IF NOT EXISTS `action_id` INT NULL AFTER `issue_id`,
    ADD COLUMN IF NOT EXISTS `reference_number` VARCHAR(100) NULL AFTER `action_id`,
    ADD COLUMN IF NOT EXISTS `status` VARCHAR(50) NOT NULL DEFAULT 'Draft' AFTER `response_text`,
    ADD COLUMN IF NOT EXISTS `reviewed_by` INT NULL AFTER `prepared_by`,
    ADD COLUMN IF NOT EXISTS `reviewed_at` DATETIME NULL AFTER `reviewed_by`,
    ADD COLUMN IF NOT EXISTS `published_by` INT NULL AFTER `published_at`,
    ADD COLUMN IF NOT EXISTS `publication_notes` TEXT NULL AFTER `published_by`;

CREATE TABLE IF NOT EXISTS `hearing_response_history` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `response_id` INT NOT NULL,
    `previous_status` VARCHAR(50) DEFAULT NULL,
    `new_status` VARCHAR(50) DEFAULT NULL,
    `details` TEXT DEFAULT NULL,
    `changed_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_hearing_response_history_response` (`response_id`,`created_at`),
    CONSTRAINT `fk_hearing_response_history_response`
        FOREIGN KEY (`response_id`) REFERENCES `hearing_responses` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_hearing_response_history_user`
        FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `lph_schema_migrations` (`migration_key`,`description`)
VALUES (
    '010_response_action_finalization',
    'Adds complete official response approval/publication workflow linked to issues/actions.'
)
ON DUPLICATE KEY UPDATE `description`=VALUES(`description`);

SELECT
    EXISTS(
        SELECT 1 FROM information_schema.tables
        WHERE table_schema=DATABASE() AND table_name='hearing_response_history'
    ) AS response_history_ok,
    EXISTS(
        SELECT 1 FROM information_schema.columns
        WHERE table_schema=DATABASE() AND table_name='hearing_responses'
          AND column_name='status'
    ) AS response_status_ok;
