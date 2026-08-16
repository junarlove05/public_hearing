<?php
declare(strict_types=1);

/**
 * Fine-grained authorization and authentication hardening helpers.
 *
 * The functions deliberately retain a legacy fallback so extracting
 * the PHP files before running migration 011 does not lock existing
 * users out of the subsystem.
 */

function lphSystemId(): int
{
    static $id = null;

    if ($id !== null) {
        return $id;
    }

    try {
        $stmt = db()->query(
            "SELECT id FROM systems WHERE code='hearing' LIMIT 1"
        );
        $value = $stmt->fetchColumn();
        $id = $value ? (int)$value : 4;
    } catch (Throwable $e) {
        $id = 4;
    }

    return $id;
}

function lphTableExistsSecurity(string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema=DATABASE()
               AND table_name=:table'
        );
        $stmt->execute([':table'=>$table]);
        return $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function lphUserHasSystemAccess(int $userId): bool
{
    if ($userId <= 0 || !lphTableExistsSecurity('user_system_access')) {
        return true;
    }

    try {
        $stmt = db()->prepare(
            'SELECT status
             FROM user_system_access
             WHERE user_id=:user
               AND system_id=:system
             LIMIT 1'
        );
        $stmt->execute([
            ':user'=>$userId,
            ':system'=>lphSystemId(),
        ]);

        $row = $stmt->fetch();

        // Legacy compatibility: missing row means "not explicitly denied".
        // Migration 011 backfills explicit access for current users.
        if (!$row) {
            return true;
        }

        return strcasecmp((string)$row['status'], 'Active') === 0;
    } catch (Throwable $e) {
        error_log('LPH system-access check failed: '.$e->getMessage());
        return true;
    }
}

function lphLegacyPermissionFallback(string $code): bool
{
    $role = currentRole();

    $allAuthenticated = [
        'lph.dashboard.view',
        'lph.hearings.view',
        'lph.feedback.submit',
    ];

    if (in_array($code, $allAuthenticated, true)) {
        return isLoggedIn();
    }

    $staff = [
        'lph.records.manage',
        'lph.hearings.manage',
        'lph.stakeholders.view',
        'lph.stakeholders.manage',
        'lph.attendance.view',
        'lph.attendance.manage',
        'lph.feedback.review',
        'lph.surveys.manage',
        'lph.issues.view',
        'lph.issues.manage',
        'lph.actions.view',
        'lph.actions.manage',
        'lph.responses.view',
        'lph.responses.manage',
        'lph.responses.publish',
        'lph.reports.view',
    ];

    if (in_array($code, $staff, true)) {
        return in_array($role, [ROLE_ADMIN, ROLE_STAFF], true);
    }

    $committee = [
        'lph.attendance.view',
        'lph.attendance.manage',
        'lph.feedback.review',
        'lph.issues.view',
        'lph.actions.view',
        'lph.responses.view',
    ];

    if (in_array($code, $committee, true) && $role === ROLE_COMMITTEE) {
        return true;
    }

    if (in_array($code, [
        'lph.activity_logs.view',
        'lph.users.manage',
        'lph.system_health.view',
    ], true)) {
        return $role === ROLE_ADMIN;
    }

    return false;
}

function hasPermission(string $code): bool
{
    if (!isLoggedIn()) {
        return false;
    }

    if (currentRole() === ROLE_ADMIN) {
        return true;
    }

    if (
        !lphTableExistsSecurity('permissions')
        || !lphTableExistsSecurity('role_permissions')
    ) {
        return lphLegacyPermissionFallback($code);
    }

    try {
        $permission = db()->prepare(
            'SELECT id
             FROM permissions
             WHERE code=:code
             LIMIT 1'
        );
        $permission->execute([':code'=>$code]);
        $permissionId = $permission->fetchColumn();

        if (!$permissionId) {
            return lphLegacyPermissionFallback($code);
        }

        $roleId = (int)($_SESSION['role_id'] ?? 0);

        if ($roleId <= 0) {
            return false;
        }

        $stmt = db()->prepare(
            'SELECT COUNT(*)
             FROM role_permissions
             WHERE role_id=:role
               AND permission_id=:permission'
        );
        $stmt->execute([
            ':role'=>$roleId,
            ':permission'=>$permissionId,
        ]);

        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        error_log('LPH permission check failed: '.$e->getMessage());
        return lphLegacyPermissionFallback($code);
    }
}

function requirePermission(string $code): void
{
    requireLogin();

    if (!hasPermission($code)) {
        http_response_code(403);

        if (isAjaxRequest()) {
            jsonResponse(false, 'You do not have permission to perform this action.');
        }

        include __DIR__ . '/../pages/403.php';
        exit;
    }
}

function lphClientIp(): string
{
    // Do not trust forwarded headers on a normal local/XAMPP setup.
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
}

function lphLoginIdentifierHash(string $value): string
{
    return hash('sha256', strtolower(trim($value)));
}

function lphLoginIpHash(): string
{
    return hash('sha256', lphClientIp());
}

function lphLoginRateLimitStatus(string $email): array
{
    if (!lphTableExistsSecurity('lph_login_attempts')) {
        return [
            'blocked'=>false,
            'remaining'=>5,
            'retry_after_seconds'=>0,
        ];
    }

    $emailHash = lphLoginIdentifierHash($email);
    $ipHash = lphLoginIpHash();

    try {
        $stmt = db()->prepare(
            "SELECT COUNT(*)
             FROM lph_login_attempts
             WHERE successful=0
               AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
               AND (email_hash=:email OR ip_hash=:ip)"
        );
        $stmt->execute([
            ':email'=>$emailHash,
            ':ip'=>$ipHash,
        ]);
        $failed = (int)$stmt->fetchColumn();

        if ($failed < 5) {
            return [
                'blocked'=>false,
                'remaining'=>max(0, 5-$failed),
                'retry_after_seconds'=>0,
            ];
        }

        $last = db()->prepare(
            "SELECT attempted_at
             FROM lph_login_attempts
             WHERE successful=0
               AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
               AND (email_hash=:email OR ip_hash=:ip)
             ORDER BY attempted_at DESC
             LIMIT 1"
        );
        $last->execute([
            ':email'=>$emailHash,
            ':ip'=>$ipHash,
        ]);

        $lastAt = $last->fetchColumn();
        $retry = $lastAt
            ? max(60, (strtotime((string)$lastAt) + 900) - time())
            : 900;

        return [
            'blocked'=>true,
            'remaining'=>0,
            'retry_after_seconds'=>$retry,
        ];
    } catch (Throwable $e) {
        error_log('Login-rate-limit check failed: '.$e->getMessage());

        return [
            'blocked'=>false,
            'remaining'=>5,
            'retry_after_seconds'=>0,
        ];
    }
}

function lphRecordLoginAttempt(string $email, bool $successful): void
{
    if (!lphTableExistsSecurity('lph_login_attempts')) {
        return;
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO lph_login_attempts
             (email_hash,ip_hash,successful,attempted_at)
             VALUES (:email,:ip,:successful,NOW())'
        );

        $stmt->execute([
            ':email'=>lphLoginIdentifierHash($email),
            ':ip'=>lphLoginIpHash(),
            ':successful'=>$successful ? 1 : 0,
        ]);

        // Keep the table bounded without requiring a cron job.
        if (random_int(1, 25) === 1) {
            db()->exec(
                'DELETE FROM lph_login_attempts
                 WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 90 DAY)'
            );
        }
    } catch (Throwable $e) {
        error_log('Login-attempt logging failed: '.$e->getMessage());
    }
}
