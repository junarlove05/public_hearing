USE `legislative_management_db`;

-- ============================================================
-- LPH SUBSYSTEM #7 — FINAL SECURITY / RBAC MIGRATION
-- ============================================================

CREATE TABLE IF NOT EXISTS `lph_login_attempts` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `email_hash` CHAR(64) NOT NULL,
    `ip_hash` CHAR(64) NOT NULL,
    `successful` TINYINT(1) NOT NULL DEFAULT 0,
    `attempted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_lph_login_attempts_email` (`email_hash`,`attempted_at`),
    KEY `idx_lph_login_attempts_ip` (`ip_hash`,`attempted_at`),
    KEY `idx_lph_login_attempts_success` (`successful`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Fine-grained permissions for the Public Hearing subsystem.
-- system_id is resolved by the stable systems.code='hearing'.
-- ------------------------------------------------------------
INSERT INTO `permissions` (`system_id`,`code`,`name`,`description`,`created_at`)
SELECT s.id, p.code, p.name, p.description, NOW()
FROM `systems` s
JOIN (
    SELECT 'lph.dashboard.view' code, 'View LPH Dashboard' name,
           'Allows access to the Public Hearing subsystem dashboard.' description
    UNION ALL SELECT 'lph.records.manage','Manage LPH Core Records',
           'Allows create/update/delete operations for staff-managed Public Hearing records.'
    UNION ALL SELECT 'lph.hearings.view','View Hearings',
           'Allows viewing hearing schedules and details.'
    UNION ALL SELECT 'lph.hearings.manage','Manage Hearings',
           'Allows creation, update, cancellation and document management for hearings.'
    UNION ALL SELECT 'lph.stakeholders.view','View Stakeholders',
           'Allows viewing stakeholder and registration information.'
    UNION ALL SELECT 'lph.stakeholders.manage','Manage Stakeholders',
           'Allows stakeholder verification, invitations and registration management.'
    UNION ALL SELECT 'lph.attendance.view','View Attendance',
           'Allows viewing hearing attendance.'
    UNION ALL SELECT 'lph.attendance.manage','Manage Attendance',
           'Allows check-in, checkout and attendance verification.'
    UNION ALL SELECT 'lph.feedback.submit','Submit Feedback',
           'Allows submitting public feedback and survey responses.'
    UNION ALL SELECT 'lph.feedback.review','Review Feedback',
           'Allows moderation, validation and official reply processing for feedback.'
    UNION ALL SELECT 'lph.surveys.manage','Manage Surveys',
           'Allows creation and management of consultation surveys.'
    UNION ALL SELECT 'lph.issues.view','View Issues',
           'Allows viewing logged consultation issues.'
    UNION ALL SELECT 'lph.issues.manage','Manage Issues',
           'Allows issue assignment, workflow transition and resolution.'
    UNION ALL SELECT 'lph.actions.view','View Actions',
           'Allows viewing response and action tracking.'
    UNION ALL SELECT 'lph.actions.manage','Manage Actions',
           'Allows creation, assignment and progress management for actions.'
    UNION ALL SELECT 'lph.responses.view','View Official Responses',
           'Allows viewing official issue responses.'
    UNION ALL SELECT 'lph.responses.manage','Manage Official Responses',
           'Allows drafting and review of official responses.'
    UNION ALL SELECT 'lph.responses.publish','Publish Official Responses',
           'Allows approval and public publication of official responses.'
    UNION ALL SELECT 'lph.reports.view','View Reports',
           'Allows access to reports and analytics.'
    UNION ALL SELECT 'lph.activity_logs.view','View Activity Logs',
           'Allows access to the Public Hearing subsystem audit trail.'
    UNION ALL SELECT 'lph.users.manage','Manage Users',
           'Allows administration of shared users and Public Hearing system access.'
    UNION ALL SELECT 'lph.system_health.view','View System Health',
           'Allows access to final subsystem readiness and integrity checks.'
) p
WHERE s.code='hearing'
ON DUPLICATE KEY UPDATE
    `name`=VALUES(`name`),
    `description`=VALUES(`description`),
    `system_id`=VALUES(`system_id`);

-- ------------------------------------------------------------
-- Administrator receives all LPH permissions.
-- ------------------------------------------------------------
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`,`created_at`)
SELECT r.id, p.id, NOW()
FROM roles r
JOIN permissions p ON p.code LIKE 'lph.%'
WHERE r.name='Administrator';

-- ------------------------------------------------------------
-- Legislative Staff permissions.
-- ------------------------------------------------------------
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`,`created_at`)
SELECT r.id, p.id, NOW()
FROM roles r
JOIN permissions p ON p.code IN (
    'lph.dashboard.view',
    'lph.records.manage',
    'lph.hearings.view',
    'lph.hearings.manage',
    'lph.stakeholders.view',
    'lph.stakeholders.manage',
    'lph.attendance.view',
    'lph.attendance.manage',
    'lph.feedback.submit',
    'lph.feedback.review',
    'lph.surveys.manage',
    'lph.issues.view',
    'lph.issues.manage',
    'lph.actions.view',
    'lph.actions.manage',
    'lph.responses.view',
    'lph.responses.manage',
    'lph.responses.publish',
    'lph.reports.view'
)
WHERE r.name='Legislative Staff';

