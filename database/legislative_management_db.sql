
CREATE TABLE IF NOT EXISTS roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role_id INT NOT NULL,
  status VARCHAR(50) DEFAULT 'Active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (role_id) REFERENCES roles(id)
);

CREATE TABLE IF NOT EXISTS committees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  description TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS hearing_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS hearings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  hearing_type_id INT,
  committee_id INT,
  venue VARCHAR(255),
  hearing_date DATE NOT NULL,
  hearing_time TIME NOT NULL,
  status VARCHAR(50) NOT NULL DEFAULT 'Upcoming',
  description TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (hearing_type_id) REFERENCES hearing_types(id),
  FOREIGN KEY (committee_id) REFERENCES committees(id)
);

CREATE TABLE IF NOT EXISTS hearing_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  hearing_id INT NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (hearing_id) REFERENCES hearings(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS stakeholder_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS stakeholders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  phone VARCHAR(50),
  organization VARCHAR(255),
  category_id INT,
  status VARCHAR(50) DEFAULT 'Pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES stakeholder_categories(id)
);

CREATE TABLE IF NOT EXISTS invitations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  stakeholder_id INT NOT NULL,
  hearing_id INT,
  status VARCHAR(50) DEFAULT 'Pending',
  invitation_code VARCHAR(100) UNIQUE,
  sent_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (stakeholder_id) REFERENCES stakeholders(id) ON DELETE CASCADE,
  FOREIGN KEY (hearing_id) REFERENCES hearings(id)
);

CREATE TABLE IF NOT EXISTS registrations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  stakeholder_id INT NOT NULL,
  hearing_id INT,
  registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (stakeholder_id) REFERENCES stakeholders(id) ON DELETE CASCADE,
  FOREIGN KEY (hearing_id) REFERENCES hearings(id)
);

