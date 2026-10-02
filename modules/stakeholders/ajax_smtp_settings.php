<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';
require_once __DIR__ . '/../../includes/mailer.php';

requireLogin();
if (!canManage()) {
    jsonResponse(false, 'Only authorized personnel can configure email settings.');
}

$action = trim((string)($_GET['action'] ?? ($_POST['action'] ?? 'get')));
$pdo = db();

if ($action === 'get') {
    $cfg = lphGetSmtpConfig($pdo);
    $payloadData = [
        'email_provider'    => $cfg['provider'] ?? 'smtp',
        'smtp_host'         => $cfg['host'],
        'smtp_port'         => $cfg['port'],
        'smtp_encryption'   => $cfg['encryption'],
        'smtp_user'         => $cfg['user'],
        'smtp_from_address' => $cfg['from_address'],
        'smtp_from_name'    => $cfg['from_name'],
        'google_script_url' => $cfg['google_script_url'] ?? '',
        'has_pass'          => !empty($cfg['pass']),
        'has_resend_key'    => !empty($cfg['resend_api_key']),
        'has_brevo_key'     => !empty($cfg['brevo_api_key']),
        'has_script_url'    => !empty($cfg['google_script_url']),
        'is_railway'        => !empty(getenv('RAILWAY_ENVIRONMENT')) || !empty(getenv('RAILWAY_PROJECT_ID'))
    ];
    jsonResponse(true, 'Email configuration loaded.', array_merge(['data' => $payloadData], $payloadData));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}
requireCsrf();

