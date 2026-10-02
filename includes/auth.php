<?php
/**
 * includes/auth.php
 * ------------------------------------------------------------------
 * Session bootstrap, login-state helpers, and Role-Based Access
 * Control (RBAC) guards. Include this file (it starts the session)
 * at the very top of every protected page.
 * ------------------------------------------------------------------
 */

// FIX (network-error root cause #1): start an output buffer before anything
// else runs. This guarantees jsonResponse() in functions.php can always
// safely discard stray output (PHP warnings/notices/whitespace) right
// before sending JSON, and it also prevents "headers already sent" errors
// if any file accidentally emits whitespace before a header() call.
if (ob_get_level() === 0) {
    ob_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/activity_log.php';

/**
 * FIX (network-error root cause #4): guarantee that even a totally
 * unexpected PHP error (a bug, a bad query, a missing null-check — not
 * just the CSRF/session cases already handled above) still produces valid
 * JSON for AJAX requests instead of a raw HTML/text error dump that would
 * break response.json() on the client and show as "A network error
 * occurred." This does not change behavior for normal page loads, only
 * for requests sent with the X-Requested-With: XMLHttpRequest header that
 * every fetch()/AJAX call in this app already sends.
 */
set_exception_handler(function (Throwable $e): void {
    error_log('Uncaught exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (isAjaxRequest()) {
        http_response_code(500);
        jsonResponse(false, APP_DEBUG
            ? 'Server error: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'
            : 'A server error occurred while processing your request. Please try again or contact your administrator.'
        );
    }
    http_response_code(500);
    if (APP_DEBUG) {
        echo '<h1>Server Error</h1><pre>' . htmlspecialchars($e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>';
    } else {
        echo '<h1>A server error occurred</h1><p>Please try again or contact your administrator.</p>';
    }
});

register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    error_log('Fatal error: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
    if (!headers_sent() && function_exists('isAjaxRequest') && isAjaxRequest()) {
        http_response_code(500);
        jsonResponse(false, APP_DEBUG
            ? 'Server error: ' . $error['message'] . ' (' . basename($error['file']) . ':' . $error['line'] . ')'
            : 'A server error occurred while processing your request. Please try again or contact your administrator.'
        );
    }
});

/* ---- Secure session bootstrap ------------------------------------- */
if (session_status() === PHP_SESSION_NONE) {
    $isHttps =
        (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $isHttps,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
}

/* ---- Idle timeout --------------------------------------------------- */
// FIX (network-error root cause #2 — the main one): previously this always
// called redirect() (a raw HTTP 302 to login.php) when a session expired.
// For a normal page load that's correct. But for an AJAX/fetch() request,
// the browser follows that redirect and hands the resulting login.php HTML
// page to response.json() — which throws a SyntaxError because HTML isn't
// valid JSON. That thrown error lands in the calling code's .catch() block,
// which is exactly why forms intermittently showed "A network error
// occurred." instead of a real error: the request actually succeeded in
// reaching the server, but the *expired session* response wasn't JSON.
/* ---- Idle timeout (aligned with standard 8-hour session lifetime) -- */
if (isset($_SESSION['user_id'])) {
    $idleTimeout = defined('INACTIVITY_TIMEOUT') ? INACTIVITY_TIMEOUT : (defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 28800);
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $idleTimeout) {
        $timedOutUser = (int)($_SESSION['user_id'] ?? 0);
        if ($timedOutUser > 0) {
            logActivity($timedOutUser, 'Auto Logout', 'User session expired after inactivity.');
        }
        session_unset();
        session_destroy();
        if (isAjaxRequest()) {
            jsonResponse(false, 'Your session has expired. Please log in again.', ['session_expired' => true]);
        }
        redirect(APP_URL . '/login.php?timeout=1');
    }
    $_SESSION['last_activity'] = time();
}

/* =========================================================
 * BASIC AUTH STATE
 * ========================================================= */

function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}

function currentUser(): ?array
{
    if (!isLoggedIn()) return null;
    return [
        'id'        => $_SESSION['user_id'],
        'full_name' => $_SESSION['full_name'] ?? '',
        'email'     => $_SESSION['email'] ?? '',
        'role_id'   => $_SESSION['role_id'] ?? null,
        'role_name' => $_SESSION['role_name'] ?? '',
    ];
}

function currentUserId(): ?int
{
    return $_SESSION['user_id'] ?? null;
}

function currentRole(): ?string
{
    return $_SESSION['role_name'] ?? null;
}

/**
 * Redirect to login if not authenticated. Call at the top of protected
 * pages AND at the top of every AJAX endpoint. For AJAX requests, responds
 * with JSON instead of an HTML redirect (see the idle-timeout fix above for
 * why this matters — the same "HTML instead of JSON" problem applies here).
 */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        if (isAjaxRequest()) {
            jsonResponse(false, 'You must be logged in to do that. Please log in and try again.', ['session_expired' => true]);
        }
        setFlash('warning', 'Please log in to continue.');
        redirect(APP_URL . '/login.php');
    }

    if (
        function_exists('lphUserHasSystemAccess')
        && !lphUserHasSystemAccess((int)currentUserId())
    ) {
        if (isAjaxRequest()) {
            jsonResponse(false, 'Your account does not currently have access to the Public Hearing subsystem.');
        }

        http_response_code(403);
        include __DIR__ . '/../pages/403.php';
        exit;
    }
}

/**
 * Restrict a page to one or more roles.
 * Usage: requireRole([ROLE_ADMIN, ROLE_STAFF]);
 * For AJAX requests, responds with JSON instead of rendering the HTML
 * 403 page, for the same reason requireLogin() does above.
 */
function requireRole(array $allowedRoles): void
{
    requireLogin();
    if (!in_array(currentRole(), $allowedRoles, true)) {
        http_response_code(403);
        if (isAjaxRequest()) {
            jsonResponse(false, 'You do not have permission to perform this action.');
        }
        include __DIR__ . '/../pages/403.php';
        exit;
    }
}

/** True if the current user's role is in the given list. */
function hasRole(array $roles): bool
{
    return isLoggedIn() && in_array(currentRole(), $roles, true);
}

/** True if current user is an Administrator. */
function isAdmin(): bool
{
    return currentRole() === ROLE_ADMIN;
}

/**
 * Roles allowed to manage (create/edit/delete) records in the internal
 * management modules. Public Users and Registered Stakeholders are
 * generally read-only / submission-only on the public side.
 */
function canManage(): bool
{
    if (function_exists('hasPermission')) {
        return hasPermission('lph.records.manage');
    }

    return hasRole([ROLE_ADMIN, ROLE_STAFF]);
}

require_once __DIR__ . '/lph_security.php';
