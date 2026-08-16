<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) jsonResponse(false, 'Invalid action id.');

$pdo = db();
$checkStmt = $pdo->prepare('SELECT id FROM hearing_actions WHERE id = :id');
$checkStmt->execute([':id' => $id]);
if (!$checkStmt->fetchColumn()) jsonResponse(false, 'Action not found.');

if (empty($_FILES['documents']) || !is_array($_FILES['documents']['name'])) {
    jsonResponse(false, 'Please select at least one document.');
}

$uploaded = 0;
$errors = [];
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
        $uploaded++;
    } else {
        $errors[] = $single['name'] . ': ' . $result['message'];
    }
}

if ($uploaded > 0) logActivity(currentUserId(), 'Upload', "Uploaded $uploaded document(s) to action #$id");
if ($uploaded === 0) jsonResponse(false, $errors ? implode('; ', $errors) : 'No documents were uploaded.');
$message = $uploaded . ' document(s) uploaded successfully.';
if ($errors) $message .= ' Some files failed: ' . implode('; ', $errors);
jsonResponse(true, $message, ['uploaded' => $uploaded, 'errors' => $errors]);
