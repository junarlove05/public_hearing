<?php
require_once __DIR__ . '/includes/auth.php';
$pdo = db();

try {
    $pdo->exec("ALTER TABLE stakeholders ADD COLUMN valid_id_path VARCHAR(255) NULL AFTER address");
    echo "Added valid_id_path column.\n";
} catch (Exception $e) {
    echo "Notice: " . $e->getMessage() . "\n";
}

showCols($pdo, 'stakeholders');
showCols($pdo, 'qr_codes');
showCols($pdo, 'registrations');
showCols($pdo, 'attendance');
