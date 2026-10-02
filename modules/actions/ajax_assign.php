<?php
/**
 * modules/actions/ajax_assign.php
 * ------------------------------------------------------------------
 * Handles Assign/Reassign of Response & Action records.
 * STRICTLY restricted to Administrators.
 * ------------------------------------------------------------------
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$isAdmin = (function_exists('isAdmin') && isAdmin()) || (isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === 1);
if (!$isAdmin) {
    jsonResponse(false, 'Only administrators can assign or reassign actions.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

requireCsrf();

$id        = (int)($_POST['id'] ?? 0);
$userId    = !empty($_POST['assigned_user_id']) ? (int)$_POST['assigned_user_id'] : null;
$officeId  = !empty($_POST['assigned_office_id']) ? (int)$_POST['assigned_office_id'] : null;
$remarks   = clean($_POST['remarks'] ?? 'Action assigned by Administrator.');

if ($id <= 0) {
    jsonResponse(false, 'Invalid action ID.');
}

if (!$userId && !$officeId) {
    // Fallback: check if text-based assigned_office was submitted
    $assignedValue = clean($_POST['assigned_office'] ?? '');
    if ($assignedValue !== '') {
        $pdo = db();
        $off = $pdo->prepare("SELECT id FROM offices WHERE name = :val OR code = :val LIMIT 1");
        $off->execute([':val' => $assignedValue]);
        $officeId = $off->fetchColumn() ?: null;
        if (!$officeId) {
            $usr = $pdo->prepare("SELECT id FROM users WHERE full_name = :val OR username = :val LIMIT 1");
            $usr->execute([':val' => $assignedValue]);
            $userId = $usr->fetchColumn() ?: null;
        }
    }
}

if (!$userId && !$officeId) {
    jsonResponse(false, 'Please select a registered user or an office to assign.');
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

    if (!empty($act['assigned_user_id'])) {
        $pdo->rollBack();
        jsonResponse(false, 'A user is already assigned to this action. Assignment is closed.');
    }

    $assignedUserName = null;
    if ($userId) {
        $uStmt = $pdo->prepare(
            'SELECT u.full_name, r.name AS role_name
             FROM users u
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id AND u.deleted_at IS NULL'
        );
        $uStmt->execute([':id' => $userId]);
        $targetUser = $uStmt->fetch(PDO::FETCH_ASSOC);
        if (!$targetUser) {
            $pdo->rollBack();
            jsonResponse(false, 'The selected user was not found.');
        }
        $targetRole = strtolower(trim((string)($targetUser['role_name'] ?? '')));
        if (str_contains($targetRole, 'public') || str_contains($targetRole, 'stakeholder')) {
            $pdo->rollBack();
            jsonResponse(false, 'Hindi maaaring i-assign ang mga Public User o Stakeholder sa Response & Action records.');
        }
        $assignedUserName = $targetUser['full_name'];
    }

    $assignedOfficeName = null;
    if ($officeId) {
        $oStmt = $pdo->prepare('SELECT name FROM offices WHERE id = :id');
        $oStmt->execute([':id' => $officeId]);
        $assignedOfficeName = $oStmt->fetchColumn() ?: null;
    }

    $assignedDisplay = $assignedUserName ?: ($assignedOfficeName ?: 'Unassigned');
    if ($assignedUserName && $assignedOfficeName) {
        $assignedDisplay = "{$assignedUserName} ({$assignedOfficeName})";
    }

    // Update hearing_actions
    $pdo->prepare(
        'UPDATE hearing_actions
         SET assigned_user_id = :uid,
             assigned_office_id = :oid,
             updated_at = NOW()
         WHERE id = :id'
    )->execute([
        ':uid' => $userId,
        ':oid' => $officeId,
        ':id'  => $id,
    ]);

    // Record in hearing_action_assignments
    $pdo->prepare(
        'INSERT INTO hearing_action_assignments (action_id, assigned_office_id, assigned_user_id, assigned_by, remarks, assigned_at)
         VALUES (:action_id, :office_id, :user_id, :assigned_by, :remarks, NOW())'
    )->execute([
        ':action_id'   => $id,
        ':office_id'   => $officeId,
        ':user_id'     => $userId,
        ':assigned_by' => currentUserId(),
        ':remarks'     => $remarks ?: 'Assigned by Administrator',
    ]);

    // Log to hearing_action_updates timeline
    $pdo->prepare(
        'INSERT INTO hearing_action_updates (action_id, previous_status, new_status, update_text, updated_by, created_at)
         VALUES (:action_id, :prev_status, :new_status, :update_text, :updated_by, NOW())'
    )->execute([
        ':action_id'   => $id,
        ':prev_status' => $act['status'],
        ':new_status'  => $act['status'],
        ':update_text' => 'Assigned to ' . $assignedDisplay . ($remarks ? ". Remarks: {$remarks}" : ''),
        ':updated_by'  => currentUserId(),
    ]);

    // Send in-app notification to the assigned user
    if ($userId && $userId !== currentUserId()) {
        $sysId = function_exists('lphSystemId') ? lphSystemId() : 4;
        $pdo->prepare(
            'INSERT INTO notifications (user_id, system_id, notification_type, title, message, target_url, is_read, created_at)
             VALUES (:user_id, :system_id, "action_assigned", :title, :message, :target_url, 0, NOW())'
        )->execute([
            ':user_id'    => $userId,
            ':system_id'  => $sysId,
            ':title'      => 'Assigned to Action: ' . $act['reference_number'],
            ':message'    => "You have been assigned to Action {$act['reference_number']}: {$act['title']}.",
            ':target_url' => APP_URL . '/modules/actions/view.php?id=' . $id,
        ]);
    }

    $pdo->commit();
    logActivity(currentUserId(), 'Action Assignment', "Assigned {$act['reference_number']} to {$assignedDisplay}.");
    jsonResponse(true, 'Action assigned to ' . $assignedDisplay . ' successfully.', ['assigned_display' => $assignedDisplay]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Action assign error: ' . $e->getMessage());
    jsonResponse(false, APP_DEBUG ? $e->getMessage() : 'A database error occurred while assigning the action.');
}
