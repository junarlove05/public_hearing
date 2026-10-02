<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pdo = db();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT s.*,
            h.reference_number hearing_reference,
            h.title hearing_title,
            h.hearing_date,
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
    setFlash('danger', 'Survey not found.');
    redirect(APP_URL . '/modules/feedback/surveys.php');
}

// Fetch submissions
$subStmt = $pdo->prepare(
    'SELECT ss.*, s.organization
     FROM survey_submissions ss
     LEFT JOIN stakeholders s ON s.id = ss.stakeholder_id
     WHERE ss.survey_id = :id
     ORDER BY ss.submitted_at DESC, ss.id DESC'
);
$subStmt->execute([':id' => $id]);
$submissions = $subStmt->fetchAll(PDO::FETCH_ASSOC);
$totalSubmissions = count($submissions);

// Count verified stakeholders vs anonymous
$stakeholderCount = 0;
$anonymousCount = 0;
foreach ($submissions as $sub) {
    if (!empty($sub['stakeholder_id'])) $stakeholderCount++;
    if (empty($sub['respondent_name']) || strtolower($sub['respondent_name']) === 'anonymous participant') $anonymousCount++;
}

// Fetch all questions
$qStmt = $pdo->prepare('SELECT * FROM survey_questions WHERE survey_id = :id ORDER BY sequence_number, id');
$qStmt->execute([':id' => $id]);
$questions = $qStmt->fetchAll(PDO::FETCH_ASSOC);

// Process results for each question
$analytics = [];
$chartData = [];

