<?php
declare(strict_types=1);

/**
 * modules/stakeholders/ajax_bulk_remind.php
 * ------------------------------------------------------------------
 * Dispatches reminder notices to all invited/accepted stakeholders
 * for an upcoming hearing session.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';
require_once __DIR__ . '/../../includes/mailer.php';

requireLogin();
if (!canManage()) {
    jsonResponse(false, 'You do not have permission to send hearing reminders.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}
requireCsrf();

$hearingId = (int)($_POST['hearing_id'] ?? 0);
$sessionDate = clean($_POST['session_date'] ?? '');

if ($hearingId <= 0) {
    jsonResponse(false, 'Please select a valid hearing to send reminders for.');
}

$pdo = db();

try {
    $hStmt = $pdo->prepare('SELECT id, reference_number, title, hearing_date, venue, hearing_time, status FROM hearings WHERE id = :id');
    $hStmt->execute([':id' => $hearingId]);
    $hearing = $hStmt->fetch();
    if (!$hearing) {
        jsonResponse(false, 'Hearing record was not found.');
    }

    $sql = "SELECT i.id, i.hearing_id, i.stakeholder_id, i.status, i.session_date,
                   s.full_name, s.email, s.organization,
                   h.title AS hearing_title, h.venue, h.hearing_date, h.hearing_time, h.reference_number,
                   (SELECT code_value FROM qr_codes q WHERE q.stakeholder_id = s.id LIMIT 1) AS qr_code
            FROM invitations i
            JOIN stakeholders s ON s.id = i.stakeholder_id
            JOIN hearings h ON h.id = i.hearing_id
            WHERE i.hearing_id = :hid 
              AND i.status IN ('Accepted', 'Sent', 'Pending')
              AND s.email IS NOT NULL AND s.email != ''";

    $params = [':hid' => $hearingId];
    if ($sessionDate !== '') {
        $sql .= " AND (i.session_date = :sdate OR i.session_date IS NULL)";
        $params[':sdate'] = $sessionDate;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invitations = $stmt->fetchAll();

    if (empty($invitations)) {
        jsonResponse(false, 'No active invitations found with valid email addresses for this hearing.');
    }

    $sent = 0;
    $errors = 0;

    foreach ($invitations as $inv) {
        $mailResult = lphSendInvitationEmail($inv);
        if ($mailResult['ok']) {
            $sent++;
            lphHistory($pdo, 'invitation', (int)$inv['id'], 'Reminder Sent', $inv['status'], $inv['status'],
                "Automated hearing reminder sent to {$inv['full_name']} ({$inv['email']}).");
        } else {
            $errors++;
        }
    }

    logActivity(
        currentUserId(),
        'Hearing Reminders Sent',
        "Dispatched {$sent} reminder email(s) for Hearing #{$hearingId} ({$hearing['title']})."
    );

    jsonResponse(true, "Sent reminder notices to {$sent} stakeholder(s)" . ($errors > 0 ? " ({$errors} failed)." : "."), [
        'sent' => $sent,
        'errors' => $errors,
        'total' => count($invitations)
    ]);

} catch (Throwable $e) {
    error_log('Bulk remind error: ' . $e->getMessage());
    jsonResponse(false, 'An error occurred while sending reminders: ' . $e->getMessage());
}
