<?php
/**
 * modules/actions/ajax_verify.php
 * ------------------------------------------------------------------
 * Handles Admin Verification for action status updates submitted by
 * the assigned user.
 * ------------------------------------------------------------------
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

// Strictly check: ONLY Administrators can verify action updates!
$isAdmin = (function_exists('isAdmin') && isAdmin()) || (isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === 1);
if (!$isAdmin) {
    jsonResponse(false, 'Only administrators can verify action updates.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

requireCsrf();

$id     = (int)($_POST['id'] ?? 0);
$action = clean($_POST['action'] ?? 'verify'); // 'verify' (approve) or 'return'
$notes  = clean($_POST['verification_notes'] ?? '');

if ($id <= 0) {
    jsonResponse(false, 'Invalid action ID.');
}

if ($action === 'return' && $notes === '') {
    jsonResponse(false, 'Please provide remarks explaining why the update is returned for revision.');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM hearing_actions WHERE id = :id FOR UPDATE');

try {
    $pdo->beginTransaction();
    $stmt->execute([':id' => $id]);
    $act = $stmt->fetch();

    if (!$act) {
        $pdo->rollBack();
        jsonResponse(false, 'Action record not found.');
    }

    $adminUser   = currentUser();
    $adminName   = $adminUser['full_name'] ?? 'Administrator';
    $assignedUid = (int)($act['assigned_user_id'] ?? 0);

    if ($action === 'verify' || $action === 'approve') {
        $verifiedNotes = $notes !== '' ? $notes : 'Action update verified and approved by Administrator.';

        $pdo->prepare(
            'UPDATE hearing_actions
             SET verification_status = "Verified",
                 verified_by = :vby,
                 verified_at = NOW(),
                 verification_notes = :vnotes,
                 updated_at = NOW()
             WHERE id = :id'
        )->execute([
            ':vby'    => currentUserId(),
            ':vnotes' => $verifiedNotes,
            ':id'     => $id,
        ]);

        $histNote = "Action update verified & approved by Admin {$adminName}." . ($notes !== '' ? " Remarks: {$notes}" : '');

        $pdo->prepare(
            'INSERT INTO hearing_action_updates (action_id, previous_status, new_status, update_text, updated_by, created_at)
             VALUES (:action_id, :prev_status, :new_status, :update_text, :updated_by, NOW())'
        )->execute([
            ':action_id'   => $id,
            ':prev_status' => $act['status'],
            ':new_status'  => $act['status'],
            ':update_text' => $histNote,
            ':updated_by'  => currentUserId(),
        ]);

        // Send in-app notification to assigned user
        if ($assignedUid > 0) {
            $sysId = function_exists('lphSystemId') ? lphSystemId() : 4;
            $pdo->prepare(
                'INSERT INTO notifications (user_id, system_id, notification_type, title, message, target_url, is_read, created_at)
                 VALUES (:user_id, :system_id, "action_verified", :title, :message, :target_url, 0, NOW())'
            )->execute([
                ':user_id'    => $assignedUid,
                ':system_id'  => $sysId,
                ':title'      => 'Action Update Verified: ' . $act['reference_number'],
                ':message'    => "Your update on action {$act['reference_number']} was verified and approved by {$adminName}.",
                ':target_url' => APP_URL . '/modules/actions/view.php?id=' . $id,
            ]);
        }

        logActivity(currentUserId(), 'Verify Action', "Verified update on {$act['reference_number']} ({$act['status']}).");

        $pdo->commit();
        jsonResponse(true, 'Action update has been verified and approved successfully.');

    } elseif ($action === 'return') {
        // If returning an action that was marked Completed, rollback to In Progress
        $newStatus = ($act['status'] === 'Completed') ? 'In Progress' : $act['status'];

        $pdo->prepare(
            'UPDATE hearing_actions
             SET verification_status = "Returned",
                 verification_notes = :vnotes,
                 verified_by = :vby,
                 verified_at = NOW(),
                 status = :new_status,
                 completed_at = NULL,
                 updated_at = NOW()
             WHERE id = :id'
        )->execute([
            ':vnotes'     => $notes,
            ':vby'        => currentUserId(),
            ':new_status' => $newStatus,
            ':id'         => $id,
        ]);

        $histNote = "Action update returned by Admin {$adminName} for revision. Remarks: {$notes}";

        $pdo->prepare(
            'INSERT INTO hearing_action_updates (action_id, previous_status, new_status, update_text, updated_by, created_at)
             VALUES (:action_id, :prev_status, :new_status, :update_text, :updated_by, NOW())'
        )->execute([
            ':action_id'   => $id,
            ':prev_status' => $act['status'],
            ':new_status'  => $newStatus,
            ':update_text' => $histNote,
            ':updated_by'  => currentUserId(),
        ]);

        // Send in-app notification to assigned user
        if ($assignedUid > 0) {
            $sysId = function_exists('lphSystemId') ? lphSystemId() : 4;
            $pdo->prepare(
                'INSERT INTO notifications (user_id, system_id, notification_type, title, message, target_url, is_read, created_at)
                 VALUES (:user_id, :system_id, "action_returned", :title, :message, :target_url, 0, NOW())'
            )->execute([
                ':user_id'    => $assignedUid,
                ':system_id'  => $sysId,
                ':title'      => 'Action Returned for Revision: ' . $act['reference_number'],
                ':message'    => "Administrator {$adminName} returned your update on {$act['reference_number']}: {$notes}",
                ':target_url' => APP_URL . '/modules/actions/view.php?id=' . $id,
            ]);
        }

        logActivity(currentUserId(), 'Return Action', "Returned update on {$act['reference_number']} for revision: {$notes}");

        $pdo->commit();
        jsonResponse(true, 'Action update has been returned to the assigned user for revision.');

    } else {
        $pdo->rollBack();
        jsonResponse(false, 'Invalid verification action.');
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Action verify error: ' . $e->getMessage());
    jsonResponse(false, APP_DEBUG ? $e->getMessage() : 'Unable to complete verification.');
}
