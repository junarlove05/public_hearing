INSERT INTO systems (code, name, status) VALUES
('ordinance', 'Ordinance and Resolution Life Cycle Management System', 'Planned'),
('agenda', 'Legislative Agenda and Calendar Management System', 'Planned'),
('voting', 'Voting, Quorum, and Decision Support System', 'Planned'),
('hearing', 'Public Hearing and Consultation Management System', 'Active'),
('citizen', 'Citizen Engagement and Public Feedback Management System', 'Planned');


INSERT INTO legislative_item_types (code, name) VALUES
('ordinance', 'Ordinance'),
('resolution', 'Resolution'),
('proposal', 'Citizen Proposal'),
('petition', 'Petition'),
('executive_request', 'Executive Request'),
('policy_matter', 'Policy Matter'),
('public_issue', 'Public Issue');

USE legislative_management_db;

/* ============================================================
   1. STANDARD SYSTEM ROLES
   These names must match config/config.php exactly.
   ============================================================ */

INSERT INTO roles (name)
VALUES
    ('Administrator'),
    ('Legislative Staff'),
    ('Committee Member'),
    ('Registered Stakeholder'),
    ('Public User')
ON DUPLICATE KEY UPDATE
    name = VALUES(name);


/* ============================================================
   2. FIVE INTEGRATED SUBSYSTEMS
   ============================================================ */

INSERT INTO systems (
    code,
    name,
    description,
    base_url,
    status
)
VALUES
(
    'ordinance',
    'Ordinance and Resolution Life Cycle Management System',
    'Manages ordinances and resolutions from drafting through implementation and revision.',
    NULL,
    'Planned'
),
(
    'agenda',
    'Legislative Agenda and Calendar Management System',
    'Manages legislative priorities, schedules, meetings and deadlines.',
    NULL,
    'Planned'
),
(
    'voting',
    'Voting, Quorum, and Decision Support System',
    'Manages quorum verification, voting, tallying and legislative decisions.',
    NULL,
    'Planned'
),
(
    'hearing',
    'Public Hearing and Consultation Management System',
    'Manages hearings, stakeholders, attendance, feedback, issues and actions.',
    'http://localhost/lph',
    'Active'
),
(
    'citizen',
    'Citizen Engagement and Public Feedback Management System',
    'Manages public feedback, citizen proposals, complaints and official responses.',
    NULL,
    'Planned'
)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    base_url = VALUES(base_url),
    status = VALUES(status);


/* ============================================================
   3. GET THE ADMINISTRATOR ROLE
   ============================================================ */

SET @admin_role_id = (
    SELECT id
    FROM roles
    WHERE name = 'Administrator'
    LIMIT 1
);


/* ============================================================
   4. CREATE THE DEVELOPMENT ADMINISTRATOR ACCOUNT

   Email:    admin@legislative.local
   Password: Admin@123
   ============================================================ */

INSERT INTO users (
    username,
    full_name,
    email,
    phone,
    password,
    role_id,
    office_id,
    department_id,
    status,
    created_at
)
VALUES (
    'admin',
    'System Administrator',
    'admin@legislative.local',
    NULL,
    '$2y$12$7vQcfuIlcWb8KFRnrslnWObtFwcOcDaaorJHmGNdGARDMVvJSxyOS',
    @admin_role_id,
    NULL,
    NULL,
    'Active',
    NOW()
)
ON DUPLICATE KEY UPDATE
    username = VALUES(username),
    full_name = VALUES(full_name),
    password = VALUES(password),
    role_id = VALUES(role_id),
    status = 'Active',
    deleted_at = NULL;


/* ============================================================
   5. GET THE ADMIN USER ID
   ============================================================ */

SET @admin_user_id = (
    SELECT id
    FROM users
    WHERE email = 'admin@legislative.local'
    LIMIT 1
);


/* ============================================================
   6. ASSIGN THE ADMINISTRATOR ROLE
   Keep users.role_id for the current application and user_roles
   for the future shared authentication structure.
   ============================================================ */

