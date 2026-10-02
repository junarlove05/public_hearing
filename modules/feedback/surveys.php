<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';

requireLogin();

$pdo = db();
$pageTitle = 'Consultation Surveys';
$activeMenu = 'feedback';
$activeTab = 'surveys';
$canManageSurveys = hasPermission('lph.surveys.manage') || canManage();

$hearings = $pdo->query("SELECT id, reference_number, title FROM hearings ORDER BY hearing_date DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$items = $pdo->query("SELECT id, reference_number, title FROM legislative_items WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);

$surveys = $pdo->query(
    "SELECT s.*,
            h.reference_number hearing_reference,
            h.title hearing_title,
            li.reference_number legislative_reference,
            li.title legislative_title,
            (SELECT COUNT(*) FROM survey_questions q WHERE q.survey_id = s.id) AS question_count,
            (SELECT COUNT(*) FROM survey_submissions ss WHERE ss.survey_id = s.id) AS submission_count
     FROM surveys s
     LEFT JOIN hearings h ON h.id = s.hearing_id
     LEFT JOIN legislative_items li ON li.id = s.legislative_item_id
     ORDER BY s.created_at DESC, s.id DESC"
)->fetchAll(PDO::FETCH_ASSOC);

// Calculate KPI statistics
$now = time();
$totalSurveys = count($surveys);
$activeSurveys = 0;
$upcomingSurveys = 0;
$closedSurveys = 0;
$draftSurveys = 0;
$totalResponses = 0;

foreach ($surveys as &$s) {
    $totalResponses += (int)$s['submission_count'];

    $opensAt = !empty($s['opens_at']) ? strtotime($s['opens_at']) : null;
    $closesAt = !empty($s['closes_at']) ? strtotime($s['closes_at']) : null;

    if ($s['status'] === 'Draft') {
        $s['computed_status'] = 'Draft';
        $draftSurveys++;
    } elseif ($s['status'] === 'Archived') {
        $s['computed_status'] = 'Archived';
        $closedSurveys++;
    } elseif ($s['status'] === 'Closed') {
        $s['computed_status'] = 'Closed';
        $closedSurveys++;
    } else {
        // Active status
        if ($opensAt && $now < $opensAt) {
            $s['computed_status'] = 'Scheduled';
            $upcomingSurveys++;
        } elseif ($closesAt && $now > $closesAt) {
            $s['computed_status'] = 'Expired';
            $closedSurveys++;
        } else {
            $s['computed_status'] = 'Open';
            $activeSurveys++;
        }
    }
}
unset($s);

include __DIR__ . '/../../layouts/header.php';
?>
<link rel="stylesheet" href="<?= e(APP_URL . '/assets/css/lph-complete-modules.css') ?>">

<style>
/* Module Page & Header */
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
    font-size: 1.65rem;
    font-weight: 800;
    margin: 0 0 0.35rem 0;
    letter-spacing: -0.3px;
}
.lphwf-head p {
    color: #64748b;
    font-size: 0.88rem;
    margin: 0;
}

/* Nav Tabs Dark Gold Styling */
.nav-tabs {
    border-bottom: 2px solid rgba(169, 121, 0, 0.3) !important;
    gap: 0.35rem;
}
.nav-tabs .nav-link {
    border: none !important;
    border-bottom: 3px solid transparent !important;
    color: #475569 !important;
    font-weight: 650 !important;
    font-size: 0.84rem !important;
    padding: 0.55rem 1rem !important;
    background: transparent !important;
    transition: all 0.2s ease !important;
    border-radius: 8px 8px 0 0 !important;
}
.nav-tabs .nav-link:hover {
    color: #a97900 !important;
    background: rgba(169, 121, 0, 0.05) !important;
    border-bottom: 3px solid rgba(169, 121, 0, 0.4) !important;
}
.nav-tabs .nav-link.active {
    color: #a97900 !important;
    font-weight: 800 !important;
    border-bottom: 3px solid #a97900 !important;
    background: rgba(169, 121, 0, 0.07) !important;
}

/* KPI Summary Stat Cards */
.lphwf-stats-row {
    display: flex;
    flex-wrap: wrap;
    margin-bottom: 1.25rem;
}
.lphwf-stat {
    display: flex !important;
    align-items: center !important;
    gap: 0.85rem !important;
    background: #ffffff !important;
    border: 1px solid #e2e8f0 !important;
    border-bottom: 3.5px solid #a97900 !important;
    border-radius: 12px !important;
    padding: 0.85rem 1rem !important;
    box-shadow: 0 2px 8px rgba(10, 22, 40, 0.04) !important;
    transition: all 0.2s ease !important;
}
.lphwf-stat:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(10, 22, 40, 0.08) !important;
}
.lphwf-stat i {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 42px !important;
    height: 42px !important;
    min-width: 42px !important;
    flex-shrink: 0 !important;
    color: #1a3a5c !important;
    background: #eef5fb !important;
    border-radius: 10px !important;
    font-size: 1.25rem !important;
}
.lphwf-stat strong {
    display: block !important;
    color: #0a1628 !important;
    font-size: 1.35rem !important;
    font-weight: 750 !important;
    line-height: 1.2 !important;
    margin-bottom: 2px !important;
}
.lphwf-stat small {
    display: block !important;
    color: #64748b !important;
    font-size: 0.68rem !important;
    font-weight: 650 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.3px !important;
}

