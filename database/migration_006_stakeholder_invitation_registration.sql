USE `legislative_management_db`;

CREATE TABLE IF NOT EXISTS `stakeholder_history` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `stakeholder_id` INT NOT NULL,
    `action` VARCHAR(100) NOT NULL,
    `details` TEXT DEFAULT NULL,
    `changed_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_stakeholder_history_stakeholder` (`stakeholder_id`,`created_at`),
    CONSTRAINT `fk_stakeholder_history_stakeholder`
        FOREIGN KEY (`stakeholder_id`) REFERENCES `stakeholders` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_stakeholder_history_user`
        FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `invitation_history` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `invitation_id` INT NOT NULL,
    `previous_status` VARCHAR(50) DEFAULT NULL,
    `new_status` VARCHAR(50) DEFAULT NULL,
    `details` TEXT DEFAULT NULL,
    `changed_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_invitation_history_invitation` (`invitation_id`,`created_at`),
    CONSTRAINT `fk_invitation_history_invitation`
        FOREIGN KEY (`invitation_id`) REFERENCES `invitations` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_invitation_history_user`
        FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `registration_history` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `registration_id` INT NOT NULL,
    `previous_status` VARCHAR(50) DEFAULT NULL,
    `new_status` VARCHAR(50) DEFAULT NULL,
    `details` TEXT DEFAULT NULL,
    `changed_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_registration_history_registration` (`registration_id`,`created_at`),
    CONSTRAINT `fk_registration_history_registration`
        FOREIGN KEY (`registration_id`) REFERENCES `registrations` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_registration_history_user`
        FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `lph_schema_migrations` (`migration_key`,`description`)
VALUES
('006_stakeholder_invitation_registration',
 'Completes stakeholder, invitation, registration, verification and QR workflow support.')
ON DUPLICATE KEY UPDATE `description`=VALUES(`description`);

SELECT
    EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='stakeholder_history') AS stakeholder_history_ok,
    EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='invitation_history') AS invitation_history_ok,
    EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='registration_history') AS registration_history_ok;
