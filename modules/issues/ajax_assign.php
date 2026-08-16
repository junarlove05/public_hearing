<?php
/**
 * Assign or reassign an issue using the current hearing_* schema.
 * Accepts the existing form field `assigned_to` and resolves it to
 * either an active office or an active user.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id = (int)($_POST['id'] ?? 0);
$assignedTo = clean($_POST['assigned_to'] ?? '');
if ($id <= 0 || $assignedTo === '') jsonResponse(false, 'Please provide an office or person to assign.');

$pdo = db();
try {
    $checkStmt = $pdo->prepare('SELECT id FROM hearing_issues WHERE id = :id');
    $checkStmt->execute([':id' => $id]);
    if (!$checkStmt->fetchColumn()) jsonResponse(false, 'Issue not found.');

    $officeId = null;
    $userId = null;
    $displayName = $assignedTo;

    $officeStmt = $pdo->prepare(
        "SELECT id, name FROM offices
         WHERE status = 'Active' AND (name = :value OR code = :value)
         LIMIT 1"
    );
    $officeStmt->execute([':value' => $assignedTo]);
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
        $userStmt->execute([':value' => $assignedTo]);
        if ($user = $userStmt->fetch()) {
            $userId = (int)$user['id'];
            $displayName = $user['full_name'];
        }
    }

    if (!$officeId && !$userId) {
        jsonResponse(false, 'No active office or user matched that value. Use an existing office name, office code, user name, username, or email.');
    }

    $pdo->beginTransaction();

    $pdo->prepare(
        'INSERT INTO hearing_issue_assignments
         (issue_id, assigned_office_id, assigned_user_id, assigned_by, remarks, assigned_at)
         VALUES (:issue_id, :office_id, :user_id, :assigned_by, :remarks, NOW())'
    )->execute([
        ':issue_id' => $id,
        ':office_id' => $officeId,
        ':user_id' => $userId,
        ':assigned_by' => currentUserId(),
        ':remarks' => 'Issue reassigned from the detail page.',
    ]);

    $pdo->prepare(
        'UPDATE hearing_issues
         SET assigned_office_id = :office_id,
             assigned_user_id = :user_id
         WHERE id = :id'
    )->execute([
        ':office_id' => $officeId,
        ':user_id' => $userId,
        ':id' => $id,
    ]);

    $pdo->prepare(
        'INSERT INTO hearing_issue_history (issue_id, note, created_by, created_at)
         VALUES (:issue_id, :note, :created_by, NOW())'
    )->execute([
        ':issue_id' => $id,
        ':note' => 'Assigned to ' . $displayName . '.',
        ':created_by' => currentUserId(),
    ]);

    $pdo->commit();
    logActivity(currentUserId(), 'Update', 'Assigned issue #' . $id . ' to ' . $displayName);
    jsonResponse(true, 'Issue assigned to ' . $displayName . '.', ['assigned_to' => $displayName]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Issue assign error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while assigning the issue.');
}
