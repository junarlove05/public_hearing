<?php
/**
 * modules/stakeholders/print_registration_qr.php
 * ------------------------------------------------------------------
 * Printable Official Signage / Standee for Stakeholder QR Registration.
 * Can be placed at venue entrances or sent to participants.
 * ------------------------------------------------------------------
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!empty($_GET['url'])) {
    $regUrl = trim($_GET['url']);
} else {
    $regUrl = rtrim(APP_URL, '/') . '/modules/stakeholders/register.php';
    if (!preg_match('#^https?://#i', $regUrl)) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $regUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $regUrl;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Official Stakeholder QR Registration Signage · City of Manila</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
  <script src="<?= e(vendorAsset('qrcodejs/qrcode.min.js', 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js')) ?>"></script>
  <style>
    body {
      font-family: 'Plus Jakarta Sans', sans-serif;
      background: #f1f5f9;
      color: #0F2137;
      margin: 0;
      padding: 2rem 1rem;
    }
    .standee-card {
      max-width: 600px;
      margin: 0 auto;
      background: #ffffff;
      border: 3px solid #0F2137;
      border-radius: 18px;
      box-shadow: 0 15px 35px rgba(0,0,0,0.15);
      overflow: hidden;
      text-align: center;
    }
    .standee-header {
      background: #0F2137;
      color: #ffffff;
      padding: 2rem 1.5rem 1.5rem;
      border-bottom: 5px solid #c89523;
    }
    .qr-frame {
      display: inline-block;
      padding: 1.5rem;
      background: #ffffff;
      border: 2px dashed #0F2137;
      border-radius: 16px;
      margin: 1.75rem 0;
      box-shadow: 0 4px 12px rgba(0,0,0,0.06);
    }
    .instructions-box {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 1.25rem;
      margin: 0 2rem 2rem;
      text-align: left;
    }
    .step-badge {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      background: #0F2137;
      color: #c89523;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 0.85rem;
      flex-shrink: 0;
    }
    @media print {
      body {
        background: #ffffff;
        padding: 0;
      }
      .standee-card {
        border: 2px solid #0F2137;
        box-shadow: none;
        max-width: 100%;
        margin: 0;
        border-radius: 0;
      }
      .no-print {
        display: none !important;
      }
    }
  </style>
</head>
<body>

<div class="text-center mb-3 no-print d-flex align-items-center justify-content-center gap-2 flex-wrap">
  <button class="btn btn-primary px-4 fw-semibold shadow-sm" onclick="window.print()">
    <i class="bi bi-printer me-1"></i> Print Standee
  </button>
  <span class="badge bg-white text-dark border py-2 px-3 font-monospace small shadow-sm">
    <i class="bi bi-link-45deg me-1 text-primary"></i> <?= e($regUrl) ?>
  </span>
  <button class="btn btn-outline-secondary px-3" onclick="window.close()">
    Close
  </button>
</div>

<div class="standee-card">
  <div class="standee-header">
    <div class="text-warning fw-bold text-uppercase mb-1" style="font-size: 0.8rem; letter-spacing: 1px;">
      CITY COUNCIL OF MANILA · SANGGUNIANG PANLUNGSOD
    </div>
    <h2 class="fw-bold mb-1">STAKEHOLDER REGISTRATION</h2>
    <div class="text-white-50 small">Legislative Public Hearing Subsystem (LPH)</div>
  </div>

  <div class="p-4">
    <h5 class="fw-bold text-dark mb-1">Scan QR Code with Smartphone</h5>
    <p class="text-muted small mb-0">To officially register as an accredited stakeholder or sector representative</p>

    <div class="qr-frame">
      <div id="qrcodeCanvas"></div>
    </div>

    <div class="instructions-box">
      <div class="fw-bold text-dark small mb-2"><i class="bi bi-info-circle-fill text-primary me-1"></i> Registration Steps:</div>
      <div class="d-flex align-items-start gap-2.5 mb-2">
        <div class="step-badge">1</div>
        <div class="small">Scan the QR code using your smartphone camera or QR scanner.</div>
      </div>
      <div class="d-flex align-items-start gap-2.5 mb-2">
        <div class="step-badge">2</div>
        <div class="small">Fill in your full name, organization/office, contact details, and category.</div>
      </div>
      <div class="d-flex align-items-start gap-2.5 mb-2">
        <div class="step-badge">3</div>
        <div class="small"><strong>Upload a clear photo or copy of your Valid ID</strong> (Government, Office, or Barangay ID).</div>
      </div>
      <div class="d-flex align-items-start gap-2.5">
        <div class="step-badge">4</div>
        <div class="small">Await Administrator verification and approval for your official Attendance QR Pass.</div>
      </div>
    </div>

    <div class="text-muted small" style="font-size: 0.72rem;">
      City Hall of Manila, Padre Burgos Ave, Ermita, Manila · Official Legislative Document
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const qrHolder = document.getElementById('qrcodeCanvas');
  const targetUrl = <?= json_encode($regUrl) ?>;
  new QRCode(qrHolder, {
    text: targetUrl,
    width: 230,
    height: 230,
    colorDark: "#0F2137",
    colorLight: "#ffffff",
    correctLevel: QRCode.CorrectLevel.H
  });
});
</script>
</body>
</html>
