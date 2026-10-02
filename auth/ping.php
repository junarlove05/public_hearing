<?php
declare(strict_types=1);

/**
 * auth/ping.php
 * ------------------------------------------------------------------
 * Lightweight heartbeat / keep-alive endpoint for active user sessions.
 * Touching auth.php automatically refreshes $_SESSION['last_activity'].
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    jsonResponse(false, 'Not logged in.', ['session_expired' => true]);
}

jsonResponse(true, 'Session active.', [
    'logged_in' => true,
    'last_activity' => $_SESSION['last_activity'] ?? time(),
    'timeout_seconds' => defined('INACTIVITY_TIMEOUT') ? INACTIVITY_TIMEOUT : (defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 28800)
]);