-- ------------------------------------------------------------
-- Committee Member permissions.
-- ------------------------------------------------------------
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`,`created_at`)
SELECT r.id, p.id, NOW()
FROM roles r
JOIN permissions p ON p.code IN (
    'lph.dashboard.view',
    'lph.hearings.view',
    'lph.attendance.view',
    'lph.attendance.manage',
    'lph.feedback.submit',
    'lph.feedback.review',
    'lph.issues.view',
    'lph.actions.view',
    'lph.responses.view'
)
WHERE r.name='Committee Member';

-- ------------------------------------------------------------
-- Registered Stakeholder permissions.
-- ------------------------------------------------------------
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`,`created_at`)
SELECT r.id, p.id, NOW()
FROM roles r
JOIN permissions p ON p.code IN (
    'lph.dashboard.view',
    'lph.hearings.view',
    'lph.feedback.submit'
)
WHERE r.name='Registered Stakeholder';

-- ------------------------------------------------------------
-- Public User permissions.
-- ------------------------------------------------------------
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`,`created_at`)
SELECT r.id, p.id, NOW()
FROM roles r
JOIN permissions p ON p.code IN (
    'lph.dashboard.view',
    'lph.hearings.view',
    'lph.feedback.submit'
)
WHERE r.name='Public User';

-- ------------------------------------------------------------
-- Preserve current behavior by explicitly granting active existing
-- users access to the Public Hearing subsystem when no access row
-- currently exists.
-- ------------------------------------------------------------
INSERT INTO `user_system_access`
    (`user_id`,`system_id`,`access_level`,`status`,`granted_by`,`granted_at`,`updated_at`)
SELECT
    u.id,
    s.id,
    CASE r.name
        WHEN 'Administrator' THEN 'Administrator'
        WHEN 'Legislative Staff' THEN 'Staff'
        WHEN 'Committee Member' THEN 'Committee'
        WHEN 'Registered Stakeholder' THEN 'Stakeholder'
        ELSE 'Standard'
    END,
    'Active',
    (
        SELECT ua.id
        FROM users ua
        JOIN roles ra ON ra.id=ua.role_id
        WHERE ra.name='Administrator'
          AND ua.status='Active'
          AND ua.deleted_at IS NULL
        ORDER BY ua.id
        LIMIT 1
    ),
    NOW(),
    NOW()
FROM users u
JOIN roles r ON r.id=u.role_id
JOIN systems s ON s.code='hearing'
LEFT JOIN user_system_access usa
       ON usa.user_id=u.id
      AND usa.system_id=s.id
WHERE u.deleted_at IS NULL
  AND usa.id IS NULL
ON DUPLICATE KEY UPDATE
    `updated_at`=VALUES(`updated_at`);

INSERT INTO `lph_schema_migrations` (`migration_key`,`description`)
VALUES (
    '011_security_rbac_final',
    'Final LPH fine-grained permissions, system access backfill and login-rate-limit storage.'
)
ON DUPLICATE KEY UPDATE
    `description`=VALUES(`description`);

-- ------------------------------------------------------------
-- Verification
-- ------------------------------------------------------------
SELECT
    EXISTS(
        SELECT 1 FROM information_schema.tables
        WHERE table_schema=DATABASE()
          AND table_name='lph_login_attempts'
    ) AS login_attempts_ok,
    (
        SELECT COUNT(*)
        FROM permissions
        WHERE code LIKE 'lph.%'
    ) AS lph_permission_count,
    (
        SELECT COUNT(*)
        FROM role_permissions rp
        JOIN permissions p ON p.id=rp.permission_id
        WHERE p.code LIKE 'lph.%'
    ) AS lph_role_permission_count,
    (
        SELECT COUNT(*)
        FROM user_system_access usa
        JOIN systems s ON s.id=usa.system_id
        WHERE s.code='hearing'
    ) AS hearing_system_access_rows;
