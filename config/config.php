<?php
/**
 * config/config.php
 * ------------------------------------------------------------------
 * Global application configuration.
 * Edit the DB_* constants to match your MySQL server. The database
 * itself (legislative_public_hearing_db) and all its tables are
 * assumed to already exist -- this file only supplies credentials.
 * ------------------------------------------------------------------
 */

// ---- Database credentials ----------------------------------------
define('DB_HOST', getenv('MYSQLHOST') ?: (getenv('DB_HOST') ?: '127.0.0.1'));
define('DB_PORT', getenv('MYSQLPORT') ?: (getenv('DB_PORT') ?: '3306'));
define('DB_NAME', getenv('MYSQLDATABASE') ?: (getenv('DB_NAME') ?: 'legislative_management_db'));
define('DB_USER', getenv('MYSQLUSER') ?: (getenv('DB_USER') ?: 'root'));
define('DB_PASS', getenv('MYSQLPASSWORD') !== false ? getenv('MYSQLPASSWORD') : (getenv('DB_PASS') !== false ? getenv('DB_PASS') : ''));
define('DB_CHARSET', 'utf8mb4');

// ---- Application settings -----------------------------------------
define('APP_NAME', 'Legislative Public Hearing & Consultation Management System');
define('APP_SHORT_NAME', 'LPH-CMS');

// *** IMPORTANT — READ THIS IF FORMS/BUTTONS AREN'T WORKING ***
// APP_URL MUST exactly match the URL of the project folder in your browser's
// address bar. Every single AJAX/fetch call in the app (Add/Edit/Delete,
// search, filters, QR scanning, everything) is built from this constant on
// the client side — if it's wrong, ALL of those calls silently fail with
// 404s that look like "network error" in the UI.
//   - Placed the project at C:\xampp\htdocs\lph\  -> keep 'http://localhost/lph'
//   - Placed it at C:\xampp\htdocs\my-hearing-app\ -> change to 'http://localhost/my-hearing-app'
//   - Placed it directly in htdocs\ (no subfolder)  -> change to 'http://localhost'
// After changing this, do a hard refresh (Ctrl+F5) so the browser doesn't
// use a cached copy of the old value.
if (!defined('APP_URL')) {
    $envAppUrl = getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? null);
    if (!empty($envAppUrl)) {
        define('APP_URL', rtrim($envAppUrl, '/'));
    } else {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
            || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on');
        $scheme = $isHttps ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        $webPath = null;
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $docRoot = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT']), '/');
            $targetDir = rtrim(str_replace('\\', '/', realpath(__DIR__ . '/..') ?: (__DIR__ . '/..')), '/');
            if ($docRoot && $targetDir && str_starts_with($targetDir, $docRoot)) {
                $sub = substr($targetDir, strlen($docRoot));
                $webPath = ($sub === false || $sub === '') ? '' : '/' . ltrim($sub, '/');
            }
        }
        if ($webPath === null) {
            $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
            if (str_contains($scriptDir, '/lph')) {
                $webPath = substr($scriptDir, 0, strpos($scriptDir, '/lph') + 4);
            } else {
                $webPath = '';
            }
        }
        define('APP_URL', $scheme . $host . rtrim($webPath, '/'));
    }
}

define('APP_TIMEZONE', 'Asia/Manila');

// ---- Upload settings -------------------------------------------------
define('UPLOAD_DIR', __DIR__ . '/../assets/uploads/');
define('UPLOAD_URL', APP_URL . '/assets/uploads/');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10 MB
define('ALLOWED_UPLOAD_EXT', ['pdf', 'doc', 'docx', 'png', 'jpg', 'jpeg']);

// ---- Session settings -----------------------------------------------
define('SESSION_NAME', 'lph_session');
define('SESSION_LIFETIME', 60 * 60 * 8); // 8 hours standard session
define('INACTIVITY_TIMEOUT', SESSION_LIFETIME); // Inactivity timeout aligned to 8 hours (removes 5-minute auto logout)

// FIX (network-error root cause #3): PHP's own session garbage collector
// has its own separate lifetime setting (session.gc_maxlifetime), which
// defaults to only 1440 seconds (24 minutes) on most PHP/XAMPP installs —
// completely unrelated to the 8-hour cookie lifetime configured above. If
// left mismatched, PHP can silently delete the session's data file on the
// server after ~24 minutes of any user's inactivity anywhere on the shared
// host, while the browser's cookie still looks valid for hours. The next
// AJAX request then finds an empty session, gets treated as logged out,
// and (before the auth.php fix) received an HTML login page instead of
// JSON — which is what actually produced the intermittent "network error"
// messages. Aligning both lifetimes here closes that gap.
ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);
ini_set('session.cookie_lifetime', (string)SESSION_LIFETIME);

// ---- Pagination -------------------------------------------------------
define('DEFAULT_PAGE_SIZE', 10);

// ---- Roles (must match `roles` table `name` values exactly) -----------
define('ROLE_ADMIN', 'Administrator');
define('ROLE_STAFF', 'Legislative Staff');
define('ROLE_COMMITTEE', 'Committee Member');
define('ROLE_STAKEHOLDER', 'Registered Stakeholder');
define('ROLE_PUBLIC', 'Public User');

date_default_timezone_set(APP_TIMEZONE);

// ---- Error display (turn off in production) ----------------------------
define('APP_DEBUG', true);
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// Central error/exception log file
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

// ---- Gmail / SMTP Email Dispatch Settings ----------------------------
// To send live emails to stakeholders' Gmail accounts, enter your Gmail & Google App Password here:
// 1. Visit Google Account (https://myaccount.google.com/) -> Security
// 2. Enable 2-Step Verification
// 3. Search "App Passwords" -> Create an App Password (e.g., name it "Legislative")
// 4. Paste your 16-character App Password into SMTP_PASS below (without spaces)
define('MAIL_MAILER', 'smtp');
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_ENCRYPTION', 'tls');
define('SMTP_USER', '');                      // Your Gmail address (e.g. 'council.legislative@gmail.com')
define('SMTP_PASS', '');                      // Your 16-character Google App Password (e.g. 'abcd1234efgh5678')
define('MAIL_FROM_ADDRESS', '');              // Leave blank to use SMTP_USER
define('MAIL_FROM_NAME', 'City Council - Legislative Public Hearing');