CREATE TABLE IF NOT EXISTS qr_codes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  stakeholder_id INT NOT NULL,
  code_value VARCHAR(255) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (stakeholder_id) REFERENCES stakeholders(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  stakeholder_id INT NOT NULL,
  hearing_id INT NOT NULL,
  status VARCHAR(50) DEFAULT 'Present',
  checked_in_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (stakeholder_id) REFERENCES stakeholders(id) ON DELETE CASCADE,
  FOREIGN KEY (hearing_id) REFERENCES hearings(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS attendance_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  stakeholder_id INT NOT NULL,
  hearing_id INT NOT NULL,
  action VARCHAR(100) NOT NULL,
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (stakeholder_id) REFERENCES stakeholders(id) ON DELETE CASCADE,
  FOREIGN KEY (hearing_id) REFERENCES hearings(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS feedback_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS feedback (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(150) NOT NULL,
  category_id INT,
  subject VARCHAR(255),
  message TEXT NOT NULL,
  status VARCHAR(50) DEFAULT 'New',
  submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES feedback_categories(id)
);

CREATE TABLE IF NOT EXISTS surveys (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  description TEXT,
  status VARCHAR(50) DEFAULT 'Active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS survey_responses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  survey_id INT NOT NULL,
  respondent_name VARCHAR(150),
  respondent_email VARCHAR(150),
  response_text TEXT NOT NULL,
  submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


CREATE TABLE systems (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    base_url VARCHAR(255),
    status VARCHAR(30) NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
);


CREATE TABLE permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    system_id INT NULL,
    code VARCHAR(150) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_permissions_system
        FOREIGN KEY (system_id)
        REFERENCES systems(id)
        ON DELETE SET NULL
);

CREATE TABLE role_permissions (
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (role_id, permission_id),

    CONSTRAINT fk_role_permissions_role
        FOREIGN KEY (role_id)
        REFERENCES roles(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_role_permissions_permission
        FOREIGN KEY (permission_id)
        REFERENCES permissions(id)
        ON DELETE CASCADE
);

CREATE TABLE user_roles (
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    assigned_by INT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (user_id, role_id),

    CONSTRAINT fk_user_roles_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_user_roles_role
        FOREIGN KEY (role_id)
        REFERENCES roles(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_user_roles_assigned_by
        FOREIGN KEY (assigned_by)
        REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE TABLE user_system_access (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    system_id INT NOT NULL,
    access_level VARCHAR(50) DEFAULT 'Standard',
    status VARCHAR(30) DEFAULT 'Active',
    granted_by INT NULL,
    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_user_system (user_id, system_id),

    CONSTRAINT fk_user_system_access_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_user_system_access_system
        FOREIGN KEY (system_id)
        REFERENCES systems(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_user_system_access_granted_by
        FOREIGN KEY (granted_by)
        REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE TABLE offices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) UNIQUE,
    name VARCHAR(200) NOT NULL,
    office_type VARCHAR(100),
    status VARCHAR(30) DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NULL,
    code VARCHAR(50) UNIQUE,
    name VARCHAR(200) NOT NULL,
    status VARCHAR(30) DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_departments_office
        FOREIGN KEY (office_id)
        REFERENCES offices(id)
        ON DELETE SET NULL
);

ALTER TABLE users
    ADD COLUMN username VARCHAR(100) NULL AFTER id,
    ADD COLUMN office_id INT NULL AFTER role_id,
    ADD COLUMN department_id INT NULL AFTER office_id,
    ADD COLUMN phone VARCHAR(50) NULL AFTER email,
    ADD COLUMN last_login_at DATETIME NULL,
    ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    ADD COLUMN deleted_at DATETIME NULL,
    ADD UNIQUE KEY uq_users_username (username),
    ADD CONSTRAINT fk_users_office
        FOREIGN KEY (office_id)
        REFERENCES offices(id)
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_users_department
        FOREIGN KEY (department_id)
        REFERENCES departments(id)
        ON DELETE SET NULL;
		
		
		CREATE TABLE legislative_item_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


CREATE TABLE legislative_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NULL UNIQUE,
    reference_number VARCHAR(100) NOT NULL UNIQUE,
    item_type_id INT NOT NULL,
    origin_system_id INT NULL,
    originating_office_id INT NULL,
    title VARCHAR(255) NOT NULL,
    summary TEXT,
    current_status VARCHAR(100) NOT NULL DEFAULT 'Draft',
    priority_level VARCHAR(50) DEFAULT 'Normal',
    visibility VARCHAR(30) DEFAULT 'Internal',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,

    CONSTRAINT fk_legislative_items_type
        FOREIGN KEY (item_type_id)
        REFERENCES legislative_item_types(id),

    CONSTRAINT fk_legislative_items_system
        FOREIGN KEY (origin_system_id)
        REFERENCES systems(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_legislative_items_office
        FOREIGN KEY (originating_office_id)
        REFERENCES offices(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_legislative_items_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE TABLE legislative_item_status_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    legislative_item_id INT NOT NULL,
    previous_status VARCHAR(100),
    new_status VARCHAR(100) NOT NULL,
    remarks TEXT,
    changed_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_legislative_status_item
        FOREIGN KEY (legislative_item_id)
        REFERENCES legislative_items(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_legislative_status_user
        FOREIGN KEY (changed_by)
        REFERENCES users(id)
        ON DELETE SET NULL
);

ALTER TABLE committees
    ADD COLUMN office_id INT NULL,
    ADD COLUMN status VARCHAR(30) DEFAULT 'Active',
    ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    ADD CONSTRAINT fk_committees_office
        FOREIGN KEY (office_id)
        REFERENCES offices(id)
        ON DELETE SET NULL;
		

CREATE TABLE committee_members (
    committee_id INT NOT NULL,
    user_id INT NOT NULL,
    position VARCHAR(100),
    is_active TINYINT(1) DEFAULT 1,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (committee_id, user_id),

    CONSTRAINT fk_committee_members_committee
        FOREIGN KEY (committee_id)
        REFERENCES committees(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_committee_members_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
);


ALTER TABLE hearings
    ADD COLUMN legislative_item_id INT NULL AFTER id,
    ADD COLUMN reference_number VARCHAR(100) NULL AFTER legislative_item_id,
    ADD COLUMN end_date DATE NULL AFTER hearing_date,
    ADD COLUMN end_time TIME NULL AFTER hearing_time,
    ADD COLUMN registration_deadline DATETIME NULL,
    ADD COLUMN maximum_participants INT NULL,
    ADD COLUMN meeting_link VARCHAR(500) NULL,
    ADD COLUMN visibility VARCHAR(30) DEFAULT 'Public',
    ADD COLUMN created_by INT NULL,
    ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    ADD COLUMN cancelled_at DATETIME NULL,
    ADD COLUMN cancellation_reason TEXT NULL,
    ADD UNIQUE KEY uq_hearings_reference_number (reference_number),
    ADD CONSTRAINT fk_hearings_legislative_item
        FOREIGN KEY (legislative_item_id)
        REFERENCES legislative_items(id)
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_hearings_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE SET NULL;
		
ALTER TABLE hearing_documents
    ADD COLUMN document_type VARCHAR(100) DEFAULT 'Supporting Document',
    ADD COLUMN description TEXT NULL,
    ADD COLUMN version_number INT DEFAULT 1,
    ADD COLUMN visibility VARCHAR(30) DEFAULT 'Internal',
    ADD COLUMN uploaded_by INT NULL,
    ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    ADD CONSTRAINT fk_hearing_documents_uploaded_by
        FOREIGN KEY (uploaded_by)
        REFERENCES users(id)
        ON DELETE SET NULL;

ALTER TABLE stakeholders
    ADD COLUMN user_id INT NULL AFTER id,
    ADD COLUMN address TEXT NULL,
    ADD COLUMN sector VARCHAR(150) NULL,
    ADD COLUMN verified_at DATETIME NULL,
    ADD COLUMN verified_by INT NULL,
    ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    ADD CONSTRAINT fk_stakeholders_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_stakeholders_verified_by
        FOREIGN KEY (verified_by)
        REFERENCES users(id)
        ON DELETE SET NULL;
		
ALTER TABLE invitations
    ADD COLUMN invited_by INT NULL,
    ADD COLUMN responded_at DATETIME NULL,
    ADD COLUMN expires_at DATETIME NULL,
    ADD COLUMN remarks TEXT NULL,
    ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    ADD CONSTRAINT fk_invitations_invited_by
        FOREIGN KEY (invited_by)
        REFERENCES users(id)
        ON DELETE SET NULL;
		
ALTER TABLE invitations
    ADD UNIQUE KEY uq_invitation_stakeholder_hearing
        (stakeholder_id, hearing_id);
		

ALTER TABLE registrations
    ADD COLUMN registration_code VARCHAR(100) NULL,
    ADD COLUMN registration_status VARCHAR(50) DEFAULT 'Pending',
    ADD COLUMN attendance_type VARCHAR(50) DEFAULT 'On-site',
    ADD COLUMN approved_by INT NULL,
    ADD COLUMN approved_at DATETIME NULL,
    ADD COLUMN rejection_reason TEXT NULL,
    ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    ADD UNIQUE KEY uq_registration_code (registration_code),
    ADD CONSTRAINT fk_registrations_approved_by
        FOREIGN KEY (approved_by)
        REFERENCES users(id)
        ON DELETE SET NULL;
		
ALTER TABLE registrations
    ADD UNIQUE KEY uq_registration_stakeholder_hearing
        (stakeholder_id, hearing_id);
		
ALTER TABLE qr_codes
    ADD COLUMN registration_id INT NULL AFTER stakeholder_id,
    ADD COLUMN hearing_id INT NULL AFTER registration_id,
    ADD COLUMN status VARCHAR(30) DEFAULT 'Active',
    ADD COLUMN expires_at DATETIME NULL,
    ADD COLUMN used_at DATETIME NULL,
    ADD CONSTRAINT fk_qr_registration
        FOREIGN KEY (registration_id)
        REFERENCES registrations(id)
        ON DELETE CASCADE,
    ADD CONSTRAINT fk_qr_hearing
        FOREIGN KEY (hearing_id)
        REFERENCES hearings(id)
        ON DELETE CASCADE;


ALTER TABLE attendance
    ADD COLUMN registration_id INT NULL AFTER hearing_id,
    ADD COLUMN attendance_type VARCHAR(50) DEFAULT 'On-site',
    ADD COLUMN check_in_method VARCHAR(50) DEFAULT 'Manual',
    ADD COLUMN checked_out_at DATETIME NULL,
    ADD COLUMN verified_by INT NULL,
    ADD COLUMN remarks TEXT NULL,
    ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    ADD CONSTRAINT fk_attendance_registration
        FOREIGN KEY (registration_id)
        REFERENCES registrations(id)
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_attendance_verified_by
        FOREIGN KEY (verified_by)
        REFERENCES users(id)
        ON DELETE SET NULL;
		
		
		ALTER TABLE attendance
    ADD UNIQUE KEY uq_attendance_stakeholder_hearing
        (stakeholder_id, hearing_id);
		
		
		ALTER TABLE feedback
    ADD COLUMN hearing_id INT NULL AFTER id,
    ADD COLUMN legislative_item_id INT NULL AFTER hearing_id,
    ADD COLUMN stakeholder_id INT NULL AFTER legislative_item_id,
    ADD COLUMN user_id INT NULL AFTER stakeholder_id,
    ADD COLUMN feedback_position VARCHAR(50) NULL,
    ADD COLUMN is_anonymous TINYINT(1) DEFAULT 0,
    ADD COLUMN visibility VARCHAR(30) DEFAULT 'Internal',
    ADD COLUMN validated_by INT NULL,
    ADD COLUMN validated_at DATETIME NULL,
    ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    ADD CONSTRAINT fk_feedback_hearing
        FOREIGN KEY (hearing_id)
        REFERENCES hearings(id)
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_feedback_legislative_item
        FOREIGN KEY (legislative_item_id)
        REFERENCES legislative_items(id)
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_feedback_stakeholder
        FOREIGN KEY (stakeholder_id)
        REFERENCES stakeholders(id)
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_feedback_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_feedback_validated_by
        FOREIGN KEY (validated_by)
        REFERENCES users(id)
        ON DELETE SET NULL;

-->>14. Normalize surveys
-->>Keep survey_responses temporarily for compatibility, but use these new tables for future surveys.		
		ALTER TABLE surveys
    ADD COLUMN hearing_id INT NULL,
    ADD COLUMN legislative_item_id INT NULL,
    ADD COLUMN created_by INT NULL,
    ADD COLUMN opens_at DATETIME NULL,
    ADD COLUMN closes_at DATETIME NULL,
    ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    ADD CONSTRAINT fk_surveys_hearing
        FOREIGN KEY (hearing_id)
        REFERENCES hearings(id)
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_surveys_legislative_item
        FOREIGN KEY (legislative_item_id)
        REFERENCES legislative_items(id)
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_surveys_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE SET NULL;
		
-->> Add:

CREATE TABLE survey_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    survey_id INT NOT NULL,
    question_text TEXT NOT NULL,
    question_type VARCHAR(50) NOT NULL,
    is_required TINYINT(1) DEFAULT 0,
    sequence_number INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_survey_questions_survey
        FOREIGN KEY (survey_id)
        REFERENCES surveys(id)
        ON DELETE CASCADE
);

CREATE TABLE survey_question_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_id INT NOT NULL,
    option_text VARCHAR(255) NOT NULL,
    sequence_number INT DEFAULT 1,

    CONSTRAINT fk_survey_options_question
        FOREIGN KEY (question_id)
        REFERENCES survey_questions(id)
        ON DELETE CASCADE
);

CREATE TABLE survey_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    survey_id INT NOT NULL,
    stakeholder_id INT NULL,
    user_id INT NULL,
    respondent_name VARCHAR(150),
    respondent_email VARCHAR(150),
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_survey_submissions_survey
        FOREIGN KEY (survey_id)
        REFERENCES surveys(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_survey_submissions_stakeholder
        FOREIGN KEY (stakeholder_id)
        REFERENCES stakeholders(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_survey_submissions_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE TABLE survey_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    question_id INT NOT NULL,
    option_id INT NULL,
    answer_text TEXT NULL,
    numeric_value DECIMAL(12,2) NULL,

    CONSTRAINT fk_survey_answers_submission
        FOREIGN KEY (submission_id)
        REFERENCES survey_submissions(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_survey_answers_question
        FOREIGN KEY (question_id)
        REFERENCES survey_questions(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_survey_answers_option
        FOREIGN KEY (option_id)
        REFERENCES survey_question_options(id)
        ON DELETE SET NULL
);

/*
15. Add Issue Logging and Action Tracking

These are required modules for #7 and are missing from the current database.

Issue categories
*/
CREATE TABLE hearing_issue_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE hearing_issues (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hearing_id INT NOT NULL,
    legislative_item_id INT NULL,
    feedback_id INT NULL,
    category_id INT NULL,
    reference_number VARCHAR(100) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    priority VARCHAR(30) DEFAULT 'Normal',
    status VARCHAR(50) DEFAULT 'Open',
    assigned_office_id INT NULL,
    assigned_user_id INT NULL,
    due_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    closed_at DATETIME NULL,

    CONSTRAINT fk_hearing_issues_hearing
        FOREIGN KEY (hearing_id)
        REFERENCES hearings(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_hearing_issues_legislative_item
        FOREIGN KEY (legislative_item_id)
        REFERENCES legislative_items(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_hearing_issues_feedback
        FOREIGN KEY (feedback_id)
        REFERENCES feedback(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_hearing_issues_category
        FOREIGN KEY (category_id)
        REFERENCES hearing_issue_categories(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_hearing_issues_office
        FOREIGN KEY (assigned_office_id)
        REFERENCES offices(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_hearing_issues_user
        FOREIGN KEY (assigned_user_id)
        REFERENCES users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_hearing_issues_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE TABLE hearing_actions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    issue_id INT NOT NULL,
    action_title VARCHAR(255) NOT NULL,
    description TEXT,
    assigned_office_id INT NULL,
    assigned_user_id INT NULL,
    status VARCHAR(50) DEFAULT 'Pending',
    due_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_hearing_actions_issue
        FOREIGN KEY (issue_id)
        REFERENCES hearing_issues(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_hearing_actions_office
        FOREIGN KEY (assigned_office_id)
        REFERENCES offices(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_hearing_actions_user
        FOREIGN KEY (assigned_user_id)
        REFERENCES users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_hearing_actions_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE TABLE hearing_action_updates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    action_id INT NOT NULL,
    previous_status VARCHAR(50),
    new_status VARCHAR(50) NOT NULL,
    update_text TEXT,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_action_updates_action
        FOREIGN KEY (action_id)
        REFERENCES hearing_actions(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_action_updates_user
        FOREIGN KEY (updated_by)
        REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE TABLE hearing_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    issue_id INT NOT NULL,
    response_text TEXT NOT NULL,
    visibility VARCHAR(30) DEFAULT 'Internal',
    prepared_by INT NULL,
    approved_by INT NULL,
    approved_at DATETIME NULL,
    published_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_hearing_responses_issue
        FOREIGN KEY (issue_id)
        REFERENCES hearing_issues(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_hearing_responses_prepared_by
        FOREIGN KEY (prepared_by)
        REFERENCES users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_hearing_responses_approved_by
        FOREIGN KEY (approved_by)
        REFERENCES users(id)
        ON DELETE SET NULL
);

/*
16. Add shared documents, notifications and audit logs
Shared documents
*/

CREATE TABLE documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    system_id INT NULL,
    legislative_item_id INT NULL,
    document_type VARCHAR(100) NOT NULL,
    title VARCHAR(255) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    version_number INT DEFAULT 1,
    visibility VARCHAR(30) DEFAULT 'Internal',
    uploaded_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_documents_system
        FOREIGN KEY (system_id)
        REFERENCES systems(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_documents_legislative_item
        FOREIGN KEY (legislative_item_id)
        REFERENCES legislative_items(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_documents_uploaded_by
        FOREIGN KEY (uploaded_by)
        REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE TABLE notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    system_id INT NULL,
    notification_type VARCHAR(100),
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    target_url VARCHAR(500),
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,

    CONSTRAINT fk_notifications_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_notifications_system
        FOREIGN KEY (system_id)
        REFERENCES systems(id)
        ON DELETE SET NULL
);


CREATE TABLE IF NOT EXISTS activity_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,

    user_id INT NULL,
    system_id INT NULL,

    action VARCHAR(100) NOT NULL,
    details TEXT NULL,

    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,

    created_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_activity_logs_user (
        user_id
    ),

    INDEX idx_activity_logs_system (
        system_id
    ),

    INDEX idx_activity_logs_action (
        action
    ),

    INDEX idx_activity_logs_created_at (
        created_at
    ),

    CONSTRAINT fk_activity_logs_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_activity_logs_system
        FOREIGN KEY (system_id)
        REFERENCES systems(id)
        ON DELETE SET NULL
);

CREATE TABLE audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    system_id INT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(150) NOT NULL,
    entity_id VARCHAR(100) NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_created_at (created_at),

    CONSTRAINT fk_audit_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_audit_system
        FOREIGN KEY (system_id)
        REFERENCES systems(id)
        ON DELETE SET NULL
);

