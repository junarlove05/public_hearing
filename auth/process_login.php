<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/login.php');
}

requireCsrf();

$email = strtolower(clean($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($email === '' || $password === '') {
    setFlash('danger', 'Please enter both email and password.');
    redirect(APP_URL . '/login.php');
}

$rate = lphLoginRateLimitStatus($email);

if ($rate['blocked']) {
    $minutes = max(
        1,
        (int)ceil($rate['retry_after_seconds'] / 60)
    );

    logActivity(
        null,
        'Login Rate Limited',
        'A login attempt was temporarily blocked by the authentication rate limit.'
    );

    setFlash(
        'danger',
        "Too many unsuccessful sign-in attempts. Please wait about {$minutes} minute(s) before trying again."
    );
    redirect(APP_URL . '/login.php');
}

try {
    $stmt = db()->prepare(
        'SELECT
            u.id,
            u.full_name,
            u.email,
            u.password,
            u.status,
            u.role_id,
            u.deleted_at,
            r.name AS role_name
         FROM users u
         INNER JOIN roles r ON r.id=u.role_id
         WHERE LOWER(u.email)=LOWER(:email)
           AND u.deleted_at IS NULL
         LIMIT 1'
    );

    $stmt->execute([':email'=>$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        lphRecordLoginAttempt($email, false);

        logActivity(
            null,
            'Login Failed',
            'Invalid credentials were supplied for a Public Hearing subsystem sign-in.'
        );

        setFlash('danger', 'Invalid email or password.');
        redirect(APP_URL . '/login.php');
    }

    if (strcasecmp((string)$user['status'], 'Active') !== 0) {
        lphRecordLoginAttempt($email, false);
        setFlash(
            'danger',
            'Your account is currently inactive. Please contact the administrator.'
        );
        redirect(APP_URL . '/login.php');
    }

    if (!lphUserHasSystemAccess((int)$user['id'])) {
        lphRecordLoginAttempt($email, false);

        logActivity(
            (int)$user['id'],
            'Access Denied',
            'User authenticated but has inactive Public Hearing subsystem access.'
        );

        setFlash(
            'danger',
            'Your account does not currently have access to the Public Hearing subsystem.'
        );
        redirect(APP_URL . '/login.php');
    }

    session_regenerate_id(true);

    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['role_id'] = (int)$user['role_id'];
    $_SESSION['role_name'] = $user['role_name'];
    $_SESSION['last_activity'] = time();

    // Rotate the CSRF secret when privilege/session state changes.
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    db()->prepare(
        'UPDATE users
         SET last_login_at=NOW(), updated_at=NOW()
         WHERE id=:id'
    )->execute([':id'=>$user['id']]);

    if (!empty($_POST['remember'])) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            session_id(),
            [
                'expires'=>time() + (30 * 24 * 60 * 60),
                'path'=>$params['path'],
                'domain'=>$params['domain'],
                'secure'=>$params['secure'],
                'httponly'=>true,
                'samesite'=>'Lax',
            ]
        );
    }

    lphRecordLoginAttempt($email, true);

    logActivity(
        (int)$user['id'],
        'Login',
        'User logged in successfully to the Public Hearing subsystem.'
    );

    setFlash(
        'success',
        'Welcome back, '.$user['full_name'].'!'
    );

    redirect(APP_URL . '/dashboard.php');
} catch (Throwable $e) {
    error_log('Login error: '.$e->getMessage());
    setFlash(
        'danger',
        APP_DEBUG
            ? 'Login error: '.$e->getMessage()
            : 'A system error occurred. Please try again later.'
    );
    redirect(APP_URL . '/login.php');
}
