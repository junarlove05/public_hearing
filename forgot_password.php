<?php
/**
 * forgot_password.php (project root)
 * ------------------------------------------------------------------
 * "Forgot Password" is listed as optional in the spec. This system
 * has no SMTP/mail service configured (no credentials were provided),
 * so rather than fake an email flow, this page:
 *   1. Accepts an email address,
 *   2. Always shows the same generic confirmation message regardless
 *      of whether the account exists (standard practice — prevents
 *      account enumeration),
 *   3. Logs the request to activity_logs so an Administrator can see
 *      it and manually reset the account's password via SQL/DB tools
 *      (see README "Setup" section for the password_hash() snippet).
 * To wire up real self-service email resets, add a reset-token column
 * to `users` and integrate an SMTP library — both out of scope here
 * since the schema is fixed and no mail credentials were supplied.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    redirect(APP_URL . '/dashboard.php');
}

$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $email = clean($_POST['email'] ?? '');

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = db()->prepare('SELECT id, full_name FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        // Log regardless of whether the account exists (without revealing that fact to the visitor).
        logActivity(
            $user['id'] ?? null,
            'Password Reset Request',
            'Password reset requested for email: ' . $email . ($user ? '' : ' (no matching account)')
        );
    }
    $submitted = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password | <?= e(APP_NAME) ?></title>
<link href="<?= e(vendorAsset('bootstrap/bootstrap.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css')) ?>" rel="stylesheet">
<link href="<?= e(vendorAsset('bootstrap-icons/bootstrap-icons.css', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css')) ?>" rel="stylesheet">
<link href="assets/css/style.css" rel="stylesheet">
<style>
    /* ============================================================
       FORGOT PASSWORD - Ocean Green Theme
       Colors: Ocean Green, Teal, White, Gold Accents
       ============================================================ */
    :root {
        --og-dark: #0A2E2A;
        --og-primary: #0F4A44;
        --og-medium: #1A6B63;
        --og-soft: #2A8F85;
        --og-light: #4AB5AA;
        --og-lighter: #7ACDC4;
        --og-pale: #D4F0EC;
        --og-white: #FFFFFF;
        --og-gold: #a97900;
        --og-gold-light: #F7D95A;
        --og-shadow: 0 8px 40px rgba(10, 46, 42, 0.25);
    }

    .auth-body {
        background: linear-gradient(135deg, #0A2E2A 0%, #0F4A44 40%, #1A6B63 100%);
        min-height: 100vh;
        padding: 1rem;
        position: relative;
        overflow: hidden;
    }

    /* Background decorative elements */
    .auth-body::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -20%;
        width: 600px;
        height: 600px;
        background: radial-gradient(circle, rgba(74, 181, 170, 0.08) 0%, transparent 70%);
        border-radius: 50%;
        pointer-events: none;
    }

    .auth-body::after {
        content: '';
        position: absolute;
        bottom: -30%;
        left: -10%;
        width: 400px;
        height: 400px;
        background: radial-gradient(circle, rgba(245, 200, 66, 0.05) 0%, transparent 70%);
        border-radius: 50%;
        pointer-events: none;
    }

    .auth-body .container {
        position: relative;
        z-index: 1;
    }

    /* Brand Header */
    .auth-body .text-center {
        color: var(--og-white);
        text-shadow: 0 2px 20px rgba(0, 0, 0, 0.15);
    }

    .auth-body .text-center .bi-bank2 {
        color: var(--og-gold);
        font-size: 3.5rem;
        display: block;
        margin-bottom: 0.5rem;
        filter: drop-shadow(0 4px 12px rgba(245, 200, 66, 0.2));
    }

    .auth-body .text-center h4 {
        font-weight: 700;
        font-size: 1.3rem;
        letter-spacing: 0.5px;
        color: var(--og-white);
        margin-bottom: 0.25rem;
    }

    .auth-body .text-center small {
        color: rgba(255, 255, 255, 0.6);
        font-size: 0.75rem;
        letter-spacing: 0.3px;
    }

    /* Card */
    .auth-card {
        border: none;
        border-radius: 20px;
        box-shadow: var(--og-shadow);
        overflow: hidden;
        backdrop-filter: blur(10px);
        background: rgba(255, 255, 255, 0.95);
        border: 1px solid rgba(255, 255, 255, 0.1);
        transition: all 0.3s ease;
    }

    .auth-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 50px rgba(10, 46, 42, 0.3);
    }

    .auth-card .card-body {
        padding: 2.5rem 2.5rem;
        background: transparent;
    }

    /* Card Title */
    .auth-card .card-title {
        color: var(--og-dark);
        font-weight: 700;
        font-size: 1.25rem;
        margin-bottom: 1.5rem;
        padding-bottom: 0.75rem;
        border-bottom: 3px solid var(--og-gold);
        display: inline-block;
    }

    .auth-card .card-title i {
        color: var(--og-gold);
        margin-right: 0.5rem;
        background: rgba(245, 200, 66, 0.1);
        padding: 0.4rem 0.5rem;
        border-radius: 10px;
    }

    /* Form Controls */
    .auth-card .form-control {
        border: 2px solid #E2E8F0;
        border-radius: 12px;
        padding: 0.7rem 1rem;
        font-size: 0.9rem;
        transition: all 0.3s ease;
        background: #F8FAFC;
        color: var(--og-dark);
        font-weight: 500;
    }

    .auth-card .form-control:focus {
        border-color: var(--og-light);
        box-shadow: 0 0 0 4px rgba(74, 181, 170, 0.15);
        background: var(--og-white);
    }

    .auth-card .form-control::placeholder {
        color: #94A3B8;
        font-weight: 400;
    }

    .auth-card .form-label {
        font-weight: 600;
        color: var(--og-dark);
        font-size: 0.85rem;
        margin-bottom: 0.4rem;
    }

    /* Buttons */
    .auth-card .btn-primary {
        background: linear-gradient(135deg, var(--og-dark) 0%, var(--og-primary) 100%);
        border: none;
        color: var(--og-white);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        font-weight: 600;
        padding: 0.7rem 1.5rem;
        border-radius: 12px;
        box-shadow: 0 4px 15px rgba(10, 46, 42, 0.2);
        position: relative;
        overflow: hidden;
        border: 1px solid rgba(245, 200, 66, 0.15);
    }

    .auth-card .btn-primary::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(245, 200, 66, 0.1), transparent);
        transition: left 0.5s ease;
    }

    .auth-card .btn-primary:hover::before {
        left: 100%;
    }

    .auth-card .btn-primary:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 25px rgba(10, 46, 42, 0.3);
        color: var(--og-white);
        border-color: var(--og-gold);
    }

    .auth-card .btn-primary:active {
        transform: translateY(0);
    }

    .auth-card .btn-primary i {
        color: var(--og-gold);
        margin-right: 0.5rem;
    }

    /* Success message */
    .auth-card .text-center .bi-envelope-check {
        color: var(--og-medium);
        font-size: 4rem;
        display: block;
        margin-bottom: 0.5rem;
    }

    .auth-card .text-center .text-success {
        color: var(--og-medium) !important;
        font-weight: 600;
        font-size: 1.1rem;
    }

    /* Links */
    .auth-card a.small {
        color: var(--og-light);
        font-weight: 500;
        transition: all 0.3s ease;
        text-decoration: none;
    }

    .auth-card a.small:hover {
        color: var(--og-dark);
        text-decoration: underline;
    }

    .auth-card a.small i {
        color: var(--og-gold);
        transition: transform 0.3s ease;
    }

    .auth-card a.small:hover i {
        transform: translateX(-4px);
    }

    /* Text muted */
    .auth-card .text-muted {
        color: #64748B !important;
        font-size: 0.85rem;
        line-height: 1.5;
    }

    .auth-card .text-muted.small {
        font-size: 0.8rem;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .auth-body .text-center .bi-bank2 {
            font-size: 2.8rem;
        }

        .auth-body .text-center h4 {
            font-size: 1.1rem;
        }

        .auth-card .card-body {
            padding: 1.75rem 1.5rem;
        }

        .auth-card .card-title {
            font-size: 1.1rem;
        }
    }

    @media (max-width: 576px) {
        .auth-body {
            padding: 0.5rem;
        }

        .auth-card .card-body {
            padding: 1.25rem 1rem;
        }

        .auth-card .btn-primary {
            font-size: 0.9rem;
            padding: 0.6rem 1rem;
        }

        .auth-card .form-control {
            font-size: 0.8rem;
            padding: 0.5rem 0.8rem;
        }
    }
