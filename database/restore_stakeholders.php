<?php
declare(strict_types=1);

/**
 * database/restore_stakeholders.php
 * ------------------------------------------------------------------
 * Restore stakeholder records from the safety backup.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireLogin();
requireRole([ROLE_ADMIN]);

$pdo = db();
$backupFile = __DIR__ . '/backups/stakeholders_backup_2026-09-21_094742.sql';

if (!file_exists($backupFile)) {
    die("Backup file not found: " . htmlspecialchars($backupFile));
}

try {
    $sql = file_get_contents($backupFile);
    $pdo->exec($sql);
    echo "<h1>Stakeholder Backup Restored Successfully!</h1>";
    echo "<p>All 11 stakeholders and their associated invitations, registrations, attendance records, and QR codes have been restored.</p>";
    echo "<p><a href='../dashboard.php'>Go to Dashboard</a></p>";
} catch (Exception $e) {
    echo "<h1>Error restoring backup</h1>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
