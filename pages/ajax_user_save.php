<?php
/**
 * pages/ajax_user_save.php
 * ------------------------------------------------------------------
 * Creates or updates a user (id=0 means create). Password is required
 * on creation and optional on update (leave blank to keep the current
 * password). Passwords are always hashed with password_hash().
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_ADMIN]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id       = (int)($_POST['id'] ?? 0);
$fullName = clean($_POST['full_name'] ?? '');
$email    = clean($_POST['email'] ?? '');
$roleId   = (int)($_POST['role_id'] ?? 0);
$status   = clean($_POST['status'] ?? 'Active');
$password = (string)($_POST['password'] ?? '');

$errors = [];
if ($fullName === '') $errors[] = 'Full name is required.';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
if ($roleId <= 0) $errors[] = 'Please select a role.';
if ($id === 0 && $password === '') $errors[] = 'Password is required for new users.';
if ($password !== '' && strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
if (!empty($errors)) jsonResponse(false, implode(' ', $errors));

$pdo = db();
try {
    $dupStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id != :id');
    $dupStmt->execute([':email' => $email, ':id' => $id]);
    if ($dupStmt->fetch()) jsonResponse(false, 'A user with this email address already exists.');

    if ($id > 0) {
        if ($id === currentUserId() && $status !== 'Active') {
            jsonResponse(false, 'You cannot deactivate your own account.');
        }

        if ($password !== '') {
            $stmt = $pdo->prepare(
                'UPDATE users SET full_name = :name, email = :email, role_id = :role, status = :status, password = :password WHERE id = :id'
            );
            $stmt->execute([
                ':name' => $fullName, ':email' => $email, ':role' => $roleId, ':status' => $status,
                ':password' => password_hash($password, PASSWORD_DEFAULT), ':id' => $id,
            ]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE users SET full_name = :name, email = :email, role_id = :role, status = :status WHERE id = :id'
            );
            $stmt->execute([':name' => $fullName, ':email' => $email, ':role' => $roleId, ':status' => $status, ':id' => $id]);
        }

        logActivity(currentUserId(), 'Update', 'Updated user #' . $id . ' (' . $fullName . ')');
        jsonResponse(true, 'User updated successfully.', ['id' => $id]);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO users (full_name, email, password, role_id, status, created_at) VALUES (:name, :email, :password, :role, :status, NOW())'
    );
    $stmt->execute([
        ':name' => $fullName, ':email' => $email, ':password' => password_hash($password, PASSWORD_DEFAULT),
        ':role' => $roleId, ':status' => $status,
    ]);
    $id = (int)$pdo->lastInsertId();

    logActivity(currentUserId(), 'Insert', 'Created user #' . $id . ' (' . $fullName . ')');
    jsonResponse(true, 'User created successfully.', ['id' => $id]);

} catch (PDOException $e) {
    error_log('User save error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while saving the user.');
}