</style>
</head>
<body class="auth-body d-flex align-items-center justify-content-center min-vh-100">
<div class="container">
  <div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
      <div class="text-center mb-4 text-white">
        <i class="bi bi-bank2 display-4"></i>
        <h4 class="mt-2 mb-0">Legislative Public Hearing</h4>
        <small>&amp; Consultation Management System</small>
      </div>
      <div class="card shadow-lg border-0 auth-card">
        <div class="card-body p-4 p-md-5">
          <h5 class="card-title mb-4 fw-bold"><i class="bi bi-key"></i> Forgot Password</h5>

          <?php if ($submitted): ?>
            <div class="text-center py-3">
              <i class="bi bi-envelope-check display-5"></i>
              <p class="mt-3 mb-1 text-success">If an account exists for that email, your request has been recorded.</p>
              <p class="text-muted small">Please contact your system administrator, who can verify your identity and reset your password.</p>
            </div>
          <?php else: ?>
            <p class="text-muted small">Enter your account email. Your request will be logged for an administrator to follow up, since this deployment has no automated email delivery configured.</p>
            <form method="POST" novalidate>
              <?= csrfField() ?>
              <div class="mb-3">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-control" required autofocus placeholder="name@example.com">
              </div>
              <button type="submit" class="btn btn-primary w-100"><i class="bi bi-send"></i> Request Password Reset</button>
            </form>
          <?php endif; ?>

          <div class="text-center mt-3">
            <a href="login.php" class="small"><i class="bi bi-arrow-left"></i> Back to Login</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>