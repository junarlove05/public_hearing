<?php
/**
 * modules/stakeholders/ajax_get.php
 * ------------------------------------------------------------------
 * Returns a single stakeholder record as JSON for the edit modal.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) jsonResponse(false, 'Invalid stakeholder id.');

$stmt = db()->prepare('SELECT * FROM stakeholders WHERE id = :id');
$stmt->execute([':id' => $id]);
$stakeholder = $stmt->fetch();

if (!$stakeholder) jsonResponse(false, 'Stakeholder not found.');

jsonResponse(true, '', ['stakeholder' => $stakeholder]);
