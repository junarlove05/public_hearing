USE `legislative_management_db`;

CREATE TABLE IF NOT EXISTS `lph_schema_migrations` (
    `id` int NOT NULL AUTO_INCREMENT,
    `migration_key` varchar(150) NOT NULL,
    `description` varchar(255) DEFAULT NULL,
    `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_lph_schema_migrations_key` (`migration_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `hearing_history` (
    `id` bigint NOT NULL AUTO_INCREMENT,
    `hearing_id` int NOT NULL,
    `action` varchar(100) NOT NULL,
    `previous_status` varchar(50) DEFAULT NULL,
    `new_status` varchar(50) DEFAULT NULL,
    `details` text DEFAULT NULL,
    `changed_by` int DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_hearing_history_hearing` (`hearing_id`, `created_at`),
    KEY `idx_hearing_history_user` (`changed_by`),
    CONSTRAINT `fk_hearing_history_hearing`
        FOREIGN KEY (`hearing_id`) REFERENCES `hearings` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_hearing_history_user`
        FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `lph_schema_migrations` (`migration_key`, `description`)
VALUES (
    '005_hearing_scheduling_complete',
    'Adds hearing workflow history required by the completed Hearing Scheduling module.'
)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`);

SELECT
    EXISTS(
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'hearings'
    ) AS hearings_ok,
    EXISTS(
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'hearing_documents'
    ) AS hearing_documents_ok,
    EXISTS(
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'hearing_history'
    ) AS hearing_history_ok,
    EXISTS(
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'hearings'
          AND column_name = 'legislative_item_id'
    ) AS legislative_item_link_ok,
    EXISTS(
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'hearings'
          AND column_name = 'registration_deadline'
    ) AS registration_deadline_ok,
    EXISTS(
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'hearings'
          AND column_name = 'maximum_participants'
    ) AS maximum_participants_ok,
    EXISTS(
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'hearings'
          AND column_name = 'meeting_link'
    ) AS meeting_link_ok;
