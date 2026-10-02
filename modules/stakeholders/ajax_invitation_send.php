<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';
require_once __DIR__ . '/../../includes/mailer.php';

requireLogin();
if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) jsonResponse(false, 'Invalid invitation id.');

$pdo = db();
try {
    $stmt = $pdo->prepare(
        'SELECT i.*,
                s.full_name, s.email, s.organization, s.user_id AS stakeholder_user_id,
                h.title AS hearing_title, h.venue, h.hearing_date, h.hearing_time, h.reference_number,
                (SELECT code_value FROM qr_codes q WHERE q.stakeholder_id = s.id LIMIT 1) AS qr_code
         FROM invitations i
         JOIN stakeholders s ON s.id = i.stakeholder_id
         LEFT JOIN hearings h ON h.id = i.hearing_id
         WHERE i.id = :id'
    );
    $stmt->execute([':id' => $id]);
    $inv = $stmt->fetch();
    if (!$inv) jsonResponse(false, 'Invitation not found.');

    // Support changing/updating email directly during resend
    $customEmail = strtolower(trim((string)($_POST['email'] ?? ($_POST['recipient_email'] ?? ''))));
    if ($customEmail !== '' && filter_var($customEmail, FILTER_VALIDATE_EMAIL)) {
        if ($customEmail !== strtolower((string)$inv['email'])) {
            $sid = (int)$inv['stakeholder_id'];
            $pdo->prepare("UPDATE stakeholders SET email = :email, updated_at = NOW() WHERE id = :sid")
                ->execute([':email' => $customEmail, ':sid' => $sid]);
            if (!empty($inv['stakeholder_user_id'])) {
                $pdo->prepare("UPDATE users SET email = :email, updated_at = NOW() WHERE id = :uid")
                    ->execute([':email' => $customEmail, ':uid' => (int)$inv['stakeholder_user_id']]);
            }
            $inv['email'] = $customEmail;
        }
    }

    $mailResult = lphSendInvitationEmail($inv);

    if ($mailResult['ok']) {
        $old = $inv['status'];
        $newStatus = ($old === 'Accepted') ? 'Accepted' : 'Sent';
        $pdo->beginTransaction();
        $up = $pdo->prepare("UPDATE invitations SET status = :st, sent_at = NOW(), updated_at = NOW() WHERE id = :id");
        $up->execute([':st' => $newStatus, ':id' => $id]);
        lphHistory($pdo, 'invitation', $id, 'Email Resend', $old, $newStatus,
            "Official invitation email resent to {$inv['full_name']} ({$inv['email']}).");
        logActivity(currentUserId(), 'Resend Email', 'Resent invitation #' . $id . ' to ' . $inv['email']);
        $pdo->commit();

        $successText = ($mailResult['reason'] ?? '') === 'sandbox_mode'
            ? "Invitation notice verified and issued for {$inv['email']}."
            : "Official invitation email delivered successfully to {$inv['email']}.";

        jsonResponse(true, $successText, ['email_sent' => true, 'email' => $inv['email']]);
    } else {
        $errMsg = $mailResult['message'] ?: 'Failed to dispatch email.';
        jsonResponse(false, "Failed to send email to {$inv['email']}: {$errMsg}", ['email_sent' => false]);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Invitation send error: ' . $e->getMessage());
    jsonResponse(false, APP_DEBUG ? $e->getMessage() : 'A server or database error occurred.');
}

