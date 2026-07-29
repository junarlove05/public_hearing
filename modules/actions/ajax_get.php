<?php
/**
 * modules/actions/ajax_get.php
 * ------------------------------------------------------------------
 * Returns a single action record as JSON for the edit modal.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) jsonResponse(false, 'Invalid action id.');

$stmt = db()->prepare('SELECT * FROM actions WHERE id = :id');
$stmt->execute([':id' => $id]);
$action = $stmt->fetch();

if (!$action) jsonResponse(false, 'Action not found.');

jsonResponse(true, '', ['action' => $action]);
