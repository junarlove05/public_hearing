<?php
/**
 * modules/actions/document_delete.php
 * ------------------------------------------------------------------
 * Deletes a single row from action_documents plus its file on disk.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$docId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($docId <= 0) jsonResponse(false, 'Invalid document id.');

$pdo = db();
try {
    $stmt = $pdo->prepare('SELECT * FROM action_documents WHERE id = :id');
    $stmt->execute([':id' => $docId]);
    $doc = $stmt->fetch();
    if (!$doc) jsonResponse(false, 'Document not found.');

    $fullPath = rtrim(UPLOAD_DIR, '/') . '/' . $doc['file_path'];
    if (is_file($fullPath)) @unlink($fullPath);

    $del = $pdo->prepare('DELETE FROM action_documents WHERE id = :id');
    $del->execute([':id' => $docId]);

    logActivity(currentUserId(), 'Delete', 'Deleted document "' . $doc['file_name'] . '" from action #' . $doc['action_id']);
    jsonResponse(true, 'Document deleted successfully.');
} catch (PDOException $e) {
    error_log('Action document delete error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while deleting the document.');
}
