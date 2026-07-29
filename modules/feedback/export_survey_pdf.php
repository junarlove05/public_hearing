<?php
/**
 * modules/feedback/export_survey_pdf.php
 * ------------------------------------------------------------------
 * Exports all responses for a single survey as a downloadable PDF.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/SimplePdf.php';
requireRole([ROLE_ADMIN, ROLE_STAFF, ROLE_COMMITTEE]);

$id = (int)($_GET['id'] ?? 0);
$pdo = db();

$stmt = $pdo->prepare('SELECT * FROM surveys WHERE id = :id');
$stmt->execute([':id' => $id]);
$survey = $stmt->fetch();
if (!$survey) die('Survey not found.');

$respStmt = $pdo->prepare('SELECT * FROM survey_responses WHERE survey_id = :id ORDER BY submitted_at DESC');
$respStmt->execute([':id' => $id]);
$responses = $respStmt->fetchAll();

logActivity(currentUserId(), 'Export', 'Exported survey responses PDF for survey #' . $id);

$pdf = new SimplePdf('Survey Responses: ' . $survey['title'], 'Total responses: ' . count($responses) . '  |  Generated ' . date('F j, Y g:i A'));

$tableRows = array_map(fn($r) => [
    $r['respondent_name'] ?: 'Anonymous', $r['respondent_email'] ?: '-',
    mb_substr($r['response_text'], 0, 80), formatDateTime($r['submitted_at']),
], $responses);

if (empty($tableRows)) $tableRows[] = ['No responses yet.', '', '', ''];

$pdf->addTable(['Respondent', 'Email', 'Response', 'Submitted'], [90, 110, 220, 100], $tableRows);

$pdf->output('survey-' . $id . '-responses.pdf');
