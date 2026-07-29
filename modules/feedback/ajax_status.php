<?php
/**
 * modules/feedback/ajax_status.php
 * ------------------------------------------------------------------
 * Quick status change (New / Reviewed / Closed) without composing a
 * reply. Use ajax_reply.php for the Replied transition, which also
 * records the reply text for audit purposes.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id     = (int)($_POST['id'] ?? 0);
$status = clean($_POST['status'] ?? '');
$allowed = ['New', 'Reviewed', 'Replied', 'Closed'];

if ($id <= 0 || !in_array($status, $allowed, true)) jsonResponse(false, 'Invalid request.');

$pdo = db();
try {
    $stmt = $pdo->prepare('UPDATE feedback SET status = :status WHERE id = :id');
    $stmt->execute([':status' => $status, ':id' => $id]);

    if ($stmt->rowCount() === 0) jsonResponse(false, 'Feedback not found or status unchanged.');

    logActivity(currentUserId(), 'Update', 'Set feedback #' . $id . ' status to ' . $status);
    jsonResponse(true, 'Feedback status updated to ' . $status . '.');
} catch (PDOException $e) {
    error_log('Feedback status update error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred.');
}
