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

if (!canManage()) {
    jsonResponse(false, 'You do not have permission to perform this action.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

requireCsrf();

$id          = (int)($_POST['id'] ?? 0);
$title       = clean($_POST['title'] ?? '');
$description = clean($_POST['description'] ?? '');
$categoryId  = (int)($_POST['category_id'] ?? 0) ?: null;
$hearingId   = (int)($_POST['hearing_id'] ?? 0) ?: null;
$priority    = clean($_POST['priority'] ?? 'Medium');
$status      = clean($_POST['status'] ?? 'Open');
$dueAt       = clean($_POST['due_at'] ?? '');

/*
 * Preferred new field: assigned_office_id
 * Old form compatibility: assigned_office
 */
$assignedOfficeId   = (int)($_POST['assigned_office_id'] ?? 0) ?: null;
$assignedOfficeName = clean($_POST['assigned_office'] ?? '');

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

    $pdo->beginTransaction();

    if ($id > 0) {
        $beforeStmt = $pdo->prepare(
            'SELECT
                title,
                status,
                assigned_office_id
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
                due_at = :due_at,
                closed_at = :closed_at
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
            ':due_at'              => $dueAt ?: null,
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
            (int)($before['assigned_office_id'] ?? 0)
            !== (int)($assignedOfficeId ?? 0)
        ) {
            $assignmentStmt = $pdo->prepare(
                'INSERT INTO hearing_issue_assignments (
                    issue_id,
                    assigned_office_id,
                    assigned_by,
                    remarks,
                    assigned_at
                 ) VALUES (
                    :issue_id,
                    :assigned_office_id,
                    :assigned_by,
                    :remarks,
                    NOW()
                 )'
            );

            $assignmentStmt->execute([
                ':issue_id'          => $id,
                ':assigned_office_id' => $assignedOfficeId,
                ':assigned_by'       => currentUserId(),
                ':remarks'           => 'Issue assignment updated.',
            ]);
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

        if ($assignedOfficeId) {
            $assignmentStmt = $pdo->prepare(
                'INSERT INTO hearing_issue_assignments (
                    issue_id,
                    assigned_office_id,
                    assigned_by,
                    remarks,
                    assigned_at
                 ) VALUES (
                    :issue_id,
                    :assigned_office_id,
                    :assigned_by,
                    :remarks,
                    NOW()
                 )'
            );

            $assignmentStmt->execute([
                ':issue_id'          => $id,
                ':assigned_office_id' => $assignedOfficeId,
                ':assigned_by'       => currentUserId(),
                ':remarks'           => 'Initial issue assignment.',
            ]);
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
