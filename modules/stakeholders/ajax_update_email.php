<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';

requireLogin();
if (!canManage()) {
    jsonResponse(false, 'You do not have permission to manage stakeholders.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}
requireCsrf();

$sid = (int)($_POST['stakeholder_id'] ?? 0);
$email = strtolower(trim((string)($_POST['email'] ?? '')));

if ($sid <= 0) {
    jsonResponse(false, 'Invalid stakeholder ID.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(false, 'Please provide a valid email address.');
}

$pdo = db();
try {
    $stmt = $pdo->prepare('SELECT id, full_name, email, user_id FROM stakeholders WHERE id = :id');
    $stmt->execute([':id' => $sid]);
    $stk = $stmt->fetch();
    if (!$stk) {
        jsonResponse(false, 'Stakeholder record not found.');
    }

    $oldEmail = $stk['email'];

    // Check duplicate among other stakeholders (allow same if it's the same person)
    $dup = $pdo->prepare('SELECT id, full_name FROM stakeholders WHERE LOWER(email) = LOWER(:email) AND id <> :id LIMIT 1');
    $dup->execute([':email' => $email, ':id' => $sid]);
    $dupRow = $dup->fetch();
    if ($dupRow && strtolower(trim($dupRow['full_name'])) !== strtolower(trim($stk['full_name']))) {
        jsonResponse(false, "The email '{$email}' is already in use by '{$dupRow['full_name']}' (Stakeholder #{$dupRow['id']}).");
    }

    $pdo->beginTransaction();
    $up = $pdo->prepare('UPDATE stakeholders SET email = :email, updated_at = NOW() WHERE id = :id');
    $up->execute([':email' => $email, ':id' => $sid]);

    if (!empty($stk['user_id'])) {
        $pdo->prepare('UPDATE users SET email = :email, updated_at = NOW() WHERE id = :uid')
            ->execute([':email' => $email, ':uid' => (int)$stk['user_id']]);
    }

    lphHistory($pdo, 'stakeholder', $sid, 'Email Update', $oldEmail, $email,
        "Updated email address for {$stk['full_name']} from '{$oldEmail}' to '{$email}'.");

    logActivity(currentUserId(), 'Update Stakeholder Email', "Changed email for stakeholder #{$sid} ({$stk['full_name']}) to {$email}.");

    $pdo->commit();
    jsonResponse(true, "Successfully updated email address for {$stk['full_name']} to {$email}!", [
        'stakeholder_id' => $sid,
        'email'          => $email,
        'full_name'      => $stk['full_name']
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[ajax_update_email] ' . $e->getMessage());
    jsonResponse(false, APP_DEBUG ? $e->getMessage() : 'Unable to update stakeholder email.');
}