/* Card Styling */
.lphwf-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-top: 3.5px solid #a97900 !important;
    border-radius: 12px;
    box-shadow: 0 4px 18px rgba(10, 22, 40, 0.05);
    overflow: hidden;
}
.lphwf-card .card-header {
    background: #ffffff;
    border-bottom: 1px solid #e2e8f0;
    color: #0F2137;
    font-weight: 750;
    padding: 0.85rem 1.25rem;
}

/* Primary Button Theming */
.btn-primary {
    background-color: #0F2137 !important;
    border-color: #0F2137 !important;
    color: #ffffff !important;
    box-shadow: 0 2px 6px rgba(15, 33, 55, 0.2) !important;
}
.btn-primary:hover, .btn-primary:focus {
    background-color: #1A3A5C !important;
    border-color: #1A3A5C !important;
}

/* Question Builder Card */
.survey-q-item {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-left: 3px solid #a97900;
    border-radius: 10px;
    padding: 0.85rem;
    margin-bottom: 0.75rem;
    transition: all 0.2s ease;
}
.survey-q-item:hover {
    background: #ffffff;
    box-shadow: 0 4px 12px rgba(15, 33, 55, 0.06);
}

/* Table Design */
.lphwf-table th {
    background: #f8fafc;
    color: #475569;
    font-size: 0.76rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    border-bottom: 1px solid #e2e8f0;
    padding: 0.75rem 1rem;
}
.lphwf-table td {
    padding: 0.85rem 1rem;
    vertical-align: middle;
    border-bottom: 1px solid #f1f5f9;
}
</style>

