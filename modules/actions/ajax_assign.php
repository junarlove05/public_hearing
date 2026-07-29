<?php
/**
 * modules/actions/ajax_assign.php
 * ------------------------------------------------------------------
 * Records a new office assignment in action_assignments. The
 * `actions` table has no assigned_office column — the current
 * assignment is always the most recent action_assignments row.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id             = (int)($_POST['id'] ?? 0);
$assignedOffice = clean($_POST['assigned_office'] ?? '');

if ($id <= 0 || $assignedOffice === '') jsonResponse(false, 'Please provide an office to assign.');

$pdo = db();
try {
    $checkStmt = $pdo->prepare('SELECT id FROM actions WHERE id = :id');
    $checkStmt->execute([':id' => $id]);
    if (!$checkStmt->fetch()) jsonResponse(false, 'Action not found.');

    $pdo->prepare('INSERT INTO action_assignments (action_id, assigned_office, assigned_at) VALUES (:id, :office, NOW())')
        ->execute([':id' => $id, ':office' => $assignedOffice]);

    logActivity(currentUserId(), 'Update', 'Assigned action #' . $id . ' to ' . $assignedOffice);
    jsonResponse(true, 'Action assigned to ' . $assignedOffice . '.', ['assigned_office' => $assignedOffice]);
} catch (PDOException $e) {
    error_log('Action assign error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred.');
}
