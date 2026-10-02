<?php

/**
 * modules/issues/ajax_save.php
 * ------------------------------------------------------------------
 * Handles CREATE and UPDATE for the `issues` table (id=0 means
 * create). Also seeds an issue_history entry on creation, and an
 * issue_assignments entry if an office is assigned at creation time.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

requireCsrf();

$id          = (int)($_POST['id'] ?? 0);
$pdo         = db();

$isAdmin = (function_exists('isAdmin') && isAdmin()) || (isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === 1);
$isAssignedUser = false;
$existingIssue  = null;
if ($id > 0) {
    $chkStmt = $pdo->prepare('SELECT id, assigned_user_id, hearing_id, legislative_item_id, category_id, assigned_office_id FROM hearing_issues WHERE id = :id');
    $chkStmt->execute([':id' => $id]);
    $existingIssue = $chkStmt->fetch();
    if ($existingIssue && function_exists('currentUserId') && currentUserId() && (int)($existingIssue['assigned_user_id'] ?? 0) === currentUserId()) {
        $isAssignedUser = true;
    }
}

if (!canManage() && !$isAdmin && !$isAssignedUser) {
    jsonResponse(false, 'You do not have permission to perform this action.');
}

$title          = clean($_POST['title'] ?? '');
$description    = clean($_POST['description'] ?? '');
$categoryId     = (int)($_POST['category_id'] ?? 0) ?: null;
$hearingId      = (int)($_POST['hearing_id'] ?? 0) ?: null;
$priority       = clean($_POST['priority'] ?? 'Medium');
$status         = clean($_POST['status'] ?? 'Open');
$dueAt          = clean($_POST['due_at'] ?? '');
$assignedUserId = (int)($_POST['assigned_user_id'] ?? 0) ?: null;

/*
 * Preferred new field: assigned_office_id
 * Old form compatibility: assigned_office
 */
$assignedOfficeId   = (int)($_POST['assigned_office_id'] ?? 0) ?: null;
$assignedOfficeName = clean($_POST['assigned_office'] ?? '');

if ($assignedUserId) {
    $chkUser = $pdo->prepare(
        "SELECT u.id, u.full_name, u.office_id, u.role_id, u.username, r.name AS role_name
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE u.id = :id AND u.deleted_at IS NULL AND u.status = 'Active'"
    );
    $chkUser->execute([':id' => $assignedUserId]);
    $uData = $chkUser->fetch();
    if ($uData) {
        $rName = strtolower(trim((string)($uData['role_name'] ?? '')));
        $uName = strtolower(trim((string)($uData['username'] ?? '')));
        if ($rName === 'administrator' || (int)($uData['role_id'] ?? 0) === 1 || $uName === 'admin' || str_contains($rName, 'admin')) {
            jsonResponse(false, 'Hindi maaaring i-assign ang Administrator sa issue. Mangyaring pumili ng Legislative Staff o Committee Member.');
        }
        if (str_contains($rName, 'public') || str_contains($rName, 'stakeholder')) {
            jsonResponse(false, 'Hindi maaaring i-assign ang mga Public User o Stakeholder sa issue.');
        }
        if (!$assignedOfficeId && !empty($uData['office_id'])) {
            $assignedOfficeId = (int)$uData['office_id'];
        }
    } else {
        jsonResponse(false, 'Ang napiling user ay hindi natagpuan o hindi aktibo.');
    }
}

$statusAliases = [
    'Processing' => 'In Progress',
];

$status = $statusAliases[$status] ?? $status;

$allowedPriorities = [
    'Low',
    'Medium',
    'High',
    'Critical',
];

$allowedStatuses = [
    'Open',
    'In Progress',
    'Resolved',
    'Closed',
];

$errors = [];

if ($title === '') {
    $errors[] = 'Title is required.';
}

if ($description === '') {
    $errors[] = 'Description is required.';
}

