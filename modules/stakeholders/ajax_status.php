<?php
/**
 * modules/stakeholders/ajax_status.php
 * ------------------------------------------------------------------
 * Quick-action endpoint used by the Approve / Reject buttons on the
 * stakeholders list to update `stakeholders.status` without opening
 * the full edit modal.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id     = (int)($_POST['id'] ?? 0);
$status = clean($_POST['status'] ?? '');
$allowed = ['Pending', 'Approved', 'Rejected'];

if ($id <= 0 || !in_array($status, $allowed, true)) {
    jsonResponse(false, 'Invalid request.');
}

$pdo = db();
try {
    $stmt = $pdo->prepare('SELECT full_name FROM stakeholders WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $stakeholder = $stmt->fetch();
    if (!$stakeholder) jsonResponse(false, 'Stakeholder not found.');

    $update = $pdo->prepare('UPDATE stakeholders SET status = :status WHERE id = :id');
    $update->execute([':status' => $status, ':id' => $id]);

    logActivity(currentUserId(), 'Update', 'Set stakeholder #' . $id . ' (' . $stakeholder['full_name'] . ') status to ' . $status);
    jsonResponse(true, 'Stakeholder status updated to ' . $status . '.');

} catch (PDOException $e) {
    error_log('Stakeholder status update error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred.');
}