foreach ($questions as $q) {
    $qid = (int)$q['id'];
    $qType = $q['question_type'];

    if (in_array($qType, ['Single Choice', 'Multiple Choice'], true)) {
        $st = $pdo->prepare(
            'SELECT o.id, o.option_text, COUNT(a.id) AS total
             FROM survey_question_options o
             LEFT JOIN survey_answers a ON a.option_id = o.id
             WHERE o.question_id = :qid
             GROUP BY o.id, o.option_text, o.sequence_number
             ORDER BY o.sequence_number, o.id'
        );
        $st->execute([':qid' => $qid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $totalOptionAnswers = array_sum(array_column($rows, 'total'));
        foreach ($rows as &$r) {
            $r['percentage'] = $totalOptionAnswers > 0
                ? round(($r['total'] / $totalOptionAnswers) * 100, 1)
                : 0;
        }
        unset($r);

        $analytics[$qid] = [
            'type' => 'choice',
            'options' => $rows,
            'total_answers' => $totalOptionAnswers
        ];

        // Prepare Chart.js payload
        $chartData[$qid] = [
            'labels' => array_column($rows, 'option_text'),
            'counts' => array_map('intval', array_column($rows, 'total')),
            'chartType' => count($rows) <= 4 ? 'doughnut' : 'bar'
        ];

    } elseif ($qType === 'Rating') {
        $st = $pdo->prepare(
            'SELECT COUNT(*) total,
                    AVG(numeric_value) average,
                    MIN(numeric_value) minimum,
                    MAX(numeric_value) maximum
             FROM survey_answers
             WHERE question_id = :qid AND numeric_value IS NOT NULL'
        );
        $st->execute([':qid' => $qid]);
        $stats = $st->fetch(PDO::FETCH_ASSOC);

        // Fetch distribution by star (1 to 5)
        $starDist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $stDist = $pdo->prepare(
            'SELECT ROUND(numeric_value) AS star, COUNT(*) AS count
             FROM survey_answers
             WHERE question_id = :qid AND numeric_value BETWEEN 1 AND 5
             GROUP BY ROUND(numeric_value)'
        );
        $stDist->execute([':qid' => $qid]);
        foreach ($stDist->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $starDist[(int)$d['star']] = (int)$d['count'];
        }

        $analytics[$qid] = [
            'type' => 'rating',
            'total' => (int)($stats['total'] ?? 0),
            'average' => $stats['average'] !== null ? round((float)$stats['average'], 2) : 0,
            'min' => $stats['minimum'] !== null ? (float)$stats['minimum'] : null,
            'max' => $stats['maximum'] !== null ? (float)$stats['maximum'] : null,
            'distribution' => $starDist
        ];

    } elseif ($qType === 'Number') {
        $st = $pdo->prepare(
            'SELECT COUNT(*) total,
                    AVG(numeric_value) average,
                    MIN(numeric_value) minimum,
                    MAX(numeric_value) maximum
             FROM survey_answers
             WHERE question_id = :qid AND numeric_value IS NOT NULL'
        );
        $st->execute([':qid' => $qid]);
        $stats = $st->fetch(PDO::FETCH_ASSOC);

        $analytics[$qid] = [
            'type' => 'number',
            'total' => (int)($stats['total'] ?? 0),
            'average' => $stats['average'] !== null ? round((float)$stats['average'], 2) : 0,
            'min' => $stats['minimum'] !== null ? (float)$stats['minimum'] : null,
            'max' => $stats['maximum'] !== null ? (float)$stats['maximum'] : null
        ];

    } else {
        // Text / Long Text
        $st = $pdo->prepare(
            'SELECT a.answer_text, ss.respondent_name, ss.submitted_at
             FROM survey_answers a
             JOIN survey_submissions ss ON ss.id = a.submission_id
             WHERE a.question_id = :qid AND a.answer_text IS NOT NULL AND TRIM(a.answer_text) != ""
             ORDER BY a.id DESC'
        );
        $st->execute([':qid' => $qid]);
        $textAnswers = $st->fetchAll(PDO::FETCH_ASSOC);

        $analytics[$qid] = [
            'type' => 'text',
            'answers' => $textAnswers,
            'count' => count($textAnswers)
        ];
    }
}

// Fetch all answers mapped by submission_id for the modal viewer
$submissionAnswers = [];
if (!empty($submissions)) {
    $subIds = array_column($submissions, 'id');
    $inClause = implode(',', array_map('intval', $subIds));
    $ansStmt = $pdo->query(
        "SELECT a.submission_id, a.question_id, a.answer_text, a.numeric_value,
                o.option_text, q.question_text, q.question_type
         FROM survey_answers a
         JOIN survey_questions q ON q.id = a.question_id
         LEFT JOIN survey_question_options o ON o.id = a.option_id
         WHERE a.submission_id IN ($inClause)
         ORDER BY q.sequence_number, a.id"
    );
    while ($row = $ansStmt->fetch(PDO::FETCH_ASSOC)) {
        $sid = (int)$row['submission_id'];
        $qid = (int)$row['question_id'];
        if (!isset($submissionAnswers[$sid][$qid])) {
            $submissionAnswers[$sid][$qid] = [
                'question' => $row['question_text'],
                'type' => $row['question_type'],
                'values' => []
            ];
        }
        if ($row['option_text'] !== null) {
            $submissionAnswers[$sid][$qid]['values'][] = $row['option_text'];
        } elseif ($row['numeric_value'] !== null) {
            $submissionAnswers[$sid][$qid]['values'][] = (string)$row['numeric_value'];
        } elseif ($row['answer_text'] !== null) {
            $submissionAnswers[$sid][$qid]['values'][] = $row['answer_text'];
        }
    }
}

$pageTitle = 'Survey Analytics · ' . $survey['title'];
$activeMenu = 'feedback';
$activeTab = 'surveys';

include __DIR__ . '/../../layouts/header.php';
?>
<link rel="stylesheet" href="<?= e(APP_URL . '/assets/css/lph-complete-modules.css') ?>">

<style>
.lphwf-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1.25rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid #e2e8f0;
}
.lphwf-eyebrow {
    color: #a97900;
    font-size: 0.78rem;
    font-weight: 750;
    text-transform: uppercase;
    letter-spacing: 0.75px;
    margin-bottom: 0.25rem;
}
.lphwf-head h1 {
    color: #0F2137;
    font-size: 1.6rem;
    font-weight: 800;
    margin: 0 0 0.35rem 0;
}
.lphwf-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-top: 3.5px solid #a97900 !important;
    border-radius: 12px;
    box-shadow: 0 4px 18px rgba(10, 22, 40, 0.05);
    overflow: hidden;
    margin-bottom: 1.25rem;
}
.lphwf-card .card-header {
    background: #ffffff;
    border-bottom: 1px solid #e2e8f0;
    color: #0F2137;
    font-weight: 750;
    padding: 0.85rem 1.25rem;
}

