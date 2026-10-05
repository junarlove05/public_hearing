<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';

requireLogin();
if (!canManage()) jsonResponse(false, 'You do not have permission to manage stakeholders.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$pdo = db();
$id = (int)($_POST['id'] ?? 0);
$fullName = clean($_POST['full_name'] ?? '');
$email = strtolower(clean($_POST['email'] ?? ''));
$phone = clean($_POST['phone'] ?? '');
$organization = clean($_POST['organization'] ?? '');
$categoryId = (int)($_POST['category_id'] ?? 0) ?: null;
$address = trim((string)($_POST['address'] ?? ''));
$sector = clean($_POST['sector'] ?? '');
$status = clean($_POST['status'] ?? 'Pending');

$allowedStatuses = ['Pending', 'Verified', 'Inactive', 'Rejected', 'Active', 'Approved'];

$errors = [];
if ($fullName === '') $errors[] = 'Full name is required.';
$isUnlistedEmail = in_array(strtolower($email), ['not publicly listed', 'unlisted', 'n/a', 'none'], true);
if ($email === '' || (!$isUnlistedEmail && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
    $errors[] = 'A valid email address (or "Not publicly listed") is required.';
}
if (!in_array($status, $allowedStatuses, true)) $errors[] = 'Invalid stakeholder status.';

if ($categoryId) {
    $check = $pdo->prepare('SELECT COUNT(*) FROM stakeholder_categories WHERE id=:id');
    $check->execute([':id'=>$categoryId]);
    if ((int)$check->fetchColumn()===0) $errors[]='Selected stakeholder category does not exist.';
}

// Email validation: allow any valid email or unlisted
// We do NOT block updating emails so admins can freely change stakeholder Gmails.
$existing = null;
if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM stakeholders WHERE id=:id');
    $stmt->execute([':id'=>$id]);
    $existing = $stmt->fetch();
    if (!$existing) $errors[]='Stakeholder not found.';
}

if ($errors) jsonResponse(false, implode(' ', $errors));

try {
    $pdo->beginTransaction();

    $verifiedAt = null;
    $verifiedBy = null;

    if (in_array($status, ['Verified', 'Approved', 'Active'], true)) {
        $verifiedAt = !empty($existing['verified_at']) ? $existing['verified_at'] : date('Y-m-d H:i:s');
        $verifiedBy = !empty($existing['verified_by']) ? $existing['verified_by'] : currentUserId();
    }

    if ($id > 0) {
        $oldStatus = $existing['status'];

        $stmt = $pdo->prepare(
            'UPDATE stakeholders
             SET full_name=:full_name,email=:email,phone=:phone,organization=:organization,
                 category_id=:category_id,address=:address,sector=:sector,status=:status,
                 verified_at=:verified_at,verified_by=:verified_by,updated_at=NOW()
             WHERE id=:id'
        );
        $stmt->execute([
            ':full_name'=>$fullName, ':email'=>$email, ':phone'=>$phone ?: null,
            ':organization'=>$organization ?: null, ':category_id'=>$categoryId,
            ':address'=>$address ?: null, ':sector'=>$sector ?: null, ':status'=>$status,
            ':verified_at'=>$verifiedAt, ':verified_by'=>$verifiedBy, ':id'=>$id,
        ]);

        if (!empty($existing['user_id'])) {
            $pdo->prepare('UPDATE users SET email = :email, updated_at = NOW() WHERE id = :uid')
                ->execute([':email' => $email, ':uid' => (int)$existing['user_id']]);
        }

        $isVerified = in_array($status, ['Verified', 'Approved', 'Active'], true);
        $code = null;

        if ($isVerified) {
            // Ensure verified stakeholder has an active QR code
            $qrCheck = $pdo->prepare('SELECT code_value FROM qr_codes WHERE stakeholder_id = :sid LIMIT 1');
            $qrCheck->execute([':sid' => $id]);
            $code = $qrCheck->fetchColumn();
            if (!$code) {
                $code = 'STK-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                $pdo->prepare('INSERT INTO qr_codes (stakeholder_id, code_value, created_at) VALUES (:sid, :code, NOW())')
                    ->execute([':sid' => $id, ':code' => $code]);
            }
        } else {
            // Unverified: Remove any existing QR code
            $pdo->prepare('DELETE FROM qr_codes WHERE stakeholder_id = :sid')->execute([':sid' => $id]);
        }

        lphHistory(
            $pdo, 'stakeholder', $id,
            $oldStatus !== $status ? 'Status Change' : 'Update',
            null, null,
            "Updated stakeholder {$fullName}. Status: {$oldStatus} -> {$status}."
        );

        logActivity(currentUserId(), 'Update Stakeholder', "Updated stakeholder {$fullName} ({$email}).");
        $message = $isVerified ? 'Stakeholder updated successfully. Attendance QR code is active.' : 'Stakeholder updated successfully. Status is Pending (QR code is issued upon verification).';
    } else {
        $defaultPassword = password_hash('Stakeholder@2026', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare(
            'INSERT INTO stakeholders
             (user_id,full_name,email,password,phone,organization,category_id,status,address,sector,
              verified_at,verified_by,created_at,updated_at)
             VALUES
             (NULL,:full_name,:email,:password,:phone,:organization,:category_id,:status,:address,:sector,
              :verified_at,:verified_by,NOW(),NOW())'
        );
        $stmt->execute([
            ':full_name'=>$fullName, ':email'=>$email, ':password'=>$defaultPassword, ':phone'=>$phone ?: null,
            ':organization'=>$organization ?: null, ':category_id'=>$categoryId,
            ':status'=>$status, ':address'=>$address ?: null, ':sector'=>$sector ?: null,
            ':verified_at'=>$verifiedAt, ':verified_by'=>$verifiedBy,
        ]);
        $id = (int)$pdo->lastInsertId();

        $isVerified = in_array($status, ['Verified', 'Approved', 'Active'], true);
        $code = null;

        // Auto-generate QR identification code ONLY IF account is Verified
        if ($isVerified) {
            $code = 'STK-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            $qrStmt = $pdo->prepare('INSERT INTO qr_codes (stakeholder_id, code_value, created_at) VALUES (:sid, :code, NOW())');
            $qrStmt->execute([':sid' => $id, ':code' => $code]);
            $message = 'Stakeholder created successfully. Attendance QR pass generated.';
        } else {
            $message = 'Stakeholder registered successfully. Note: Attendance QR pass will be generated once account is Verified.';
        }

        lphHistory($pdo, 'stakeholder', $id, 'Create', null, null, "Created stakeholder {$fullName}.");
        logActivity(currentUserId(), 'Create Stakeholder', "Created stakeholder {$fullName} ({$email}).");
    }

    $pdo->commit();
    jsonResponse(true, $message, [
        'id'          => $id,
        'full_name'   => $fullName,
        'email'       => $email,
        'phone'       => $phone,
        'organization'=> $organization,
        'sector'      => $sector,
        'status'      => $status,
        'category_id' => $categoryId,
        'is_verified' => $isVerified,
        'code_value'  => $code,
        'qr_url'      => ($isVerified && $code) ? (APP_URL . '/modules/stakeholders/qr.php?id=' . $id) : null
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Stakeholder save error: '.$e->getMessage());
    jsonResponse(false, APP_DEBUG ? $e->getMessage() : 'Unable to save stakeholder.');
}
