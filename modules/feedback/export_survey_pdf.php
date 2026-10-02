<?php
declare(strict_types=1);

/**
 * modules/feedback/export_survey_pdf.php
 * ------------------------------------------------------------------
 * Exports comprehensive survey analytics and respondent submissions
 * as a downloadable PDF report.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/SimplePdf.php';

requireRole([ROLE_ADMIN, ROLE_STAFF, ROLE_COMMITTEE]);

$id = (int)($_GET['id'] ?? 0);
$pdo = db();

$stmt = $pdo->prepare(
    'SELECT s.*,
            h.reference_number hearing_reference,
            h.title hearing_title,
            li.reference_number legislative_reference,
            li.title legislative_title
     FROM surveys s
     LEFT JOIN hearings h ON h.id = s.hearing_id
     LEFT JOIN legislative_items li ON li.id = s.legislative_item_id
     WHERE s.id = :id'
);
$stmt->execute([':id' => $id]);
$survey = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$survey) {
    die('Survey not found.');
}

// Fetch submissions
$subStmt = $pdo->prepare(
    'SELECT ss.*, s.organization
     FROM survey_submissions ss
     LEFT JOIN stakeholders s ON s.id = ss.stakeholder_id
     WHERE ss.survey_id = :id
     ORDER BY ss.submitted_at DESC'
);
$subStmt->execute([':id' => $id]);
$submissions = $subStmt->fetchAll(PDO::FETCH_ASSOC);
$totalSubmissions = count($submissions);

// Fetch questions & summary
$qStmt = $pdo->prepare('SELECT * FROM survey_questions WHERE survey_id = :id ORDER BY sequence_number, id');
$qStmt->execute([':id' => $id]);
$questions = $qStmt->fetchAll(PDO::FETCH_ASSOC);

logActivity(currentUserId(), 'Export', 'Exported survey report PDF for survey #' . $id . ' (' . $survey['title'] . ')');

$contextStr = $survey['hearing_reference']
    ? ('Hearing: ' . $survey['hearing_reference'] . ' - ' . $survey['hearing_title'])
    : ($survey['legislative_reference'] ? ('Item: ' . $survey['legislative_reference']) : 'General Consultation');

$subtitle = $contextStr . '  |  Total Submissions: ' . $totalSubmissions . '  |  ' . date('F j, Y g:i A');

$pdf = new SimplePdf('Survey Report: ' . $survey['title'], $subtitle);

// Summary of Submissions Table
$tableRows = [];
$idx = 1;
foreach ($submissions as $r) {
    $name = $r['respondent_name'] ?: 'Anonymous Participant';
    $email = $r['respondent_email'] ?: ($r['organization'] ? ('Org: ' . $r['organization']) : '-');
    $submitted = !empty($r['submitted_at']) ? date('M d, Y h:i A', strtotime($r['submitted_at'])) : '-';

    $tableRows[] = [
        (string)$idx++,
        $name,
        $email,
        $submitted
    ];
}

if (empty($tableRows)) {
    $tableRows[] = ['-', 'No responses submitted yet.', '', ''];
}

$pdf->addTable(['#', 'Respondent Name', 'Email / Organization', 'Submitted At'], [30, 190, 200, 120], $tableRows);

$pdf->output('survey-' . $id . '-consultation-report.pdf');
