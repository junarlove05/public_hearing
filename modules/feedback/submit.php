<?php
/**
 * modules/feedback/submit.php
 * ------------------------------------------------------------------
 * Fully public feedback submission page — no login required. Intended
 * to be linked from the department's public website. Posts to
 * ajax_submit.php, which also requires no authentication.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
// Deliberately no requireLogin() — this page is public.

$categories = db()->query('SELECT id, name FROM feedback_categories ORDER BY name')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Submit Feedback | <?= e(APP_NAME) ?></title>
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
        <small>Public Feedback Submission</small>
      </div>
      <div class="card shadow-lg border-0 auth-card">
        <div class="card-body p-4 p-md-5">
          <div id="formArea">
            <h5 class="card-title mb-3 fw-bold text-primary"><i class="bi bi-chat-square-text"></i> Share Your Feedback</h5>
            <form id="publicFeedbackForm">
              <?= csrfField() ?>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Your Name <span class="text-danger">*</span></label>
                  <input type="text" name="name" class="form-control" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Email <span class="text-danger">*</span></label>
                  <input type="email" name="email" class="form-control" required>
                </div>
                <div class="col-12">
                  <label class="form-label">Category</label>
                  <select name="category_id" class="form-select">
                    <option value="">-- Select Category --</option>
                    <?php foreach ($categories as $c): ?>
                      <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-12">
                  <label class="form-label">Subject</label>
                  <input type="text" name="subject" class="form-control" maxlength="255">
                </div>
                <div class="col-12">
                  <label class="form-label">Message <span class="text-danger">*</span></label>
                  <textarea name="message" class="form-control" rows="5" required></textarea>
                </div>
              </div>
              <button type="submit" class="btn btn-primary w-100 mt-4"><i class="bi bi-send"></i> Submit Feedback</button>
            </form>
          </div>
          <div id="successArea" class="d-none text-center py-4">
            <i class="bi bi-check-circle-fill text-success display-4"></i>
            <h5 class="mt-3">Thank you!</h5>
            <p class="text-muted">Your feedback has been submitted successfully. We appreciate your input.</p>
            <a href="track.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-search"></i> Track My Feedback</a>
            <button class="btn btn-outline-secondary btn-sm" id="btnSubmitAnother">Submit Another Response</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="<?= e(vendorAsset('bootstrap/bootstrap.bundle.min.js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js')) ?>"></script>
<script>
document.getElementById('publicFeedbackForm').addEventListener('submit', function (e) {
  e.preventDefault();
  const form = this;
  const btn = form.querySelector('button[type="submit"]');
  btn.disabled = true;
  btn.innerHTML = 'Submitting...';

  fetch('<?= e(APP_URL) ?>/modules/feedback/ajax_submit.php', {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: new FormData(form)
  })
    .then(r => r.json())
    .then(data => {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-send"></i> Submit Feedback';
      if (data.success) {
        document.getElementById('formArea').classList.add('d-none');
        document.getElementById('successArea').classList.remove('d-none');
      } else {
        alert(data.message);
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-send"></i> Submit Feedback';
      alert('A network error occurred. Please try again.');
    });
});
document.getElementById('btnSubmitAnother').addEventListener('click', function () {
  document.getElementById('publicFeedbackForm').reset();
  document.getElementById('formArea').classList.remove('d-none');
  document.getElementById('successArea').classList.add('d-none');
});
</script>
</body>
</html>
