<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Central LPH audit logger.
 *
 * Stores the Public Hearing system id plus request context when available.
 * Logging is deliberately non-fatal so audit failure never destroys the
 * user's primary transaction.
 */
function logActivity(?int $userId, string $action, string $details = ''): void
{
    try {
        $systemId = function_exists('lphSystemId')
            ? lphSystemId()
            : 4;

        $ip = substr(
            (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            0,
            45
        );

        $userAgent = substr(
            (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
            0,
            500
        );

        $stmt = db()->prepare(
            'INSERT INTO activity_logs
                (user_id,system_id,action,details,ip_address,user_agent,created_at)
             VALUES
                (:user_id,:system_id,:action,:details,:ip_address,:user_agent,NOW())'
        );

        $stmt->execute([
            ':user_id'=>$userId,
            ':system_id'=>$systemId,
            ':action'=>substr($action, 0, 100),
            ':details'=>$details,
            ':ip_address'=>$ip !== '' ? $ip : null,
            ':user_agent'=>$userAgent !== '' ? $userAgent : null,
        ]);
    } catch (Throwable $e) {
        error_log('Activity log failed: '.$e->getMessage());
    }
}
