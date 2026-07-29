<?php
/**
 * modules/issues/ajax_assign.php
 * ------------------------------------------------------------------
 * Records a new assignment in issue_assignments and updates the
 * issue's current assigned_office field to match.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id         = (int)($_POST['id'] ?? 0);
$assignedTo = clean($_POST['assigned_to'] ?? '');

if ($id <= 0 || $assignedTo === '') jsonResponse(false, 'Please provide an office or person to assign.');

$pdo = db();
try {
    $checkStmt = $pdo->prepare('SELECT id FROM issues WHERE id = :id');
    $checkStmt->execute([':id' => $id]);
    if (!$checkStmt->fetch()) jsonResponse(false, 'Issue not found.');

    $pdo->prepare('INSERT INTO issue_assignments (issue_id, assigned_to, assigned_at) VALUES (:id, :to, NOW())')
        ->execute([':id' => $id, ':to' => $assignedTo]);

    $pdo->prepare('UPDATE issues SET assigned_office = :office WHERE id = :id')
        ->execute([':office' => $assignedTo, ':id' => $id]);

    logActivity(currentUserId(), 'Update', 'Assigned issue #' . $id . ' to ' . $assignedTo);
    jsonResponse(true, 'Issue assigned to ' . $assignedTo . '.', ['assigned_to' => $assignedTo]);
} catch (PDOException $e) {
    error_log('Issue assign error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred.');
}
