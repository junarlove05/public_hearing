<?php
/**
 * modules/stakeholders/ajax_invitation_send.php
 * ------------------------------------------------------------------
 * Marks an invitation's status as 'Sent' and stamps sent_at = NOW().
 * (Actual email delivery would require SMTP/mail service credentials
 * which are outside this app's scope; this records the workflow state.)
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) jsonResponse(false, 'Invalid invitation id.');

$pdo = db();
try {
    $stmt = $pdo->prepare("UPDATE invitations SET status = 'Sent', sent_at = NOW() WHERE id = :id");
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) jsonResponse(false, 'Invitation not found.');

    logActivity(currentUserId(), 'Update', 'Marked invitation #' . $id . ' as Sent');
    jsonResponse(true, 'Invitation marked as sent.');
} catch (PDOException $e) {
    error_log('Invitation send error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred.');
}
