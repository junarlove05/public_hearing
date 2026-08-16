<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/hearing_helpers.php';

requireLogin();

if (!canManage()) {
    jsonResponse(false, 'You do not have permission to upload hearing documents.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

requireCsrf();

$pdo = db();

$hearingId = (int)($_POST['hearing_id'] ?? 0);
$documentType = clean($_POST['document_type'] ?? 'Supporting Document');
$description = trim((string)($_POST['description'] ?? ''));
$visibility = clean($_POST['visibility'] ?? 'Internal');

if ($hearingId <= 0) {
    jsonResponse(false, 'Invalid hearing.');
}

if (!in_array($visibility, ['Public', 'Internal', 'Restricted'], true)) {
    jsonResponse(false, 'Invalid document visibility.');
}

$hearingStmt = $pdo->prepare(
    'SELECT id, reference_number, title, status
     FROM hearings
     WHERE id = :id'
);
$hearingStmt->execute([':id' => $hearingId]);
$hearing = $hearingStmt->fetch();

if (!$hearing) {
    jsonResponse(false, 'Hearing not found.');
}

if (
    empty($_FILES['document'])
    || ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
) {
    jsonResponse(false, 'Choose a document to upload.');
}

$result = handleUpload($_FILES['document'], 'hearings');

if (!$result['success']) {
    jsonResponse(false, $result['message']);
}

$fullPath = rtrim(UPLOAD_DIR, '/') . '/' . $result['file_path'];

try {
    $pdo->beginTransaction();

    $versionStmt = $pdo->prepare(
        'SELECT COALESCE(MAX(version_number), 0) + 1
         FROM hearing_documents
         WHERE hearing_id = :hearing_id
           AND document_type = :document_type'
    );
    $versionStmt->execute([
        ':hearing_id' => $hearingId,
        ':document_type' => $documentType ?: 'Supporting Document',
    ]);
    $version = max(1, (int)$versionStmt->fetchColumn());

    $stmt = $pdo->prepare(
        'INSERT INTO hearing_documents
            (hearing_id, file_name, file_path, document_type,
             description, version_number, visibility, uploaded_by,
             uploaded_at, updated_at)
         VALUES
            (:hearing_id, :file_name, :file_path, :document_type,
             :description, :version_number, :visibility, :uploaded_by,
             NOW(), NOW())'
    );

    $stmt->execute([
        ':hearing_id' => $hearingId,
        ':file_name' => $result['file_name'],
        ':file_path' => $result['file_path'],
        ':document_type' => $documentType ?: 'Supporting Document',
        ':description' => $description ?: null,
        ':version_number' => $version,
        ':visibility' => $visibility,
        ':uploaded_by' => currentUserId(),
    ]);

    hearingAddHistory(
        $pdo,
        $hearingId,
        'Upload Document',
        $hearing['status'],
        $hearing['status'],
        'Uploaded document "' . $result['file_name'] . '".',
        currentUserId()
    );

    logActivity(
        currentUserId(),
        'Upload Hearing Document',
        'Uploaded "' . $result['file_name'] . '" to '
        . ($hearing['reference_number'] ?: ('Hearing #' . $hearingId))
    );

    $pdo->commit();

    jsonResponse(true, 'Document uploaded successfully.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if (is_file($fullPath)) {
        @unlink($fullPath);
    }

    error_log('Hearing document upload error: ' . $e->getMessage());

    jsonResponse(
        false,
        APP_DEBUG
            ? 'Unable to upload document: ' . $e->getMessage()
            : 'A database error occurred while recording the document.'
    );
}
