<?php
/**
 * modules/stakeholders/ajax_delete.php
 * ------------------------------------------------------------------
 * Deletes a stakeholder. DB cascades remove their invitations,
 * registrations, and qr_codes automatically (ON DELETE CASCADE).
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) jsonResponse(false, 'Invalid stakeholder id.');

$pdo = db();

try {
    $stmt = $pdo->prepare('SELECT full_name FROM stakeholders WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $stakeholder = $stmt->fetch();

    if (!$stakeholder) jsonResponse(false, 'Stakeholder not found.');

    $del = $pdo->prepare('DELETE FROM stakeholders WHERE id = :id');
    $del->execute([':id' => $id]);

    logActivity(currentUserId(), 'Delete', 'Deleted stakeholder #' . $id . ' (' . $stakeholder['full_name'] . ')');
    jsonResponse(true, 'Stakeholder deleted successfully.');

} catch (PDOException $e) {
    error_log('Stakeholder delete error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while deleting the stakeholder.');
}
