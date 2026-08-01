<?php
/**
 * Returns subsystem links that the current user may access.
 */

function getSubsystemLinks(): array
{
    $pdo = db();
    $userId = currentUserId();

    if (!$userId) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT
            s.code,
            s.name,
            s.base_url,
            s.status,
            usa.access_level
         FROM systems s
         INNER JOIN user_system_access usa
            ON usa.system_id = s.id
         WHERE usa.user_id = :user_id
           AND usa.status = 'Active'
         ORDER BY s.id"
    );

    $stmt->execute([
        ':user_id' => $userId,
    ]);

    return $stmt->fetchAll();
}
