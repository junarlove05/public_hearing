<?php
/**
 * modules/actions/ajax_upload_document.php
 * ------------------------------------------------------------------
 * Uploads one or more documents to an existing action from view.php's
 * upload form (separate from the bulk upload available at creation
 * time in ajax_save.php).
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) jsonResponse(false, 'Invalid action id.');

$pdo = db();
$checkStmt = $pdo->prepare('SELECT id FROM actions WHERE id = :id');
$checkStmt->execute([':id' => $id]);
if (!$checkStmt->fetch()) jsonResponse(false, 'Action not found.');

if (empty($_FILES['documents']) || !is_array($_FILES['documents']['name'])) {
    jsonResponse(false, 'Please choose at least one file to upload.');
}

$uploadedCount = 0;
$errors = [];
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
        $errors[] = $result['message'];
    }
}

if ($uploadedCount > 0) {
    logActivity(currentUserId(), 'Upload', "Uploaded $uploadedCount document(s) to action #$id");
    jsonResponse(true, "$uploadedCount document(s) uploaded successfully." . (!empty($errors) ? ' Some files were skipped: ' . implode(' ', $errors) : ''));
}

jsonResponse(false, !empty($errors) ? implode(' ', $errors) : 'No files were uploaded.');
