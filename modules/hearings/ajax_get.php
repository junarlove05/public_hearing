<?php
/**
 * modules/hearings/ajax_get.php
 * ------------------------------------------------------------------
 * Returns a single hearing record as JSON, used to populate the
 * "Edit Hearing" modal via AJAX.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    jsonResponse(false, 'Invalid hearing id.');
}

$stmt = db()->prepare('SELECT * FROM hearings WHERE id = :id');
$stmt->execute([':id' => $id]);
$hearing = $stmt->fetch();

if (!$hearing) {
    jsonResponse(false, 'Hearing not found.');
}

jsonResponse(true, '', ['hearing' => $hearing]);


