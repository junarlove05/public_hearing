<?php
/**
 * auth/process_change_password.php
 * ------------------------------------------------------------------
 * Handles the "Change Password" form submitted from pages/profile.php.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/pages/profile.php');
}

requireCsrf();

$currentPassword = (string)($_POST['current_password'] ?? '');
$newPassword     = (string)($_POST['new_password'] ?? '');
$confirmPassword = (string)($_POST['confirm_password'] ?? '');

if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    setFlash('danger', 'All fields are required.');
    redirect(APP_URL . '/pages/profile.php');
}

if (strlen($newPassword) < 8) {
    setFlash('danger', 'New password must be at least 8 characters long.');
    redirect(APP_URL . '/pages/profile.php');
}

if ($newPassword !== $confirmPassword) {
    setFlash('danger', 'New password and confirmation do not match.');
    redirect(APP_URL . '/pages/profile.php');
}

try {
    $stmt = db()->prepare('SELECT password FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => currentUserId()]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($currentPassword, $user['password'])) {
        setFlash('danger', 'Current password is incorrect.');
        redirect(APP_URL . '/pages/profile.php');
    }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $update = db()->prepare('UPDATE users SET password = :password, password_changed_at = NOW() WHERE id = :id');
    $update->execute([':password' => $hash, ':id' => currentUserId()]);

    logActivity(currentUserId(), 'Update', 'User changed their password (3-week rotation timer reset).');
    setFlash('success', 'Password updated successfully! Your 3-week rotation countdown has been reset.');
    redirect(APP_URL . '/pages/profile.php');

} catch (PDOException $e) {
    error_log('Change password error: ' . $e->getMessage());
    setFlash('danger', 'A system error occurred. Please try again later.');
    redirect(APP_URL . '/pages/profile.php');
}
