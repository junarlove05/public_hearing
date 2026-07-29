<?php
/**
 * modules/feedback/ajax_survey_response_submit.php
 * ------------------------------------------------------------------
 * Handles survey response submissions from the public survey_form.php.
 * No login required (session/CSRF still apply, same as ajax_submit.php).
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
// Deliberately no requireLogin() — public endpoint.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$surveyId = (int)($_POST['survey_id'] ?? 0);
$name     = clean($_POST['respondent_name'] ?? '');
$email    = clean($_POST['respondent_email'] ?? '');
$response = clean($_POST['response_text'] ?? '');

if ($surveyId <= 0) jsonResponse(false, 'Invalid survey.');
if ($response === '') jsonResponse(false, 'Please write a response before submitting.');
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) jsonResponse(false, 'Please enter a valid email address.');

$pdo = db();
try {
    $surveyStmt = $pdo->prepare("SELECT status FROM surveys WHERE id = :id");
    $surveyStmt->execute([':id' => $surveyId]);
    $survey = $surveyStmt->fetch();
    if (!$survey) jsonResponse(false, 'Survey not found.');
    if ($survey['status'] !== 'Active') jsonResponse(false, 'This survey is no longer accepting responses.');

    $stmt = $pdo->prepare(
        'INSERT INTO survey_responses (survey_id, respondent_name, respondent_email, response_text, submitted_at)
         VALUES (:sid, :name, :email, :response, NOW())'
    );
    $stmt->execute([
        ':sid' => $surveyId, ':name' => $name ?: null, ':email' => $email ?: null, ':response' => $response,
    ]);
    $id = (int)$pdo->lastInsertId();

    logActivity(currentUserId(), 'Insert', 'New survey response submitted for survey #' . $surveyId . ' (#' . $id . ')');
    jsonResponse(true, 'Thank you! Your response has been recorded.', ['id' => $id]);

} catch (PDOException $e) {
    error_log('Survey response submit error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while submitting your response. Please try again.');
}
