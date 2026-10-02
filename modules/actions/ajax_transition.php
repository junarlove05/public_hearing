<?php
/**
 * modules/actions/ajax_transition.php
 * ------------------------------------------------------------------
 * Handles status updates and progress notes submitted strictly by the
 * user assigned to the action.
 * Automatically marks verification_status as 'Pending' for Admin review.
 * ------------------------------------------------------------------
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

requireCsrf();

$id     = (int)($_POST['id'] ?? 0);
$status = clean($_POST['status'] ?? '');
$note   = clean($_POST['note'] ?? '');

if ($id <= 0) {
    jsonResponse(false, 'Invalid action ID.');
}

$allowedStatuses = ['Pending', 'In Progress', 'Under Review', 'Completed', 'Cancelled'];
if (!in_array($status, $allowedStatuses, true)) {
    jsonResponse(false, 'Invalid action status.');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM hearing_actions WHERE id = :id FOR UPDATE');

try {
    $pdo->beginTransaction();
    $stmt->execute([':id' => $id]);
    $action = $stmt->fetch();

    if (!$action) {
        $pdo->rollBack();
        jsonResponse(false, 'Action record not found.');
    }

    $currentUid     = (int)(currentUserId() ?? 0);
    $assignedUid    = (int)($action['assigned_user_id'] ?? 0);
    $isAssignedUser = ($currentUid > 0 && $assignedUid === $currentUid);

    // Strictly enforce: ONLY the assigned user can submit status updates!
    if (!$isAssignedUser) {
        $pdo->rollBack();
        jsonResponse(false, 'Only the user specifically assigned to this action can update its status. Administrators verify submitted updates.');
    }

    $prevStatus = $action['status'];
    $statusChanged = ($prevStatus !== $status);

    if (!$statusChanged && $note === '') {
        $pdo->rollBack();
        jsonResponse(true, 'No changes were made.', ['status' => $status]);
    }

    $completedAt = ($status === 'Completed') ? ($action['completed_at'] ?: date('Y-m-d H:i:s')) : null;

    // Update hearing_actions: resets verification to 'Pending' for Admin review
    $pdo->prepare(
        'UPDATE hearing_actions
         SET status = :status,
             verification_status = "Pending",
             verified_by = NULL,
             verified_at = NULL,
             progress_notes = :notes,
             completed_at = :completed_at,
             updated_at = NOW()
         WHERE id = :id'
    )->execute([
        ':status'       => $status,
        ':notes'        => $note ?: $action['progress_notes'],
        ':completed_at' => $completedAt,
        ':id'           => $id,
    ]);

    $staffName = currentUser()['full_name'] ?? 'Assigned Staff';
    if ($statusChanged && $note !== '') {
        $historyText = "Status updated to {$status} by {$staffName}. Note: {$note} (Pending Admin Verification)";
    } elseif ($statusChanged) {
        $historyText = "Status updated to {$status} by {$staffName} (Pending Admin Verification).";
    } else {
        $historyText = "Progress note added by {$staffName}: {$note}";
    }

    // Insert update history record
    $pdo->prepare(
        'INSERT INTO hearing_action_updates (action_id, previous_status, new_status, update_text, updated_by, created_at)
         VALUES (:action_id, :prev_status, :new_status, :update_text, :updated_by, NOW())'
    )->execute([
        ':action_id'    => $id,
        ':prev_status'  => $prevStatus,
        ':new_status'   => $status,
        ':update_text'  => $historyText,
        ':updated_by'   => $currentUid,
    ]);

    // Send in-app notification to all active Administrators
    try {
        $adminIds = $pdo->query("SELECT id FROM users WHERE role_id = 1 AND status = 'Active'")->fetchAll(PDO::FETCH_COLUMN);
        $sysId = function_exists('lphSystemId') ? lphSystemId() : 4;
        foreach ($adminIds as $admId) {
            if ((int)$admId !== $currentUid) {
                $pdo->prepare(
                    'INSERT INTO notifications (user_id, system_id, notification_type, title, message, target_url, is_read, created_at)
                     VALUES (:user_id, :system_id, "action_verification_required", :title, :message, :target_url, 0, NOW())'
                )->execute([
                    ':user_id'    => (int)$admId,
                    ':system_id'  => $sysId,
                    ':title'      => 'Action Update for Verification: ' . $action['reference_number'],
                    ':message'    => "Staff {$staffName} updated action {$action['reference_number']} to '{$status}'. Awaiting your verification.",
                    ':target_url' => APP_URL . '/modules/actions/view.php?id=' . $id,
                ]);
            }
        }
    } catch (Throwable $e) {}

    logActivity($currentUid, 'Action Workflow', "{$action['reference_number']} status {$prevStatus} -> {$status} (Pending Admin Verification)." . ($note !== '' ? " Note: {$note}" : ''));

    $pdo->commit();
    jsonResponse(true, 'Action status and progress note submitted successfully. Awaiting Admin verification.', ['status' => $status]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Action transition error: ' . $e->getMessage());
    jsonResponse(false, APP_DEBUG ? $e->getMessage() : 'Unable to update action workflow.');
}
