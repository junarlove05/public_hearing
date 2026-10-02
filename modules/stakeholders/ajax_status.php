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
$allowed = ['Pending', 'Approved', 'Verified', 'Rejected', 'Inactive'];

if ($id <= 0 || !in_array($status, $allowed, true)) {
    jsonResponse(false, 'Invalid request.');
}

$pdo = db();
try {
    $stmt = $pdo->prepare('SELECT full_name, email FROM stakeholders WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $stakeholder = $stmt->fetch();
    if (!$stakeholder) jsonResponse(false, 'Stakeholder not found.');

    $isVerified = in_array($status, ['Approved', 'Verified', 'Active'], true);
    $verifiedAt = $isVerified ? date('Y-m-d H:i:s') : null;
    $verifiedBy = $isVerified ? currentUserId() : null;

    $update = $pdo->prepare('UPDATE stakeholders SET status = :status, verified_at = :vat, verified_by = :vby, updated_at = NOW() WHERE id = :id');
    $update->execute([':status' => $status, ':vat' => $verifiedAt, ':vby' => $verifiedBy, ':id' => $id]);

    $code = null;
    if ($isVerified) {
        $qrCheck = $pdo->prepare('SELECT code_value FROM qr_codes WHERE stakeholder_id = :sid LIMIT 1');
        $qrCheck->execute([':sid' => $id]);
        $code = $qrCheck->fetchColumn();
        if (!$code) {
            $code = 'STK-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            $pdo->prepare('INSERT INTO qr_codes (stakeholder_id, code_value, created_at) VALUES (:sid, :code, NOW())')
                ->execute([':sid' => $id, ':code' => $code]);
        }
    } else {
        $pdo->prepare('DELETE FROM qr_codes WHERE stakeholder_id = :sid')->execute([':sid' => $id]);
    }

    logActivity(currentUserId(), 'Update', 'Set stakeholder #' . $id . ' (' . $stakeholder['full_name'] . ') status to ' . $status);
    jsonResponse(true, 'Stakeholder ' . $stakeholder['full_name'] . ' status updated to ' . $status . '.', [
        'id' => $id,
        'status' => $status,
        'is_verified' => $isVerified,
        'code_value' => $code,
        'qr_url' => ($isVerified && $code) ? (APP_URL . '/modules/stakeholders/qr.php?id=' . $id) : null
    ]);

} catch (PDOException $e) {
    error_log('Stakeholder status update error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred.');
}
