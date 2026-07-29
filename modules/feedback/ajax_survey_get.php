<?php
/**
 * modules/feedback/ajax_survey_get.php
 * ------------------------------------------------------------------
 * Returns a single survey record as JSON for the edit modal.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) jsonResponse(false, 'Invalid survey id.');

$stmt = db()->prepare('SELECT * FROM surveys WHERE id = :id');
$stmt->execute([':id' => $id]);
$survey = $stmt->fetch();

if (!$survey) jsonResponse(false, 'Survey not found.');

jsonResponse(true, '', ['survey' => $survey]);
