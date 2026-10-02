<?php
/**
 * modules/stakeholders/invitation_print.php
 * ------------------------------------------------------------------
 * Official Executive Print-Friendly Invitation:
 * Features the City of Manila letterhead, official seal watermark,
 * stakeholder & hearing details, check-in QR code, and security credential.
 * ------------------------------------------------------------------
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT i.*, 
            s.full_name, s.email, s.organization, s.phone, s.sector, 
            sc.name AS category_name,
            h.id AS hearing_id, h.title AS hearing_title, h.reference_number, h.venue, 
            h.hearing_date, h.hearing_time, h.status AS hearing_status,
            hsd.day_number, hsd.start_time AS session_start_time, hsd.end_time AS session_end_time,
            COALESCE(i.session_date, hsd.session_date, h.hearing_date) AS effective_session_date,
            r.attendance_type, r.registration_code,
            (SELECT code_value FROM qr_codes q WHERE q.stakeholder_id = s.id LIMIT 1) AS qr_code
     FROM invitations i
     JOIN stakeholders s ON s.id = i.stakeholder_id
     LEFT JOIN stakeholder_categories sc ON sc.id = s.category_id
     LEFT JOIN hearings h ON h.id = i.hearing_id
     LEFT JOIN hearing_session_days hsd ON (hsd.id = i.session_day_id)
     LEFT JOIN registrations r ON (
         r.hearing_id = i.hearing_id 
         AND r.stakeholder_id = s.id 
         AND (
             (i.session_date IS NOT NULL AND r.session_date = i.session_date)
             OR (i.session_day_id IS NOT NULL AND r.session_day_id = i.session_day_id)
             OR (i.session_date IS NULL AND r.session_date IS NULL)
         )
     )
     WHERE i.id = :id'
);
$stmt->execute([':id' => $id]);
$inv = $stmt->fetch();

if (!$inv) {
    die('Invitation not found.');
}

// Prepare Logo Data URI (guarantees offline/print availability)
$logoPath = __DIR__ . '/../../assets/images/manila.png';
$logoDataUri = file_exists($logoPath)
    ? 'data:image/png;base64,' . base64_encode((string)file_get_contents($logoPath))
    : (function_exists('appUrl') ? appUrl('assets/images/manila.png') : 'assets/images/manila.png');

$qrValue = !empty($inv['qr_code']) ? $inv['qr_code'] : $inv['invitation_code'];
$attendanceMode = !empty($inv['attendance_type']) ? $inv['attendance_type'] : 'On-site';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Official Invitation - <?= e($inv['full_name']) ?> · <?= e($inv['invitation_code']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="<?= e(vendorAsset('bootstrap/bootstrap.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css')) ?>" rel="stylesheet">
<link href="<?= e(vendorAsset('bootstrap-icons/bootstrap-icons.css', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css')) ?>" rel="stylesheet">
<style>
  :root {
    --navy-primary: #0a2540;
    --navy-deep: #06192c;
    --gold-primary: #c59b27;
    --gold-light: #f7edd4;
    --gold-accent: #d4af37;
    --slate-text: #2d3748;
    --muted-text: #64748b;
  }

  * { box-sizing: border-box; }
  
  body {
    background-color: #f1f5f9;
    font-family: 'Outfit', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    color: var(--slate-text);
    margin: 0;
    padding: 30px 15px;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }

  /* Screen Action Bar */
  .action-bar {
    max-width: 720px;
    margin: 0 auto 20px auto;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    background: #ffffff;
    padding: 12px 20px;
    border-radius: 10px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.06);
    border: 1px solid #e2e8f0;
  }

  /* Document Card Container */
  .invitation-document {
    max-width: 720px;
    margin: 0 auto;
    background: #ffffff;
    border: 2px solid var(--gold-primary);
    border-radius: 12px;
    padding: 12px;
    box-shadow: 0 12px 35px rgba(10,37,64,0.08);
    position: relative;
    overflow: hidden;
  }

  /* Inner Ornamental Border */
  .inner-border {
    border: 1.5px solid var(--navy-primary);
    border-radius: 8px;
    padding: 34px 38px;
    position: relative;
    overflow: hidden;
    background: #ffffff;
  }

  /* Manila Logo Background Watermark */
  .watermark-seal {
    position: absolute;
    top: 52%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 440px;
    height: 440px;
    background-image: url('<?= $logoDataUri ?>');
    background-repeat: no-repeat;
    background-position: center;
    background-size: contain;
    opacity: 0.07;
    pointer-events: none;
    z-index: 1;
  }

  /* Content wrapper (above watermark) */
  .doc-content {
    position: relative;
    z-index: 2;
  }

  /* Official Letterhead Header */
  .doc-header {
    text-align: center;
    margin-bottom: 22px;
  }

  .header-logo-wrap {
    margin-bottom: 10px;
  }

  .header-logo-wrap img {
    width: 82px;
    height: 82px;
    object-fit: contain;
    filter: drop-shadow(0 2px 5px rgba(0,0,0,0.12));
  }

  .rep-ph {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 2px;
    color: var(--muted-text);
    margin: 0;
  }

  .city-mnl {
    font-size: 1.15rem;
    font-weight: 800;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: var(--navy-primary);
    margin: 2px 0;
  }

  .council-title {
    font-family: 'Cinzel', serif;
    font-size: 1.25rem;
    font-weight: 700;
    letter-spacing: 0.5px;
    color: var(--navy-deep);
    margin: 0;
  }

  .subsystem-tag {
    display: inline-block;
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--gold-primary);
    letter-spacing: 0.4px;
    margin-top: 4px;
    text-transform: uppercase;
  }

  .gold-divider {
    height: 3px;
    background: linear-gradient(90deg, transparent 0%, var(--gold-primary) 25%, var(--gold-accent) 50%, var(--gold-primary) 75%, transparent 100%);
    margin: 16px 0 20px 0;
    border-radius: 2px;
  }

  /* Document Type Ribbon */
  .doc-badge-wrap {
    text-align: center;
    margin-bottom: 22px;
  }

  .doc-badge {
    display: inline-block;
    background: linear-gradient(135deg, var(--navy-deep) 0%, var(--navy-primary) 100%);
    color: #ffffff;
    padding: 6px 20px;
    border-radius: 50px;
    font-size: 0.8rem;
    font-weight: 700;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    box-shadow: 0 3px 8px rgba(10,37,64,0.15);
    border: 1px solid var(--gold-accent);
  }

  /* Salutation */
  .salutation-block {
    margin-bottom: 20px;
    line-height: 1.6;
  }

  .salutation-block .salute-name {
    font-size: 1.2rem;
    font-weight: 700;
    color: var(--navy-primary);
  }

  .salutation-block p {
    font-size: 0.95rem;
    color: #334155;
    margin: 6px 0 0 0;
  }

  /* Details Table Card */
  .details-box {
    background: rgba(248, 250, 252, 0.88);
    border: 1px solid #e2e8f0;
    border-left: 4px solid var(--navy-primary);
    border-radius: 8px;
    padding: 16px 20px;
    margin-bottom: 24px;
  }

  .details-table {
    width: 100%;
    border-collapse: collapse;
  }

  .details-table tr:not(:last-child) {
    border-bottom: 1px dashed #e2e8f0;
  }

  .details-table th {
    width: 28%;
    padding: 9px 8px;
    font-size: 0.82rem;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--muted-text);
    letter-spacing: 0.5px;
    vertical-align: top;
  }

  .details-table td {
    padding: 9px 8px;
    font-size: 0.94rem;
    color: var(--navy-deep);
    vertical-align: top;
  }

  .details-table td strong {
    font-weight: 700;
    color: var(--navy-primary);
  }

  .mode-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 0.76rem;
    font-weight: 600;
    padding: 2px 10px;
    border-radius: 12px;
    background: #eef6ff;
    color: #0b3d6e;
    border: 1px solid #bad9fc;
  }

  /* Security Credential & QR Container */
  .security-credential-card {
    background: linear-gradient(135deg, #ffffff 0%, #fafcff 100%);
    border: 1.5px solid #cbd5e1;
    border-radius: 10px;
    padding: 18px;
    margin-bottom: 22px;
    text-align: center;
    box-shadow: 0 4px 12px rgba(10,37,64,0.03);
  }

  .cred-header {
    font-size: 0.74rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: var(--muted-text);
    margin-bottom: 6px;
  }

  .code-display {
    display: inline-block;
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, Courier, monospace;
    font-size: 1.15rem;
    font-weight: 700;
    color: var(--navy-deep);
    background: #f8fafc;
    border: 1.5px dashed var(--gold-primary);
    padding: 6px 18px;
    border-radius: 6px;
    letter-spacing: 1.5px;
    margin-bottom: 14px;
  }

  .qr-frame {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 10px;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    box-shadow: 0 3px 10px rgba(0,0,0,0.05);
  }

  .qr-caption {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--navy-primary);
    margin-top: 8px;
  }

  /* Advisory Box */
  .advisory-box {
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 8px;
    padding: 12px 16px;
    font-size: 0.82rem;
    color: #92400e;
    line-height: 1.5;
    margin-bottom: 24px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
  }

  .advisory-box i {
    font-size: 1.1rem;
    color: #d97706;
    flex-shrink: 0;
    margin-top: 1px;
  }

  /* Official Footer Sign-off */
  .doc-footer {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    border-top: 1px solid #e2e8f0;
    padding-top: 16px;
    font-size: 0.75rem;
    color: var(--muted-text);
  }

  .doc-footer-left {
    max-width: 58%;
    line-height: 1.5;
  }

  .doc-footer-right {
    text-align: right;
  }

  .doc-footer-right .sig-title {
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--navy-primary);
    font-size: 0.78rem;
  }

  /* Print Styles */
  @media print {
    body {
      background: #ffffff;
      padding: 0;
      margin: 0;
    }
    .action-bar {
      display: none !important;
    }
    .invitation-document {
      border: 1.5px solid #c59b27 !important;
      box-shadow: none !important;
      max-width: 100% !important;
      width: 100% !important;
      padding: 6px !important;
      border-radius: 0 !important;
    }
    .inner-border {
      padding: 24px 28px !important;
      border-radius: 0 !important;
    }
    .watermark-seal {
      opacity: 0.08 !important;
      -webkit-print-color-adjust: exact !important;
      print-color-adjust: exact !important;
    }
    @page {
      size: A4 portrait;
      margin: 8mm;
    }
  }
