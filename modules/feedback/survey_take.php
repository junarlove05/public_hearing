<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

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
    if (isLoggedIn()) {
        setFlash('danger', 'Survey not found.');
        redirect(APP_URL . '/modules/feedback/surveys.php');
    } else {
        die('Survey not found or has been removed.');
    }
}

$qStmt = $pdo->prepare('SELECT * FROM survey_questions WHERE survey_id = :id ORDER BY sequence_number, id');
$qStmt->execute([':id' => $id]);
$questions = $qStmt->fetchAll(PDO::FETCH_ASSOC);

$options = [];
foreach ($questions as $question) {
    $oStmt = $pdo->prepare('SELECT * FROM survey_question_options WHERE question_id = :qid ORDER BY sequence_number, id');
    $oStmt->execute([':qid' => $question['id']]);
    $options[$question['id']] = $oStmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = $survey['title'];
$now = time();
$opensAt = !empty($survey['opens_at']) ? strtotime($survey['opens_at']) : null;
$closesAt = !empty($survey['closes_at']) ? strtotime($survey['closes_at']) : null;

$isScheduled = ($opensAt && $now < $opensAt);
$isExpired = ($closesAt && $now > $closesAt);
$isActive = ($survey['status'] === 'Active');
$isOpen = $isActive && !$isScheduled && !$isExpired;

$currentUser = currentUser();
$defaultName = $currentUser['name'] ?? '';
$defaultEmail = $currentUser['email'] ?? '';
$isStaffUser = isLoggedIn();
$isQrScan = isset($_GET['qr']) || !empty($_GET['qr']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($survey['title']) ?> | Public Consultation Survey</title>
  <link href="<?= e(vendorAsset('bootstrap/bootstrap.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css')) ?>" rel="stylesheet">
  <link href="<?= e(vendorAsset('bootstrap-icons/bootstrap-icons.css', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css')) ?>" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="<?= e(APP_URL) ?>/assets/css/style.css" rel="stylesheet">

  <style>
    body {
      background: #0b1523;
      background-image: radial-gradient(circle at 50% 0%, #1a3a5c 0%, #0b1523 70%);
      min-height: 100vh;
      font-family: 'Plus Jakarta Sans', sans-serif;
      color: #0F2137;
    }
    .survey-container {
      max-width: 760px;
      margin: 2.5rem auto 4rem;
      padding: 0 1rem;
    }
    .survey-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
      overflow: hidden;
    }
    .survey-hero {
      background: linear-gradient(135deg, #0F2137 0%, #1A3A5C 100%);
      border-bottom: 4px solid #a97900;
      padding: 2.25rem 2rem 1.75rem;
      color: #ffffff;
    }
    .survey-badge-context {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      background: rgba(169, 121, 0, 0.2);
      color: #f1c40f;
      border: 1px solid rgba(169, 121, 0, 0.4);
      padding: 0.25rem 0.65rem;
      border-radius: 6px;
      font-size: 0.76rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.4px;
      margin-bottom: 0.75rem;
    }
    .survey-hero h1 {
      font-size: 1.65rem;
      font-weight: 800;
      margin: 0 0 0.5rem 0;
      color: #ffffff;
    }
    .survey-hero p {
      font-size: 0.9rem;
      color: #cbd5e1;
      margin: 0;
      line-height: 1.5;
    }
    .question-block {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 1.25rem 1.25rem 1.15rem;
      margin-bottom: 1.25rem;
      transition: all 0.2s ease;
    }
    .question-block:focus-within {
      border-color: #a97900;
      box-shadow: 0 0 0 3px rgba(169, 121, 0, 0.12);
    }
    .q-title {
      font-size: 0.96rem;
      font-weight: 700;
      color: #0F2137;
      margin-bottom: 0.75rem;
      display: flex;
      align-items: flex-start;
      gap: 0.5rem;
    }
    .q-num {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 24px;
      height: 24px;
      background: rgba(15, 33, 55, 0.08);
      color: #0F2137;
      border-radius: 6px;
      font-size: 0.75rem;
      font-weight: 800;
      flex-shrink: 0;
      margin-top: 1px;
    }

    /* Option Radio/Checkbox styles */
    .option-item {
      display: flex;
      align-items: center;
      padding: 0.65rem 0.85rem;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      margin-bottom: 0.5rem;
      cursor: pointer;
      transition: all 0.15s ease;
      background: #f8fafc;
    }
    .option-item:hover {
      background: #ffffff;
      border-color: #cbd5e1;
    }
    .option-item input {
      margin-right: 0.75rem;
      cursor: pointer;
    }
    .option-item.selected {
      border-color: #a97900;
      background: #fffdf5;
    }

    /* Rating Stars */
    .rating-group {
      display: flex;
      gap: 0.5rem;
      flex-wrap: wrap;
    }
    .rating-btn {
      flex: 1;
      min-width: 60px;
      border: 1.5px solid #e2e8f0;
      background: #f8fafc;
      border-radius: 8px;
      padding: 0.6rem 0.3rem;
      text-align: center;
      cursor: pointer;
      transition: all 0.2s ease;
    }
    .rating-btn:hover {
      border-color: #a97900;
      background: #ffffff;
    }
    .rating-btn input {
      display: none;
    }
    .rating-btn.active {
      border-color: #a97900;
      background: #fff9e6;
      color: #8A6400;
      font-weight: 700;
    }
    .rating-btn .star-val {
      font-size: 1.15rem;
      font-weight: 800;
      display: block;
    }
    .rating-btn .star-lbl {
      font-size: 0.68rem;
      color: #64748b;
      display: block;
      margin-top: 2px;
    }

    /* Submit Button */
    .btn-submit-survey {
      background: #0F2137;
      color: #ffffff;
      border: none;
      font-weight: 700;
      font-size: 1rem;
      padding: 0.75rem 2rem;
      border-radius: 10px;
      box-shadow: 0 4px 14px rgba(15, 33, 55, 0.25);
      transition: all 0.2s ease;
    }
    .btn-submit-survey:hover {
      background: #1A3A5C;
      color: #ffffff;
      transform: translateY(-1px);
    }
  </style>
</head>
<body>

<div class="survey-container">
  <!-- Top Navigation / Branding -->
  <div class="d-flex justify-content-between align-items-center mb-3 text-white">
    <div class="d-flex align-items-center gap-2">
      <i class="bi bi-bank2 fs-4 text-warning"></i>
      <div>
        <strong class="d-block" style="font-size: 0.9rem; letter-spacing: 0.3px;">CITY OF MANILA · SANGGUNIANG PANLUNGSOD</strong>
        <small class="text-white-50" style="font-size: 0.75rem;">Public Hearing Civic Consultation Survey</small>
      </div>
    </div>
    <?php if ($isStaffUser && !$isQrScan): ?>
      <a href="surveys.php" class="btn btn-sm btn-outline-light py-1 px-2.5" style="font-size: 0.78rem;">
        <i class="bi bi-arrow-left me-1"></i> Staff Surveys Register
      </a>
    <?php endif; ?>
  </div>

  <div class="survey-card">
    <div class="survey-hero">
      <?php if (!empty($survey['hearing_reference'])): ?>
        <div class="survey-badge-context">
          <i class="bi bi-calendar-event"></i> Public Hearing: <?= e($survey['hearing_reference']) ?>
        </div>
      <?php elseif (!empty($survey['legislative_reference'])): ?>
        <div class="survey-badge-context">
          <i class="bi bi-file-earmark-text"></i> Ordinance / Item: <?= e($survey['legislative_reference']) ?>
        </div>
      <?php else: ?>
        <div class="survey-badge-context">
          <i class="bi bi-chat-square-text"></i> Civic Consultation
        </div>
      <?php endif; ?>

      <h1><?= e($survey['title']) ?></h1>

      <?php if (!empty($survey['description'])): ?>
        <p><?= nl2br(e($survey['description'])) ?></p>
      <?php endif; ?>

      <?php if (!empty($survey['hearing_title'])): ?>
        <div class="mt-2 text-white-50 small">
          <i class="bi bi-info-circle me-1"></i> Hearing: <?= e($survey['hearing_title']) ?>
          <?= !empty($survey['hearing_date']) ? (' · ' . date('F j, Y', strtotime($survey['hearing_date']))) : '' ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card-body p-4">
      <?php if (!$isOpen): ?>
        <div class="text-center py-5">
          <?php if ($isScheduled): ?>
            <i class="bi bi-clock-history text-warning display-4 d-block mb-3"></i>
            <h4 class="fw-bold text-dark">Survey Opens Soon</h4>
            <p class="text-muted">This consultation survey is scheduled to open on <strong><?= formatDateTime($survey['opens_at']) ?></strong>. Please check back at that time.</p>
          <?php elseif ($isExpired || $survey['status'] === 'Closed'): ?>
            <i class="bi bi-lock-fill text-danger display-4 d-block mb-3"></i>
            <h4 class="fw-bold text-dark">Survey Closed</h4>
            <p class="text-muted">This consultation survey is closed and is no longer accepting submissions. Thank you for your interest in local legislative matters.</p>
          <?php else: ?>
            <i class="bi bi-pause-circle text-secondary display-4 d-block mb-3"></i>
            <h4 class="fw-bold text-dark">Survey Inactive</h4>
            <p class="text-muted">This survey is currently in draft mode or suspended by the committee secretariat.</p>
          <?php endif; ?>
          <div class="mt-4">
            <button type="button" class="btn btn-secondary px-4 py-2" onclick="exitSurvey()">
              <i class="bi bi-box-arrow-right me-1"></i> Close Page (Exit)
            </button>
          </div>
        </div>

      <?php else: ?>

        <!-- SUCCESS SCREEN (Hidden initially) -->
        <div id="surveySuccessArea" class="text-center py-5 d-none">
          <div class="rounded-circle bg-success bg-opacity-10 text-success d-inline-flex align-items-center justify-content-center mb-3" style="width: 72px; height: 72px;">
            <i class="bi bi-check-circle-fill display-5"></i>
          </div>
          <h3 class="fw-bold text-dark mb-2">Thank You!</h3>
          <p class="text-muted mb-4" id="successMessage">Your consultation survey response has been successfully submitted and forwarded to the Committee secretariat for review.</p>
          <div class="d-flex flex-column align-items-center justify-content-center gap-2">
            <?php if ($isStaffUser && !$isQrScan): ?>
              <div class="d-flex justify-content-center gap-2">
                <a href="survey_results.php?id=<?= (int)$id ?>" class="btn btn-primary">
                  <i class="bi bi-bar-chart me-1"></i> View Survey Analytics
                </a>
                <a href="surveys.php" class="btn btn-outline-secondary">
                  Back to Surveys
                </a>
              </div>
            <?php else: ?>
              <button type="button" class="btn btn-danger px-4 py-2.5 fw-semibold shadow-sm" id="btnExitSurvey" onclick="exitSurvey()" style="min-width: 180px; border-radius: 8px;">
                <i class="bi bi-box-arrow-right me-1"></i> Close Page (Exit)
              </button>
              <p class="text-muted small mt-2 mb-0"><i class="bi bi-shield-check text-success me-1"></i>Successfully recorded. You may now close this browser tab.</p>
            <?php endif; ?>
          </div>
        </div>

        <!-- RESPONSE FORM -->
        <form id="publicSurveyForm">
          <?= csrfField() ?>
          <input type="hidden" name="survey_id" value="<?= (int)$id ?>">

          <!-- Respondent Information -->
          <div class="question-block" style="background: #f8fafc; border-left: 3px solid #0F2137;">
            <h6 class="fw-bold text-dark mb-2"><i class="bi bi-person-badge me-1"></i> Participant Details</h6>
            <p class="text-muted small mb-3">Your details help the committee verify stakeholders and send future hearing notices.</p>

            <div class="row g-2" id="respondentFields">
              <div class="col-md-6">
                <label class="form-label small fw-semibold text-secondary mb-1">Full Name</label>
                <input type="text" class="form-control" name="respondent_name" id="respondentName" value="<?= e($defaultName) ?>" placeholder="e.g., Juan Dela Cruz">
              </div>
              <div class="col-md-6">
                <label class="form-label small fw-semibold text-secondary mb-1">Email Address</label>
                <input type="email" class="form-control" name="respondent_email" id="respondentEmail" value="<?= e($defaultEmail) ?>" placeholder="e.g., juan@example.com">
              </div>
            </div>

            <div class="form-check mt-2.5">
              <input class="form-check-input" type="checkbox" name="is_anonymous" id="chkAnonymous" value="1">
              <label class="form-check-label small text-secondary" for="chkAnonymous">
                Submit as <strong>Anonymous Participant</strong> (hides your name &amp; email)
              </label>
            </div>
          </div>

          <!-- Questions List -->
          <?php foreach ($questions as $idx => $q): ?>
            <?php
              $qid = (int)$q['id'];
              $isReq = (int)$q['is_required'] === 1;
              $qType = $q['question_type'];
            ?>
            <div class="question-block">
              <div class="q-title">
                <span class="q-num"><?= $idx + 1 ?></span>
                <div>
                  <?= e($q['question_text']) ?>
                  <?php if ($isReq): ?>
                    <span class="text-danger" title="Required">*</span>
                  <?php endif; ?>
                </div>
              </div>

              <?php if ($qType === 'Long Text'): ?>
                <textarea class="form-control" name="q[<?= $qid ?>]" rows="4" placeholder="Write your feedback or recommendation here..." <?= $isReq ? 'required' : '' ?>></textarea>

              <?php elseif ($qType === 'Single Choice'): ?>
                <?php foreach ($options[$qid] as $o): ?>
                  <label class="option-item">
                    <input type="radio" name="q[<?= $qid ?>]" value="<?= (int)$o['id'] ?>" <?= $isReq ? 'required' : '' ?>>
                    <span><?= e($o['option_text']) ?></span>
                  </label>
                <?php endforeach; ?>

              <?php elseif ($qType === 'Multiple Choice'): ?>
                <?php foreach ($options[$qid] as $o): ?>
                  <label class="option-item">
                    <input type="checkbox" name="q[<?= $qid ?>][]" value="<?= (int)$o['id'] ?>">
                    <span><?= e($o['option_text']) ?></span>
                  </label>
                <?php endforeach; ?>

              <?php elseif ($qType === 'Rating'): ?>
                <div class="rating-group" data-qid="<?= $qid ?>">
                  <?php
                    $starLabels = [
                      1 => ['★', 'Strongly Disagree / Poor'],
                      2 => ['★★', 'Disagree / Fair'],
                      3 => ['★★★', 'Neutral / Moderate'],
                      4 => ['★★★★', 'Agree / Good'],
                      5 => ['★★★★★', 'Strongly Agree / Excellent'],
                    ];
                  ?>
                  <?php foreach ($starLabels as $num => [$stars, $lbl]): ?>
                    <label class="rating-btn">
                      <input type="radio" name="q[<?= $qid ?>]" value="<?= $num ?>" <?= $isReq ? 'required' : '' ?>>
                      <span class="star-val text-warning"><?= $num ?> ★</span>
                      <span class="star-lbl"><?= e($lbl) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>

              <?php elseif ($qType === 'Number'): ?>
                <input type="number" step="any" class="form-control" name="q[<?= $qid ?>]" placeholder="Enter number..." <?= $isReq ? 'required' : '' ?>>

              <?php else: ?>
                <input type="text" class="form-control" name="q[<?= $qid ?>]" placeholder="Your answer..." <?= $isReq ? 'required' : '' ?>>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>

          <div class="d-flex justify-content-between align-items-center pt-2">
            <span class="small text-muted"><span class="text-danger">*</span> Required questions</span>
            <button type="submit" class="btn btn-submit-survey" id="btnSubmitForm">
              <i class="bi bi-send-check me-1"></i> Submit Response
            </button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="text-center mt-3 text-white-50 small">
    &copy; <?= date('Y') ?> City Government of Manila · Integrated Legislative Management System
  </div>
</div>

<script src="<?= e(vendorAsset('bootstrap/bootstrap.bundle.min.js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(vendorAsset('sweetalert2/sweetalert2.all.min.js', 'https://cdn.jsdelivr.net/npm/sweetalert2@11')) ?>"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const form = document.getElementById('publicSurveyForm');
  const chkAnonymous = document.getElementById('chkAnonymous');
  const respondentFields = document.getElementById('respondentFields');
  const rName = document.getElementById('respondentName');
  const rEmail = document.getElementById('respondentEmail');

  // Anonymous toggle
  if (chkAnonymous) {
    chkAnonymous.addEventListener('change', function() {
      if (this.checked) {
        respondentFields.style.opacity = '0.4';
        rName.disabled = true;
        rEmail.disabled = true;
      } else {
        respondentFields.style.opacity = '1';
        rName.disabled = false;
        rEmail.disabled = false;
      }
    });
  }

  // Option item selection styling
  document.querySelectorAll('.option-item input').forEach(input => {
    input.addEventListener('change', function() {
      const parentLabel = this.closest('.option-item');
      if (this.type === 'radio') {
        const name = this.name;
        document.querySelectorAll(`input[name="${name}"]`).forEach(r => {
          r.closest('.option-item').classList.remove('selected');
        });
        if (this.checked) parentLabel.classList.add('selected');
      } else if (this.type === 'checkbox') {
        parentLabel.classList.toggle('selected', this.checked);
      }
    });
  });

  // Rating pill selection styling
  document.querySelectorAll('.rating-btn input').forEach(input => {
    input.addEventListener('change', function() {
      const group = this.closest('.rating-group');
      group.querySelectorAll('.rating-btn').forEach(btn => btn.classList.remove('active'));
      if (this.checked) {
        this.closest('.rating-btn').classList.add('active');
      }
    });
  });

  // Form submission via Fetch
  if (form) {
    form.addEventListener('submit', async function(e) {
      e.preventDefault();
      const btn = document.getElementById('btnSubmitForm');
      const origText = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Submitting...';

      try {
        const formData = new FormData(form);
        const res = await fetch('<?= e(APP_URL) ?>/modules/feedback/ajax_survey_submit.php', {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: formData
        });

        const data = await res.json();
        btn.disabled = false;
        btn.innerHTML = origText;

        if (data && data.success) {
          form.classList.add('d-none');
          const successArea = document.getElementById('surveySuccessArea');
          if (successArea) {
            successArea.classList.remove('d-none');
            window.scrollTo({ top: 0, behavior: 'smooth' });
          }
        } else {
          Swal.fire({
            icon: 'warning',
            title: 'Please Check',
            text: (data && data.message) ? data.message : 'Could not submit survey response.'
          });
        }
      } catch (err) {
        btn.disabled = false;
        btn.innerHTML = origText;
        Swal.fire({
          icon: 'error',
          title: 'Network Error',
          text: 'Unable to reach the server. Please verify your connection and try again.'
        });
      }
    });
  }
});

function exitSurvey() {
  // 1. Subukang isara ang tab/window sa iba't ibang pamamaraan
  try { window.opener = null; } catch(e) {}
  try { window.open('', '_self', ''); } catch(e) {}
  try { window.close(); } catch(e) {}
  
  // Kung binuksan sa loob ng in-app scanner / webview, subukang mag-history back
  try {
    if (window.history && window.history.length > 1) {
      window.history.back();
    }
  } catch(e) {}

  // 2. Alisin ang buong survey card at palitan ng Exit screen bago i-blank
  document.body.innerHTML = `
    <div style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:#060d17;color:#fff;text-align:center;padding:20px;font-family:-apple-system,BlinkMacSystemFont,sans-serif;">
      <div style="max-width:380px;width:100%;padding:32px 24px;background:#0d1c2e;border:1px solid #1e3a5f;border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,0.5);">
        <div style="font-size:48px;margin-bottom:12px;">✅</div>
        <h3 style="font-weight:700;margin-bottom:8px;color:#fff;">Submitted &amp; Completed</h3>
        <p style="color:#94a3b8;font-size:14px;margin-bottom:20px;line-height:1.5;">Your survey responses have been safely recorded.</p>
        <button onclick="forceCloseTab()" style="background:#dc2626;color:#fff;border:none;padding:12px 24px;border-radius:8px;font-weight:700;font-size:14px;cursor:pointer;width:100%;box-shadow:0 4px 12px rgba(220,38,38,0.3);">
          ✕ Close Tab / Window
        </button>
        <div style="font-size:12px;color:#64748b;margin-top:16px;">
          You can also press <strong>(X)</strong> or swipe down the browser tab.
        </div>
      </div>
    </div>
  `;

  // Subukang i-close muli o i-unload papuntang blank screen
  setTimeout(() => {
    try { window.close(); } catch(e) {}
    try { window.location.replace('about:blank'); } catch(e) {}
  }, 500);
}

function forceCloseTab() {
  try { window.opener = null; } catch(e) {}
  try { window.open('', '_self', ''); } catch(e) {}
  try { window.close(); } catch(e) {}
  try { window.location.replace('about:blank'); } catch(e) {}
}
</script>

</body>
</html>
