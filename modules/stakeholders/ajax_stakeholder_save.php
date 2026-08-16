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

$allowedStatuses = ['Pending','Verified','Inactive','Rejected'];

$errors = [];
if ($fullName === '') $errors[] = 'Full name is required.';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
if (!in_array($status, $allowedStatuses, true)) $errors[] = 'Invalid stakeholder status.';

if ($categoryId) {
    $check = $pdo->prepare('SELECT COUNT(*) FROM stakeholder_categories WHERE id=:id');
    $check->execute([':id'=>$categoryId]);
    if ((int)$check->fetchColumn()===0) $errors[]='Selected stakeholder category does not exist.';
}

$dup = $pdo->prepare('SELECT id FROM stakeholders WHERE LOWER(email)=LOWER(:email) AND id<>:id LIMIT 1');
$dup->execute([':email'=>$email, ':id'=>$id]);
if ($dup->fetch()) $errors[]='Another stakeholder already uses this email address.';

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

    if ($status === 'Verified') {
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

        lphHistory(
            $pdo, 'stakeholder', $id,
            $oldStatus !== $status ? 'Status Change' : 'Update',
            null, null,
            "Updated stakeholder {$fullName}. Status: {$oldStatus} -> {$status}."
        );

        logActivity(currentUserId(), 'Update Stakeholder', "Updated stakeholder {$fullName} ({$email}).");
        $message = 'Stakeholder updated successfully.';
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO stakeholders
             (user_id,full_name,email,phone,organization,category_id,status,address,sector,
              verified_at,verified_by,created_at,updated_at)
             VALUES
             (NULL,:full_name,:email,:phone,:organization,:category_id,:status,:address,:sector,
              :verified_at,:verified_by,NOW(),NOW())'
        );
        $stmt->execute([
            ':full_name'=>$fullName, ':email'=>$email, ':phone'=>$phone ?: null,
            ':organization'=>$organization ?: null, ':category_id'=>$categoryId,
            ':status'=>$status, ':address'=>$address ?: null, ':sector'=>$sector ?: null,
            ':verified_at'=>$verifiedAt, ':verified_by'=>$verifiedBy,
        ]);
        $id = (int)$pdo->lastInsertId();

        lphHistory($pdo, 'stakeholder', $id, 'Create', null, null, "Created stakeholder {$fullName}.");
        logActivity(currentUserId(), 'Create Stakeholder', "Created stakeholder {$fullName} ({$email}).");
        $message = 'Stakeholder created successfully.';
    }

    $pdo->commit();
    jsonResponse(true, $message, ['id'=>$id]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Stakeholder save error: '.$e->getMessage());
    jsonResponse(false, APP_DEBUG ? $e->getMessage() : 'Unable to save stakeholder.');
}
