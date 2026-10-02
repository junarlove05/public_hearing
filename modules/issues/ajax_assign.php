<?php
declare(strict_types=1);

/**
 * Assign or reassign an issue to a registered user and/or office.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$isAdmin = (function_exists('isAdmin') && isAdmin()) || (isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === 1);
if (!canManage() && !$isAdmin) {
    jsonResponse(false, 'You do not have permission to perform this action.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}
requireCsrf();

$id = (int)($_POST['id'] ?? 0);
$userId = (int)($_POST['assigned_user_id'] ?? 0) ?: null;
$officeId = (int)($_POST['assigned_office_id'] ?? 0) ?: null;
$remarks = clean($_POST['remarks'] ?? '');

// Backward compatibility: If plain text 'assigned_to' was provided instead of IDs
if (!$userId && !$officeId && !empty($_POST['assigned_to'])) {
    $assignedTo = clean($_POST['assigned_to']);
    $pdo = db();
    $officeStmt = $pdo->prepare(
        "SELECT id, name FROM offices
         WHERE status = 'Active' AND (name = :value OR code = :value)
         LIMIT 1"
    );
    $officeStmt->execute([':value' => $assignedTo]);
    if ($office = $officeStmt->fetch()) {
        $officeId = (int)$office['id'];
    } else {
        $userStmt = $pdo->prepare(
            "SELECT u.id, u.full_name, u.office_id, u.role_id, u.username, r.name AS role_name
             FROM users u
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE u.deleted_at IS NULL AND u.status = 'Active'
               AND (u.full_name = :value OR u.email = :value OR u.username = :value)
             LIMIT 1"
        );
        $userStmt->execute([':value' => $assignedTo]);
        if ($user = $userStmt->fetch()) {
            $rName = strtolower(trim((string)($user['role_name'] ?? '')));
            $uName = strtolower(trim((string)($user['username'] ?? '')));
            if ($rName === 'administrator' || (int)($user['role_id'] ?? 0) === 1 || $uName === 'admin' || str_contains($rName, 'admin')) {
                jsonResponse(false, 'Hindi maaaring i-assign ang Administrator sa issue. Mangyaring pumili ng Legislative Staff o Committee Member.');
            }
            if (str_contains($rName, 'public') || str_contains($rName, 'stakeholder')) {
                jsonResponse(false, 'Hindi maaaring i-assign ang mga Public User o Stakeholder sa issue.');
            }
            $userId = (int)$user['id'];
            if (!empty($user['office_id'])) {
                $officeId = (int)$user['office_id'];
            }
        }
    }
}

if ($id <= 0) {
    jsonResponse(false, 'Invalid issue ID.');
}

if (!$userId && !$officeId) {
    jsonResponse(false, 'Please select at least a registered user or an office to assign.');
}

$pdo = db();
try {
    $checkStmt = $pdo->prepare('SELECT id, reference_number FROM hearing_issues WHERE id = :id');
    $checkStmt->execute([':id' => $id]);
    $issue = $checkStmt->fetch();
    if (!$issue) {
        jsonResponse(false, 'Issue not found.');
    }

    $userName = null;
    if ($userId) {
        $uStmt = $pdo->prepare(
            "SELECT u.id, u.full_name, u.office_id, u.role_id, u.username, r.name AS role_name
             FROM users u
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id AND u.deleted_at IS NULL AND u.status = 'Active'"
        );
        $uStmt->execute([':id' => $userId]);
        $uRow = $uStmt->fetch();
        if ($uRow) {
            $rName = strtolower(trim((string)($uRow['role_name'] ?? '')));
            $uName = strtolower(trim((string)($uRow['username'] ?? '')));
            if ($rName === 'administrator' || (int)($uRow['role_id'] ?? 0) === 1 || $uName === 'admin' || str_contains($rName, 'admin')) {
                jsonResponse(false, 'Hindi maaaring i-assign ang Administrator sa issue. Mangyaring pumili ng Legislative Staff o Committee Member.');
            }
            if (str_contains($rName, 'public') || str_contains($rName, 'stakeholder')) {
                jsonResponse(false, 'Hindi maaaring i-assign ang mga Public User o Stakeholder sa issue.');
            }
            $userName = $uRow['full_name'];
            if (!$officeId && !empty($uRow['office_id'])) {
                $officeId = (int)$uRow['office_id'];
            }
        } else {
            jsonResponse(false, 'Ang napiling user ay hindi natagpuan o hindi aktibo.');
        }
    }

    $officeName = null;
    if ($officeId) {
        $oStmt = $pdo->prepare("SELECT id, name FROM offices WHERE id = :id AND status = 'Active'");
        $oStmt->execute([':id' => $officeId]);
        $oRow = $oStmt->fetch();
        if ($oRow) {
            $officeName = $oRow['name'];
        } else {
            $officeId = null;
        }
    }

    if (!$userId && !$officeId) {
        jsonResponse(false, 'No active user or office found for the selection.');
    }

    $displayParts = [];
    if ($userName) {
        $displayParts[] = "Person: {$userName}";
    }
    if ($officeName) {
        $displayParts[] = "Office: {$officeName}";
    }
    $displayLabel = implode(' · ', $displayParts);

    $pdo->beginTransaction();

    $pdo->prepare(
        'INSERT INTO hearing_issue_assignments
         (issue_id, assigned_office_id, assigned_user_id, assigned_by, remarks, assigned_at)
         VALUES (:issue_id, :office_id, :user_id, :assigned_by, :remarks, NOW())'
    )->execute([
        ':issue_id'    => $id,
        ':office_id'   => $officeId,
        ':user_id'     => $userId,
        ':assigned_by' => currentUserId(),
        ':remarks'     => $remarks !== '' ? $remarks : 'Issue reassigned from the detail page.',
    ]);

    $pdo->prepare(
        'UPDATE hearing_issues
         SET assigned_office_id = :office_id,
             assigned_user_id = :user_id,
             updated_at = NOW()
         WHERE id = :id'
    )->execute([
        ':office_id' => $officeId,
        ':user_id'   => $userId,
        ':id'        => $id,
    ]);

    $note = 'Assigned to ' . $displayLabel . ($remarks !== '' ? " — Remarks: {$remarks}" : '') . '.';
    $pdo->prepare(
        'INSERT INTO hearing_issue_history (issue_id, note, created_by, created_at)
         VALUES (:issue_id, :note, :created_by, NOW())'
    )->execute([
        ':issue_id'   => $id,
        ':note'       => $note,
        ':created_by' => currentUserId(),
    ]);

    // Send notification to the assigned user
    if ($userId) {
        $issueUrl = APP_URL . '/modules/issues/view.php?id=' . $id;
        $notifStmt = $pdo->prepare(
            "INSERT INTO notifications
                (user_id, system_id, notification_type, title, message, target_url, is_read, created_at)
             VALUES
                (:uid, :sys, 'Issue Assignment', :title, :msg, :url, 0, NOW())"
        );
        $notifStmt->execute([
            ':uid'   => $userId,
            ':sys'   => function_exists('lphSystemId') ? lphSystemId() : 4,
            ':title' => "Assigned to Hearing Issue: {$issue['reference_number']}",
            ':msg'   => "Nakatoka sa iyong account ang issue: \"{$issue['title']}\". Pindutin upang matingnan at maaksyunan.",
            ':url'   => $issueUrl,
        ]);
    }

    $pdo->commit();
    logActivity(currentUserId(), 'Update', "Assigned issue {$issue['reference_number']} to {$displayLabel}");
    jsonResponse(true, 'Issue successfully assigned to ' . $displayLabel . '.', [
        'assigned_to' => $displayLabel,
        'user_name'   => $userName,
        'office_name' => $officeName,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Issue assign error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while assigning the issue.');
}
