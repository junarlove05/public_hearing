<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';
require_once __DIR__ . '/../../includes/mailer.php';

requireLogin();
if (!canManage()) jsonResponse(false, 'You do not have permission to create invitations.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$pdo = db();
$hearingSessionKey = trim((string)($_POST['hearing_session_key'] ?? ''));
$sessionDayId = (int)($_POST['session_day_id'] ?? 0);
$sessionDate = trim((string)($_POST['session_date'] ?? ''));
$hearingId = (int)($_POST['hearing_id'] ?? 0);

if ($hearingSessionKey !== '') {
    $parts = explode('_', $hearingSessionKey);
    if (isset($parts[0]) && is_numeric($parts[0])) $hearingId = (int)$parts[0];
    if (isset($parts[1]) && is_numeric($parts[1])) $sessionDayId = (int)$parts[1];
    if (isset($parts[2]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $parts[2])) $sessionDate = $parts[2];
}

// Resolve session date if missing
if ($sessionDate === '' && $sessionDayId > 0) {
    $sStmt = $pdo->prepare('SELECT session_date FROM hearing_session_days WHERE id = :id');
    $sStmt->execute([':id' => $sessionDayId]);
    $sessionDate = (string)($sStmt->fetchColumn() ?: '');
}
if ($sessionDate === '' && $hearingId > 0) {
    $hStmt = $pdo->prepare('SELECT hearing_date FROM hearings WHERE id = :id');
    $hStmt->execute([':id' => $hearingId]);
    $sessionDate = (string)($hStmt->fetchColumn() ?: '');
}

$stakeholderIds = $_POST['stakeholder_ids'] ?? [];
$expiresAt = clean($_POST['expires_at'] ?? '') ?: null;
$remarks = trim((string)($_POST['remarks'] ?? ''));

if ($hearingId <= 0) jsonResponse(false, 'Select a hearing.');
if (!is_array($stakeholderIds)) $stakeholderIds = [$stakeholderIds];
$stakeholderIds = array_values(array_unique(array_filter(array_map('intval', $stakeholderIds))));
if (!$stakeholderIds) jsonResponse(false, 'Select at least one stakeholder.');

$hearingStmt = $pdo->prepare('SELECT id,title,status FROM hearings WHERE id=:id');
$hearingStmt->execute([':id'=>$hearingId]);
$hearing = $hearingStmt->fetch();
if (!$hearing) jsonResponse(false, 'Hearing not found.');
if (in_array($hearing['status'], ['Completed','Cancelled'], true)) {
    jsonResponse(false, 'Invitations cannot be created for a completed or cancelled hearing.');
}

$created = 0;
$skipped = 0;
$toEmailDispatch = [];

try {
    $pdo->beginTransaction();

    foreach ($stakeholderIds as $sid) {
        $s = $pdo->prepare("SELECT id, full_name, email, organization, user_id, status FROM stakeholders WHERE id = :id");
        $s->execute([':id'=>$sid]);
        $stakeholder = $s->fetch();
        if (!$stakeholder) { $skipped++; continue; }

        if ($sessionDayId > 0) {
            $dup = $pdo->prepare(
                "SELECT id FROM invitations
                 WHERE stakeholder_id=:sid AND hearing_id=:hid AND session_day_id=:sdid
                   AND status IN ('Pending','Sent','Accepted')
                 LIMIT 1"
            );
            $dup->execute([':sid'=>$sid, ':hid'=>$hearingId, ':sdid'=>$sessionDayId]);
        } elseif ($sessionDate !== '') {
            $dup = $pdo->prepare(
                "SELECT id FROM invitations
                 WHERE stakeholder_id=:sid AND hearing_id=:hid AND session_date=:sdate
                   AND status IN ('Pending','Sent','Accepted')
                 LIMIT 1"
            );
            $dup->execute([':sid'=>$sid, ':hid'=>$hearingId, ':sdate'=>$sessionDate]);
        } else {
            $dup = $pdo->prepare(
                "SELECT id FROM invitations
                 WHERE stakeholder_id=:sid AND hearing_id=:hid AND session_day_id IS NULL AND session_date IS NULL
                   AND status IN ('Pending','Sent','Accepted')
                 LIMIT 1"
            );
            $dup->execute([':sid'=>$sid, ':hid'=>$hearingId]);
        }
        if ($dup->fetch()) { $skipped++; continue; }

        $code = lphUniqueCode($pdo, 'invitations', 'invitation_code', 'INV');

        $stmt = $pdo->prepare(
            'INSERT INTO invitations
             (stakeholder_id,hearing_id,session_day_id,session_date,status,invitation_code,sent_at,created_at,
              invited_by,responded_at,expires_at,remarks,updated_at)
             VALUES
             (:sid,:hid,:sdid,:sdate,"Pending",:code,NULL,NOW(),:user,NULL,:expires,:remarks,NOW())'
        );
        $stmt->execute([
            ':sid'=>$sid, ':hid'=>$hearingId, 
            ':sdid'=>($sessionDayId > 0 ? $sessionDayId : null),
            ':sdate'=>($sessionDate !== '' ? $sessionDate : null),
            ':code'=>$code,
            ':user'=>currentUserId(), ':expires'=>$expiresAt, ':remarks'=>$remarks ?: null
        ]);
        $invitationId = (int)$pdo->lastInsertId();

        // Ensure stakeholder has a permanent QR identification code ONLY IF Verified
        $sStatus = $stakeholder['status'] ?? '';
        if (in_array($sStatus, ['Verified', 'Approved', 'Active'], true)) {
            $qrCheck = $pdo->prepare('SELECT code_value FROM qr_codes WHERE stakeholder_id=:sid LIMIT 1');
            $qrCheck->execute([':sid'=>$sid]);
            if (!$qrCheck->fetchColumn()) {
                $stkCode = 'STK-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                $pdo->prepare('INSERT INTO qr_codes (stakeholder_id, code_value, status, created_at) VALUES (:sid, :code, "Active", NOW())')
                    ->execute([':sid'=>$sid, ':code'=>$stkCode]);
            }
        }

        // Automatically enroll invited stakeholder into registrations with Pending status
        if ($sessionDate !== '') {
            $regCheck = $pdo->prepare('SELECT id, registration_status FROM registrations WHERE stakeholder_id=:sid AND hearing_id=:hid AND session_date=:sdate LIMIT 1');
            $regCheck->execute([':sid'=>$sid, ':hid'=>$hearingId, ':sdate'=>$sessionDate]);
        } else {
            $regCheck = $pdo->prepare('SELECT id, registration_status FROM registrations WHERE stakeholder_id=:sid AND hearing_id=:hid LIMIT 1');
            $regCheck->execute([':sid'=>$sid, ':hid'=>$hearingId]);
        }
        $existingReg = $regCheck->fetch();

        if (!$existingReg) {
            $regCode = lphUniqueCode($pdo, 'registrations', 'registration_code', 'REG');
            $regInsert = $pdo->prepare(
                'INSERT INTO registrations
                 (stakeholder_id, hearing_id, session_day_id, session_date, registration_code, registration_status, attendance_type, approved_by, approved_at, registered_at)
                 VALUES (:sid, :hid, :sdid, :sdate, :code, "Pending", "Invited", NULL, NULL, NOW())'
            );
            $regInsert->execute([
                ':sid' => $sid,
                ':hid' => $hearingId,
                ':sdid' => ($sessionDayId > 0 ? $sessionDayId : null),
                ':sdate' => ($sessionDate !== '' ? $sessionDate : null),
                ':code' => $regCode
            ]);
        }

        $sessionSuffix = $sessionDate ? ' (' . date('M d, Y', strtotime($sessionDate)) . ')' : '';
        lphHistory($pdo, 'invitation', $invitationId, 'Create', null, 'Pending',
            'Invitation created for '.$stakeholder['full_name'].' to '.$hearing['title'] . $sessionSuffix . '.');
        $created++;

        if (!empty($stakeholder['email']) && filter_var($stakeholder['email'], FILTER_VALIDATE_EMAIL)) {
            $toEmailDispatch[] = [
                'id'                  => $invitationId,
                'invitation_code'     => $code,
                'email'               => $stakeholder['email'],
                'full_name'           => $stakeholder['full_name'],
                'organization'        => $stakeholder['organization'] ?? '',
                'stakeholder_user_id' => $stakeholder['user_id'] ?? null,
                'hearing_id'          => $hearingId,
                'hearing_title'       => $hearing['title'],
                'venue'               => $hearing['venue'] ?? '',
                'hearing_date'        => $sessionDate ?: ($hearing['hearing_date'] ?? ''),
                'hearing_time'        => $hearing['hearing_time'] ?? '',
                'reference_number'    => $hearing['reference_number'] ?? '',
                'remarks'             => $remarks ?: '',
            ];
        }
    }

    logActivity(
        currentUserId(),
        'Create Hearing Invitations',
        "Created {$created} invitation(s) for hearing {$hearing['title']}; {$skipped} skipped."
    );

    $pdo->commit();

    $emailsDelivered = 0;
    $emailNotice = null;
    foreach ($toEmailDispatch as $disp) {
        $mailRes = lphSendInvitationEmail($disp);
        if (!empty($mailRes['ok'])) {
            $emailsDelivered++;
            try {
                $pdo->prepare("UPDATE invitations SET status = 'Sent', sent_at = NOW() WHERE id = :id")->execute([':id' => $disp['id']]);
            } catch (Throwable $t) {}
        } else {
            if (!$emailNotice) $emailNotice = $mailRes['message'] ?? 'Gmail SMTP not configured.';
            $reason = (string)($mailRes['reason'] ?? '');
            // Prevent hanging: if connection failed or unconfigured or auth failed, do not repeat the loop for all remaining rows
            if (in_array($reason, ['connect_failed', 'host_unreachable', 'auth_failed', 'unconfigured', 'api_error'], true)) {
                $rem = count($toEmailDispatch) - $emailsDelivered - 1;
                if ($rem > 0) {
                    $emailNotice .= " (Skipped {$rem} remaining recipient(s) to avoid connection delay).";
                }
                break;
            }
        }
    }

    if ($emailsDelivered > 0) {
        $msg = "Created {$created} invitation(s). {$emailsDelivered} official email invitation(s) successfully delivered to recipient Gmail inbox.";
    } elseif ($emailNotice) {
        $msg = "Created {$created} invitation(s). Email delivery notice: {$emailNotice}";
    } else {
        $msg = "Created {$created} invitation(s).";
    }

    jsonResponse(true, $msg, [
        'created'          => $created,
        'emails_delivered' => $emailsDelivered,
        'email_notice'     => $emailNotice
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Invitation create error: '.$e->getMessage());
    jsonResponse(false, APP_DEBUG ? $e->getMessage() : 'Unable to create invitations.');
}
