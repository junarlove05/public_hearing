<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) jsonResponse(false, 'Invalid document id.');

$pdo = db();
try {
    $stmt = $pdo->prepare('SELECT * FROM hearing_action_documents WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $doc = $stmt->fetch();
    if (!$doc) jsonResponse(false, 'Document not found.');

    $del = $pdo->prepare('DELETE FROM hearing_action_documents WHERE id = :id');
    $del->execute([':id' => $id]);

    $fullPath = rtrim(UPLOAD_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string)$doc['file_path'], '/\\');
    if (is_file($fullPath)) @unlink($fullPath);

    logActivity(currentUserId(), 'Delete', 'Deleted document "' . $doc['file_name'] . '" from action #' . $doc['action_id']);
    jsonResponse(true, 'Document deleted successfully.');
} catch (Throwable $e) {
    error_log('Action document delete error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while deleting the document.');
}
