<?php
/**
 * logout.php (project root)
 * ------------------------------------------------------------------
 * Destroys the session and logs the Logout activity.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    logActivity(currentUserId(), 'Logout', 'User logged out.');
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

session_destroy();

redirect(APP_URL . '/login.php');