/* Stat Cards */
.lphwf-stat {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-bottom: 3.5px solid #a97900 !important;
    border-radius: 12px;
    padding: 0.85rem 1rem;
    box-shadow: 0 2px 8px rgba(10, 22, 40, 0.04);
}
.lphwf-stat i {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 42px;
    height: 42px;
    min-width: 42px;
    color: #1a3a5c;
    background: #eef5fb;
    border-radius: 10px;
    font-size: 1.25rem;
}
.lphwf-stat strong {
    display: block;
    color: #0a1628;
    font-size: 1.35rem;
    font-weight: 750;
    line-height: 1.2;
}
.lphwf-stat small {
    display: block;
    color: #64748b;
    font-size: 0.68rem;
    font-weight: 650;
    text-transform: uppercase;
}

/* Option Progress Bars */
.option-bar-wrap {
    margin-bottom: 0.75rem;
}
.option-bar-label {
    display: flex;
    justify-content: space-between;
    font-size: 0.85rem;
    font-weight: 600;
    color: #0F2137;
    margin-bottom: 0.25rem;
}
.progress {
    height: 8px;
    background-color: #f1f5f9;
    border-radius: 99px;
    overflow: hidden;
}
.progress-bar-gold {
    background-color: #a97900;
}

/* Star Rating Rating Visuals */
.rating-hero-score {
    font-size: 2.75rem;
    font-weight: 800;
    color: #0F2137;
    line-height: 1;
}
.star-rating-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.35rem 0.65rem;
    background: #fffbeb;
    border: 1px solid #fef3c7;
    border-radius: 8px;
    color: #b45309;
    font-weight: 700;
}
</style>

