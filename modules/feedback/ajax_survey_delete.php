<?php
/**
 * modules/feedback/ajax_survey_delete.php
 * ------------------------------------------------------------------
 * Deletes a survey. DB cascades remove its survey_responses
 * automatically (ON DELETE CASCADE).
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) jsonResponse(false, 'Invalid survey id.');

$pdo = db();
try {
    $stmt = $pdo->prepare('SELECT title FROM surveys WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $survey = $stmt->fetch();
    if (!$survey) jsonResponse(false, 'Survey not found.');

    $del = $pdo->prepare('DELETE FROM surveys WHERE id = :id');
    $del->execute([':id' => $id]);

    logActivity(currentUserId(), 'Delete', 'Deleted survey #' . $id . ' (' . $survey['title'] . ')');
    jsonResponse(true, 'Survey deleted successfully.');
} catch (PDOException $e) {
    error_log('Survey delete error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while deleting the survey.');
}
