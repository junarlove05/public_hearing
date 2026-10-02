<?php
/**
 * Create/update response actions using the current hearing_* schema.
 * Keeps compatibility with the existing form's text-based assigned_office field.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id = (int)($_POST['id'] ?? 0);
$isUpdate = $id > 0;
$title = clean($_POST['title'] ?? '');
$description = clean($_POST['description'] ?? '');
$issueId = (int)($_POST['issue_id'] ?? 0) ?: null;
$deadline = clean($_POST['deadline'] ?? '');
$status = clean($_POST['status'] ?? 'Pending');
$assignedOfficeId = (int)($_POST['assigned_office_id'] ?? 0) ?: null;
$assignedOfficeName = clean($_POST['assigned_office'] ?? '');
$assignedUserId = null;

$allowedStatus = ['Pending', 'On Going', 'Completed', 'Cancelled'];
$errors = [];
if ($title === '') $errors[] = 'Title is required.';
if (!in_array($status, $allowedStatus, true)) $errors[] = 'Invalid status value.';
if ($deadline !== '' && strtotime($deadline) === false) $errors[] = 'Invalid deadline.';
if ($errors) jsonResponse(false, implode(' ', $errors));

$pdo = db();
try {
    if ($issueId) {
        $issueStmt = $pdo->prepare('SELECT id FROM hearing_issues WHERE id = :id');
        $issueStmt->execute([':id' => $issueId]);
        if (!$issueStmt->fetchColumn()) jsonResponse(false, 'Linked issue was not found.');
    }

    if (!$assignedOfficeId && $assignedOfficeName !== '') {
        /*
         * PDO uses native prepared statements in LPH
         * (PDO::ATTR_EMULATE_PREPARES = false), so every named
         * placeholder must be unique. Reusing :value more than once
         * causes SQLSTATE[HY093] and the generic database error.
         *
         * We also allow a simple partial office-name match so values
         * such as "Budget office" can resolve to "City Budget Office".
         */
        $officeStmt = $pdo->prepare(
            "SELECT id, name
             FROM offices
             WHERE status = 'Active'
               AND (
                    LOWER(name) = LOWER(:office_name)
                    OR LOWER(code) = LOWER(:office_code)
                    OR LOWER(name) LIKE LOWER(:office_like)
               )
             ORDER BY
               CASE
                 WHEN LOWER(name) = LOWER(:office_name_exact) THEN 0
                 WHEN LOWER(code) = LOWER(:office_code_exact) THEN 1
                 ELSE 2
               END,
               name
             LIMIT 1"
        );
        $officeStmt->execute([
            ':office_name' => $assignedOfficeName,
            ':office_code' => $assignedOfficeName,
            ':office_like' => '%' . $assignedOfficeName . '%',
            ':office_name_exact' => $assignedOfficeName,
            ':office_code_exact' => $assignedOfficeName,
        ]);
        $office = $officeStmt->fetch();
        if ($office) {
            $assignedOfficeId = (int)$office['id'];
            $assignedOfficeName = $office['name'];
        } else {
            $userStmt = $pdo->prepare(
                "SELECT u.id, u.full_name, r.name AS role_name
                 FROM users u
                 LEFT JOIN roles r ON r.id = u.role_id
                 WHERE u.deleted_at IS NULL
                   AND u.status = 'Active'
                   AND LOWER(r.name) NOT LIKE '%public%'
                   AND LOWER(r.name) NOT LIKE '%stakeholder%'
                   AND (
                        u.full_name = :user_full_name
                        OR u.email = :user_email
                        OR u.username = :user_username
                   )
                 LIMIT 1"
            );
            $userStmt->execute([
                ':user_full_name' => $assignedOfficeName,
                ':user_email' => $assignedOfficeName,
                ':user_username' => $assignedOfficeName,
            ]);
            $user = $userStmt->fetch();
            if (!$user) jsonResponse(false, 'The assignee was not found or is a public user. Use an existing active office or official staff/member.');
            $assignedUserId = (int)$user['id'];
            $assignedOfficeName = $user['full_name'];
        }
    }

    $pdo->beginTransaction();

    if ($id > 0) {
        $beforeStmt = $pdo->prepare(
            'SELECT status, completed_at, assigned_office_id, assigned_user_id
             FROM hearing_actions
             WHERE id = :id FOR UPDATE'
        );
        $beforeStmt->execute([':id' => $id]);
        $before = $beforeStmt->fetch();
        if (!$before) {
            $pdo->rollBack();
            jsonResponse(false, 'Action not found.');
        }

        // The edit modal may leave the assignee field unchanged. Preserve the
        // current assignment unless the user explicitly enters a new one.
        if ($assignedOfficeName === '' && !$assignedOfficeId && !$assignedUserId) {
            $assignedOfficeId = $before['assigned_office_id'] ? (int)$before['assigned_office_id'] : null;
            $assignedUserId = $before['assigned_user_id'] ? (int)$before['assigned_user_id'] : null;
        }

        // If a user has already been assigned, lock it so it cannot be reassigned
        if (!empty($before['assigned_user_id'])) {
            $assignedUserId = (int)$before['assigned_user_id'];
        }

        $completedAt = null;
        if ($status === 'Completed') {
            $completedAt = $before['completed_at'] ?: date('Y-m-d H:i:s');
        }

        $pdo->prepare(
            'UPDATE hearing_actions
             SET title = :title,
                 description = :description,
                 issue_id = :issue_id,
                 assigned_office_id = :assigned_office_id,
                 assigned_user_id = :assigned_user_id,
                 deadline = :deadline,
                 status = :status,
                 completed_at = :completed_at
             WHERE id = :id'
        )->execute([
            ':title' => $title,
            ':description' => $description ?: null,
            ':issue_id' => $issueId,
            ':assigned_office_id' => $assignedOfficeId,
            ':assigned_user_id' => $assignedUserId,
            ':deadline' => $deadline ?: null,
            ':status' => $status,
            ':completed_at' => $completedAt,
            ':id' => $id,
        ]);

        if ($before['status'] !== $status) {
            $pdo->prepare(
                'INSERT INTO hearing_action_updates
                 (action_id, previous_status, new_status, update_text, updated_by, created_at)
                 VALUES (:action_id, :previous_status, :new_status, :text, :updated_by, NOW())'
            )->execute([
                ':action_id' => $id,
                ':previous_status' => $before['status'],
                ':new_status' => $status,
                ':text' => 'Status changed from ' . $before['status'] . ' to ' . $status . '.',
                ':updated_by' => currentUserId(),
            ]);
        }

        if ((int)($before['assigned_office_id'] ?? 0) !== (int)($assignedOfficeId ?? 0) || (int)($before['assigned_user_id'] ?? 0) !== (int)($assignedUserId ?? 0)) {
            $pdo->prepare(
                'INSERT INTO hearing_action_assignments
                 (action_id, assigned_office_id, assigned_user_id, assigned_by, remarks, assigned_at)
                 VALUES (:action_id, :office_id, :user_id, :assigned_by, :remarks, NOW())'
            )->execute([
                ':action_id' => $id,
                ':office_id' => $assignedOfficeId,
                ':user_id' => $assignedUserId,
                ':assigned_by' => currentUserId(),
                ':remarks' => 'Action assignment updated.',
            ]);
        }

        $eventLabel = 'Updated action #' . $id . ' (' . $title . ')';
    } else {
        $reference = 'ACT-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $completedAt = $status === 'Completed' ? date('Y-m-d H:i:s') : null;

        $pdo->prepare(
            'INSERT INTO hearing_actions
             (issue_id, reference_number, title, description, assigned_office_id, assigned_user_id,
              status, deadline, completed_at, created_by, created_at)
             VALUES (:issue_id, :reference_number, :title, :description, :assigned_office_id, :assigned_user_id,
                     :status, :deadline, :completed_at, :created_by, NOW())'
        )->execute([
            ':issue_id' => $issueId,
            ':reference_number' => $reference,
            ':title' => $title,
            ':description' => $description ?: null,
            ':assigned_office_id' => $assignedOfficeId,
            ':assigned_user_id' => $assignedUserId,
            ':status' => $status,
            ':deadline' => $deadline ?: null,
            ':completed_at' => $completedAt,
            ':created_by' => currentUserId(),
        ]);
        $id = (int)$pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO hearing_action_updates
             (action_id, previous_status, new_status, update_text, updated_by, created_at)
             VALUES (:action_id, NULL, :new_status, :text, :updated_by, NOW())'
        )->execute([
            ':action_id' => $id,
            ':new_status' => $status,
            ':text' => 'Action created with status ' . $status . '.',
            ':updated_by' => currentUserId(),
        ]);

        if ($assignedOfficeId || $assignedUserId) {
            $pdo->prepare(
                'INSERT INTO hearing_action_assignments
                 (action_id, assigned_office_id, assigned_user_id, assigned_by, remarks, assigned_at)
                 VALUES (:action_id, :office_id, :user_id, :assigned_by, :remarks, NOW())'
            )->execute([
                ':action_id' => $id,
                ':office_id' => $assignedOfficeId,
                ':user_id' => $assignedUserId,
                ':assigned_by' => currentUserId(),
                ':remarks' => 'Initial action assignment.',
            ]);
        }

        $eventLabel = 'Created action #' . $id . ' (' . $title . ')';
    }

    $uploadedCount = 0;
    $uploadErrors = [];
    if (!empty($_FILES['documents']) && is_array($_FILES['documents']['name'])) {
        $count = count($_FILES['documents']['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($_FILES['documents']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
            $single = [
                'name' => $_FILES['documents']['name'][$i],
                'type' => $_FILES['documents']['type'][$i],
                'tmp_name' => $_FILES['documents']['tmp_name'][$i],
                'error' => $_FILES['documents']['error'][$i],
                'size' => $_FILES['documents']['size'][$i],
            ];
            $result = handleUpload($single, 'actions');
            if ($result['success']) {
                $pdo->prepare(
                    'INSERT INTO hearing_action_documents
                     (action_id, file_name, file_path, document_type, visibility, uploaded_by, uploaded_at)
                     VALUES (:action_id, :file_name, :file_path, :document_type, :visibility, :uploaded_by, NOW())'
                )->execute([
                    ':action_id' => $id,
                    ':file_name' => $result['file_name'],
                    ':file_path' => $result['file_path'],
                    ':document_type' => 'Supporting Document',
                    ':visibility' => 'Internal',
                    ':uploaded_by' => currentUserId(),
                ]);
                $uploadedCount++;
            } else {
                $uploadErrors[] = $single['name'] . ': ' . $result['message'];
            }
        }
    }

    $pdo->commit();
    logActivity(currentUserId(), $isUpdate ? 'Update' : 'Insert', $eventLabel);
    if ($uploadedCount > 0) logActivity(currentUserId(), 'Upload', "Uploaded $uploadedCount document(s) to action #$id");

    $message = 'Action saved successfully.';
    if ($uploadErrors) $message .= ' Some files could not be uploaded: ' . implode('; ', $uploadErrors);
    jsonResponse(true, $message, [
        'id' => $id,
        'documents_uploaded' => $uploadedCount,
        'upload_errors' => $uploadErrors,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Action save error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while saving the action.');
}