<div class="app-wrapper">
  <?php include __DIR__ . '/../../layouts/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../layouts/top_controls.php'; ?>

    <!-- Breadcrumb & Header -->
    <div class="lphwf-head">
      <div>
        <div class="lphwf-eyebrow">
          <i class="bi bi-bar-chart-line me-1"></i> Subsystem #7 · Step 5 · Survey Analytics
        </div>
        <h1><?= e($survey['title']) ?></h1>
        <p>
          <?php if ($survey['hearing_reference']): ?>
            <span class="badge" style="background: rgba(169, 121, 0, 0.12); color: #8A6400; border: 1px solid rgba(169, 121, 0, 0.3); font-size: 0.75rem;">
              <i class="bi bi-calendar-event me-1"></i>Hearing: <?= e($survey['hearing_reference']) ?>
            </span>
          <?php endif; ?>
          <?php if ($survey['legislative_reference']): ?>
            <span class="badge bg-light text-dark border font-monospace ms-1" style="font-size: 0.75rem;">
              <?= e($survey['legislative_reference']) ?>
            </span>
          <?php endif; ?>
          <span class="badge bg-light text-secondary border ms-1" style="font-size: 0.75rem;">
            <?= e($survey['status']) ?>
          </span>
          <span class="text-muted small ms-2">
            <?= count($questions) ?> questions · <?= $totalSubmissions ?> recorded response(s)
          </span>
        </p>
      </div>
      <div class="d-flex gap-2 flex-wrap align-items-center">
        <a class="btn btn-outline-secondary" href="surveys.php">
          <i class="bi bi-arrow-left me-1"></i> All Surveys
        </a>
        <a class="btn btn-outline-primary" href="survey_take.php?id=<?= (int)$id ?>" target="_blank">
          <i class="bi bi-box-arrow-up-right me-1"></i> Public Form
        </a>
        <a class="btn btn-outline-secondary" href="export_survey_pdf.php?id=<?= (int)$id ?>" target="_blank">
          <i class="bi bi-file-earmark-pdf me-1"></i> Export PDF
        </a>
      </div>
    </div>

    <!-- Integrated Subsystem #7 Tabs -->
    <?php include __DIR__ . '/tabs.php'; ?>

    <!-- Top KPI Stats Strip -->
    <div class="row g-3 mb-3">
      <div class="col-6 col-md-3">
        <div class="lphwf-stat h-100">
          <i class="bi bi-people text-primary"></i>
          <div>
            <strong><?= $totalSubmissions ?></strong>
            <small>Total Responses</small>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="lphwf-stat h-100">
          <i class="bi bi-person-check text-success" style="background: #ecfdf5 !important; color: #059669 !important;"></i>
          <div>
            <strong class="text-success"><?= $stakeholderCount ?></strong>
            <small>Verified Stakeholders</small>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="lphwf-stat h-100">
          <i class="bi bi-incognito text-secondary" style="background: #f1f5f9 !important; color: #64748b !important;"></i>
          <div>
            <strong class="text-secondary"><?= $anonymousCount ?></strong>
            <small>Anonymous Participants</small>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="lphwf-stat h-100">
          <i class="bi bi-patch-question text-warning" style="background: #fffbeb !important; color: #d97706 !important;"></i>
          <div>
            <strong style="color: #d97706;"><?= count($questions) ?></strong>
            <small>Survey Questions</small>
          </div>
        </div>
      </div>
    </div>

    <!-- =================================================================== -->
    <!-- QUESTION-BY-QUESTION ANALYTICS CARDS                                -->
    <!-- =================================================================== -->
    <h5 class="fw-bold text-dark mb-3 mt-4">
      <i class="bi bi-pie-chart me-1" style="color: #a97900;"></i> Question Results &amp; Response Distribution
    </h5>

    <?php if (empty($questions)): ?>
      <div class="alert alert-light border text-center py-4 text-muted">
        No questions configured for this survey.
      </div>
    <?php endif; ?>

    <?php foreach ($questions as $idx => $q): ?>
      <?php
        $qid = (int)$q['id'];
        $data = $analytics[$qid] ?? null;
      ?>
      <div class="card lphwf-card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div>
            <span class="badge" style="background: rgba(169, 121, 0, 0.12); color: #8A6400; border: 1px solid rgba(169, 121, 0, 0.3); font-size: 0.72rem; font-weight: 750; margin-right: 6px;">
              Question <?= $idx + 1 ?>
            </span>
            <span class="fw-bold fs-6 text-dark"><?= e($q['question_text']) ?></span>
            <?php if ((int)$q['is_required'] === 1): ?>
              <span class="text-danger small ms-1" title="Mandatory question">*</span>
            <?php endif; ?>
          </div>
          <span class="badge bg-light text-secondary border font-monospace" style="font-size: 0.7rem;">
            <?= e($q['question_type']) ?>
          </span>
        </div>

        <div class="card-body p-3">
          <?php if (!$data): ?>
            <p class="text-muted small mb-0">No data collected for this question.</p>

          <?php elseif ($data['type'] === 'choice'): ?>
            <!-- Choice Options: Horizontal Progress & Chart.js -->
            <div class="row g-3 align-items-center">
              <div class="col-lg-7">
                <?php if ($data['total_answers'] === 0): ?>
                  <p class="text-muted small mb-0">No responses recorded yet.</p>
                <?php else: ?>
                  <?php foreach ($data['options'] as $opt): ?>
                    <div class="option-bar-wrap">
                      <div class="option-bar-label">
                        <span><?= e($opt['option_text']) ?></span>
                        <span class="text-muted">
                          <strong><?= (int)$opt['total'] ?></strong> (<?= $opt['percentage'] ?>%)
                        </span>
                      </div>
                      <div class="progress">
                        <div class="progress-bar progress-bar-gold" role="progressbar" style="width: <?= $opt['percentage'] ?>%;"></div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
              <div class="col-lg-5 text-center">
                <?php if ($data['total_answers'] > 0): ?>
                  <div style="max-height: 200px; position: relative; display: flex; align-items: center; justify-content: center;">
                    <canvas id="chart_<?= $qid ?>" style="max-height: 190px; max-width: 100%;"></canvas>
                  </div>
                <?php else: ?>
                  <span class="text-muted small">Chart will display when responses are received</span>
                <?php endif; ?>
              </div>
            </div>

          <?php elseif ($data['type'] === 'rating'): ?>
            <!-- 5-Star Rating Visuals -->
            <div class="row g-3 align-items-center">
              <div class="col-md-4 text-center border-end">
                <div class="rating-hero-score"><?= $data['average'] > 0 ? $data['average'] : '—' ?></div>
                <div class="text-warning fs-5 mb-1">
                  <?php for ($i = 1; $i <= 5; $i++): ?>
                    <i class="bi <?= $i <= round($data['average']) ? 'bi-star-fill' : 'bi-star' ?>"></i>
                  <?php endfor; ?>
                </div>
                <div class="small text-muted">Out of 5 Stars · <?= $data['total'] ?> rating(s)</div>
              </div>

              <div class="col-md-8">
                <?php foreach ([5, 4, 3, 2, 1] as $star): ?>
                  <?php
                    $count = $data['distribution'][$star] ?? 0;
                    $pct = $data['total'] > 0 ? round(($count / $data['total']) * 100, 1) : 0;
                  ?>
                  <div class="d-flex align-items-center gap-2 mb-1.5" style="font-size: 0.82rem;">
                    <span style="min-width: 55px;" class="fw-semibold text-secondary"><?= $star ?> Stars</span>
                    <div class="progress flex-grow-1" style="height: 7px;">
                      <div class="progress-bar bg-warning" role="progressbar" style="width: <?= $pct ?>%;"></div>
                    </div>
                    <span style="min-width: 65px;" class="text-end text-muted small"><?= $count ?> (<?= $pct ?>%)</span>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>

          <?php elseif ($data['type'] === 'number'): ?>
            <div class="row text-center py-2">
              <div class="col-3">
                <strong class="fs-4 text-dark d-block"><?= $data['total'] ?></strong>
                <small class="text-muted text-uppercase" style="font-size: 0.68rem;">Entries</small>
              </div>
              <div class="col-3 border-start">
                <strong class="fs-4 text-primary d-block"><?= $data['average'] ?: '—' ?></strong>
                <small class="text-muted text-uppercase" style="font-size: 0.68rem;">Average</small>
              </div>
              <div class="col-3 border-start">
                <strong class="fs-4 text-dark d-block"><?= $data['min'] !== null ? $data['min'] : '—' ?></strong>
                <small class="text-muted text-uppercase" style="font-size: 0.68rem;">Minimum</small>
              </div>
              <div class="col-3 border-start">
                <strong class="fs-4 text-dark d-block"><?= $data['max'] !== null ? $data['max'] : '—' ?></strong>
                <small class="text-muted text-uppercase" style="font-size: 0.68rem;">Maximum</small>
              </div>
            </div>

          <?php elseif ($data['type'] === 'text'): ?>
            <!-- Text Feedback Responses List -->
            <?php if (empty($data['answers'])): ?>
              <p class="text-muted small mb-0">No text comments submitted yet.</p>
            <?php else: ?>
              <div class="list-group list-group-flush">
                <?php foreach (array_slice($data['answers'], 0, 15) as $ans): ?>
                  <div class="list-group-item px-0 py-2.5">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                      <strong class="small text-dark" style="font-size: 0.82rem;">
                        <i class="bi bi-chat-left-quote text-muted me-1"></i>
                        <?= e($ans['respondent_name'] ?: 'Anonymous Participant') ?>
                      </strong>
                      <span class="text-muted" style="font-size: 0.72rem;">
                        <?= !empty($ans['submitted_at']) ? date('M d, Y h:i A', strtotime($ans['submitted_at'])) : '' ?>
                      </span>
                    </div>
                    <div class="small text-secondary bg-light p-2.5 rounded-2 border">
                      <?= nl2br(e($ans['answer_text'])) ?>
                    </div>
                  </div>
                <?php endforeach; ?>
                <?php if (count($data['answers']) > 15): ?>
                  <div class="text-center pt-2">
                    <small class="text-muted">Showing 15 of <?= count($data['answers']) ?> comments. See Submissions Log below for all entries.</small>
                  </div>
                <?php endif; ?>
              </div>
            <?php endif; ?>

          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <!-- =================================================================== -->
    <!-- INDIVIDUAL SUBMISSIONS LOG                                          -->
    <!-- =================================================================== -->
    <div class="card lphwf-card mt-4 mb-4">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center">
          <span style="display:inline-block;width:4px;height:16px;background:#a97900;border-radius:2px;margin-right:8px;"></span>
          <span class="fs-6">Respondent Submissions Log (<?= $totalSubmissions ?>)</span>
        </div>
        <a class="btn btn-sm btn-outline-secondary" href="export_survey_pdf.php?id=<?= (int)$id ?>">
          <i class="bi bi-filetype-pdf me-1"></i> Download PDF Report
        </a>
      </div>

      <div class="table-responsive">
        <table class="table lphwf-table table-hover mb-0">
          <thead>
            <tr>
              <th style="width: 50px;">#</th>
              <th>Respondent</th>
              <th>Email / Affiliation</th>
              <th>Stakeholder Status</th>
              <th>Submission Date</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($submissions)): ?>
              <tr>
                <td colspan="6" class="text-center py-4 text-muted">
                  No responses recorded yet. Share the survey link or QR code with hearing attendees to collect data.
                </td>
              </tr>
            <?php endif; ?>

            <?php foreach ($submissions as $sidx => $sub): ?>
              <?php
                $sid = (int)$sub['id'];
                $answersData = $submissionAnswers[$sid] ?? [];
              ?>
              <tr>
                <td class="font-monospace small text-muted"><?= $sidx + 1 ?></td>
                <td>
                  <strong><?= e($sub['respondent_name'] ?: 'Anonymous Participant') ?></strong>
                </td>
                <td class="small text-muted">
                  <?= e($sub['respondent_email'] ?: ($sub['organization'] ? ('Org: ' . $sub['organization']) : '—')) ?>
                </td>
                <td>
                  <?php if (!empty($sub['stakeholder_id'])): ?>
                    <span class="badge" style="background: rgba(169, 121, 0, 0.12); color: #8A6400; border: 1px solid rgba(169, 121, 0, 0.3); font-size: 0.72rem;">
                      <i class="bi bi-check-circle-fill me-1"></i>Verified Stakeholder
                    </span>
                  <?php else: ?>
                    <span class="badge bg-light text-secondary border" style="font-size: 0.72rem;">
                      Public Participant
                    </span>
                  <?php endif; ?>
                </td>
                <td class="small text-muted">
                  <?= !empty($sub['submitted_at']) ? date('M d, Y h:i A', strtotime($sub['submitted_at'])) : '—' ?>
                </td>
                <td class="text-end">
                  <button type="button" class="btn btn-sm btn-outline-primary btn-view-submission"
                          data-sid="<?= $sid ?>"
                          data-name="<?= e($sub['respondent_name'] ?: 'Anonymous Participant') ?>"
                          data-time="<?= !empty($sub['submitted_at']) ? date('M d, Y h:i A', strtotime($sub['submitted_at'])) : '' ?>"
                          style="font-size: 0.76rem; padding: 2px 8px;">
                    <i class="bi bi-eye me-1"></i> View Answers
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<!-- ======================================================================= -->
<!-- MODAL: VIEW INDIVIDUAL RESPONDENT SUBMISSION                            -->
<!-- ======================================================================= -->
<div class="modal fade" id="submissionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: 600px;">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 14px; overflow: hidden;">
      <div class="modal-header py-2.5 px-3.5" style="background: #0F2137; border-bottom: 2px solid #a97900; color: #ffffff;">
        <div>
          <h6 class="modal-title fw-bold mb-0 text-white" id="subModalRespondent">Respondent Answers</h6>
          <small class="text-white-50" id="subModalTime">Submitted Date</small>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-3.5" id="subModalBody">
        <!-- Injected via JavaScript -->
      </div>
      <div class="modal-footer py-2 px-3 bg-light border-top">
        <button type="button" class="btn btn-sm btn-primary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Chart.js and Vendor Assets -->
