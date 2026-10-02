<?php
/**
 * modules/issues/ajax_add_note.php
 * ------------------------------------------------------------------
 * Adds a note/update entry to issue_history (the issue's timeline).
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

requireCsrf();

$id   = (int)($_POST['id'] ?? 0);
$note = clean($_POST['note'] ?? '');

if ($id <= 0 || $note === '') {
    jsonResponse(false, 'Please write a note before submitting.');
}

$pdo = db();

try {
    $checkStmt = $pdo->prepare(
        'SELECT id, assigned_user_id
         FROM hearing_issues
         WHERE id = :id'
    );

    $checkStmt->execute([':id' => $id]);
    $issueRow = $checkStmt->fetch();

    if (!$issueRow) {
        jsonResponse(false, 'Issue not found.');
    }

    $isAssignedUser = (function_exists('currentUserId') && currentUserId() && (int)($issueRow['assigned_user_id'] ?? 0) === currentUserId());
    if (!canManage() && !$isAssignedUser) {
        jsonResponse(false, 'You do not have permission to add notes to this issue.');
    }

    $stmt = $pdo->prepare(
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

    $stmt->execute([
        ':issue_id'  => $id,
        ':note'      => $note,
        ':created_by'=> currentUserId(),
    ]);

    logActivity(
        currentUserId(),
        'Update',
        'Added note to hearing issue #' . $id
    );

    jsonResponse(true, 'Note added successfully.');

} catch (PDOException $e) {
    error_log('Hearing issue note error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while adding the note.');
}
