<?php
/**
 * modules/stakeholders/ajax_save.php
 * ------------------------------------------------------------------
 * Handles CREATE and UPDATE for the `stakeholders` table (id=0 means
 * create). On creation, also generates a unique QR identification
 * code and stores it in `qr_codes` (used later for attendance scanning).
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id           = (int)($_POST['id'] ?? 0);
$fullName     = clean($_POST['full_name'] ?? '');
$email        = clean($_POST['email'] ?? '');
$phone        = clean($_POST['phone'] ?? '');
$organization = clean($_POST['organization'] ?? '');
$categoryId   = (int)($_POST['category_id'] ?? 0) ?: null;
$status       = clean($_POST['status'] ?? 'Pending');

$allowedStatus = ['Pending', 'Approved', 'Rejected'];

$errors = [];
if ($fullName === '') $errors[] = 'Full name is required.';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
if (!in_array($status, $allowedStatus, true)) $errors[] = 'Invalid status value.';

if (!empty($errors)) jsonResponse(false, implode(' ', $errors));

$pdo = db();

try {
    // Uniqueness check on email (table has UNIQUE constraint, but we validate first for a friendly message).
    $dupStmt = $pdo->prepare('SELECT id FROM stakeholders WHERE email = :email AND id != :id');
    $dupStmt->execute([':email' => $email, ':id' => $id]);
    if ($dupStmt->fetch()) {
        jsonResponse(false, 'A stakeholder with this email address already exists.');
    }

    if ($id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE stakeholders SET full_name = :name, email = :email, phone = :phone,
             organization = :org, category_id = :cat, status = :status WHERE id = :id'
        );
        $stmt->execute([
            ':name' => $fullName, ':email' => $email, ':phone' => $phone,
            ':org' => $organization, ':cat' => $categoryId, ':status' => $status, ':id' => $id,
        ]);
        logActivity(currentUserId(), 'Update', 'Updated stakeholder #' . $id . ' (' . $fullName . ')');
        jsonResponse(true, 'Stakeholder updated successfully.', ['id' => $id]);
    }

    // ---- CREATE ----
    $stmt = $pdo->prepare(
        'INSERT INTO stakeholders (full_name, email, phone, organization, category_id, status, created_at)
         VALUES (:name, :email, :phone, :org, :cat, :status, NOW())'
    );
    $stmt->execute([
        ':name' => $fullName, ':email' => $email, ':phone' => $phone,
        ':org' => $organization, ':cat' => $categoryId, ':status' => $status,
    ]);
    $id = (int)$pdo->lastInsertId();

    // Auto-generate a unique QR identification code for this stakeholder.
    $code = generateCode('STK-');
    $qrStmt = $pdo->prepare('INSERT INTO qr_codes (stakeholder_id, code_value, created_at) VALUES (:sid, :code, NOW())');
    $qrStmt->execute([':sid' => $id, ':code' => $code]);

    logActivity(currentUserId(), 'Insert', 'Created stakeholder #' . $id . ' (' . $fullName . ')');
    jsonResponse(true, 'Stakeholder created successfully.', ['id' => $id]);

} catch (PDOException $e) {
    error_log('Stakeholder save error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while saving the stakeholder.');
}
