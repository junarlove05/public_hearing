USE `legislative_management_db`;

ALTER TABLE `hearing_issues`
    ADD COLUMN IF NOT EXISTS `resolution_summary` TEXT NULL AFTER `closed_at`,
    ADD COLUMN IF NOT EXISTS `resolved_by` INT NULL AFTER `resolution_summary`,
    ADD COLUMN IF NOT EXISTS `resolved_at` DATETIME NULL AFTER `resolved_by`;

CREATE TABLE IF NOT EXISTS `hearing_issue_documents` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `issue_id` INT NOT NULL,
    `file_name` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `document_type` VARCHAR(100) NOT NULL DEFAULT 'Supporting Document',
    `description` TEXT DEFAULT NULL,
    `visibility` VARCHAR(30) NOT NULL DEFAULT 'Internal',
    `uploaded_by` INT DEFAULT NULL,
    `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_hearing_issue_documents_issue` (`issue_id`,`uploaded_at`),
    CONSTRAINT `fk_hearing_issue_documents_issue`
        FOREIGN KEY (`issue_id`) REFERENCES `hearing_issues` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_hearing_issue_documents_user`
        FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `lph_schema_migrations` (`migration_key`,`description`)
VALUES (
    '009_issue_workflow_finalization',
    'Adds issue resolution metadata and supporting-document workflow.'
)
ON DUPLICATE KEY UPDATE `description`=VALUES(`description`);

SELECT
    EXISTS(
        SELECT 1 FROM information_schema.tables
        WHERE table_schema=DATABASE() AND table_name='hearing_issue_documents'
    ) AS issue_documents_ok,
    EXISTS(
        SELECT 1 FROM information_schema.columns
        WHERE table_schema=DATABASE() AND table_name='hearing_issues'
          AND column_name='resolution_summary'
    ) AS issue_resolution_ok;