<div class="app-wrapper">
  <?php include __DIR__ . '/../../layouts/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../layouts/top_controls.php'; ?>

    <div class="lphwf-head">
      <div>
        <div class="lphwf-eyebrow"><i class="bi bi-ui-checks-grid me-1"></i> Subsystem #7 · Step 5 · Surveys</div>
        <h1>Consultation Surveys</h1>
        <p>Create structured questionnaires linked to public hearings or ordinances and collect responses from citizens and stakeholders.</p>
      </div>
      <div class="d-flex gap-2 align-items-center">
        <?php if ($canManageSurveys): ?>
          <button class="btn btn-primary" id="btnNewSurvey">
            <i class="bi bi-plus-circle me-1"></i> Create Survey
          </button>
        <?php endif; ?>
      </div>
    </div>

    <!-- Integrated Subsystem #7 Tabs -->
    <?php include __DIR__ . '/tabs.php'; ?>

    <!-- KPI Summary Cards Strip -->
    <div class="row g-3 mb-3 lphwf-stats-row">
      <div class="col-6 col-md-4 col-xl">
        <div class="lphwf-stat w-100 h-100">
          <i class="bi bi-ui-checks"></i>
          <div>
            <strong><?= $totalSurveys ?></strong>
            <small>Total Surveys</small>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-xl">
        <div class="lphwf-stat w-100 h-100">
          <i class="bi bi-broadcast text-success" style="background: #ecfdf5 !important; color: #059669 !important;"></i>
          <div>
            <strong class="text-success"><?= $activeSurveys ?></strong>
            <small>Open Now</small>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-xl">
        <div class="lphwf-stat w-100 h-100">
          <i class="bi bi-calendar-event text-warning" style="background: #fffbeb !important; color: #d97706 !important;"></i>
          <div>
            <strong style="color: #d97706;"><?= $upcomingSurveys ?></strong>
            <small>Scheduled</small>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-xl">
        <div class="lphwf-stat w-100 h-100">
          <i class="bi bi-archive text-secondary" style="background: #f1f5f9 !important; color: #64748b !important;"></i>
          <div>
            <strong class="text-secondary"><?= $closedSurveys ?></strong>
            <small>Closed / Expired</small>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-xl">
        <div class="lphwf-stat w-100 h-100">
          <i class="bi bi-people text-info" style="background: #f0fdfa !important; color: #0d9488 !important;"></i>
          <div>
            <strong style="color: #0d9488;"><?= $totalResponses ?></strong>
            <small>Total Responses</small>
          </div>
        </div>
      </div>
    </div>

    <!-- Survey Register Card -->
    <div class="card lphwf-card mb-4">
      <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center">
          <span style="display:inline-block;width:4px;height:16px;background:#a97900;border-radius:2px;margin-right:8px;"></span>
          <span class="fs-6">Survey Register &amp; Public Forms</span>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <!-- Status Filters -->
          <div class="btn-group btn-group-sm" id="surveyFilterGroup">
            <button type="button" class="btn btn-outline-secondary active" data-filter="all">All</button>
            <button type="button" class="btn btn-outline-secondary" data-filter="open">Open Now</button>
            <button type="button" class="btn btn-outline-secondary" data-filter="scheduled">Scheduled</button>
            <button type="button" class="btn btn-outline-secondary" data-filter="closed">Closed</button>
            <button type="button" class="btn btn-outline-secondary" data-filter="draft">Draft</button>
          </div>
          <!-- Live Search Input -->
          <div class="input-group input-group-sm" style="width: 220px;">
            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
            <input type="text" class="form-control border-start-0 ps-0" id="surveySearchInput" placeholder="Search surveys...">
          </div>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table lphwf-table table-hover mb-0" id="surveyTable">
          <thead>
            <tr>
              <th style="min-width: 220px;">Survey Title &amp; Description</th>
              <th style="min-width: 170px;">Linked Context</th>
              <th style="min-width: 160px;">Response Window</th>
              <th style="min-width: 100px;">Status</th>
              <th class="text-center" style="min-width: 80px;">Questions</th>
              <th class="text-center" style="min-width: 90px;">Responses</th>
              <th class="text-end" style="min-width: 180px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($surveys)): ?>
              <tr id="noSurveysRow">
                <td colspan="7" class="text-center py-5 text-muted">
                  <i class="bi bi-ui-checks-grid display-6 d-block mb-2 text-secondary opacity-50"></i>
                  <strong class="d-block text-dark">No consultation surveys created yet.</strong>
                  <span class="small">Click "Create Survey" to design structured questions for hearing attendees.</span>
                </td>
              </tr>
            <?php endif; ?>

            <?php foreach ($surveys as $s): ?>
              <?php
                $computed = $s['computed_status'];
                $filterTag = strtolower($computed);
                if (in_array($computed, ['Expired', 'Archived', 'Closed'])) $filterTag = 'closed';

                $searchCorpus = strtolower(
                    $s['title'] . ' ' .
                    ($s['description'] ?? '') . ' ' .
                    ($s['hearing_reference'] ?? '') . ' ' .
                    ($s['hearing_title'] ?? '') . ' ' .
                    ($s['legislative_reference'] ?? '') . ' ' .
                    ($s['legislative_title'] ?? '')
                );

                $publicUrl = APP_URL . '/modules/feedback/survey_take.php?id=' . (int)$s['id'] . '&qr=1';
              ?>
              <tr class="survey-row" data-filter="<?= e($filterTag) ?>" data-search="<?= e($searchCorpus) ?>">
                <td>
                  <strong class="d-block text-dark" style="font-size: 0.92rem;"><?= e($s['title']) ?></strong>
                  <div class="small text-muted" style="line-height: 1.35;">
                    <?= e(mb_strimwidth((string)($s['description'] ?? ''), 0, 95, '…')) ?: '<span class="fst-italic opacity-75">No description</span>' ?>
                  </div>
                </td>
                <td>
                  <?php if (!empty($s['hearing_reference'])): ?>
                    <span class="badge" style="background: rgba(169, 121, 0, 0.1); color: #8A6400; border: 1px solid rgba(169, 121, 0, 0.25); border-radius: 6px; font-size: 0.72rem;">
                      <i class="bi bi-calendar-check me-1"></i><?= e($s['hearing_reference']) ?>
                    </span>
                    <div class="small text-muted text-truncate mt-1" style="max-width: 180px;" title="<?= e($s['hearing_title'] ?? '') ?>">
                      <?= e($s['hearing_title'] ?? '') ?>
                    </div>
                  <?php elseif (!empty($s['legislative_reference'])): ?>
                    <span class="badge bg-light text-dark border" style="border-radius: 6px; font-size: 0.72rem;">
                      <i class="bi bi-file-earmark-text me-1"></i><?= e($s['legislative_reference']) ?>
                    </span>
                    <div class="small text-muted text-truncate mt-1" style="max-width: 180px;" title="<?= e($s['legislative_title'] ?? '') ?>">
                      <?= e($s['legislative_title'] ?? '') ?>
                    </div>
                  <?php else: ?>
                    <span class="badge bg-light text-secondary border" style="font-size: 0.72rem;">
                      <i class="bi bi-globe2 me-1"></i>General Consultation
                    </span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="small" style="font-weight: 600; color: #0F2137;">
                    <i class="bi bi-box-arrow-in-right text-muted me-1"></i>
                    <?= !empty($s['opens_at']) ? formatDateTime($s['opens_at']) : 'Open immediately' ?>
                  </div>
                  <div class="small text-muted mt-0.5">
                    <i class="bi bi-box-arrow-right text-muted me-1"></i>
                    <?= !empty($s['closes_at']) ? ('Closes ' . formatDateTime($s['closes_at'])) : 'No deadline' ?>
                  </div>
                </td>
                <td>
                  <?php if ($computed === 'Open'): ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.74rem; padding: 4px 8px; border-radius: 6px;">
                      <i class="bi bi-broadcast me-1"></i>Open Now
                    </span>
                  <?php elseif ($computed === 'Scheduled'): ?>
                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle" style="font-size: 0.74rem; padding: 4px 8px; border-radius: 6px;">
                      <i class="bi bi-clock-history me-1"></i>Scheduled
                    </span>
                  <?php elseif ($computed === 'Expired'): ?>
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle" style="font-size: 0.74rem; padding: 4px 8px; border-radius: 6px;">
                      <i class="bi bi-hourglass-bottom me-1"></i>Expired
                    </span>
                  <?php elseif ($computed === 'Closed' || $computed === 'Archived'): ?>
                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle" style="font-size: 0.74rem; padding: 4px 8px; border-radius: 6px;">
                      <i class="bi bi-lock me-1"></i><?= e($computed) ?>
                    </span>
                  <?php else: ?>
                    <span class="badge bg-light text-dark border" style="font-size: 0.74rem; padding: 4px 8px; border-radius: 6px;">
                      <i class="bi bi-pencil me-1"></i>Draft
                    </span>
                  <?php endif; ?>
                </td>
                <td class="text-center">
                  <span class="badge bg-light text-dark border px-2 py-1" style="font-size: 0.75rem;">
                    <?= (int)$s['question_count'] ?>
                  </span>
                </td>
                <td class="text-center">
                  <a href="survey_results.php?id=<?= (int)$s['id'] ?>" class="badge text-decoration-none px-2 py-1" style="background: rgba(169, 121, 0, 0.12); color: #8A6400; border: 1px solid rgba(169, 121, 0, 0.3); font-size: 0.75rem; font-weight: 700;">
                    <?= (int)$s['submission_count'] ?>
                  </a>
                </td>
                <td class="text-end">
                  <div class="btn-group btn-group-sm">
                    <!-- Share / QR Code Modal Trigger -->
                    <button type="button" class="btn btn-outline-secondary btn-share-survey"
                            data-id="<?= (int)$s['id'] ?>"
                            data-title="<?= e($s['title']) ?>"
                            data-url="<?= e($publicUrl) ?>"
                            title="Share Link &amp; QR Code">
                      <i class="bi bi-qr-code"></i>
                    </button>

                    <!-- Preview / Take Form -->
                    <a href="survey_take.php?id=<?= (int)$s['id'] ?>" target="_blank" class="btn btn-outline-secondary" title="Open Public Survey Form">
                      <i class="bi bi-box-arrow-up-right"></i>
                    </a>

                    <!-- Results & Analytics -->
                    <a href="survey_results.php?id=<?= (int)$s['id'] ?>" class="btn btn-outline-secondary" title="View Survey Analytics &amp; Results">
                      <i class="bi bi-bar-chart-line"></i>
                    </a>

                    <?php if ($canManageSurveys): ?>
                      <!-- Edit Survey Modal Trigger -->
                      <button type="button" class="btn btn-outline-primary btn-edit-survey"
                              data-id="<?= (int)$s['id'] ?>"
                              title="Edit Survey &amp; Questions">
                        <i class="bi bi-pencil-square"></i>
                      </button>

                    <?php endif; ?>
                  </div>
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
<!-- SHARE & QR CODE MODAL FOR HEARINGS / ATTENDEES -->
<!-- ======================================================================= -->
<div class="modal fade" id="shareModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width: 480px;">
    <div class="modal-content border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
      <div class="modal-header py-3 px-4 bg-light border-bottom">
        <div class="d-flex align-items-center gap-2.5">
          <div class="rounded-circle d-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary" style="width: 36px; height: 36px;">
            <i class="bi bi-qr-code fs-5"></i>
          </div>
          <div>
            <h5 class="modal-title fw-bold text-dark mb-0" style="font-size: 1.05rem;">Survey QR Code &amp; Link</h5>
            <small class="text-muted" style="font-size: 0.8rem;">Display on projector or share with stakeholders</small>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4 text-center">
        <h6 id="shareSurveyTitle" class="fw-bold mb-1 text-dark">Survey Title</h6>
        <p class="text-muted small mb-3">Scan this QR code using a mobile phone camera to open the survey form directly.</p>

        <!-- QR Code Holder -->
        <div class="d-flex justify-content-center my-3">
          <div id="shareQrHolder" class="p-3 bg-white border rounded-3 shadow-sm" style="width: 220px; height: 220px; display: flex; align-items: center; justify-content: center;"></div>
        </div>

        <!-- Direct Link Field with Copy Button -->
        <label class="form-label small fw-semibold text-secondary text-start w-100 mb-1">Direct Public Survey URL</label>
        <div class="input-group input-group-sm mb-2">
          <input type="text" class="form-control" id="shareUrlInput" readonly>
          <button class="btn btn-primary" type="button" id="btnCopyShareUrl">
            <i class="bi bi-clipboard me-1"></i>Copy
          </button>
        </div>
        <small class="text-success d-none" id="shareCopiedFeedback"><i class="bi bi-check-circle me-1"></i>Link copied to clipboard!</small>
      </div>
      <div class="modal-footer py-2 px-3 bg-light border-top d-flex justify-content-between">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnPrintQr">
          <i class="bi bi-printer me-1"></i>Print QR Code
        </button>
        <button type="button" class="btn btn-sm btn-primary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<?php if ($canManageSurveys): ?>
