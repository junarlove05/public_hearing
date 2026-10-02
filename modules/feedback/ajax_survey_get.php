<?php
declare(strict_types=1);

/**
 * modules/feedback/ajax_survey_get.php
 * ------------------------------------------------------------------
 * Returns a single survey record, its structured questions & options,
 * and response submission count as JSON for the edit modal.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    jsonResponse(false, 'Invalid survey id.');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM surveys WHERE id = :id');
$stmt->execute([':id' => $id]);
$survey = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$survey) {
    jsonResponse(false, 'Survey not found.');
}

// Format datetimes for datetime-local input fields (YYYY-MM-DDTHH:MM)
if (!empty($survey['opens_at'])) {
    $survey['opens_at_input'] = date('Y-m-d\TH:i', strtotime($survey['opens_at']));
} else {
    $survey['opens_at_input'] = '';
}

if (!empty($survey['closes_at'])) {
    $survey['closes_at_input'] = date('Y-m-d\TH:i', strtotime($survey['closes_at']));
} else {
    $survey['closes_at_input'] = '';
}

// Fetch questions and their options
$qStmt = $pdo->prepare('SELECT * FROM survey_questions WHERE survey_id = :id ORDER BY sequence_number, id');
$qStmt->execute([':id' => $id]);
$questions = $qStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($questions as &$q) {
    $oStmt = $pdo->prepare('SELECT * FROM survey_question_options WHERE question_id = :qid ORDER BY sequence_number, id');
    $oStmt->execute([':qid' => $q['id']]);
    $options = $oStmt->fetchAll(PDO::FETCH_ASSOC);
    $q['options'] = $options;
    // Also build multi-line text representation for textarea
    $q['options_text'] = implode("\n", array_column($options, 'option_text'));
}
unset($q);

$cStmt = $pdo->prepare('SELECT COUNT(*) FROM survey_submissions WHERE survey_id = :id');
$cStmt->execute([':id' => $id]);
$submissionCount = (int)$cStmt->fetchColumn();

jsonResponse(true, '', [
    'survey' => $survey,
    'questions' => $questions,
    'submission_count' => $submissionCount
]);
