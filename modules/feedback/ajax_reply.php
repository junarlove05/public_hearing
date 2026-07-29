<?php
/**
 * modules/feedback/ajax_reply.php
 * ------------------------------------------------------------------
 * Records a staff reply to a feedback entry and marks it 'Replied'.
 * If database/migration_001_feedback_reply_tracking.sql has been run,
 * the reply text/timestamp/author are stored on the feedback row itself
 * so the submitter can view it on the public "Track My Feedback" page
 * (modules/feedback/track.php). Either way, the reply is also logged to
 * activity_logs for the internal audit trail. Actual email delivery
 * would require SMTP credentials outside this app's scope — staff copy
 * the composed reply into their own email client to send it, or the
 * submitter can look it up via the public tracking page.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!canManage()) jsonResponse(false, 'You do not have permission to reply to feedback.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id        = (int)($_POST['id'] ?? 0);
$replyText = clean($_POST['reply_text'] ?? '');

if ($id <= 0 || $replyText === '') {
    jsonResponse(false, 'Please write a reply message.');
}

$pdo = db();
try {
    $stmt = $pdo->prepare('SELECT name, email, subject FROM feedback WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $feedback = $stmt->fetch();
    if (!$feedback) jsonResponse(false, 'Feedback not found.');

    if (feedbackTableHasReplyColumns()) {
        $update = $pdo->prepare(
            "UPDATE feedback SET status = 'Replied', reply_text = :reply, replied_at = NOW(), replied_by = :uid WHERE id = :id"
        );
        $update->execute([':reply' => $replyText, ':uid' => currentUserId(), ':id' => $id]);
    } else {
        $update = $pdo->prepare("UPDATE feedback SET status = 'Replied' WHERE id = :id");
        $update->execute([':id' => $id]);
    }

    logActivity(
        currentUserId(),
        'Reply',
        'Replied to feedback #' . $id . ' from ' . $feedback['name'] . ' (' . $feedback['email'] . '). Reply: ' . $replyText
    );

    jsonResponse(true, 'Reply recorded and feedback marked as Replied.');
} catch (PDOException $e) {
    error_log('Feedback reply error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred.');
}
