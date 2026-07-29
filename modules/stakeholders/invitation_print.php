<?php
/**
 * modules/stakeholders/invitation_print.php
 * ------------------------------------------------------------------
 * Print-friendly single invitation: stakeholder + hearing details,
 * invitation code, and the stakeholder's QR code for check-in.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT i.*, s.full_name, s.email, s.organization, h.title AS hearing_title, h.venue, h.hearing_date, h.hearing_time,
            (SELECT code_value FROM qr_codes q WHERE q.stakeholder_id = s.id LIMIT 1) AS qr_code
     FROM invitations i
     JOIN stakeholders s ON s.id = i.stakeholder_id
     LEFT JOIN hearings h ON h.id = i.hearing_id
     WHERE i.id = :id'
);
$stmt->execute([':id' => $id]);
$inv = $stmt->fetch();

if (!$inv) {
    die('Invitation not found.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Invitation - <?= e($inv['full_name']) ?></title>
<link href="<?= e(vendorAsset('bootstrap/bootstrap.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css')) ?>" rel="stylesheet">
<style>
  body { padding: 40px; }
  .invite-card { max-width: 600px; margin: 0 auto; border: 2px solid #0b3d6e; border-radius: 12px; padding: 32px; }
  .invite-header { text-align: center; border-bottom: 1px solid #e1e5ea; padding-bottom: 16px; margin-bottom: 20px; }
  .invite-code { font-family: monospace; font-size: 18px; background: #f4f6f9; padding: 8px 16px; border-radius: 6px; display: inline-block; }
  @media print { .no-print { display: none; } }
</style>
</head>
<body onload="window.print()">
<div class="no-print text-end mb-3" style="max-width:600px;margin:0 auto;">
  <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
</div>

<div class="invite-card">
  <div class="invite-header">
    <h5 class="mb-0"><?= e(APP_NAME) ?></h5>
    <div class="text-muted small">Official Invitation to Public Hearing / Consultation</div>
  </div>

  <p>Dear <strong><?= e($inv['full_name']) ?></strong>,</p>
  <p>You are cordially invited to participate in:</p>

  <table class="table table-borderless mb-3">
    <tr><th style="width:35%;">Hearing</th><td><?= e($inv['hearing_title'] ?? 'General Public Consultation') ?></td></tr>
    <?php if ($inv['hearing_date']): ?>
    <tr><th>Date &amp; Time</th><td><?= formatDate($inv['hearing_date']) ?> at <?= formatTime($inv['hearing_time']) ?></td></tr>
    <tr><th>Venue</th><td><?= e($inv['venue'] ?: 'To be announced') ?></td></tr>
    <?php endif; ?>
    <tr><th>Organization</th><td><?= e($inv['organization'] ?: '-') ?></td></tr>
  </table>

  <p class="text-center">
    <span class="d-block small text-muted mb-1">Your Invitation Code</span>
    <span class="invite-code"><?= e($inv['invitation_code']) ?></span>
  </p>

  <?php if (!empty($inv['qr_code'])): ?>
  <div class="text-center my-3">
    <div id="qrHolder"></div>
    <div class="small text-muted mt-1">Present this QR code upon check-in</div>
  </div>
  <script src="<?= e(vendorAsset('qrcodejs/qrcode.min.js', 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js')) ?>"></script>
  <script>
    new QRCode(document.getElementById('qrHolder'), {
      text: <?= json_encode($inv['qr_code']) ?>, width: 140, height: 140
    });
  </script>
  <?php endif; ?>

  <p class="small text-muted mt-4">Please bring this invitation (printed or digital) and a valid ID upon arrival.</p>
</div>
</body>
</html>
