<?php
/**
 * modules/feedback/export_pdf.php
 * ------------------------------------------------------------------
 * Exports the full feedback list (with category + status) as PDF.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/SimplePdf.php';
requireRole([ROLE_ADMIN, ROLE_STAFF]);

$pdo = db();
$rows = $pdo->query(
    "SELECT f.name, f.email, fc.name AS category_name, f.subject, f.status, f.submitted_at
     FROM feedback f LEFT JOIN feedback_categories fc ON fc.id = f.category_id
     ORDER BY f.submitted_at DESC"
)->fetchAll();

logActivity(currentUserId(), 'Export', 'Exported feedback report PDF');

$pdf = new SimplePdf('Feedback Report', 'Generated ' . date('F j, Y g:i A') . '  |  Total: ' . count($rows));

$tableRows = array_map(fn($r) => [
    $r['name'], $r['email'], $r['category_name'] ?: '-', $r['subject'] ?: '-', $r['status'], formatDateTime($r['submitted_at']),
], $rows);

if (empty($tableRows)) $tableRows[] = ['No feedback records found.', '', '', '', '', ''];

$pdf->addTable(
    ['Name', 'Email', 'Category', 'Subject', 'Status', 'Submitted'],
    [80, 110, 80, 90, 60, 100],
    $tableRows
);

$pdf->output('feedback-report.pdf');
