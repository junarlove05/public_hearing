<?php
/**
 * modules/feedback/track.php
 * ------------------------------------------------------------------
 * Public "Track My Feedback" page — no login required. Lets a
 * submitter look up their own feedback by email (required) and
 * optionally narrow to one entry via its reference number, then view
 * its status and, once replied, the administrator's reply.
 *
 * SECURITY: email is always required and matched exactly, so a visitor
 * can only ever see feedback tied to an email address they know —
 * reference number alone is never sufficient (feedback IDs are
 * sequential and would otherwise be guessable/enumerable).
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
// Deliberately no requireLogin() — this page is public.

$email = clean($_GET['email'] ?? '');
$refInput = clean($_GET['ref'] ?? '');
$searched = $email !== '';
$results = [];
$hasReplyColumns = feedbackTableHasReplyColumns();

// Parse a reference number like "FB-000042" or plain "42" into an integer id.
$refId = null;
if ($refInput !== '') {
    $digits = preg_replace('/\D/', '', $refInput);
    if ($digits !== '') $refId = (int)$digits;
}

if ($searched && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $pdo = db();
    $where = ['f.email = :email'];
    $params = [':email' => $email];
    if ($refId !== null) { $where[] = 'f.id = :ref'; $params[':ref'] = $refId; }
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $selectReplyCols = $hasReplyColumns ? ', f.reply_text, f.replied_at, u.full_name AS replied_by_name' : '';
    $joinUsers = $hasReplyColumns ? 'LEFT JOIN users u ON u.id = f.replied_by' : '';

    $stmt = $pdo->prepare(
        "SELECT f.id, f.subject, f.message, f.status, f.submitted_at, fc.name AS category_name $selectReplyCols
         FROM feedback f
         LEFT JOIN feedback_categories fc ON fc.id = f.category_id
         $joinUsers
         $whereSql
         ORDER BY f.submitted_at DESC LIMIT 50"
    );
    $stmt->execute($params);
    $results = $stmt->fetchAll();
}

function feedbackRefCode(int $id): string
{
    return 'FB-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Track My Feedback | <?= e(APP_NAME) ?></title>
<link href="<?= e(vendorAsset('bootstrap/bootstrap.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css')) ?>" rel="stylesheet">
<link href="<?= e(vendorAsset('bootstrap-icons/bootstrap-icons.css', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css')) ?>" rel="stylesheet">
<link href="<?= e(APP_URL) ?>/assets/css/style.css" rel="stylesheet">
</head>
<body class="auth-body py-5">
<div class="container">
  <div class="row justify-content-center">
    <div class="col-md-8 col-lg-7">
      <div class="text-center mb-4 text-white">
        <i class="bi bi-bank2 display-4"></i>
        <h4 class="mt-2 mb-0"><?= e(APP_NAME) ?></h4>
        <small>Track My Feedback</small>
      </div>

      <div class="card shadow-lg border-0 auth-card mb-3">
        <div class="card-body p-4">
          <h5 class="card-title mb-3 fw-bold text-primary"><i class="bi bi-search"></i> Look Up Your Feedback</h5>
          <form method="GET" class="row g-2">
            <div class="col-md-7">
              <label class="form-label small text-muted mb-1">Email Address <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control" required value="<?= e($email) ?>" placeholder="The email you submitted with">
            </div>
            <div class="col-md-5">
              <label class="form-label small text-muted mb-1">Reference Number <span class="text-muted">(optional)</span></label>
              <input type="text" name="ref" class="form-control" value="<?= e($refInput) ?>" placeholder="e.g. FB-000042">
            </div>
            <div class="col-12 mt-3">
              <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Search</button>
            </div>
          </form>
        </div>
      </div>

      <?php if ($searched && !filter_var($email, FILTER_VALIDATE_EMAIL)): ?>
        <div class="alert alert-danger">Please enter a valid email address.</div>
      <?php elseif ($searched && empty($results)): ?>
        <div class="alert alert-info">No feedback found for that email<?= $refId ? ' and reference number' : '' ?>. Double-check the email address you submitted with.</div>
      <?php endif; ?>

      <?php foreach ($results as $r): ?>
        <div class="card shadow-sm border-0 auth-card mb-3">
          <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <div>
                <span class="font-monospace small text-muted"><?= e(feedbackRefCode($r['id'])) ?></span>
                <h6 class="mb-0 mt-1"><?= e($r['subject'] ?: '(No subject)') ?></h6>
                <div class="text-muted small"><?= e($r['category_name'] ?? 'General') ?> &middot; Submitted <?= formatDateTime($r['submitted_at']) ?></div>
              </div>
              <?= statusBadge($r['status']) ?>
            </div>
            <div class="border rounded p-3 bg-light small mb-2"><?= nl2br(e($r['message'])) ?></div>

            <?php if ($r['status'] === 'Replied'): ?>
              <?php if ($hasReplyColumns && !empty($r['reply_text'])): ?>
                <div class="mt-3">
                  <div class="fw-semibold small text-success mb-1"><i class="bi bi-reply-fill"></i> Administrator's Reply</div>
                  <div class="border border-success rounded p-3 small" style="background:#f0fff4;"><?= nl2br(e($r['reply_text'])) ?></div>
                  <div class="text-muted small mt-1">
                    Replied <?= $r['replied_at'] ? formatDateTime($r['replied_at']) : '' ?>
                    <?= !empty($r['replied_by_name']) ? ' by ' . e($r['replied_by_name']) : '' ?>
                  </div>
                </div>
              <?php else: ?>
                <div class="alert alert-secondary small mb-0 mt-2">This feedback has been marked as replied, but reply details aren't available for display on this system yet.</div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="text-center mt-3">
        <a href="submit.php" class="text-white small"><i class="bi bi-plus-circle"></i> Submit New Feedback</a>
      </div>
    </div>
  </div>
</div>
</body>
</html>