if ($action === 'save') {
    $provider = trim((string)($_POST['email_provider'] ?? 'smtp'));
    $user = trim((string)($_POST['smtp_user'] ?? ''));
    $pass = trim((string)($_POST['smtp_pass'] ?? ''));
    $resendKey = trim((string)($_POST['resend_api_key'] ?? ''));
    $brevoKey = trim((string)($_POST['brevo_api_key'] ?? ''));
    $scriptUrl = trim((string)($_POST['google_script_url'] ?? ''));
    $host = trim((string)($_POST['smtp_host'] ?? 'smtp.gmail.com')) ?: 'smtp.gmail.com';
    $port = (int)($_POST['smtp_port'] ?? 587);
    $encryption = trim((string)($_POST['smtp_encryption'] ?? 'tls')) ?: 'tls';
    $fromName = trim((string)($_POST['smtp_from_name'] ?? 'City Council - Legislative Public Hearing'));
    $fromAddress = trim((string)($_POST['smtp_from_address'] ?? ''));

    if ($user !== '' && !filter_var($user, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, 'Please provide a valid Gmail address.');
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `lph_settings` (
            `setting_key` VARCHAR(100) PRIMARY KEY,
            `setting_value` TEXT,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $upsert = $pdo->prepare(
            "INSERT INTO lph_settings (setting_key, setting_value) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = :v2"
        );

        $upsert->execute([':k' => 'email_provider', ':v' => $provider, ':v2' => $provider]);
        $upsert->execute([':k' => 'smtp_user', ':v' => $user, ':v2' => $user]);
        $upsert->execute([':k' => 'smtp_host', ':v' => $host, ':v2' => $host]);
        $upsert->execute([':k' => 'smtp_port', ':v' => (string)$port, ':v2' => (string)$port]);
        $upsert->execute([':k' => 'smtp_encryption', ':v' => $encryption, ':v2' => $encryption]);
        $upsert->execute([':k' => 'smtp_from_name', ':v' => $fromName, ':v2' => $fromName]);
        if ($fromAddress !== '') {
            $upsert->execute([':k' => 'smtp_from_address', ':v' => $fromAddress, ':v2' => $fromAddress]);
        }

        if ($pass !== '') {
            $cleanPass = str_replace(' ', '', $pass);
            $upsert->execute([':k' => 'smtp_pass', ':v' => $cleanPass, ':v2' => $cleanPass]);
        }
        if ($resendKey !== '') {
            $upsert->execute([':k' => 'resend_api_key', ':v' => $resendKey, ':v2' => $resendKey]);
        }
        if ($brevoKey !== '') {
            $upsert->execute([':k' => 'brevo_api_key', ':v' => $brevoKey, ':v2' => $brevoKey]);
        }
        if ($scriptUrl !== '') {
            $upsert->execute([':k' => 'google_script_url', ':v' => $scriptUrl, ':v2' => $scriptUrl]);
        }

        logActivity(currentUserId(), 'Update', "Updated email dispatcher configuration (Provider: {$provider})");
        jsonResponse(true, 'Email configuration saved successfully!');
    } catch (Throwable $e) {
        error_log('[ajax_smtp_settings save] ' . $e->getMessage());
        jsonResponse(false, APP_DEBUG ? $e->getMessage() : 'Failed to save email settings.');
    }
}

if ($action === 'test') {
    $testTo = trim((string)($_POST['test_email'] ?? ''));
    if (!filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, 'Please enter a valid recipient email address for testing.');
    }

    $overrideProvider = trim((string)($_POST['email_provider'] ?? ''));
    $overrideBrevoKey = trim((string)($_POST['brevo_api_key'] ?? ''));
    $overrideResendKey = trim((string)($_POST['resend_api_key'] ?? ''));
    $overrideUser = trim((string)($_POST['smtp_user'] ?? ''));
    $overrideFromName = trim((string)($_POST['smtp_from_name'] ?? ''));

    // If tested directly from modal, automatically persist credentials
    if ($overrideProvider !== '' || $overrideBrevoKey !== '' || $overrideUser !== '') {
        try {
            $upsert = $pdo->prepare(
                "INSERT INTO lph_settings (setting_key, setting_value) VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE setting_value = :v2"
            );
            if ($overrideProvider !== '') {
                $upsert->execute([':k' => 'email_provider', ':v' => $overrideProvider, ':v2' => $overrideProvider]);
            }
            if ($overrideBrevoKey !== '') {
                $upsert->execute([':k' => 'brevo_api_key', ':v' => $overrideBrevoKey, ':v2' => $overrideBrevoKey]);
            }
            if ($overrideResendKey !== '') {
                $upsert->execute([':k' => 'resend_api_key', ':v' => $overrideResendKey, ':v2' => $overrideResendKey]);
            }
            if ($overrideUser !== '' && filter_var($overrideUser, FILTER_VALIDATE_EMAIL)) {
                $upsert->execute([':k' => 'smtp_user', ':v' => $overrideUser, ':v2' => $overrideUser]);
            }
            if ($overrideFromName !== '') {
                $upsert->execute([':k' => 'smtp_from_name', ':v' => $overrideFromName, ':v2' => $overrideFromName]);
            }
        } catch (Throwable $e) {}
    }

    $subject = 'Official Test Email: Legislative Public Hearing System';
    $htmlBody = '
    <div style="font-family:sans-serif;max-width:600px;margin:auto;padding:24px;border:1px solid #e2e8f0;border-radius:12px;background:#ffffff;">
        <div style="background:#0F2137;padding:16px 20px;border-radius:8px;color:#ffffff;margin-bottom:20px;">
            <h2 style="margin:0;font-size:18px;color:#facc15;">Public Hearing &amp; Consultation Management System</h2>
            <div style="font-size:12px;opacity:0.8;">Live Email Connection Test</div>
        </div>
        <p style="color:#334155;font-size:15px;line-height:1.6;">
            Magandang araw! This is an official test message confirming that your <strong>email service</strong> is properly configured and successfully transmitting messages.
        </p>
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-left:4px solid #16a34a;padding:14px;border-radius:6px;margin:20px 0;">
            <strong style="color:#166534;">Email Dispatch Successful!</strong><br>
            <span style="font-size:13px;color:#15803d;">Recipient: ' . htmlspecialchars($testTo, ENT_QUOTES) . '</span><br>
            <span style="font-size:12px;color:#64748b;">Timestamp: ' . date('Y-m-d H:i:s') . '</span>
        </div>
        <p style="font-size:12px;color:#64748b;margin-top:20px;">
            Hearing invitations sent to stakeholders will now be delivered straight to their inboxes.
        </p>
    </div>';

    $result = lphSendMail($testTo, 'Stakeholder / Administrator', $subject, $htmlBody);

    if ($result['ok'] && ($result['reason'] ?? '') !== 'sandbox_mode') {
        jsonResponse(true, "Test email delivered successfully to {$testTo}! Check your inbox (or spam folder).", ['ok' => true]);
    } elseif (($result['reason'] ?? '') === 'sandbox_mode') {
        jsonResponse(false, "No active live email provider or API key is saved yet. Please enter your Gmail App Password, Brevo API key, or Resend API key above and click 'Save Configuration' first.", ['ok' => false, 'reason' => 'unconfigured']);
    } else {
        $msg = $result['message'] ?: 'Failed to send test email.';
        jsonResponse(false, "Delivery Notice: {$msg}", ['ok' => false, 'reason' => $result['reason'] ?? '']);
    }
}

jsonResponse(false, 'Unrecognized action.');
