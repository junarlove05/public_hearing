<?php
/**
 * modules/stakeholders/ajax_registration_save.php
 * ------------------------------------------------------------------
 * Manually registers a stakeholder for a hearing (staff-assisted
 * registration). Prevents duplicate registrations for the same
 * stakeholder + hearing pair.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$stakeholderId = (int)($_POST['stakeholder_id'] ?? 0);
$hearingId     = (int)($_POST['hearing_id'] ?? 0) ?: null;

if ($stakeholderId <= 0) jsonResponse(false, 'Please select a stakeholder.');

$pdo = db();
try {
    $checkStmt = $pdo->prepare(
        'SELECT id FROM registrations WHERE stakeholder_id = :sid AND hearing_id ' . ($hearingId ? '= :hid' : 'IS NULL') . ' LIMIT 1'
    );
    $checkParams = [':sid' => $stakeholderId];
    if ($hearingId) $checkParams[':hid'] = $hearingId;
    $checkStmt->execute($checkParams);
    if ($checkStmt->fetch()) {
        jsonResponse(false, 'This stakeholder is already registered for that hearing.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO registrations (stakeholder_id, hearing_id, registered_at) VALUES (:sid, :hid, NOW())'
    );
    $stmt->execute([':sid' => $stakeholderId, ':hid' => $hearingId]);
    $id = (int)$pdo->lastInsertId();

    logActivity(currentUserId(), 'Insert', 'Registered stakeholder #' . $stakeholderId . ' for hearing #' . ($hearingId ?? 'none'));
    jsonResponse(true, 'Registration recorded successfully.', ['id' => $id]);

} catch (PDOException $e) {
    error_log('Registration save error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while saving the registration.');
}
