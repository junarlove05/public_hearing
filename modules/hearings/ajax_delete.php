<?php
/**
 * modules/hearings/ajax_delete.php
 * ------------------------------------------------------------------
 * Deletes a hearing. The DB schema cascades hearing_documents on
 * delete automatically (ON DELETE CASCADE), but we still remove the
 * physical files from disk first since MySQL can't do that for us.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!canManage()) {
    jsonResponse(false, 'You do not have permission to perform this action.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}
requireCsrf();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    jsonResponse(false, 'Invalid hearing id.');
}

$pdo = db();

try {
    $stmt = $pdo->prepare('SELECT title FROM hearings WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $hearing = $stmt->fetch();

    if (!$hearing) {
        jsonResponse(false, 'Hearing not found.');
    }

    // Remove uploaded document files from disk before the DB cascade removes their rows.
    $docStmt = $pdo->prepare('SELECT file_path FROM hearing_documents WHERE hearing_id = :id');
    $docStmt->execute([':id' => $id]);
    foreach ($docStmt->fetchAll() as $doc) {
        $fullPath = rtrim(UPLOAD_DIR, '/') . '/' . $doc['file_path'];
        if (is_file($fullPath)) @unlink($fullPath);
    }

    $del = $pdo->prepare('DELETE FROM hearings WHERE id = :id');
    $del->execute([':id' => $id]);

    logActivity(currentUserId(), 'Delete', 'Deleted hearing #' . $id . ' (' . $hearing['title'] . ')');
    jsonResponse(true, 'Hearing deleted successfully.');

} catch (PDOException $e) {
    error_log('Hearing delete error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while deleting the hearing.');
}
