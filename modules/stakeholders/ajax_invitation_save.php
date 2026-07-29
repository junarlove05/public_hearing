<?php
/**
 * modules/stakeholders/ajax_invitation_save.php
 * ------------------------------------------------------------------
 * Creates or updates a single invitation. On creation, auto-generates
 * a unique invitation_code. Used by the "Add Invitation" modal on
 * invitations.php (single-stakeholder path; see ajax_bulk_invite.php
 * for multi-stakeholder invitations).
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id            = (int)($_POST['id'] ?? 0);
$stakeholderId = (int)($_POST['stakeholder_id'] ?? 0);
$hearingId     = (int)($_POST['hearing_id'] ?? 0) ?: null;
$status        = clean($_POST['status'] ?? 'Pending');

$allowedStatus = ['Pending', 'Sent'];

$errors = [];
if ($stakeholderId <= 0) $errors[] = 'Please select a stakeholder.';
if (!in_array($status, $allowedStatus, true)) $errors[] = 'Invalid status value.';
if (!empty($errors)) jsonResponse(false, implode(' ', $errors));

$pdo = db();

try {
    if ($id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE invitations SET stakeholder_id = :sid, hearing_id = :hid, status = :status WHERE id = :id'
        );
        $stmt->execute([':sid' => $stakeholderId, ':hid' => $hearingId, ':status' => $status, ':id' => $id]);
        logActivity(currentUserId(), 'Update', 'Updated invitation #' . $id);
        jsonResponse(true, 'Invitation updated successfully.', ['id' => $id]);
    }

    $code = generateCode('INV-');
    $stmt = $pdo->prepare(
        'INSERT INTO invitations (stakeholder_id, hearing_id, status, invitation_code, created_at)
         VALUES (:sid, :hid, :status, :code, NOW())'
    );
    $stmt->execute([':sid' => $stakeholderId, ':hid' => $hearingId, ':status' => $status, ':code' => $code]);
    $id = (int)$pdo->lastInsertId();

    logActivity(currentUserId(), 'Insert', 'Created invitation #' . $id . ' (code ' . $code . ')');
    jsonResponse(true, 'Invitation created successfully.', ['id' => $id, 'invitation_code' => $code]);

} catch (PDOException $e) {
    error_log('Invitation save error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while saving the invitation.');
}
