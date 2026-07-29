<?php
/**
 * auth/process_login.php
 * ------------------------------------------------------------------
 * Handles the login form POST from login.php.
 * Verifies credentials with password_verify() against the `users`
 * table (joined with `roles`), starts the session, and logs the
 * event to activity_logs.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/login.php');
}

requireCsrf();

$email    = clean($_POST['email'] ?? '');
$password = (string)($_POST['password'] ?? '');

if ($email === '' || $password === '') {
    setFlash('danger', 'Please enter both email and password.');
    redirect(APP_URL . '/login.php');
}

try {
    $stmt = db()->prepare(
        'SELECT u.id, u.full_name, u.email, u.password, u.status, u.role_id, r.name AS role_name
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         WHERE u.email = :email
         LIMIT 1'
    );
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        logActivity(null, 'Login Failed', 'Email: ' . $email);
        setFlash('danger', 'Invalid email or password.');
        redirect(APP_URL . '/login.php');
    }

    if (strcasecmp($user['status'], 'Active') !== 0) {
        setFlash('danger', 'Your account is currently inactive. Please contact the administrator.');
        redirect(APP_URL . '/login.php');
    }

    // ---- Regenerate session id on privilege change (session fixation protection) ----
    session_regenerate_id(true);

    $_SESSION['user_id']       = (int)$user['id'];
    $_SESSION['full_name']     = $user['full_name'];
    $_SESSION['email']         = $user['email'];
    $_SESSION['role_id']       = (int)$user['role_id'];
    $_SESSION['role_name']     = $user['role_name'];
    $_SESSION['last_activity'] = time();

    // ---- Remember Session: extend the cookie lifetime to 30 days when checked ----
    if (!empty($_POST['remember'])) {
        $params = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires'  => time() + (30 * 24 * 60 * 60),
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);
    }

    logActivity((int)$user['id'], 'Login', 'User logged in successfully.');
    setFlash('success', 'Welcome back, ' . $user['full_name'] . '!');
    redirect(APP_URL . '/dashboard.php');

} catch (PDOException $e) {
    error_log('Login error: ' . $e->getMessage());
    setFlash('danger', 'A system error occurred. Please try again later.');
    redirect(APP_URL . '/login.php');
}
