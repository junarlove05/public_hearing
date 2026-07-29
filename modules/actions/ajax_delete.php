<?php
/**
 * modules/actions/ajax_delete.php
 * ------------------------------------------------------------------
 * Deletes an action. DB cascades remove its action_assignments,
 * action_updates, and action_documents rows automatically (ON DELETE
 * CASCADE), but we remove the physical uploaded files first.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) jsonResponse(false, 'Invalid action id.');

$pdo = db();
try {
    $stmt = $pdo->prepare('SELECT title FROM actions WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $action = $stmt->fetch();
    if (!$action) jsonResponse(false, 'Action not found.');

    $docStmt = $pdo->prepare('SELECT file_path FROM action_documents WHERE action_id = :id');
    $docStmt->execute([':id' => $id]);
    foreach ($docStmt->fetchAll() as $doc) {
        $fullPath = rtrim(UPLOAD_DIR, '/') . '/' . $doc['file_path'];
        if (is_file($fullPath)) @unlink($fullPath);
    }

    $del = $pdo->prepare('DELETE FROM actions WHERE id = :id');
    $del->execute([':id' => $id]);

    logActivity(currentUserId(), 'Delete', 'Deleted action #' . $id . ' (' . $action['title'] . ')');
    jsonResponse(true, 'Action deleted successfully.');

} catch (PDOException $e) {
    error_log('Action delete error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while deleting the action.');
}
