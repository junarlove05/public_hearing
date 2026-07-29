<?php
/**
 * modules/issues/ajax_delete.php
 * ------------------------------------------------------------------
 * Deletes an issue. DB cascades remove its issue_assignments and
 * issue_history automatically (ON DELETE CASCADE). Any actions linked
 * to this issue keep the FK but with a null-able reference per schema
 * (actions.issue_id has no ON DELETE clause, so deletion of an issue
 * with linked actions would fail at the DB level — we surface that
 * as a friendly error instead of a raw SQL exception).
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!canManage()) {
    jsonResponse(
        false,
        'You do not have permission to perform this action.'
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

requireCsrf();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($id <= 0) {
    jsonResponse(false, 'Invalid issue id.');
}

$pdo = db();

try {
    $pdo->beginTransaction();

    /*
     * Lock and retrieve the issue before deleting it.
     */
    $issueStmt = $pdo->prepare(
        'SELECT
            id,
            reference_number,
            title
         FROM hearing_issues
         WHERE id = :id
         FOR UPDATE'
    );

    $issueStmt->execute([
        ':id' => $id,
    ]);

    $issue = $issueStmt->fetch();

    if (!$issue) {
        $pdo->rollBack();
        jsonResponse(false, 'Issue not found.');
    }

    /*
     * Prevent deletion when response actions are still connected.
     * This avoids leaving actions without their original issue.
     */
    $actionStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM hearing_actions
         WHERE issue_id = :id'
    );

    $actionStmt->execute([
        ':id' => $id,
    ]);

    $linkedActionCount = (int)$actionStmt->fetchColumn();

    if ($linkedActionCount > 0) {
        $pdo->rollBack();

        jsonResponse(
            false,
            'This issue cannot be deleted because it has ' .
            $linkedActionCount .
            ' linked response action(s). Delete or reassign those actions first.'
        );
    }

    /*
     * These related tables are automatically cleared because their
     * foreign keys use ON DELETE CASCADE:
     *
     * hearing_issue_history
     * hearing_issue_assignments
     * hearing_responses
     */
    $deleteStmt = $pdo->prepare(
        'DELETE FROM hearing_issues
         WHERE id = :id'
    );

    $deleteStmt->execute([
        ':id' => $id,
    ]);

    if ($deleteStmt->rowCount() !== 1) {
        throw new RuntimeException(
            'The issue record was not deleted.'
        );
    }

    logActivity(
        currentUserId(),
        'Delete',
        sprintf(
            'Deleted hearing issue #%d [%s] (%s)',
            $id,
            $issue['reference_number'],
            $issue['title']
        )
    );

    $pdo->commit();

    jsonResponse(
        true,
        'Issue deleted successfully.'
    );

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Hearing issue delete error: ' . $e->getMessage()
    );

    jsonResponse(
        false,
        'A database error occurred while deleting the issue.'
    );
}