<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';
requireLogin();
if (!canManage()) {
    setFlash('danger','Insufficient permissions to clear stakeholders.');
    redirect(APP_URL . '/dashboard.php');
}
$pdo = db();
$pdo->beginTransaction();
try {
    // Delete related data first (adjust if foreign key constraints exist)
    $pdo->exec('DELETE FROM invitations');
    $pdo->exec('DELETE FROM registrations');
    $pdo->exec('DELETE FROM qr_codes');
    $pdo->exec('DELETE FROM stakeholders');
    $pdo->commit();
    setFlash('success','All stakeholder records and related data have been removed.');
} catch (Exception $e) {
    $pdo->rollBack();
    setFlash('danger','Failed to clear stakeholders: ' . $e->getMessage());
}
redirect(APP_URL . '/modules/stakeholders/index.php');
?>
