<?php
/**
 * pages/ajax_user_get.php
 * ------------------------------------------------------------------
 * Returns a single user record (excluding the password hash) as JSON
 * for the edit modal.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_ADMIN]);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) jsonResponse(false, 'Invalid user id.');

$stmt = db()->prepare('SELECT id, full_name, email, role_id, status FROM users WHERE id = :id');
$stmt->execute([':id' => $id]);
$user = $stmt->fetch();

if (!$user) jsonResponse(false, 'User not found.');

jsonResponse(true, '', ['user' => $user]);
