<?php
/**
 * modules/actions/ajax_save.php
 * ------------------------------------------------------------------
 * Handles CREATE and UPDATE for the `actions` table (id=0 means
 * create). On creation, optionally seeds an action_assignments row
 * (assigned office), an initial action_updates entry, and accepts
 * document uploads (multipart form, input name="documents[]").
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id             = (int)($_POST['id'] ?? 0);
$title          = clean($_POST['title'] ?? '');
$description    = clean($_POST['description'] ?? '');
$issueId        = (int)($_POST['issue_id'] ?? 0) ?: null;
$deadline       = clean($_POST['deadline'] ?? '');
$status         = clean($_POST['status'] ?? 'Pending');
$assignedOffice = clean($_POST['assigned_office'] ?? '');

$allowedStatus = ['Pending', 'On Going', 'Completed', 'Cancelled'];

$errors = [];
if ($title === '') $errors[] = 'Title is required.';
if (!in_array($status, $allowedStatus, true)) $errors[] = 'Invalid status value.';
if ($deadline !== '' && !strtotime($deadline)) $errors[] = 'Invalid deadline date.';
if (!empty($errors)) jsonResponse(false, implode(' ', $errors));

$pdo = db();

try {
    if ($id > 0) {
        $before = $pdo->prepare('SELECT status FROM actions WHERE id = :id');
        $before->execute([':id' => $id]);
        $beforeRow = $before->fetch();
        if (!$beforeRow) jsonResponse(false, 'Action not found.');

        $stmt = $pdo->prepare(
            'UPDATE actions SET title = :title, description = :description, issue_id = :issue_id,
             deadline = :deadline, status = :status WHERE id = :id'
        );
        $stmt->execute([
            ':title' => $title, ':description' => $description, ':issue_id' => $issueId,
            ':deadline' => $deadline ?: null, ':status' => $status, ':id' => $id,
        ]);

        if ($beforeRow['status'] !== $status) {
            $pdo->prepare('INSERT INTO action_updates (action_id, update_text, created_at) VALUES (:id, :text, NOW())')
                ->execute([':id' => $id, ':text' => 'Status changed from ' . $beforeRow['status'] . ' to ' . $status . '.']);
        }
        if ($assignedOffice !== '') {
            $pdo->prepare('INSERT INTO action_assignments (action_id, assigned_office, assigned_at) VALUES (:id, :office, NOW())')
                ->execute([':id' => $id, ':office' => $assignedOffice]);
        }

        logActivity(currentUserId(), 'Update', 'Updated action #' . $id . ' (' . $title . ')');
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO actions (title, description, issue_id, status, deadline, created_at)
             VALUES (:title, :description, :issue_id, :status, :deadline, NOW())'
        );
        $stmt->execute([
            ':title' => $title, ':description' => $description, ':issue_id' => $issueId,
            ':status' => $status, ':deadline' => $deadline ?: null,
        ]);
        $id = (int)$pdo->lastInsertId();

        $pdo->prepare('INSERT INTO action_updates (action_id, update_text, created_at) VALUES (:id, :text, NOW())')
            ->execute([':id' => $id, ':text' => 'Action created with status ' . $status . '.']);

        if ($assignedOffice !== '') {
            $pdo->prepare('INSERT INTO action_assignments (action_id, assigned_office, assigned_at) VALUES (:id, :office, NOW())')
                ->execute([':id' => $id, ':office' => $assignedOffice]);
        }

        logActivity(currentUserId(), 'Insert', 'Created action #' . $id . ' (' . $title . ')');
    }

    /* ---- Optional document uploads ---- */
    $uploadedCount = 0;
    $uploadErrors = [];
    if (!empty($_FILES['documents']) && is_array($_FILES['documents']['name'])) {
        $fileCount = count($_FILES['documents']['name']);
        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['documents']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
            $singleFile = [
                'name' => $_FILES['documents']['name'][$i], 'type' => $_FILES['documents']['type'][$i],
                'tmp_name' => $_FILES['documents']['tmp_name'][$i], 'error' => $_FILES['documents']['error'][$i],
                'size' => $_FILES['documents']['size'][$i],
            ];
            $result = handleUpload($singleFile, 'actions');
            if ($result['success']) {
                $pdo->prepare('INSERT INTO action_documents (action_id, file_name, file_path, uploaded_at) VALUES (:aid, :fname, :fpath, NOW())')
                    ->execute([':aid' => $id, ':fname' => $result['file_name'], ':fpath' => $result['file_path']]);
                $uploadedCount++;
            } else {
                $uploadErrors[] = $singleFile['name'] . ': ' . $result['message'];
            }
        }
        if ($uploadedCount > 0) {
            logActivity(currentUserId(), 'Upload', "Uploaded $uploadedCount document(s) to action #$id");
        }
        if (!empty($uploadErrors)) {
            error_log('Action document upload errors for action #' . $id . ': ' . implode(' | ', $uploadErrors));
        }
    }

    $message = $id > 0 ? 'Action saved successfully.' : 'Action created successfully.';
    if (!empty($uploadErrors)) {
        $message .= ' However, some file(s) could not be uploaded: ' . implode('; ', $uploadErrors);
    }

    jsonResponse(true, $message, ['id' => $id, 'documents_uploaded' => $uploadedCount, 'upload_errors' => $uploadErrors]);

} catch (PDOException $e) {
    error_log('Action save error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while saving the action.');
}
