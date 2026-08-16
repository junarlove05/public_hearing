<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id = (int)($_POST['id'] ?? 0);
$text = clean($_POST['update_text'] ?? '');
if ($id <= 0 || $text === '') jsonResponse(false, 'Please provide an update.');

$pdo = db();
try {
    $check = $pdo->prepare('SELECT status FROM hearing_actions WHERE id = :id');
    $check->execute([':id' => $id]);
    $status = $check->fetchColumn();
    if ($status === false) jsonResponse(false, 'Action not found.');

    $stmt = $pdo->prepare(
        'INSERT INTO hearing_action_updates
         (action_id, previous_status, new_status, update_text, updated_by, created_at)
         VALUES (:id, :previous_status, :new_status, :text, :updated_by, NOW())'
    );
    $stmt->execute([
        ':id' => $id,
        ':previous_status' => $status,
        ':new_status' => $status,
        ':text' => $text,
        ':updated_by' => currentUserId(),
    ]);

    logActivity(currentUserId(), 'Update', 'Added progress update to action #' . $id);
    jsonResponse(true, 'Progress update added.');
} catch (Throwable $e) {
    error_log('Action update error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while adding the update.');
}
