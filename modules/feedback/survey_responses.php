<?php
/**
 * modules/feedback/survey_responses.php
 * ------------------------------------------------------------------
 * Management view of responses for a single survey.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireRole([ROLE_ADMIN, ROLE_STAFF, ROLE_COMMITTEE]);

$id = (int)($_GET['id'] ?? 0);
$pdo = db();

$stmt = $pdo->prepare('SELECT * FROM surveys WHERE id = :id');
$stmt->execute([':id' => $id]);
$survey = $stmt->fetch();

if (!$survey) {
    setFlash('danger', 'Survey not found.');
    redirect(APP_URL . '/modules/feedback/surveys.php');
}

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM survey_responses WHERE survey_id = :id');
$countStmt->execute([':id' => $id]);
$responseCount = (int)$countStmt->fetchColumn();

$pageTitle  = 'Responses - ' . $survey['title'];
$activeMenu = 'feedback';

include __DIR__ . '/../../layouts/header.php';
?>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../layouts/sidebar.php'; ?>

  <div class="main-content">
    <div class="breadcrumb-bar d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <a href="surveys.php" class="text-decoration-none small no-print"><i class="bi bi-arrow-left"></i> Back to Surveys</a>
        <h5 class="mb-0 mt-1"><i class="bi bi-list-check text-primary"></i> <?= e($survey['title']) ?></h5>
        <small class="text-muted"><?= $responseCount ?> response(s) &middot; <?= statusBadge($survey['status']) ?></small>
      </div>
      <div class="d-flex gap-2 no-print">
        <a href="survey_form.php?id=<?= $id ?>" target="_blank" class="btn btn-outline-secondary btn-sm"><i class="bi bi-box-arrow-up-right"></i> Open Public Form</a>
        <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
        <a href="export_survey_pdf.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
      </div>
    </div>

    <div class="card mb-3 no-print">
      <div class="card-body">
        <input type="text" class="form-control form-control-sm" id="searchInput" placeholder="Search respondent name, email, or response text...">
      </div>
    </div>

    <div class="card">
      <div id="responsesTableWrap">
        <?php include __DIR__ . '/survey_responses_table.php'; ?>
      </div>
    </div>
  </div>
</div>

<script>window.SURVEY_ID = <?= (int)$id ?>;</script>
<?php
$extraJs = [APP_URL . '/assets/js/survey-responses.js'];
include __DIR__ . '/../../layouts/footer.php';
?>