</style>
</head>
<body>

<!-- Print Controls (Hidden on Print) -->
<div class="action-bar no-print">
  <div class="d-flex align-items-center gap-2">
    <a href="invitations.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-arrow-left me-1"></i> Back to Invitations
    </a>
  </div>
  <div class="d-flex align-items-center gap-2">
    <a href="invitation_download.php?id=<?= $id ?>" class="btn btn-sm btn-outline-success">
      <i class="bi bi-download me-1"></i> Download File
    </a>
    <button class="btn btn-sm btn-primary px-3 fw-bold" onclick="window.print()">
      <i class="bi bi-printer-fill me-1"></i> Print Official Invitation
    </button>
  </div>
</div>

<!-- Official Invitation Certificate Container -->
<div class="invitation-document">
  <div class="inner-border">
    <!-- Manila Logo Watermark -->
    <div class="watermark-seal" aria-hidden="true"></div>

    <div class="doc-content">
      <!-- Official Header -->
      <div class="doc-header">
        <div class="header-logo-wrap">
          <img src="<?= $logoDataUri ?>" alt="City of Manila Official Seal">
        </div>
        <p class="rep-ph">Republic of the Philippines</p>
        <h2 class="city-mnl">City of Manila</h2>
        <h3 class="council-title">Sangguniang Panlungsod</h3>
        <span class="subsystem-tag">Legislative Public Hearing &amp; Consultation Management System</span>
        <div class="gold-divider"></div>
      </div>

      <!-- Badge Title -->
      <div class="doc-badge-wrap">
        <span class="doc-badge">Official Invitation to Public Hearing / Consultation</span>
      </div>

      <!-- Salutation -->
      <div class="salutation-block">
        <div class="salute-name">Dear <?= e($inv['full_name']) ?>,</div>
        <p>You are cordially invited to participate as an official stakeholder in the legislative proceedings detailed below:</p>
      </div>

      <!-- Details Matrix -->
      <div class="details-box">
        <table class="details-table">
          <tr>
            <th>Hearing</th>
            <td>
              <strong><?= e($inv['hearing_title'] ?? 'General Public Consultation') ?></strong>
              <?php if (!empty($inv['reference_number'])): ?>
                <span class="badge bg-light text-secondary border font-monospace ms-1" style="font-size:0.75rem;">
                  <?= e($inv['reference_number']) ?>
                </span>
              <?php endif; ?>
            </td>
          </tr>
          <?php 
            $invDate = !empty($inv['effective_session_date']) ? $inv['effective_session_date'] : ($inv['hearing_date'] ?? '');
            $invDayNum = !empty($inv['day_number']) ? (int)$inv['day_number'] : null;
            $invTimeStart = !empty($inv['session_start_time']) ? $inv['session_start_time'] : ($inv['hearing_time'] ?? '');
            $invTimeEnd = !empty($inv['session_end_time']) ? $inv['session_end_time'] : null;
            $invTimeStr = $invTimeStart ? formatTime($invTimeStart) : '';
            if ($invTimeEnd) {
                $invTimeStr .= ' - ' . formatTime($invTimeEnd);
            }
          ?>
          <?php if (!empty($invDate)): ?>
          <tr>
            <th>Date &amp; Schedule</th>
            <td>
              <strong><?= formatDate($invDate) ?></strong>
              <?php if ($invDayNum): ?>
                <span class="badge bg-primary text-white ms-1 fw-bold" style="font-size:0.75rem; letter-spacing:0.3px;">Day <?= $invDayNum ?></span>
              <?php endif; ?>
              <?php if ($invTimeStr): ?>
                <span class="ms-1">at <strong><?= $invTimeStr ?></strong></span>
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <th>Venue</th>
            <td>
              <i class="bi bi-geo-alt-fill text-danger me-1"></i><?= e($inv['venue'] ?: 'Session Hall, Manila City Hall') ?>
            </td>
          </tr>
          <?php endif; ?>
          <tr>
            <th>Organization</th>
            <td><?= e($inv['organization'] ?: 'Individual / Unspecified') ?></td>
          </tr>
          <?php if (!empty($inv['category_name'])): ?>
          <tr>
            <th>Sector / Group</th>
            <td><?= e($inv['category_name']) ?></td>
          </tr>
          <?php endif; ?>
          <tr>
            <th>Attendance Mode</th>
            <td>
              <span class="mode-chip">
                <i class="bi <?= $attendanceMode === 'Online' ? 'bi-camera-video' : 'bi-person-badge' ?>"></i>
                <?= e($attendanceMode) ?>
              </span>
            </td>
          </tr>
        </table>
      </div>

      <!-- Security Credential & QR Box -->
      <div class="security-credential-card">
        <div class="cred-header">Official Invitation Code</div>
        <div>
          <span class="code-display"><?= e($inv['invitation_code']) ?></span>
        </div>

        <div class="qr-frame">
          <div id="qrHolder"></div>
        </div>
        <div class="qr-caption">
          <i class="bi bi-qr-code-scan me-1"></i> Present this QR code upon check-in
        </div>
      </div>

      <!-- Advisory Box -->
      <div class="advisory-box">
        <i class="bi bi-info-circle-fill"></i>
        <div>
          <strong>Important Reminder:</strong> Please bring this official invitation (printed copy or digital mobile display) and a valid government-issued ID upon arrival at the venue.
        </div>
      </div>

      <!-- Official Footer Authentication -->
      <div class="doc-footer">
        <div class="doc-footer-left">
          <div><strong>Verification:</strong> <?= e($inv['invitation_code']) ?> · LPHCMS-SECURE</div>
          <div>Issued by Authority of the City Council &amp; Committee Secretariat</div>
        </div>
        <div class="doc-footer-right">
          <div class="sig-title">Office of the City Council</div>
          <div class="text-muted small">City of Manila</div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="<?= e(vendorAsset('qrcodejs/qrcode.min.js', 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js')) ?>"></script>
<script>
  // Render high-contrast QR code
  new QRCode(document.getElementById('qrHolder'), {
    text: <?= json_encode($qrValue) ?>,
    width: 130,
    height: 130,
    colorDark: "#0a2540",
    colorLight: "#ffffff",
    correctLevel: QRCode.CorrectLevel.H
  });
</script>
</body>
</html>
