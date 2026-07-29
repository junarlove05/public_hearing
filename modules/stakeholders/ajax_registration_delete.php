<?php
/**
 * modules/stakeholders/ajax_registration_delete.php
 * ------------------------------------------------------------------
 * Deletes a single registration record.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) jsonResponse(false, 'Invalid registration id.');

$pdo = db();
try {
    $stmt = $pdo->prepare('DELETE FROM registrations WHERE id = :id');
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) jsonResponse(false, 'Registration not found.');

    logActivity(currentUserId(), 'Delete', 'Deleted registration #' . $id);
    jsonResponse(true, 'Registration deleted successfully.');
} catch (PDOException $e) {
    error_log('Registration delete error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while deleting the registration.');
}
