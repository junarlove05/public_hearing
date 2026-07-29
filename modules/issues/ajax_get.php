<?php
/**
 * modules/issues/ajax_get.php
 * ------------------------------------------------------------------
 * Returns a single issue record as JSON for the edit modal.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    jsonResponse(false, 'Invalid issue id.');
}

$stmt = db()->prepare(
    'SELECT
        i.id,
        i.hearing_id,
        i.legislative_item_id,
        i.feedback_id,
        i.category_id,
        i.reference_number,
        i.title,
        i.description,
        i.priority,
        i.status,
        i.assigned_office_id,
        i.assigned_user_id,
        i.due_at,
        o.name AS assigned_office
     FROM hearing_issues i
     LEFT JOIN offices o ON o.id = i.assigned_office_id
     WHERE i.id = :id'
);

$stmt->execute([':id' => $id]);
$issue = $stmt->fetch();

if (!$issue) {
    jsonResponse(false, 'Issue not found.');
}

jsonResponse(true, '', ['issue' => $issue]);
