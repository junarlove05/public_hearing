<?php
/**
 * modules/stakeholders/invitation_download.php
 * ------------------------------------------------------------------
 * Serves the same invitation document as invitation_print.php, but
 * as a downloadable .html file (Content-Disposition: attachment)
 * instead of rendering inline for printing. The downloaded file is
 * self-contained and can be opened, emailed, or printed offline.
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
    setFlash('danger', 'Invitation not found.');
    redirect(APP_URL . '/modules/stakeholders/invitations.php');
}

logActivity(currentUserId(), 'Download', 'Downloaded invitation #' . $id . ' for ' . $inv['full_name']);

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Invitation - <?= e($inv['full_name']) ?></title>
<style>
  body { font-family: Arial, Helvetica, sans-serif; padding: 40px; background: #fff; }
  .invite-card { max-width: 600px; margin: 0 auto; border: 2px solid #0b3d6e; border-radius: 12px; padding: 32px; }
  .invite-header { text-align: center; border-bottom: 1px solid #e1e5ea; padding-bottom: 16px; margin-bottom: 20px; }
  .invite-code { font-family: monospace; font-size: 18px; background: #f4f6f9; padding: 8px 16px; border-radius: 6px; display: inline-block; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
  td, th { padding: 6px 4px; text-align: left; vertical-align: top; }
  th { width: 35%; color: #555; font-weight: 600; }
  .center { text-align: center; }
</style>
</head>
<body>
<div class="invite-card">
  <div class="invite-header">
    <h2 style="margin:0;"><?= e(APP_NAME) ?></h2>
    <div style="color:#777;font-size:13px;">Official Invitation to Public Hearing / Consultation</div>
  </div>

  <p>Dear <strong><?= e($inv['full_name']) ?></strong>,</p>
  <p>You are cordially invited to participate in:</p>

  <table>
    <tr><th>Hearing</th><td><?= e($inv['hearing_title'] ?? 'General Public Consultation') ?></td></tr>
    <?php if ($inv['hearing_date']): ?>
    <tr><th>Date &amp; Time</th><td><?= formatDate($inv['hearing_date']) ?> at <?= formatTime($inv['hearing_time']) ?></td></tr>
    <tr><th>Venue</th><td><?= e($inv['venue'] ?: 'To be announced') ?></td></tr>
    <?php endif; ?>
    <tr><th>Organization</th><td><?= e($inv['organization'] ?: '-') ?></td></tr>
  </table>

  <p class="center">
    <span style="display:block;font-size:12px;color:#777;">Your Invitation Code</span>
    <span class="invite-code"><?= e($inv['invitation_code']) ?></span>
  </p>

  <p style="font-size:12px;color:#777;margin-top:24px;">
    Please bring this invitation (printed or digital) and a valid ID upon arrival.
    Present your invitation code or stakeholder QR code (available in the system) at check-in.
  </p>
</div>
</body>
</html>
<?php
$html = ob_get_clean();

$filename = 'invitation-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $inv['invitation_code']) . '.html';

header('Content-Type: text/html; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($html));
echo $html;
exit;
