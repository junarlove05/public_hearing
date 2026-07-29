<?php
/**
 * modules/feedback/ajax_survey_response_delete.php
 * ------------------------------------------------------------------
 * Deletes a single survey response.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) jsonResponse(false, 'Invalid response id.');

$pdo = db();
try {
    $stmt = $pdo->prepare('DELETE FROM survey_responses WHERE id = :id');
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) jsonResponse(false, 'Response not found.');

    logActivity(currentUserId(), 'Delete', 'Deleted survey response #' . $id);
    jsonResponse(true, 'Response deleted successfully.');
} catch (PDOException $e) {
    error_log('Survey response delete error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while deleting the response.');
}
