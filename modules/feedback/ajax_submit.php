<?php
/**
 * modules/feedback/ajax_submit.php
 * ------------------------------------------------------------------
 * Handles feedback form submissions. Deliberately does NOT require
 * login (public feedback collection), but still requires a valid
 * CSRF token — auth.php starts a PHP session for every visitor
 * (authenticated or not), so csrfToken()/requireCsrf() work fine for
 * anonymous submitters too.
 *
 * AI INTEGRATION: after the feedback is safely saved, we attempt AI
 * sentiment analysis (see includes/AI/AIAnalysisManager.php). This
 * NEVER blocks or fails the feedback submission itself — if Ollama is
 * unavailable, slow, or misconfigured, the citizen still gets a normal
 * success response and their feedback is saved exactly as before; the
 * AI analysis is a background enhancement, not a dependency.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/ai_config.php';
require_once __DIR__ . '/../../includes/AI/AIAnalysisManager.php';
// NOTE: intentionally no requireLogin() call — this is the public submission endpoint.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$name       = clean($_POST['name'] ?? '');
$email      = clean($_POST['email'] ?? '');
$categoryId = (int)($_POST['category_id'] ?? 0) ?: null;
$subject    = clean($_POST['subject'] ?? '');
$message    = clean($_POST['message'] ?? '');

$errors = [];
if ($name === '') $errors[] = 'Name is required.';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
if ($message === '') $errors[] = 'Message is required.';

if (!empty($errors)) jsonResponse(false, implode(' ', $errors));

try {
    $stmt = db()->prepare(
        "INSERT INTO feedback (name, email, category_id, subject, message, status, submitted_at)
         VALUES (:name, :email, :cat, :subject, :message, 'New', NOW())"
    );
    $stmt->execute([
        ':name' => $name, ':email' => $email, ':cat' => $categoryId,
        ':subject' => $subject, ':message' => $message,
    ]);
    $id = (int)db()->lastInsertId();

    logActivity(currentUserId(), 'Insert', 'New feedback submitted by ' . $name . ' (#' . $id . ')');

    // FIX/DESIGN: send the success response to the citizen FIRST, then run
    // AI analysis. fastcgi_finish_request() (available under PHP-FPM) closes
    // the HTTP connection immediately while the script keeps running, so the
    // citizen isn't kept waiting on a local LLM inference call that can take
    // anywhere from 1 to 30+ seconds depending on hardware. Under classic
    // mod_php (common on XAMPP/Apache), that function doesn't exist, so we
    // fall back to running the analysis synchronously before responding —
    // slower, but still correct and never breaks the submission itself.
    if (function_exists('fastcgi_finish_request')) {
        jsonResponsePrepare(true, 'Thank you! Your feedback has been submitted successfully.', ['id' => $id]);
        fastcgi_finish_request();
        AIAnalysisManager::analyzeAndStore($id, $message);
        exit;
    }

    // No fastcgi_finish_request() available: analyze synchronously (bounded
    // by AI_REQUEST_TIMEOUT_SECONDS) before responding. Any AI failure is
    // caught inside AIAnalysisManager and never surfaces as a submission error.
    AIAnalysisManager::analyzeAndStore($id, $message);
    jsonResponse(true, 'Thank you! Your feedback has been submitted successfully.', ['id' => $id]);

} catch (PDOException $e) {
    error_log('Feedback submit error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while submitting your feedback. Please try again.');
}
