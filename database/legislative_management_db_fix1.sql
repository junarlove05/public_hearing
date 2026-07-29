/* ============================================================
   CRITICAL ISSUE 1
   Final Public Hearing Issue and Action Tracking Structure
   ============================================================ */

/* Drop the empty/incomplete versions in dependency order. */
DROP TABLE IF EXISTS hearing_action_documents;
DROP TABLE IF EXISTS hearing_action_assignments;
DROP TABLE IF EXISTS hearing_action_updates;
DROP TABLE IF EXISTS hearing_responses;
DROP TABLE IF EXISTS hearing_actions;
DROP TABLE IF EXISTS hearing_issue_assignments;
DROP TABLE IF EXISTS hearing_issue_history;
DROP TABLE IF EXISTS hearing_issues;


/* ============================================================
   HEARING ISSUES
   ============================================================ */

CREATE TABLE hearing_issues (
    id INT AUTO_INCREMENT PRIMARY KEY,

    hearing_id INT NULL,
    legislative_item_id INT NULL,
    feedback_id INT NULL,
    category_id INT NULL,

    reference_number VARCHAR(100) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,

    priority VARCHAR(30) NOT NULL DEFAULT 'Medium',
    status VARCHAR(50) NOT NULL DEFAULT 'Open',

    assigned_office_id INT NULL,
    assigned_user_id INT NULL,

    due_at DATETIME NULL,
    closed_at DATETIME NULL,

    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_hearing_issues_hearing (hearing_id),
    INDEX idx_hearing_issues_legislative_item (legislative_item_id),
    INDEX idx_hearing_issues_feedback (feedback_id),
    INDEX idx_hearing_issues_category (category_id),
    INDEX idx_hearing_issues_status (status),
    INDEX idx_hearing_issues_priority (priority),
    INDEX idx_hearing_issues_office (assigned_office_id),

    CONSTRAINT fk_hearing_issues_hearing
        FOREIGN KEY (hearing_id)
        REFERENCES hearings(id)
        ON DELETE SET NULL,

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

    CONSTRAINT fk_hearing_issues_assigned_user
        FOREIGN KEY (assigned_user_id)
        REFERENCES users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_hearing_issues_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE SET NULL
);


/* Notes and status changes shown in the Issue Timeline. */
CREATE TABLE hearing_issue_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    issue_id INT NOT NULL,
    note TEXT NOT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_hearing_issue_history_issue (
        issue_id,
        created_at
    ),

    CONSTRAINT fk_hearing_issue_history_issue
        FOREIGN KEY (issue_id)
        REFERENCES hearing_issues(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_hearing_issue_history_user
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE SET NULL
);


/* Assignment and reassignment history. */
CREATE TABLE hearing_issue_assignments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    issue_id INT NOT NULL,

    assigned_office_id INT NULL,
    assigned_user_id INT NULL,
    assigned_by INT NULL,

    remarks TEXT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_hearing_issue_assignments_issue (
        issue_id,
        assigned_at
    ),

    CONSTRAINT fk_hearing_issue_assignments_issue
        FOREIGN KEY (issue_id)
        REFERENCES hearing_issues(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_hearing_issue_assignments_office
        FOREIGN KEY (assigned_office_id)
        REFERENCES offices(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_hearing_issue_assignments_user
        FOREIGN KEY (assigned_user_id)
        REFERENCES users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_hearing_issue_assignments_assigned_by
        FOREIGN KEY (assigned_by)
        REFERENCES users(id)
        ON DELETE SET NULL
);


/* ============================================================
   HEARING ACTIONS
   ============================================================ */

CREATE TABLE hearing_actions (
    id INT AUTO_INCREMENT PRIMARY KEY,

    issue_id INT NULL,
    reference_number VARCHAR(100) NOT NULL UNIQUE,

    title VARCHAR(255) NOT NULL,
    description TEXT NULL,

    assigned_office_id INT NULL,
    assigned_user_id INT NULL,

    status VARCHAR(50) NOT NULL DEFAULT 'Pending',
    deadline DATE NULL,
    completed_at DATETIME NULL,

    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_hearing_actions_issue (issue_id),
    INDEX idx_hearing_actions_status (status),
    INDEX idx_hearing_actions_deadline (deadline),
    INDEX idx_hearing_actions_office (assigned_office_id),

    CONSTRAINT fk_hearing_actions_issue
        FOREIGN KEY (issue_id)
        REFERENCES hearing_issues(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_hearing_actions_office
        FOREIGN KEY (assigned_office_id)
        REFERENCES offices(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_hearing_actions_assigned_user
        FOREIGN KEY (assigned_user_id)
        REFERENCES users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_hearing_actions_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE SET NULL
);


/* General progress updates and status transitions. */
CREATE TABLE hearing_action_updates (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    action_id INT NOT NULL,

    previous_status VARCHAR(50) NULL,
    new_status VARCHAR(50) NULL,
    update_text TEXT NOT NULL,

    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_hearing_action_updates_action (
        action_id,
        created_at
    ),

    CONSTRAINT fk_hearing_action_updates_action
        FOREIGN KEY (action_id)
        REFERENCES hearing_actions(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_hearing_action_updates_user
        FOREIGN KEY (updated_by)
        REFERENCES users(id)
        ON DELETE SET NULL
);


/* Assignment and reassignment history for actions. */
CREATE TABLE hearing_action_assignments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    action_id INT NOT NULL,

    assigned_office_id INT NULL,
    assigned_user_id INT NULL,
    assigned_by INT NULL,

    remarks TEXT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_hearing_action_assignments_action (
        action_id,
        assigned_at
    ),

    CONSTRAINT fk_hearing_action_assignments_action
        FOREIGN KEY (action_id)
        REFERENCES hearing_actions(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_hearing_action_assignments_office
        FOREIGN KEY (assigned_office_id)
        REFERENCES offices(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_hearing_action_assignments_user
        FOREIGN KEY (assigned_user_id)
        REFERENCES users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_hearing_action_assignments_assigned_by
        FOREIGN KEY (assigned_by)
        REFERENCES users(id)
        ON DELETE SET NULL
);


/* Uploaded supporting documents for actions. */
CREATE TABLE hearing_action_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    action_id INT NOT NULL,

    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    document_type VARCHAR(100)
        NOT NULL DEFAULT 'Supporting Document',
    description TEXT NULL,
    visibility VARCHAR(30)
        NOT NULL DEFAULT 'Internal',

    uploaded_by INT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_hearing_action_documents_action (
        action_id,
        uploaded_at
    ),

    CONSTRAINT fk_hearing_action_documents_action
        FOREIGN KEY (action_id)
        REFERENCES hearing_actions(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_hearing_action_documents_user
        FOREIGN KEY (uploaded_by)
        REFERENCES users(id)
        ON DELETE SET NULL
);


/* Official response attached to an issue. */
CREATE TABLE hearing_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    issue_id INT NOT NULL,

    response_text TEXT NOT NULL,
    visibility VARCHAR(30)
        NOT NULL DEFAULT 'Internal',

    prepared_by INT NULL,
    approved_by INT NULL,
    approved_at DATETIME NULL,
    published_at DATETIME NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_hearing_responses_issue (issue_id),

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