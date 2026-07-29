<?php
/**
 * modules/actions/ajax_add_update.php
 * ------------------------------------------------------------------
 * Adds a progress update entry to action_updates (the timeline).
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id         = (int)($_POST['id'] ?? 0);
$updateText = clean($_POST['update_text'] ?? '');

if ($id <= 0 || $updateText === '') jsonResponse(false, 'Please write an update before submitting.');

$pdo = db();
try {
    $checkStmt = $pdo->prepare('SELECT id FROM actions WHERE id = :id');
    $checkStmt->execute([':id' => $id]);
    if (!$checkStmt->fetch()) jsonResponse(false, 'Action not found.');

    $user = currentUser();
    $stmt = $pdo->prepare('INSERT INTO action_updates (action_id, update_text, created_at) VALUES (:id, :text, NOW())');
    $stmt->execute([':id' => $id, ':text' => $updateText . ' — ' . ($user['full_name'] ?? 'Staff')]);

    logActivity(currentUserId(), 'Update', 'Added progress update to action #' . $id);
    jsonResponse(true, 'Progress update added successfully.');
} catch (PDOException $e) {
    error_log('Action update error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred.');
}
