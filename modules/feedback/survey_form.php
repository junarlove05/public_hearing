<?php
/**
 * modules/feedback/survey_form.php
 * ------------------------------------------------------------------
 * Fully public survey response page — no login required. Linked from
 * the Surveys management list ("Open Public Form") for distribution.
 * Posts to ajax_survey_response_submit.php, also public.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
// Deliberately no requireLogin() — this page is public.

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM surveys WHERE id = :id');
$stmt->execute([':id' => $id]);
$survey = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($survey['title'] ?? 'Survey Not Found') ?> | <?= e(APP_NAME) ?></title>
<link href="<?= e(vendorAsset('bootstrap/bootstrap.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css')) ?>" rel="stylesheet">
<link href="<?= e(vendorAsset('bootstrap-icons/bootstrap-icons.css', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css')) ?>" rel="stylesheet">
<link href="<?= e(APP_URL) ?>/assets/css/style.css" rel="stylesheet">
</head>
<body class="auth-body d-flex align-items-center justify-content-center min-vh-100">
<div class="container">
  <div class="row justify-content-center">
    <div class="col-md-7 col-lg-6">
      <div class="text-center mb-4 text-white">
        <i class="bi bi-bank2 display-4"></i>
        <h4 class="mt-2 mb-0"><?= e(APP_NAME) ?></h4>
        <small>Public Survey</small>
      </div>
      <div class="card shadow-lg border-0 auth-card">
        <div class="card-body p-4 p-md-5">

          <?php if (!$survey): ?>
            <div class="text-center py-3">
              <i class="bi bi-exclamation-circle text-danger display-5"></i>
              <h5 class="mt-3">Survey Not Found</h5>
              <p class="text-muted">This survey link is invalid or has been removed.</p>
            </div>
          <?php elseif ($survey['status'] !== 'Active'): ?>
            <div class="text-center py-3">
              <i class="bi bi-lock text-warning display-5"></i>
              <h5 class="mt-3">Survey Closed</h5>
              <p class="text-muted">This survey is no longer accepting responses.</p>
            </div>
          <?php else: ?>
            <div id="formArea">
              <h5 class="card-title mb-1 fw-bold text-primary"><?= e($survey['title']) ?></h5>
              <?php if (!empty($survey['description'])): ?>
                <p class="text-muted small mb-3"><?= nl2br(e($survey['description'])) ?></p>
              <?php endif; ?>
              <form id="surveyResponseForm">
                <?= csrfField() ?>
                <input type="hidden" name="survey_id" value="<?= (int)$survey['id'] ?>">
                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label">Your Name</label>
                    <input type="text" name="respondent_name" class="form-control" placeholder="Optional">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="respondent_email" class="form-control" placeholder="Optional">
                  </div>
                  <div class="col-12">
                    <label class="form-label">Your Response <span class="text-danger">*</span></label>
                    <textarea name="response_text" class="form-control" rows="6" required></textarea>
                  </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 mt-4"><i class="bi bi-send"></i> Submit Response</button>
              </form>
            </div>
            <div id="successArea" class="d-none text-center py-4">
              <i class="bi bi-check-circle-fill text-success display-4"></i>
              <h5 class="mt-3">Thank you!</h5>
              <p class="text-muted">Your response has been recorded.</p>
            </div>
          <?php endif; ?>

        </div>
      </div>
    </div>
  </div>
</div>
<script src="<?= e(vendorAsset('bootstrap/bootstrap.bundle.min.js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js')) ?>"></script>
<?php if ($survey && $survey['status'] === 'Active'): ?>
<script>
document.getElementById('surveyResponseForm').addEventListener('submit', function (e) {
  e.preventDefault();
  const form = this;
  const btn = form.querySelector('button[type="submit"]');
  btn.disabled = true;
  btn.innerHTML = 'Submitting...';

  fetch('<?= e(APP_URL) ?>/modules/feedback/ajax_survey_response_submit.php', {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: new FormData(form)
  })
    .then(r => r.json())
    .then(data => {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-send"></i> Submit Response';
      if (data.success) {
        document.getElementById('formArea').classList.add('d-none');
        document.getElementById('successArea').classList.remove('d-none');
      } else {
        alert(data.message);
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-send"></i> Submit Response';
      alert('A network error occurred. Please try again.');
    });
});
</script>
<?php endif; ?>
</body>
</html>