INSERT INTO user_roles (
    user_id,
    role_id,
    is_primary,
    assigned_by,
    assigned_at
)
VALUES (
    @admin_user_id,
    @admin_role_id,
    1,
    @admin_user_id,
    NOW()
)
ON DUPLICATE KEY UPDATE
    is_primary = 1,
    assigned_by = VALUES(assigned_by);


/* ============================================================
   7. GRANT ACCESS TO ALL FIVE SUBSYSTEMS
   ============================================================ */

INSERT INTO user_system_access (
    user_id,
    system_id,
    access_level,
    status,
    granted_by,
    granted_at
)
SELECT
    @admin_user_id,
    s.id,
    'Administrator',
    'Active',
    @admin_user_id,
    NOW()
FROM systems s
ON DUPLICATE KEY UPDATE
    access_level = 'Administrator',
    status = 'Active',
    granted_by = @admin_user_id;


/* ============================================================
   8. CREATE GENERAL ACCESS PERMISSIONS
   ============================================================ */

INSERT INTO permissions (
    system_id,
    code,
    name,
    description
)
SELECT
    s.id,
    CONCAT(s.code, '.access'),
    CONCAT(s.name, ' Access'),
    CONCAT('Allows access to the ', s.name, '.')
FROM systems s
ON DUPLICATE KEY UPDATE
    system_id = VALUES(system_id),
    name = VALUES(name),
    description = VALUES(description);


/* ============================================================
   9. GRANT EVERY EXISTING PERMISSION TO ADMINISTRATOR
   This also includes permissions added later when this statement
   is run again.
   ============================================================ */

INSERT IGNORE INTO role_permissions (
    role_id,
    permission_id,
    created_at
)
SELECT
    @admin_role_id,
    p.id,
    NOW()
FROM permissions p;


/* ============================================================
   10. VERIFY THE CREATED ACCOUNT
   ============================================================ */

SELECT
    u.id,
    u.username,
    u.full_name,
    u.email,
    r.name AS role,
    u.status
FROM users u
INNER JOIN roles r
    ON r.id = u.role_id
WHERE u.id = @admin_user_id;

SELECT
    s.code,
    s.name AS subsystem,
    usa.access_level,
    usa.status
FROM user_system_access usa
INNER JOIN systems s
    ON s.id = usa.system_id
WHERE usa.user_id = @admin_user_id
ORDER BY s.id;


/* ============================================================
   11. HEARING ISSUE CATEGORY
   ============================================================ */

INSERT INTO hearing_issue_categories (
    name,
    description
)
VALUES
(
    'Policy Concern',
    'Concerns regarding proposed policies, ordinances or resolutions.'
),
(
    'Public Safety',
    'Issues involving safety, security and community protection.'
),
(
    'Budget and Finance',
    'Concerns involving funding, expenses and financial allocation.'
),
(
    'Environment',
    'Environmental concerns raised during public consultation.'
),
(
    'Infrastructure',
    'Issues involving roads, facilities, utilities and public infrastructure.'
),
(
    'Public Services',
    'Concerns regarding government services and service delivery.'
),
(
    'Legal and Compliance',
    'Issues involving legal requirements and regulatory compliance.'
),
(
    'Other',
    'Issues that do not fall under another available category.'
)
ON DUPLICATE KEY UPDATE
    description = VALUES(description);


/* ============================================================
   12. OFFICES
   ============================================================ */

INSERT INTO offices (
    code,
    name,
    office_type,
    status
)
VALUES
(
    'LEG-OFFICE',
    'Office of the City Council',
    'Legislative',
    'Active'
),
(
    'CITY-LEGAL',
    'City Legal Office',
    'Executive',
    'Active'
),
(
    'CITY-BUDGET',
    'City Budget Office',
    'Executive',
    'Active'
),
(
    'CITY-PLANNING',
    'City Planning and Development Office',
    'Executive',
    'Active'
),
(
    'PUBLIC-INFO',
    'Public Information Office',
    'Executive',
    'Active'
),
(
    'ENVI-OFFICE',
    'Environment and Natural Resources Office',
    'Executive',
    'Active'
)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    office_type = VALUES(office_type),
    status = VALUES(status);