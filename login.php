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

$timeout = isset($_GET['timeout']);
$flashMessages = getFlashMessages();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login | <?= e(APP_NAME) ?></title>
<link href="<?= e(vendorAsset('bootstrap/bootstrap.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css')) ?>" rel="stylesheet">
<link href="<?= e(vendorAsset('bootstrap-icons/bootstrap-icons.css', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css')) ?>" rel="stylesheet">
<link href="assets/css/style.css" rel="stylesheet">
<style>
    :root {
        --primary-blue: #1A56DB;
        --primary-blue-dark: #1E3A8A;
        --primary-blue-light: #3B82F6;
        --primary-yellow: #FBBF24;
        --primary-yellow-light: #FCD34D;
        --primary-white: #FFFFFF;
        --primary-gray: #F3F4F6;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    html, body {
        height: 100%;
        overflow: hidden;
    }

    .login-wrapper {
        display: flex;
        height: 100vh;
        width: 100%;
        background: var(--primary-white);
    }

    /* LEFT SIDE - Brand Section */
    .brand-side {
        flex: 1;
        background: linear-gradient(135deg, var(--primary-blue-dark) 0%, var(--primary-blue) 50%, var(--primary-blue-light) 100%);
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
        width: 120px;
        height: 120px;
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 2rem;
        border: 4px solid rgba(251, 191, 36, 0.4);
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        overflow: hidden;
        padding: 12px;
    }

    .brand-logo:hover {
        transform: scale(1.05) rotate(-3deg);
        box-shadow: 0 25px 70px rgba(0, 0, 0, 0.4);
    }

    .brand-logo img {
        width: 100%;
        height: 100%;
        object-fit: contain;
        border-radius: 50%;
    }

    .brand-title {
        font-size: 2.5rem;
        font-weight: 800;
        margin-bottom: 0.5rem;
        letter-spacing: -1px;
        color: var(--primary-white);
        text-shadow: 0 2px 20px rgba(0, 0, 0, 0.1);
    }

    .brand-title .highlight {
        color: var(--primary-yellow);
        position: relative;
    }

    .brand-title .highlight::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: var(--primary-yellow);
        border-radius: 2px;
        opacity: 0.5;
    }

    .brand-description {
        font-size: 1.1rem;
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
        color: var(--primary-yellow);
        font-size: 1.25rem;
    }

    .feature-item span {
        font-size: 0.85rem;
        font-weight: 500;
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

    .login-greeting {
        font-size: 1.75rem;
        font-weight: 700;
        color: var(--primary-blue-dark);
        margin-bottom: 0.25rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .login-greeting i {
        color: var(--primary-yellow);
        font-size: 1.5rem;
    }

    .login-subtitle {
        color: #6B7280;
        font-size: 0.95rem;
    }

    /* Custom Alerts */
    .alert-custom {
        border: none;
        border-radius: 12px;
        padding: 0.75rem 1rem;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-weight: 500;
        font-size: 0.9rem;
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
        font-weight: 600;
        color: #374151;
        font-size: 0.85rem;
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
        border-color: var(--primary-blue);
        background: #FFFFFF;
        box-shadow: 0 0 0 4px rgba(26, 86, 219, 0.1);
        outline: none;
    }

    .input-group-modern .form-control:focus ~ .input-icon {
        color: var(--primary-blue);
    }

    .input-group-modern .form-control::placeholder {
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
        accent-color: var(--primary-blue);
        cursor: pointer;
        margin: 0;
        transition: all 0.2s ease;
        flex-shrink: 0;
    }

    .checkbox-custom input[type="checkbox"]:checked {
        border-color: var(--primary-blue);
        background-color: var(--primary-blue);
    }

    .checkbox-custom .check-label {
        font-size: 0.875rem;
        color: #4B5563;
        font-weight: 500;
        cursor: pointer;
        user-select: none;
    }

    .forgot-link {
        color: var(--primary-blue);
        text-decoration: none;
        font-size: 0.875rem;
        font-weight: 500;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
    }

    .forgot-link:hover {
        color: var(--primary-blue-dark);
        text-decoration: underline;
    }

    /* Login Button */
    .btn-login {
        background: linear-gradient(135deg, var(--primary-blue) 0%, var(--primary-blue-dark) 100%);
        border: none;
        border-radius: 12px;
        padding: 0.85rem;
        font-weight: 600;
        font-size: 1rem;
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
        box-shadow: 0 4px 15px rgba(26, 86, 219, 0.3);
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
        transform: translateY(-2px);
        box-shadow: 0 8px 30px rgba(26, 86, 219, 0.4);
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
        text-align: center;
        margin-top: 1.5rem;
        padding-top: 1.5rem;
        border-top: 1px solid #F3F4F6;
        color: #9CA3AF;
        font-size: 0.8rem;
    }

    .login-footer a {
        color: var(--primary-blue);
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
        display: flex;
        align-items: center;
        margin: 1.5rem 0;
        gap: 1rem;
        color: #9CA3AF;
        font-size: 0.8rem;
        font-weight: 500;
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
            font-size: 2rem;
        }

        .brand-logo {
            width: 100px;
            height: 100px;
            padding: 10px;
        }

        .login-side {
            min-height: 50vh;
            padding: 2rem 1.5rem;
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
            font-size: 1.6rem;
        }

        .brand-logo {
            width: 80px;
            height: 80px;
            padding: 8px;
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
                <img src="assets/images/manila.png" alt="<?= e(APP_NAME) ?> Logo">
            </div>
            
            <h1 class="brand-title">
                <?= e(APP_NAME) ?>
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
        <div class="login-container">
            <!-- Header -->
            <div class="login-header">
                <h2 class="login-greeting">
                    <i class="bi bi-box-arrow-in-right"></i>
                    Welcome Back
                </h2>
                <p class="login-subtitle">Sign in to access your account</p>
            </div>

            <!-- Alerts -->
            <?php if ($timeout): ?>
                <div class="alert-custom alert-warning">
                    <i class="bi bi-clock-history"></i>
                    <span>Your session expired. Please log in again.</span>
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

                <!-- Options -->
                <div class="form-options">
                    <label class="checkbox-custom">
                        <input type="checkbox" name="remember" id="remember">
                        <span class="check-label">Remember me</span>
                    </label>
                    <a href="forgot_password.php" class="forgot-link">
                        <i class="bi bi-question-circle"></i>
                        Forgot password?
                    </a>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn-login">
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