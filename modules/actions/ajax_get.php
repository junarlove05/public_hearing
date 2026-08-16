<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) jsonResponse(false, 'Invalid action id.');

$stmt = db()->prepare(
    'SELECT a.*, o.name AS assigned_office, u.full_name AS assigned_user
     FROM hearing_actions a
     LEFT JOIN offices o ON o.id = a.assigned_office_id
     LEFT JOIN users u ON u.id = a.assigned_user_id
     WHERE a.id = :id'
);
$stmt->execute([':id' => $id]);
$action = $stmt->fetch();
if (!$action) jsonResponse(false, 'Action not found.');
jsonResponse(true, 'Action loaded.', ['action' => $action]);
