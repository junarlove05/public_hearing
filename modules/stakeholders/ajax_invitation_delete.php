<?php
/**
 * modules/stakeholders/ajax_invitation_delete.php
 * ------------------------------------------------------------------
 * Deletes a single invitation.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) jsonResponse(false, 'Invalid invitation id.');

$pdo = db();
try {
    $stmt = $pdo->prepare('DELETE FROM invitations WHERE id = :id');
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) jsonResponse(false, 'Invitation not found.');

    logActivity(currentUserId(), 'Delete', 'Deleted invitation #' . $id);
    jsonResponse(true, 'Invitation deleted successfully.');
} catch (PDOException $e) {
    error_log('Invitation delete error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while deleting the invitation.');
}
