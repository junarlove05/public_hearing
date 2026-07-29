<?php
/**
 * modules/feedback/my_feedback_table.php
 * ------------------------------------------------------------------
 * Shows the current logged-in user's own feedback submissions
 * (matched by account email), for Stakeholder/Public roles who don't
 * have management access to the full feedback list.
 * ------------------------------------------------------------------
 */

$user = currentUser();
$stmt = db()->prepare(
    "SELECT f.*, fc.name AS category_name
     FROM feedback f LEFT JOIN feedback_categories fc ON fc.id = f.category_id
     WHERE f.email = :email ORDER BY f.submitted_at DESC LIMIT 20"
);
$stmt->execute([':email' => $user['email']]);
$myFeedback = $stmt->fetchAll();
?>
<div class="list-group list-group-flush">
  <?php if (empty($myFeedback)): ?>
    <div class="list-group-item text-muted small">You haven't submitted any feedback yet.</div>
  <?php endif; ?>
  <?php foreach ($myFeedback as $f): ?>
    <div class="list-group-item d-flex justify-content-between align-items-start">
      <div>
        <div class="fw-semibold small"><?= e($f['subject'] ?: truncate($f['message'], 60)) ?></div>
        <div class="text-muted small"><?= e($f['category_name'] ?? 'General') ?> &middot; <?= formatDateTime($f['submitted_at']) ?></div>
      </div>
      <?= statusBadge($f['status']) ?>
    </div>
  <?php endforeach; ?>
</div>