<script src="<?= e(vendorAsset('chart.js/chart.umd.js', 'https://cdn.jsdelivr.net/npm/chart.js')) ?>"></script>

<script>
window.SURVEY_CHARTS = <?= json_encode($chartData) ?>;
window.SUBMISSION_ANSWERS = <?= json_encode($submissionAnswers) ?>;

document.addEventListener('DOMContentLoaded', function() {
  // 1. Initialize Chart.js for Choice Questions
  if (window.SURVEY_CHARTS && typeof Chart !== 'undefined') {
    Object.keys(window.SURVEY_CHARTS).forEach(qid => {
      const el = document.getElementById('chart_' + qid);
      if (!el) return;

      const config = window.SURVEY_CHARTS[qid];
      const palette = ['#a97900', '#0F2137', '#2563eb', '#059669', '#d97706', '#dc2626', '#8b5cf6'];

      if (config.chartType === 'doughnut') {
        new Chart(el, {
          type: 'doughnut',
          data: {
            labels: config.labels,
            datasets: [{
              data: config.counts,
              backgroundColor: palette.slice(0, config.labels.length),
              borderWidth: 2,
              borderColor: '#ffffff'
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: {
                position: 'bottom',
                labels: { font: { size: 10 }, boxWidth: 10, padding: 8 }
              }
            },
            cutout: '55%'
          }
        });
      } else {
        new Chart(el, {
          type: 'bar',
          data: {
            labels: config.labels,
            datasets: [{
              data: config.counts,
              backgroundColor: '#a97900',
              borderRadius: 4
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
              y: { beginAtZero: true, ticks: { precision: 0 } },
              x: { ticks: { font: { size: 9 } } }
            }
          }
        });
      }
    });
  }

  // 2. Individual Submission Answers Modal
  const subModalEl = document.getElementById('submissionModal');
  const subModal = subModalEl ? new bootstrap.Modal(subModalEl) : null;

  document.querySelectorAll('.btn-view-submission').forEach(btn => {
    btn.addEventListener('click', function() {
      const sid = parseInt(this.getAttribute('data-sid'), 10);
      const name = this.getAttribute('data-name');
      const time = this.getAttribute('data-time');

      document.getElementById('subModalRespondent').textContent = name;
      document.getElementById('subModalTime').textContent = time ? ('Submitted on ' + time) : '';

      const body = document.getElementById('subModalBody');
      body.innerHTML = '';

      const answers = window.SUBMISSION_ANSWERS[sid] || {};
      const qKeys = Object.keys(answers);

      if (qKeys.length === 0) {
        body.innerHTML = '<p class="text-muted small">No specific answer details found for this submission.</p>';
      } else {
        qKeys.forEach((qid, idx) => {
          const item = answers[qid];
          const div = document.createElement('div');
          div.className = 'border rounded-2 p-2.5 mb-2.5 bg-light';

          let valHtml = '';
          if (!item.values || item.values.length === 0) {
            valHtml = '<span class="text-muted fst-italic">No answer</span>';
          } else if (item.type === 'Rating') {
            const rVal = item.values[0];
            valHtml = `<span class="badge bg-warning text-dark">${rVal} ★</span>`;
          } else {
            valHtml = item.values.map(v => `<div class="fw-semibold text-dark">${escapeHtml(v)}</div>`).join('');
          }

          div.innerHTML = `
            <div class="small text-secondary mb-1">
              <strong>${idx + 1}.</strong> ${escapeHtml(item.question)}
            </div>
            <div class="small">${valHtml}</div>
          `;
          body.appendChild(div);
        });
      }

      if (subModal) subModal.show();
    });
  });

  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }
});
</script>

<?php include __DIR__ . '/../../layouts/footer.php'; ?>