if (!in_array($priority, $allowedPriorities, true)) {
    $errors[] = 'Invalid priority value.';
}

if (!in_array($status, $allowedStatuses, true)) {
    $errors[] = 'Invalid status value.';
}

if ($dueAt !== '' && strtotime($dueAt) === false) {
    $errors[] = 'Invalid due date.';
}

if (!empty($errors)) {
    jsonResponse(false, implode(' ', $errors));
}

$pdo = db();

try {
    /*
     * Compatibility with the old text-based office field.
     */
    if (!$assignedOfficeId && $assignedOfficeName !== '') {
        $officeStmt = $pdo->prepare(
            'SELECT id
             FROM offices
             WHERE name = :name
               AND status = "Active"
             LIMIT 1'
        );

        $officeStmt->execute([
            ':name' => $assignedOfficeName,
        ]);

        $assignedOfficeId = $officeStmt->fetchColumn() ?: null;

        if (!$assignedOfficeId) {
            jsonResponse(
                false,
                'The selected office was not found. Add the office first or select an existing office.'
            );
        }
    }

    /*
     * Validate category.
     */
    if ($categoryId) {
        $categoryStmt = $pdo->prepare(
            'SELECT id
             FROM hearing_issue_categories
             WHERE id = :id'
        );

        $categoryStmt->execute([':id' => $categoryId]);

        if (!$categoryStmt->fetchColumn()) {
            jsonResponse(false, 'Invalid issue category.');
        }
    }

    /*
     * Validate hearing and obtain its legislative item.
     */
    $legislativeItemId = null;

    if ($hearingId) {
        $hearingStmt = $pdo->prepare(
            'SELECT legislative_item_id
             FROM hearings
             WHERE id = :id'
        );

        $hearingStmt->execute([':id' => $hearingId]);
        $hearing = $hearingStmt->fetch();

        if (!$hearing) {
            jsonResponse(false, 'Invalid related hearing.');
        }

        $legislativeItemId = $hearing['legislative_item_id'] ?: null;
    }

    if ($id > 0 && $existingIssue) {
        if (!$categoryId) { $categoryId = $existingIssue['category_id'] ? (int)$existingIssue['category_id'] : null; }
        if (!$hearingId) { 
            $hearingId = $existingIssue['hearing_id'] ? (int)$existingIssue['hearing_id'] : null;
            $legislativeItemId = $existingIssue['legislative_item_id'] ? (int)$existingIssue['legislative_item_id'] : null;
        }
        if (!$assignedOfficeId) { $assignedOfficeId = $existingIssue['assigned_office_id'] ? (int)$existingIssue['assigned_office_id'] : null; }
        if (!isset($_POST['assigned_user_id']) && !empty($existingIssue['assigned_user_id'])) {
            $assignedUserId = (int)$existingIssue['assigned_user_id'];
        }
    }

    $pdo->beginTransaction();

    if ($id > 0) {
        $beforeStmt = $pdo->prepare(
            'SELECT
                title,
                status,
                assigned_office_id,
                assigned_user_id,
                resolved_at,
                resolved_by,
                resolution_summary
             FROM hearing_issues
             WHERE id = :id
             FOR UPDATE'
        );

        $beforeStmt->execute([':id' => $id]);
        $before = $beforeStmt->fetch();

        if (!$before) {
            $pdo->rollBack();
            jsonResponse(false, 'Issue not found.');
        }

        $closedAt = $status === 'Closed'
            ? date('Y-m-d H:i:s')
            : null;

        $resSummary = trim((string)($_POST['resolution_summary'] ?? ''));
        $resolvedAt = in_array($status, ['Resolved', 'Closed'], true) ? date('Y-m-d H:i:s') : null;
        $resolvedBy = in_array($status, ['Resolved', 'Closed'], true) ? currentUserId() : null;

        $updateStmt = $pdo->prepare(
            'UPDATE hearing_issues
             SET
                hearing_id = :hearing_id,
                legislative_item_id = :legislative_item_id,
                category_id = :category_id,
                title = :title,
                description = :description,
                priority = :priority,
                status = :status,
                assigned_office_id = :assigned_office_id,
                assigned_user_id = :assigned_user_id,
                due_at = :due_at,
                resolution_summary = CASE WHEN :res_summary <> "" THEN :res_summary_val ELSE resolution_summary END,
                resolved_at = CASE WHEN :status IN ("Resolved", "Closed") THEN COALESCE(resolved_at, :resolved_at) ELSE resolved_at END,
                resolved_by = CASE WHEN :status IN ("Resolved", "Closed") THEN COALESCE(resolved_by, :resolved_by) ELSE resolved_by END,
                closed_at = :closed_at,
                updated_at = NOW()
             WHERE id = :id'
        );

        $updateStmt->execute([
            ':hearing_id'          => $hearingId,
            ':legislative_item_id' => $legislativeItemId,
            ':category_id'         => $categoryId,
            ':title'               => $title,
            ':description'         => $description,
            ':priority'            => $priority,
            ':status'              => $status,
            ':assigned_office_id'  => $assignedOfficeId,
            ':assigned_user_id'    => $assignedUserId,
            ':due_at'              => $dueAt ?: null,
            ':res_summary'         => $resSummary,
            ':res_summary_val'     => $resSummary ?: null,
            ':resolved_at'         => $resolvedAt,
            ':resolved_by'         => $resolvedBy,
            ':closed_at'           => $closedAt,
            ':id'                  => $id,
        ]);

        if ($before['status'] !== $status) {
            $historyStmt = $pdo->prepare(
                'INSERT INTO hearing_issue_history (
                    issue_id,
                    note,
                    created_by,
                    created_at
                 ) VALUES (
                    :issue_id,
                    :note,
                    :created_by,
                    NOW()
                 )'
            );

            $historyStmt->execute([
                ':issue_id' => $id,
                ':note' => sprintf(
                    'Status changed from %s to %s.',
                    $before['status'],
                    $status
                ),
                ':created_by' => currentUserId(),
            ]);
        }

        if (
            (int)($before['assigned_office_id'] ?? 0) !== (int)($assignedOfficeId ?? 0) ||
            (int)($before['assigned_user_id'] ?? 0) !== (int)($assignedUserId ?? 0)
        ) {
            $assignmentStmt = $pdo->prepare(
                'INSERT INTO hearing_issue_assignments (
                    issue_id,
                    assigned_office_id,
                    assigned_user_id,
                    assigned_by,
                    remarks,
                    assigned_at
                 ) VALUES (
                    :issue_id,
                    :assigned_office_id,
                    :assigned_user_id,
                    :assigned_by,
                    :remarks,
                    NOW()
                 )'
            );

            $assignmentStmt->execute([
                ':issue_id'           => $id,
                ':assigned_office_id' => $assignedOfficeId,
                ':assigned_user_id'   => $assignedUserId,
                ':assigned_by'        => currentUserId(),
                ':remarks'            => 'Issue assignment updated.',
            ]);

            // Notify newly assigned staff
            if ($assignedUserId && $assignedUserId !== (int)($before['assigned_user_id'] ?? 0)) {
                $refNo = $existingIssue['reference_number'] ?? ('ISS-' . $id);
                $pdo->prepare(
                    "INSERT INTO notifications
                        (user_id, system_id, notification_type, title, message, target_url, is_read, created_at)
                     VALUES
                        (:uid, :sys, 'Issue Assignment', :title, :msg, :url, 0, NOW())"
                )->execute([
                    ':uid'   => $assignedUserId,
                    ':sys'   => function_exists('lphSystemId') ? lphSystemId() : 4,
                    ':title' => "Assigned to Hearing Issue: {$refNo}",
                    ':msg'   => "Nakatoka sa iyong account ang issue: \"{$title}\". Pindutin upang matingnan at maaksyunan.",
                    ':url'   => APP_URL . '/modules/issues/view.php?id=' . $id,
                ]);
            }
        }

        $message = 'Issue updated successfully.';

    } else {
        $referenceNumber =
            'ISS-' .
            date('Y') .
            '-' .
            strtoupper(bin2hex(random_bytes(4)));

        $insertStmt = $pdo->prepare(
            'INSERT INTO hearing_issues (
                hearing_id,
                legislative_item_id,
                category_id,
                reference_number,
                title,
                description,
                priority,
                status,
                assigned_office_id,
                assigned_user_id,
                due_at,
                created_by,
                created_at
             ) VALUES (
                :hearing_id,
                :legislative_item_id,
                :category_id,
                :reference_number,
                :title,
                :description,
                :priority,
                :status,
                :assigned_office_id,
                :assigned_user_id,
                :due_at,
                :created_by,
                NOW()
             )'
        );

        $insertStmt->execute([
            ':hearing_id'          => $hearingId,
            ':legislative_item_id' => $legislativeItemId,
            ':category_id'         => $categoryId,
            ':reference_number'    => $referenceNumber,
            ':title'               => $title,
            ':description'         => $description,
            ':priority'            => $priority,
            ':status'              => $status,
            ':assigned_office_id'  => $assignedOfficeId,
            ':assigned_user_id'    => $assignedUserId,
            ':due_at'              => $dueAt ?: null,
            ':created_by'          => currentUserId(),
        ]);

        $id = (int)$pdo->lastInsertId();

        $historyStmt = $pdo->prepare(
            'INSERT INTO hearing_issue_history (
                issue_id,
                note,
                created_by,
                created_at
             ) VALUES (
                :issue_id,
                :note,
                :created_by,
                NOW()
             )'
        );

        $historyStmt->execute([
            ':issue_id' => $id,
            ':note' => sprintf(
                'Issue logged with priority %s and status %s.',
                $priority,
                $status
            ),
            ':created_by' => currentUserId(),
        ]);

        if ($assignedOfficeId || $assignedUserId) {
            $assignmentStmt = $pdo->prepare(
                'INSERT INTO hearing_issue_assignments (
                    issue_id,
                    assigned_office_id,
                    assigned_user_id,
                    assigned_by,
                    remarks,
                    assigned_at
                 ) VALUES (
                    :issue_id,
                    :assigned_office_id,
                    :assigned_user_id,
                    :assigned_by,
                    :remarks,
                    NOW()
                 )'
            );

            $assignmentStmt->execute([
                ':issue_id'           => $id,
                ':assigned_office_id' => $assignedOfficeId,
                ':assigned_user_id'   => $assignedUserId,
                ':assigned_by'        => currentUserId(),
                ':remarks'            => 'Initial issue assignment.',
            ]);

            if ($assignedUserId) {
                $pdo->prepare(
                    "INSERT INTO notifications
                        (user_id, system_id, notification_type, title, message, target_url, is_read, created_at)
                     VALUES
                        (:uid, :sys, 'Issue Assignment', :title, :msg, :url, 0, NOW())"
                )->execute([
                    ':uid'   => $assignedUserId,
                    ':sys'   => function_exists('lphSystemId') ? lphSystemId() : 4,
                    ':title' => "Assigned to Hearing Issue: {$referenceNumber}",
                    ':msg'   => "Nakatoka sa iyong account ang issue: \"{$title}\". Pindutin upang matingnan at maaksyunan.",
                    ':url'   => APP_URL . '/modules/issues/view.php?id=' . $id,
                ]);
            }
        }

        $message = 'Issue created successfully.';
    }

    $pdo->commit();

    logActivity(
        currentUserId(),
        $id > 0 ? 'Update' : 'Insert',
        'Saved hearing issue #' . $id . ' (' . $title . ')'
    );

    jsonResponse(true, $message, ['id' => $id]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Hearing issue save error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while saving the issue.');
}
