<?php
/**
 * login.php (project root)
 * ------------------------------------------------------------------
 * Public login page. Renders the login form and, on success,
 * hands off credential checking to auth/process_login.php.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/includes/auth.php';

// Already logged in? Go straight to the dashboard.
if (isLoggedIn()) {
    redirect(APP_URL . '/dashboard.php');
}

$flashMessages = getFlashMessages();
$adminPolicyStatus = lphGetAdminPasswordPolicyStatus();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login | <?= e(APP_NAME) ?></title>
<link rel="icon" type="image/png" href="assets/images/logo.png">
<link rel="shortcut icon" type="image/png" href="assets/images/logo.png">

<link href="<?= e(vendorAsset('bootstrap/bootstrap.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css')) ?>" rel="stylesheet">
<link href="<?= e(vendorAsset('bootstrap-icons/bootstrap-icons.css', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css')) ?>" rel="stylesheet">

<!-- Premium Google Fonts matching Landing Page (#subsystems) -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700;800;900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<link href="assets/css/style.css" rel="stylesheet">
<style>
    :root {
        --primary-blue: #0f2137;
        --primary-blue-dark: #071426;
        --primary-blue-light: #1a3a5c;
        --primary-yellow: #a97900;
        --primary-yellow-light: #8a6200;
        --primary-white: #FFFFFF;
        --primary-gray: #F3F4F6;
        --gold-primary: #D4AF37;
        --gold-light: #E5C07B;
        --font-serif: 'Cinzel', serif;
        --font-sans: 'Plus Jakarta Sans', sans-serif;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    html, body {
        height: 100%;
        overflow: hidden;
        font-family: var(--font-sans);
        line-height: 1.6;
    }

    .login-wrapper {
        display: flex;
        height: 100vh;
        width: 100%;
        background: var(--primary-white);
    }

    /* LEFT SIDE - Brand Section */
    .brand-side {
        flex: 1.1;
        background: linear-gradient(135deg, #071426 0%, #0f2137 55%, #1a3a5c 100%);
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        padding: 3rem;
        position: relative;
        overflow: hidden;
        min-height: 100vh;
    }

    /* Decorative elements on brand side */
    .brand-side::before {
        content: '';
        position: absolute;
        width: 500px;
        height: 500px;
        background: radial-gradient(circle, rgba(251, 191, 36, 0.15) 0%, transparent 70%);
        top: -150px;
        right: -150px;
        border-radius: 50%;
        animation: floatBg 12s ease-in-out infinite;
    }

    .brand-side::after {
        content: '';
        position: absolute;
        width: 400px;
        height: 400px;
        background: radial-gradient(circle, rgba(251, 191, 36, 0.1) 0%, transparent 70%);
        bottom: -100px;
        left: -100px;
        border-radius: 50%;
        animation: floatBg 15s ease-in-out infinite reverse;
    }

    @keyframes floatBg {
        0%, 100% { transform: translate(0, 0) scale(1); }
        50% { transform: translate(30px, -30px) scale(1.1); }
    }

    /* Decorative circles */
    .circle-decoration {
        position: absolute;
        border-radius: 50%;
        border: 2px solid rgba(251, 191, 36, 0.1);
        pointer-events: none;
    }

    .circle-1 {
        width: 300px;
        height: 300px;
        top: 10%;
        right: 5%;
        animation: pulse 8s ease-in-out infinite;
    }

    .circle-2 {
        width: 200px;
        height: 200px;
        bottom: 15%;
        left: 10%;
        animation: pulse 10s ease-in-out infinite reverse;
    }

    .circle-3 {
        width: 150px;
        height: 150px;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        animation: pulse 12s ease-in-out infinite;
    }

    @keyframes pulse {
        0%, 100% { transform: scale(1); opacity: 0.3; }
        50% { transform: scale(1.1); opacity: 0.6; }
    }

    .brand-content {
        position: relative;
        z-index: 2;
        text-align: center;
        max-width: 480px;
        color: var(--primary-white);
    }

    /* Logo Image Styling */
    .brand-logo {
        width: 220px;
        height: 220px;
        background: transparent !important;
        border: none !important;
        box-shadow: none !important;
        backdrop-filter: none !important;
        border-radius: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 1.5rem;
        padding: 0;
    }

    .brand-logo img {
        width: 220px !important;
        height: 220px !important;
        max-width: 220px !important;
        max-height: 220px !important;
        object-fit: contain;
        filter: drop-shadow(0 12px 30px rgba(0, 0, 0, 0.55));
    }

    .brand-eyebrow {
        font-family: var(--font-sans);
        font-size: 0.75rem;
        font-weight: 800;
        letter-spacing: 2.5px;
        text-transform: uppercase;
        color: var(--gold-light);
        margin-bottom: 0.5rem;
    }

    .brand-title {
        font-family: var(--font-serif);
        font-size: clamp(1.85rem, 2.3vw, 2.35rem);
        font-weight: 700;
        margin-bottom: 1.75rem;
        letter-spacing: 0.5px;
        line-height: 1.25;
        color: var(--primary-white);
        text-shadow: 0 2px 20px rgba(0, 0, 0, 0.1);
    }

    .brand-title .highlight {
        color: var(--gold-light);
        position: relative;
    }

    .brand-title .highlight::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: var(--gold-light);
        border-radius: 2px;
        opacity: 0.5;
    }

    .brand-description {
        font-family: var(--font-sans);
        font-size: 0.95rem;
        opacity: 0.9;
        line-height: 1.6;
        margin-bottom: 2rem;
        color: rgba(255, 255, 255, 0.9);
    }

    .brand-features {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        text-align: left;
        margin-top: 2rem;
    }

    .feature-item {
        background: rgba(255, 255, 255, 0.1);
        backdrop-filter: blur(10px);
        padding: 1rem;
        border-radius: 12px;
        border: 1px solid rgba(255, 255, 255, 0.1);
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .feature-item:hover {
        background: rgba(255, 255, 255, 0.2);
        transform: translateY(-2px);
    }

    .feature-item i {
        color: var(--gold-light);
        font-size: 1.25rem;
    }

    .feature-item span {
        font-family: var(--font-sans);
        font-size: 0.825rem;
        font-weight: 600;
        letter-spacing: 0.2px;
        color: var(--primary-white);
    }

    /* RIGHT SIDE - Login Section */
    .login-side {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 2rem;
        background: var(--primary-white);
        position: relative;
        min-height: 100vh;
    }

    /* Back to Subsystems Portal Button - Floating Top-Right */
    .back-portal-btn {
        position: absolute;
        top: 1.75rem;
        right: 2rem;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.52rem 1.1rem;
        background: #FFFFFF;
        border: 1px solid #E2E8F0;
        border-radius: 999px;
        color: #475569;
        font-family: var(--font-sans);
        font-size: 0.785rem;
        font-weight: 600;
        letter-spacing: 0.25px;
        text-decoration: none;
        transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04), 0 1px 2px rgba(0, 0, 0, 0.02);
        z-index: 20;
    }

    .back-portal-btn i {
        font-size: 0.875rem;
        color: #B89350;
        transition: transform 0.25s ease, color 0.25s ease;
    }

    .back-portal-btn:hover {
        background: #0F172A;
        border-color: #0F172A;
        color: #FFFFFF;
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.16);
        transform: translateY(-1px);
    }

    .back-portal-btn:hover i {
        color: #F59E0B;
        transform: translateX(-3px);
    }

    /* Yellow accent bar on right side */
    .login-side::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 6px;
        height: 100%;
        background: linear-gradient(180deg, var(--primary-yellow), var(--primary-yellow-light));
        box-shadow: 0 0 30px rgba(251, 191, 36, 0.3);
    }

    .login-container {
        width: 100%;
        max-width: 440px;
        padding: 0.5rem;
        position: relative;
        z-index: 1;
        animation: slideInRight 0.6s ease-out;
    }

    @keyframes slideInRight {
        from {
            opacity: 0;
            transform: translateX(30px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }

    .login-header {
        margin-bottom: 2rem;
    }

    .login-eyebrow {
        font-family: var(--font-sans);
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 2.5px;
        text-transform: uppercase;
        color: #B89350;
        margin-bottom: 0.35rem;
    }

    .login-greeting {
        font-family: var(--font-serif);
        font-size: 1.85rem;
        font-weight: 700;
        color: #0F172A;
        margin-bottom: 0.35rem;
        display: flex;
        align-items: center;
        gap: 0.65rem;
        line-height: 1.25;
    }

    .login-greeting i {
        color: var(--primary-yellow);
        font-size: 1.5rem;
    }

    .login-subtitle {
        font-family: var(--font-sans);
        color: #64748B;
        font-size: 0.9rem;
    }

    /* Custom Alerts */
    .alert-custom {
        font-family: var(--font-sans);
        border: none;
        border-radius: 12px;
        padding: 0.75rem 1rem;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-weight: 500;
        font-size: 0.875rem;
        border-left: 4px solid;
    }

    .alert-custom i {
        font-size: 1.25rem;
    }

    .alert-custom.alert-warning {
        background: #FFFBEB;
        color: #92400E;
        border-left-color: var(--primary-yellow);
    }

    .alert-custom.alert-danger {
        background: #FEF2F2;
        color: #991B1B;
        border-left-color: #EF4444;
    }

    .alert-custom.alert-success {
        background: #F0FDF4;
        color: #065F46;
        border-left-color: #10B981;
    }

    /* Form Fields */
    .form-group {
        margin-bottom: 1.25rem;
    }

    .form-label {
        font-family: var(--font-sans);
        font-weight: 650;
        color: #071426;
        font-size: 0.825rem;
        letter-spacing: 0.2px;
        margin-bottom: 0.5rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .form-label i {
        color: var(--primary-blue);
        font-size: 0.9rem;
    }

    .input-group-modern {
        position: relative;
    }

    .input-group-modern .input-icon {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: #9CA3AF;
        z-index: 10;
        font-size: 1rem;
        transition: color 0.3s ease;
        pointer-events: none;
    }

    .input-group-modern .form-control {
        font-family: var(--font-sans);
        padding: 0.75rem 1rem 0.75rem 3rem;
        border-radius: 12px;
        border: 2px solid #E5E7EB;
        background: #FAFAFA;
        height: 3.25rem;
        font-size: 0.95rem;
        transition: all 0.3s ease;
        color: #1F2937;
    }

    .input-group-modern .form-control:focus {
        border-color: #071426;
        background: #FFFFFF;
        box-shadow: 0 0 0 4px rgba(7, 20, 38, 0.12);
        outline: none;
    }

    .input-group-modern .form-control:focus ~ .input-icon {
        color: var(--primary-yellow);
    }

    .input-group-modern .form-control::placeholder {
        font-family: var(--font-sans);
        color: #9CA3AF;
        font-weight: 400;
    }

    /* Form Options */
    .form-options {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin: 1.5rem 0;
        flex-wrap: wrap;
        gap: 0.75rem;
    }

    .checkbox-custom {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        cursor: pointer;
        margin: 0;
    }

    .checkbox-custom input[type="checkbox"] {
        width: 18px;
        height: 18px;
        border-radius: 6px;
        border: 2px solid #D1D5DB;
        accent-color: #071426;
        cursor: pointer;
        margin: 0;
        transition: all 0.2s ease;
        flex-shrink: 0;
    }

    .checkbox-custom input[type="checkbox"]:checked {
        border-color: #071426;
        background-color: #071426;
    }

    .checkbox-custom .check-label {
        font-family: var(--font-sans);
        font-size: 0.85rem;
        color: #4B5563;
        font-weight: 500;
        cursor: pointer;
        user-select: none;
    }

    .forgot-link {
        font-family: var(--font-sans);
        color: #071426;
        text-decoration: none;
        font-size: 0.85rem;
        font-weight: 600;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
    }

    .forgot-link:hover {
        color: var(--primary-yellow);
        text-decoration: underline;
    }

    /* Login Button */
    .btn-login {
        font-family: var(--font-sans);
        background: linear-gradient(135deg, #071426 0%, #1a3a5c 100%);
        border: none;
        border-radius: 12px;
        padding: 0.85rem;
        font-weight: 700;
        font-size: 0.875rem;
        letter-spacing: 1.5px;
        text-transform: uppercase;
        color: white;
        height: 3.25rem;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.75rem;
        width: 100%;
        cursor: pointer;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
        box-shadow: 0 4px 15px rgba(7, 20, 38, 0.35);
    }

    .btn-login::after {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
        transition: left 0.6s ease;
    }

    .btn-login:hover::after {
        left: 100%;
    }

    .btn-login:hover {
        background: linear-gradient(135deg, #0f2137 0%, #b8860b 100%);
        transform: translateY(-2px);
        box-shadow: 0 8px 30px rgba(184, 134, 11, 0.4);
        color: white;
    }

    .btn-login:active {
        transform: translateY(0);
    }

    .btn-login i {
        font-size: 1.1rem;
        transition: transform 0.3s ease;
    }

    .btn-login:hover i {
        transform: translateX(4px);
    }

    /* Footer */
    .login-footer {
        font-family: var(--font-sans);
        text-align: center;
        margin-top: 1.5rem;
        padding-top: 1.5rem;
        border-top: 1px solid #F3F4F6;
        color: #9CA3AF;
        font-size: 0.775rem;
        letter-spacing: 0.2px;
    }

    .login-footer a {
        font-family: var(--font-sans);
        color: #071426;
        text-decoration: none;
        font-weight: 500;
    }

    .login-footer a:hover {
        text-decoration: underline;
    }

    .login-footer i {
        color: var(--primary-yellow);
    }

    /* Divider */
    .divider {
        font-family: var(--font-sans);
        display: flex;
        align-items: center;
        margin: 1.5rem 0;
        gap: 1rem;
        color: #9CA3AF;
        font-size: 0.725rem;
        font-weight: 700;
        letter-spacing: 1.5px;
        text-transform: uppercase;
    }

    .divider::before,
    .divider::after {
        content: '';
        flex: 1;
        height: 1px;
        background: #E5E7EB;
    }

    /* Responsive */
    @media (max-width: 992px) {
        .login-wrapper {
            flex-direction: column;
            height: auto;
            overflow-y: auto;
        }

        .brand-side {
            min-height: 50vh;
            padding: 2rem;
        }

        .brand-side::before,
        .brand-side::after {
            display: none;
        }

        .brand-features {
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
        }

        .brand-title {
            font-size: 1.65rem;
            margin-bottom: 1.25rem;
        }

        .brand-logo {
            width: 100px;
            height: 100px;
            padding: 8px;
        }

        .back-portal-btn {
            top: 1.15rem;
            right: 1.15rem;
            padding: 0.42rem 0.85rem;
            font-size: 0.75rem;
        }

        .login-side {
            min-height: 50vh;
            padding: 3.75rem 1.5rem 2rem 1.5rem;
        }

        .login-side::before {
            top: 0;
            left: 0;
            width: 100%;
            height: 6px;
        }

        .login-container {
            max-width: 100%;
            padding: 0;
        }

        .login-greeting {
            font-size: 1.5rem;
        }
    }

    @media (max-width: 576px) {
        .brand-features {
            grid-template-columns: 1fr;
        }

        .brand-title {
            font-size: 1.4rem;
            margin-bottom: 1rem;
        }

        .brand-logo {
            width: 80px;
            height: 80px;
            padding: 8px;
        }

        .back-portal-btn {
            top: 0.85rem;
            right: 0.85rem;
            padding: 0.38rem 0.75rem;
            font-size: 0.72rem;
            gap: 0.35rem;
        }

        .login-container {
            padding: 0;
        }
    }
</style>
</head>
<body>
<div class="login-wrapper">
    <!-- LEFT SIDE - Brand Information -->
    <div class="brand-side">
        <div class="circle-decoration circle-1"></div>
        <div class="circle-decoration circle-2"></div>
        <div class="circle-decoration circle-3"></div>
        
        <div class="brand-content">
            <div class="brand-logo">
                <img src="assets/images/logo.png" alt="<?= e(APP_NAME) ?> Logo">
            </div>
            
            <div class="brand-eyebrow">CITY OF MANILA &bull; CIVIC CONSULTATION</div>
            <h1 class="brand-title">
                Public Hearing and Consultation Management System
            </h1>

            <div class="brand-features">
                <div class="feature-item">
                    <i class="bi bi-shield-check"></i>
                    <span>Secure Access</span>
                </div>
                <div class="feature-item">
                    <i class="bi bi-clock-history"></i>
                    <span>Real-time Updates</span>
                </div>
                <div class="feature-item">
                    <i class="bi bi-people"></i>
                    <span>User Management</span>
                </div>
                <div class="feature-item">
                    <i class="bi bi-file-text"></i>
                    <span>Document Tracking</span>
                </div>
            </div>
        </div>
    </div>

    <!-- RIGHT SIDE - Login Form -->
    <div class="login-side">
        <a href="../index.php#subsystems" class="back-portal-btn">
            <i class="bi bi-arrow-left"></i>
            <span>Back to Subsystems Portal</span>
        </a>

        <div class="login-container">
            <!-- Header -->
            <div class="login-header">
                <div class="login-eyebrow">AUTHENTICATION ACCESS</div>
                <h2 class="login-greeting">
                    <i class="bi bi-box-arrow-in-right"></i>
                    Welcome Back
                </h2>
                <p class="login-subtitle">Sign in to access your LPH account</p>
            </div>

            <!-- Alerts -->

            <!-- 3-Week Admin Password Expiry Persistent Notification -->
            <?php if (!empty($adminPolicyStatus['has_expired'])): ?>
                <div class="alert-custom alert-danger d-flex align-items-start gap-2 mb-3 text-start" style="background:#fef2f2;border:1px solid #fecaca;border-left:4px solid #ef4444;border-radius:10px;padding:0.85rem 1rem;color:#991b1b;">
                    <i class="bi bi-shield-exclamation fs-5 text-danger mt-1"></i>
                    <div style="font-size:0.825rem;line-height:1.45;">
                        <strong class="d-block mb-1" style="font-size:0.875rem;color:#7f1d1d;">Security Notice: Administrator Password Expired</strong>
                        The Administrator password has surpassed the mandatory <strong>3-week (21 days)</strong> rotation policy. Please sign in and update your password immediately in <em>My Profile</em>.
                    </div>
                </div>
            <?php endif; ?>

            <?php foreach ($flashMessages as $msg): ?>
                <div class="alert-custom alert-<?= e($msg['type']) ?>">
                    <i class="bi <?= $msg['type'] === 'success' ? 'bi-check-circle' : ($msg['type'] === 'warning' ? 'bi-exclamation-triangle' : 'bi-x-circle') ?>"></i>
                    <span><?= e($msg['message']) ?></span>
                </div>
            <?php endforeach; ?>

            <!-- Login Form -->
            <form action="auth/process_login.php" method="POST" novalidate>
                <?= csrfField() ?>

                <!-- Email -->
                <div class="form-group">
                    <label class="form-label">
                        <i class="bi bi-envelope"></i>
                        Email Address
                    </label>
                    <div class="input-group-modern">
                        <input 
                            type="email" 
                            name="email" 
                            class="form-control" 
                            required 
                            autofocus 
                            placeholder="name@example.com"
                        >
                        <i class="bi bi-envelope input-icon"></i>
                    </div>
                </div>

                <!-- Password -->
                <div class="form-group">
                    <label class="form-label">
                        <i class="bi bi-lock"></i>
                        Password
                    </label>
                    <div class="input-group-modern">
                        <input 
                            type="password" 
                            name="password" 
                            class="form-control" 
                            required 
                            placeholder="Enter your password"
                        >
                        <i class="bi bi-lock input-icon"></i>
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn-login mt-4">
                    <i class="bi bi-box-arrow-in-right"></i>
                    Sign In
                </button>
            </form>

            <!-- Divider -->
            <div class="divider">
                <span>Secure Login</span>
            </div>

            <!-- Footer -->
            <div class="login-footer">
                <i class="bi bi-shield-lock"></i>
                &nbsp;Your information is protected &bull;
                &copy; <?= date('Y') ?> <?= e(APP_NAME) ?>
            </div>
        </div>
    </div>
</div>

<script src="<?= e(vendorAsset('bootstrap/bootstrap.bundle.min.js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js')) ?>"></script>
</body>
</html>