<!-- ======================================================================= -->
<!-- SURVEY BUILDER & EDIT MODAL -->
<!-- ======================================================================= -->
<div class="modal fade" id="surveyModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: 920px;">
    <div class="modal-content border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
      <form id="surveyForm">
        <?= csrfField() ?>
        <input type="hidden" name="id" id="surveyId" value="0">

        <div class="modal-header py-3 px-4 bg-light border-bottom">
          <div class="d-flex align-items-center gap-2.5">
            <div class="rounded-circle d-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary" style="width: 36px; height: 36px;">
              <i class="bi bi-ui-checks-grid fs-5"></i>
            </div>
            <div>
              <h5 class="modal-title fw-bold text-dark mb-0" id="surveyModalTitle" style="font-size: 1.05rem;">Create Consultation Survey</h5>
              <small class="text-muted" style="font-size: 0.8rem;">Configure questionnaire, response window, and linked proceedings</small>
            </div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body p-4">
          <!-- Notice when editing with existing responses -->
          <div class="alert alert-info py-2 px-3 small d-none mb-3" id="surveyLockedNotice" style="border-radius: 8px;">
            <i class="bi bi-shield-lock-fill me-1"></i>
            <strong>Responses Recorded:</strong> Questions are locked to protect data integrity. You can safely update the title, description, schedule, and status.
          </div>

          <div class="row g-2 mb-3">
            <div class="col-md-8">
              <label class="form-label small fw-semibold text-secondary mb-1">Survey Title *</label>
              <input type="text" class="form-control form-control-sm" name="title" id="formTitle" placeholder="e.g., Community Waste Segregation Consultation" required>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold text-secondary mb-1">Status</label>
              <select class="form-select form-select-sm" name="status" id="formStatus">
                <option value="Draft">Draft</option>
                <option value="Active" selected>Active</option>
                <option value="Closed">Closed</option>
                <option value="Archived">Archived</option>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Linked Hearing</label>
              <select class="form-select form-select-sm" name="hearing_id" id="formHearingId">
                <option value="">None (Standalone)</option>
                <?php foreach ($hearings as $h): ?>
                  <option value="<?= (int)$h['id'] ?>"><?= e(($h['reference_number'] ?: '') . ' ' . $h['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Linked Legislative Item / Ordinance</label>
              <select class="form-select form-select-sm" name="legislative_item_id" id="formLegislativeItemId">
                <option value="">None</option>
                <?php foreach ($items as $i): ?>
                  <option value="<?= (int)$i['id'] ?>"><?= e($i['reference_number'] . ' - ' . $i['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Opens At (Response Window Start)</label>
              <input type="datetime-local" class="form-control form-control-sm" name="opens_at" id="formOpensAt">
              <small class="text-muted" style="font-size: 0.7rem;">Leave blank to open immediately</small>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Closes At (Response Window End)</label>
              <input type="datetime-local" class="form-control form-control-sm" name="closes_at" id="formClosesAt">
              <small class="text-muted" style="font-size: 0.7rem;">Leave blank for no deadline</small>
            </div>

            <div class="col-12">
              <label class="form-label small fw-semibold text-secondary mb-1">Public Description &amp; Instructions</label>
              <textarea class="form-control form-control-sm" name="description" id="formDescription" rows="2" placeholder="Provide background context for participants..."></textarea>
            </div>
          </div>

          <!-- Questions Section -->
          <div class="d-flex justify-content-between align-items-center mb-2 pt-2 border-top">
            <span class="badge" style="background: rgba(169, 121, 0, 0.12); color: #8A6400; border: 1px solid rgba(169, 121, 0, 0.3); font-size: 0.72rem; font-weight: 700;">
              SURVEY QUESTIONS &amp; SCALES
            </span>
            <button type="button" class="btn btn-sm btn-outline-primary py-0.5 px-2.5" id="btnAddQuestion" style="font-size: 0.78rem;">
              <i class="bi bi-plus-circle me-1"></i> Add Question
            </button>
          </div>

          <div id="questionBuilder"></div>
        </div>

        <div class="modal-footer py-2.5 px-4 bg-light border-top d-flex justify-content-end gap-2">
          <button type="button" class="btn btn-light border px-3" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary px-4 fw-semibold shadow-sm" id="btnSaveSurvey">
            <i class="bi bi-check-circle me-1"></i> Save Survey
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Include QRCode JS from local vendor asset -->
<script src="<?= e(vendorAsset('qrcodejs/qrcode.min.js', 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js')) ?>"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const csrfToken = '<?= csrfToken() ?>';
  const surveyModalEl = document.getElementById('surveyModal');
  const surveyModal = surveyModalEl ? new bootstrap.Modal(surveyModalEl) : null;
  const shareModal = new bootstrap.Modal(document.getElementById('shareModal'));
  const questionBuilder = document.getElementById('questionBuilder');
  let qCounter = 0;

  /* ========================================================= */
  /* LIVE SEARCH & STATUS FILTERING                            */
  /* ========================================================= */
  const searchInput = document.getElementById('surveySearchInput');
  const filterButtons = document.querySelectorAll('#surveyFilterGroup button');
  let activeFilter = 'all';

  function applyFilters() {
    const q = (searchInput.value || '').toLowerCase().trim();
    const rows = document.querySelectorAll('#surveyTable tbody tr.survey-row');
    let visibleCount = 0;

    rows.forEach(row => {
      const rowFilter = row.getAttribute('data-filter') || '';
      const rowSearch = row.getAttribute('data-search') || '';

      const matchesFilter = (activeFilter === 'all') || (rowFilter === activeFilter);
      const matchesSearch = !q || rowSearch.includes(q);

      if (matchesFilter && matchesSearch) {
        row.style.display = '';
        visibleCount++;
      } else {
        row.style.display = 'none';
      }
    });

    const noRow = document.getElementById('noSurveysRow');
    if (noRow) {
      noRow.style.display = (visibleCount === 0 && rows.length > 0) ? '' : (rows.length === 0 ? '' : 'none');
    }
  }

  if (searchInput) searchInput.addEventListener('input', applyFilters);

  filterButtons.forEach(btn => {
    btn.addEventListener('click', function() {
      filterButtons.forEach(b => b.classList.remove('active'));
      this.classList.add('active');
      activeFilter = this.getAttribute('data-filter') || 'all';
      applyFilters();
    });
  });

  /* ========================================================= */
  /* SHARE & QR CODE MODAL                                     */
  /* ========================================================= */
  /* SHARE & QR CODE MODAL (DOMAIN ONLY)                       */
  /* ========================================================= */
  let currentQr = null;

  function resolveDomainUrl(originalUrl) {
    if (!originalUrl) return '';
    try {
      const u = new URL(originalUrl, window.location.href);
      if (window.location.origin && window.location.origin !== 'null') {
        u.protocol = window.location.protocol;
        u.host = window.location.host;
      }
      return u.toString();
    } catch (e) {
      return originalUrl;
    }
  }

  function renderQrCode(url) {
    const qrHolder = document.getElementById('shareQrHolder');
    qrHolder.innerHTML = '';
    document.getElementById('shareUrlInput').value = url;

    if (typeof QRCode !== 'undefined') {
      currentQr = new QRCode(qrHolder, {
        text: url,
        width: 190,
        height: 190,
        colorDark: "#0F2137",
        colorLight: "#ffffff",
        correctLevel: QRCode.CorrectLevel.M
      });
    } else {
      qrHolder.innerHTML = '<span class="text-danger small">QR code generator unavailable</span>';
    }
  }

  document.querySelectorAll('.btn-share-survey').forEach(btn => {
    btn.addEventListener('click', function() {
      const title = this.getAttribute('data-title') || 'Survey';
      const rawUrl = this.getAttribute('data-url') || '';
      const domainUrl = resolveDomainUrl(rawUrl);

      document.getElementById('shareSurveyTitle').textContent = title;
      document.getElementById('shareCopiedFeedback').classList.add('d-none');

      renderQrCode(domainUrl);
      shareModal.show();
    });
  });

  // Copy URL button
  const btnCopy = document.getElementById('btnCopyShareUrl');
  if (btnCopy) {
    btnCopy.addEventListener('click', function() {
      const copyInput = document.getElementById('shareUrlInput');
      copyInput.select();
      navigator.clipboard.writeText(copyInput.value).then(() => {
        const fb = document.getElementById('shareCopiedFeedback');
        fb.classList.remove('d-none');
        setTimeout(() => fb.classList.add('d-none'), 3000);
      });
    });
  }

  // Print QR Sheet button
  const btnPrintQr = document.getElementById('btnPrintQr');
  if (btnPrintQr) {
    btnPrintQr.addEventListener('click', function() {
      const title = document.getElementById('shareSurveyTitle').textContent;
      const url = document.getElementById('shareUrlInput').value;
      const qrCanvas = document.querySelector('#shareQrHolder canvas');
      const qrImg = qrCanvas ? qrCanvas.toDataURL('image/png') : '';

      const printWin = window.open('', '_blank', 'width=650,height=750');
      printWin.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
          <title>Survey QR Code - ${title}</title>
          <style>
            body { font-family: 'Segoe UI', Tahoma, sans-serif; text-align: center; padding: 40px; color: #0F2137; }
            .header { border-bottom: 3px solid #a97900; padding-bottom: 15px; margin-bottom: 25px; }
            h2 { font-size: 24px; margin: 0 0 8px 0; color: #0F2137; }
            p { font-size: 14px; color: #64748b; margin: 0; }
            .qr-box { margin: 30px auto; padding: 20px; border: 2px dashed #a97900; border-radius: 12px; display: inline-block; }
            .url { font-size: 13px; color: #475569; word-break: break-all; margin-top: 15px; font-family: monospace; }
            .instructions { margin-top: 25px; font-size: 15px; font-weight: bold; color: #0F2137; }
            @media print { .no-print { display: none; } }
          </style>
        </head>
        <body>
          <div class="header">
            <h2>Public Hearing Consultation Survey</h2>
            <p>Integrated Legislative Management System · City Government</p>
          </div>
          <h3 style="margin: 0 0 10px 0;">${title}</h3>
          <p class="instructions">Scan the QR code below with your mobile phone camera to submit your feedback.</p>
          <div class="qr-box">
            <img src="${qrImg}" style="width: 240px; height: 240px;" alt="QR Code">
            <div class="url">${url}</div>
          </div>
          <div class="no-print" style="margin-top: 30px;">
            <button onclick="window.print()" style="padding: 10px 24px; background: #0F2137; color: #fff; border: none; border-radius: 6px; font-weight: bold; cursor: pointer;">Print Poster</button>
          </div>
        </body>
        </html>
      `);
      printWin.document.close();
    });
  }

  /* ========================================================= */
  /* QUESTION BUILDER LOGIC                                    */
  /* ========================================================= */
  function addQuestion(data = {}) {
    qCounter++;
    const qText = data.question_text || '';
    const qType = data.question_type || 'Text';
    const qReq = String(data.is_required || '0') === '1' ? '1' : '0';
    const qOpts = data.options_text || '';

    const div = document.createElement('div');
    div.className = 'survey-q-item';
    div.innerHTML = `
      <div class="row g-2">
        <div class="col-md-6">
          <label class="form-label small fw-semibold text-secondary mb-1">Question *</label>
          <input type="text" class="form-control form-control-sm q-text" name="question_text[]" value="${escapeHtml(qText)}" placeholder="Enter question..." required>
        </div>
        <div class="col-md-3">
          <label class="form-label small fw-semibold text-secondary mb-1">Response Type</label>
          <select class="form-select form-select-sm q-type" name="question_type[]">
            <option value="Text" ${qType==='Text'?'selected':''}>Single Line Text</option>
            <option value="Long Text" ${qType==='Long Text'?'selected':''}>Paragraph / Comments</option>
            <option value="Single Choice" ${qType==='Single Choice'?'selected':''}>Single Choice (Radio)</option>
            <option value="Multiple Choice" ${qType==='Multiple Choice'?'selected':''}>Multiple Choice (Checkboxes)</option>
            <option value="Rating" ${qType==='Rating'?'selected':''}>Star Rating (1 to 5 Stars)</option>
            <option value="Number" ${qType==='Number'?'selected':''}>Numeric Scale / Number</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small fw-semibold text-secondary mb-1">Required?</label>
          <select class="form-select form-select-sm" name="question_required[]">
            <option value="0" ${qReq==='0'?'selected':''}>Optional</option>
            <option value="1" ${qReq==='1'?'selected':''}>Mandatory</option>
          </select>
        </div>
        <div class="col-md-1 d-flex align-items-end">
          <button type="button" class="btn btn-sm btn-outline-danger w-100 py-1 btn-remove-q" title="Remove question">
            <i class="bi bi-trash"></i>
          </button>
        </div>
        <div class="col-12 q-options-wrap ${['Single Choice','Multiple Choice'].includes(qType)?'':'d-none'}">
          <label class="form-label small fw-semibold text-secondary mb-1">Option Choices (one choice per line) *</label>
          <textarea class="form-control form-control-sm q-options" name="question_options[]" rows="3" placeholder="Option 1&#10;Option 2&#10;Option 3">${escapeHtml(qOpts)}</textarea>
          <small class="text-muted" style="font-size: 0.7rem;">Enter at least 2 choices separated by newlines.</small>
        </div>
        <input type="hidden" name="question_options[]" value="" class="fallback-options" ${['Single Choice','Multiple Choice'].includes(qType)?'disabled':''}>
      </div>
    `;

    const selectType = div.querySelector('.q-type');
    const optsWrap = div.querySelector('.q-options-wrap');
    const optsTextarea = div.querySelector('.q-options');
    const fallback = div.querySelector('.fallback-options');

    selectType.addEventListener('change', function() {
      const isChoice = ['Single Choice', 'Multiple Choice'].includes(this.value);
      if (isChoice) {
        optsWrap.classList.remove('d-none');
        optsTextarea.disabled = false;
        fallback.disabled = true;
      } else {
        optsWrap.classList.add('d-none');
        optsTextarea.disabled = true;
        fallback.disabled = false;
      }
    });

    div.querySelector('.btn-remove-q').addEventListener('click', function() {
      if (questionBuilder.querySelectorAll('.survey-q-item').length <= 1) {
        appToast('warning', 'Survey must have at least one question.');
        return;
      }
      div.remove();
    });

    questionBuilder.appendChild(div);
  }

  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  const btnAddQuestion = document.getElementById('btnAddQuestion');
  if (btnAddQuestion) {
    btnAddQuestion.addEventListener('click', () => addQuestion());
  }

  /* ========================================================= */
  /* NEW SURVEY TRIGGER                                        */
  /* ========================================================= */
  const btnNewSurvey = document.getElementById('btnNewSurvey');
  const surveyForm = document.getElementById('surveyForm');

  if (btnNewSurvey && surveyForm && surveyModal) {
    btnNewSurvey.addEventListener('click', function() {
      surveyForm.reset();
      document.getElementById('surveyId').value = '0';
      document.getElementById('surveyModalTitle').textContent = 'Create Consultation Survey';
      document.getElementById('surveyLockedNotice').classList.add('d-none');
      if (btnAddQuestion) btnAddQuestion.disabled = false;

      questionBuilder.innerHTML = '';
      addQuestion({
        question_text: 'Do you agree with the key provisions of this ordinance?',
        question_type: 'Single Choice',
        is_required: '1',
        options_text: "Strongly Agree\nAgree\nNeutral\nDisagree\nStrongly Disagree"
      });
      addQuestion({
        question_text: 'What specific amendments or recommendations do you propose?',
        question_type: 'Long Text',
        is_required: '0'
      });
      surveyModal.show();
    });
  }

  /* ========================================================= */
  /* EDIT SURVEY TRIGGER                                       */
  /* ========================================================= */
  document.querySelectorAll('.btn-edit-survey').forEach(btn => {
    btn.addEventListener('click', async function() {
      const id = this.getAttribute('data-id');
      if (!id || !surveyModal) return;

      const r = await appFetchJson(APP_URL + '/modules/feedback/ajax_survey_get.php?id=' + id);
      if (!r || !r.success) {
        Swal.fire('Error', r ? r.message : 'Could not load survey data.', 'error');
        return;
      }

      const s = r.survey;
      const questions = r.questions || [];
      const subCount = r.submission_count || 0;

      surveyForm.reset();
      document.getElementById('surveyId').value = s.id;
      document.getElementById('formTitle').value = s.title || '';
      document.getElementById('formStatus').value = s.status || 'Active';
      document.getElementById('formHearingId').value = s.hearing_id || '';
      document.getElementById('formLegislativeItemId').value = s.legislative_item_id || '';
      document.getElementById('formOpensAt').value = s.opens_at_input || '';
      document.getElementById('formClosesAt').value = s.closes_at_input || '';
      document.getElementById('formDescription').value = s.description || '';

      document.getElementById('surveyModalTitle').textContent = `Edit Survey #${s.id}: ${s.title}`;

      const notice = document.getElementById('surveyLockedNotice');
      if (subCount > 0) {
        notice.classList.remove('d-none');
        if (btnAddQuestion) btnAddQuestion.disabled = true;
      } else {
        notice.classList.add('d-none');
        if (btnAddQuestion) btnAddQuestion.disabled = false;
      }

      questionBuilder.innerHTML = '';
      if (questions.length > 0) {
        questions.forEach(q => addQuestion(q));
      } else {
        addQuestion();
      }

      // If responses exist, disable deleting or removing questions
      if (subCount > 0) {
        questionBuilder.querySelectorAll('.btn-remove-q').forEach(b => b.disabled = true);
        questionBuilder.querySelectorAll('.q-type').forEach(b => b.disabled = true);
      }

      surveyModal.show();
    });
  });

  /* ========================================================= */
  /* SAVE SURVEY FORM SUBMISSION                               */
  /* ========================================================= */
  if (surveyForm) {
    surveyForm.addEventListener('submit', async function(e) {
      e.preventDefault();
      const btnSave = document.getElementById('btnSaveSurvey');
      const originalText = btnSave.innerHTML;
      btnSave.disabled = true;
      btnSave.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

      const r = await appPostForm(APP_URL + '/modules/feedback/ajax_survey_save.php', surveyForm);
      btnSave.disabled = false;
      btnSave.innerHTML = originalText;

      if (r && r.success) {
        appToast('success', r.message || 'Survey saved successfully.');
        surveyModal.hide();
        setTimeout(() => location.reload(), 500);
      } else if (r && !r.session_expired) {
        Swal.fire('Validation Error', r.message || 'Could not save survey.', 'error');
      }
    });
  }


});
</script>

<?php include __DIR__ . '/../../layouts/footer.php'; ?>
