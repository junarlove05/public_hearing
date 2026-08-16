<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) jsonResponse(false, 'Invalid action id.');

$pdo = db();
try {
    $stmt = $pdo->prepare('SELECT title FROM hearing_actions WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $title = $stmt->fetchColumn();
    if ($title === false) jsonResponse(false, 'Action not found.');

    $docStmt = $pdo->prepare('SELECT file_path FROM hearing_action_documents WHERE action_id = :id');
    $docStmt->execute([':id' => $id]);
    $paths = $docStmt->fetchAll(PDO::FETCH_COLUMN);

    $del = $pdo->prepare('DELETE FROM hearing_actions WHERE id = :id');
    $del->execute([':id' => $id]);

    foreach ($paths as $path) {
        $fullPath = rtrim(UPLOAD_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string)$path, '/\\');
        if (is_file($fullPath)) @unlink($fullPath);
    }

    logActivity(currentUserId(), 'Delete', 'Deleted action #' . $id . ' (' . $title . ')');
    jsonResponse(true, 'Action deleted successfully.');
} catch (Throwable $e) {
    error_log('Action delete error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while deleting the action.');
}
