<?php
/**
 * modules/stakeholders/ajax_bulk_invite.php
 * ------------------------------------------------------------------
 * Creates one invitation per selected stakeholder for a chosen
 * hearing. Skips stakeholders who already have an invitation for
 * that same hearing (no duplicate invites).
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$hearingId = (int)($_POST['hearing_id'] ?? 0) ?: null;
$stakeholderIds = array_filter(array_map('intval', explode(',', (string)($_POST['stakeholder_ids'] ?? ''))));

if (empty($stakeholderIds)) {
    jsonResponse(false, 'No stakeholders were selected.');
}

$pdo = db();
$created = 0;
$skipped = 0;

try {
    $pdo->beginTransaction();

    $checkStmt = $pdo->prepare(
        'SELECT id FROM invitations WHERE stakeholder_id = :sid AND hearing_id ' . ($hearingId ? '= :hid' : 'IS NULL') . ' LIMIT 1'
    );
    $insertStmt = $pdo->prepare(
        'INSERT INTO invitations (stakeholder_id, hearing_id, status, invitation_code, created_at)
         VALUES (:sid, :hid, :status, :code, NOW())'
    );

    foreach ($stakeholderIds as $sid) {
        $checkParams = [':sid' => $sid];
        if ($hearingId) $checkParams[':hid'] = $hearingId;
        $checkStmt->execute($checkParams);

        if ($checkStmt->fetch()) {
            $skipped++;
            continue;
        }

        $insertStmt->execute([
            ':sid' => $sid, ':hid' => $hearingId, ':status' => 'Pending', ':code' => generateCode('INV-'),
        ]);
        $created++;
    }

    $pdo->commit();

    logActivity(currentUserId(), 'Insert', "Bulk-created $created invitation(s) for hearing #" . ($hearingId ?? 'none') . ", $skipped skipped (already invited).");
    jsonResponse(true, "Bulk invite complete: $created invitation(s) created, $skipped skipped (already invited).", [
        'created' => $created, 'skipped' => $skipped,
    ]);

} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('Bulk invite error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while creating invitations.');
}
