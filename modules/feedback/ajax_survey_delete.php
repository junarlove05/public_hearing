<?php
declare(strict_types=1);

/**
 * modules/feedback/ajax_survey_delete.php
 * ------------------------------------------------------------------
 * Deletes a survey and all associated questions, options, and submissions.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!canManage() && !hasPermission('lph.surveys.manage')) {
    jsonResponse(false, 'You do not have permission to perform this action.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}
requireCsrf();

$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
if ($id <= 0) {
    jsonResponse(false, 'Invalid survey id.');
}

$pdo = db();
try {
    $stmt = $pdo->prepare('SELECT title FROM surveys WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $survey = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$survey) {
        jsonResponse(false, 'Survey not found.');
    }

    $pdo->beginTransaction();

    // Cascades normally handle this, but explicit cleanup guarantees safety
    $pdo->prepare('DELETE FROM survey_answers WHERE submission_id IN (SELECT id FROM survey_submissions WHERE survey_id = :id)')->execute([':id' => $id]);
    $pdo->prepare('DELETE FROM survey_submissions WHERE survey_id = :id')->execute([':id' => $id]);
    $pdo->prepare('DELETE FROM survey_question_options WHERE question_id IN (SELECT id FROM survey_questions WHERE survey_id = :id)')->execute([':id' => $id]);
    $pdo->prepare('DELETE FROM survey_questions WHERE survey_id = :id')->execute([':id' => $id]);
    $pdo->prepare('DELETE FROM surveys WHERE id = :id')->execute([':id' => $id]);

    $pdo->commit();

    logActivity(currentUserId(), 'Delete', 'Deleted survey #' . $id . ' (' . $survey['title'] . ')');
    jsonResponse(true, 'Survey deleted successfully.');
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Survey delete error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while deleting the survey: ' . $e->getMessage());
}
