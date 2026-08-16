<?php
/** Assign/reassign a response action using the current hearing_* schema. */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id = (int)($_POST['id'] ?? 0);
$assignedValue = clean($_POST['assigned_office'] ?? '');
if ($id <= 0 || $assignedValue === '') jsonResponse(false, 'Please provide an office or person to assign.');

$pdo = db();
try {
    $check = $pdo->prepare('SELECT id FROM hearing_actions WHERE id = :id');
    $check->execute([':id' => $id]);
    if (!$check->fetchColumn()) jsonResponse(false, 'Action not found.');

    $officeId = null;
    $userId = null;
    $displayName = $assignedValue;

    $officeStmt = $pdo->prepare(
        "SELECT id, name FROM offices
         WHERE status = 'Active' AND (name = :value OR code = :value)
         LIMIT 1"
    );
    $officeStmt->execute([':value' => $assignedValue]);
    if ($office = $officeStmt->fetch()) {
        $officeId = (int)$office['id'];
        $displayName = $office['name'];
    } else {
        $userStmt = $pdo->prepare(
            "SELECT id, full_name FROM users
             WHERE deleted_at IS NULL AND status = 'Active'
               AND (full_name = :value OR email = :value OR username = :value)
             LIMIT 1"
        );
        $userStmt->execute([':value' => $assignedValue]);
        if ($user = $userStmt->fetch()) {
            $userId = (int)$user['id'];
            $displayName = $user['full_name'];
        }
    }

    if (!$officeId && !$userId) {
        jsonResponse(false, 'No active office or user matched that value.');
    }

    $pdo->beginTransaction();
    $pdo->prepare(
        'INSERT INTO hearing_action_assignments
         (action_id, assigned_office_id, assigned_user_id, assigned_by, remarks, assigned_at)
         VALUES (:action_id, :office_id, :user_id, :assigned_by, :remarks, NOW())'
    )->execute([
        ':action_id' => $id,
        ':office_id' => $officeId,
        ':user_id' => $userId,
        ':assigned_by' => currentUserId(),
        ':remarks' => 'Action reassigned from the detail page.',
    ]);

    $pdo->prepare(
        'UPDATE hearing_actions
         SET assigned_office_id = :office_id,
             assigned_user_id = :user_id
         WHERE id = :id'
    )->execute([
        ':office_id' => $officeId,
        ':user_id' => $userId,
        ':id' => $id,
    ]);

    $pdo->prepare(
        'INSERT INTO hearing_action_updates
         (action_id, previous_status, new_status, update_text, updated_by, created_at)
         SELECT id, status, status, :text, :updated_by, NOW()
         FROM hearing_actions WHERE id = :id'
    )->execute([
        ':text' => 'Assigned to ' . $displayName . '.',
        ':updated_by' => currentUserId(),
        ':id' => $id,
    ]);

    $pdo->commit();
    logActivity(currentUserId(), 'Update', 'Assigned action #' . $id . ' to ' . $displayName);
    jsonResponse(true, 'Action assigned to ' . $displayName . '.', ['assigned_office' => $displayName]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Action assign error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while assigning the action.');
}
