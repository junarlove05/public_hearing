<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';

requireLogin();
if (!canManage()) jsonResponse(false, 'You do not have permission to delete stakeholders.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) jsonResponse(false, 'Invalid stakeholder id.');

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM stakeholders WHERE id=:id');
$stmt->execute([':id'=>$id]);
$s = $stmt->fetch();
if (!$s) jsonResponse(false, 'Stakeholder not found.');

$linked = [];
foreach (['invitations','registrations','attendance','feedback'] as $table) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE stakeholder_id=:id");
    $q->execute([':id'=>$id]);
    $count = (int)$q->fetchColumn();
    if ($count > 0) $linked[] = "{$count} {$table}";
}

if ($linked) {
    jsonResponse(
        false,
        'This stakeholder has linked records (' . implode(', ', $linked) .
        '). Mark the stakeholder Inactive instead of deleting the record.'
    );
}

try {
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM stakeholders WHERE id=:id')->execute([':id'=>$id]);
    logActivity(currentUserId(), 'Delete Stakeholder', 'Deleted stakeholder '.$s['full_name'].' ('.$s['email'].').');
    $pdo->commit();
    jsonResponse(true, 'Stakeholder deleted successfully.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Stakeholder delete error: '.$e->getMessage());
    jsonResponse(false, APP_DEBUG ? $e->getMessage() : 'Unable to delete stakeholder.');
}
