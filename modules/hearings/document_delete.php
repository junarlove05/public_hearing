<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/hearing_helpers.php';

requireLogin();

if (!canManage()) {
    jsonResponse(false, 'You do not have permission to delete hearing documents.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

requireCsrf();

$docId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($docId <= 0) {
    jsonResponse(false, 'Invalid document id.');
}

$pdo = db();

try {
    $stmt = $pdo->prepare(
        'SELECT d.*, h.reference_number, h.status AS hearing_status
         FROM hearing_documents d
         JOIN hearings h ON h.id = d.hearing_id
         WHERE d.id = :id'
    );
    $stmt->execute([':id' => $docId]);
    $doc = $stmt->fetch();

    if (!$doc) {
        jsonResponse(false, 'Document not found.');
    }

    $pdo->beginTransaction();

    $del = $pdo->prepare('DELETE FROM hearing_documents WHERE id = :id');
    $del->execute([':id' => $docId]);

    hearingAddHistory(
        $pdo,
        (int)$doc['hearing_id'],
        'Delete Document',
        $doc['hearing_status'],
        $doc['hearing_status'],
        'Deleted document "' . $doc['file_name'] . '".',
        currentUserId()
    );

    logActivity(
        currentUserId(),
        'Delete Hearing Document',
        'Deleted "' . $doc['file_name'] . '" from '
        . ($doc['reference_number'] ?: ('Hearing #' . $doc['hearing_id']))
    );

    $pdo->commit();

    $fullPath = rtrim(UPLOAD_DIR, '/') . '/' . ltrim((string)$doc['file_path'], '/');

    if (is_file($fullPath)) {
        @unlink($fullPath);
    }

    jsonResponse(true, 'Document deleted successfully.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Hearing document delete error: ' . $e->getMessage());

    jsonResponse(
        false,
        APP_DEBUG
            ? 'Unable to delete document: ' . $e->getMessage()
            : 'A database error occurred while deleting the document.'
    );
}
