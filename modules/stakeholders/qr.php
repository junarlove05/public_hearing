<?php
/**
 * modules/stakeholders/qr.php
 * ------------------------------------------------------------------
 * Displays a stakeholder's unique QR identification code (rendered
 * client-side via qrcode.js from the stored code_value), with print
 * and PNG-download actions. This QR is scanned later in the
 * Attendance Tracking module for check-in.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
$pdo = db();

$stmt = $pdo->prepare(
    'SELECT s.*, sc.name AS category_name, q.code_value
     FROM stakeholders s
     LEFT JOIN stakeholder_categories sc ON sc.id = s.category_id
     LEFT JOIN qr_codes q ON q.stakeholder_id = s.id
     WHERE s.id = :id LIMIT 1'
);
$stmt->execute([':id' => $id]);
$stakeholder = $stmt->fetch();

if (!$stakeholder) {
    setFlash('danger', 'Stakeholder not found.');
    redirect(APP_URL . '/modules/stakeholders/index.php');
}

// A stakeholder created before this module existed might not have a QR row yet — self-heal.
if (empty($stakeholder['code_value']) && canManage()) {
    $code = generateCode('STK-');
    $pdo->prepare('INSERT INTO qr_codes (stakeholder_id, code_value, created_at) VALUES (:sid, :code, NOW())')
        ->execute([':sid' => $id, ':code' => $code]);
    $stakeholder['code_value'] = $code;
}

$pageTitle  = 'QR Code - ' . $stakeholder['full_name'];
$activeMenu = 'stakeholders';

include __DIR__ . '/../../layouts/header.php';
?>
<style>
    /* ============================================================
       QR PAGE - Dark Coastal Blue Theme
       ============================================================ */
    :root {
        --db-dark-blue-darkest: #0a1628;
        --db-dark-blue-darker: #0d2137;
        --db-dark-blue-dark: #122a45;
        --db-dark-blue-medium: #1a365d;
        --db-dark-blue-primary: #1e4a7a;
        --db-dark-blue-light: #2d6a9f;
        --db-dark-blue-soft: #4a8fc9;
        --db-dark-blue-pale: #D6E4F0;
        --db-text-dark: #0a1628;
        --db-text-light: #E8EEF5;
        --db-yellow: #FFD700;
        --db-yellow-soft: #FFC107;
        --db-gray-500: #64748B;
        --db-gray-600: #475569;
    }

    .main-content {
        padding: 0.5rem 1.5rem 1.5rem 1.5rem;
        margin-top: 20px;
        min-height: calc(100vh - 72px);
        background: #E8EEF5;
    }

    /* Breadcrumb Bar */
    .breadcrumb-bar {
        background: var(--db-white);
        border-left: 4px solid var(--db-dark-blue-primary);
        box-shadow: 0 2px 15px rgba(13, 33, 55, 0.1);
        padding: 0.75rem 1.25rem;
        border-radius: 12px;
        margin-bottom: 0.75rem;
        margin-top: 0;
    }

    .breadcrumb-bar a {
        color: var(--db-dark-blue-primary) !important;
        font-weight: 500;
    }

    .breadcrumb-bar a:hover {
        color: var(--db-text-dark) !important;
        text-decoration: none;
    }

    /* Card styling */
    .card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 4px 20px rgba(13, 33, 55, 0.1);
        background: var(--db-white);
        border: 1px solid rgba(13, 33, 55, 0.06);
        transition: all 0.3s ease;
    }

    .card:hover {
        box-shadow: 0 6px 30px rgba(13, 33, 55, 0.15);
        border-color: rgba(13, 33, 55, 0.12);
    }

    .card-body {
        background: var(--db-white);
        padding: 2rem;
    }

    .card-body h5 {
        color: var(--db-dark-blue-darkest);
        font-weight: 700;
    }

    .card-body .text-muted {
        color: var(--db-gray-500) !important;
    }

    .card-body h6 {
        color: var(--db-text-dark);
        font-size: 1.1rem;
    }

    /* QR Code container */
    #qrcodeCanvas {
        background: white;
        padding: 15px;
        border-radius: 12px;
        border: 2px solid var(--db-dark-blue-soft);
        display: inline-block;
    }

    #qrcodeCanvas img,
    #qrcodeCanvas canvas {
        display: block;
        margin: 0 auto;
    }

    /* Code value display */
    .font-monospace {
        background: var(--db-dark-blue-pale) !important;
        color: var(--db-text-dark);
        padding: 0.5rem 1rem;
        border-radius: 8px;
        font-weight: 600;
        border: 1px solid var(--db-dark-blue-soft) !important;
    }

    /* Status badge with YELLOW border */
    .badge {
        padding: 0.5rem 1rem;
        font-size: 0.85rem;
        font-weight: 600;
        border-radius: 8px;
        border: 1px solid var(--db-yellow) !important;
        background: linear-gradient(135deg, var(--db-dark-blue-darkest), var(--db-dark-blue-dark)) !important;
        color: var(--db-white) !important;
        box-shadow: 0 2px 8px rgba(255, 215, 0, 0.2);
    }

    /* Status badge variants */
    .badge.bg-success {
        background: linear-gradient(135deg, #0d2137, #1a365d) !important;
        border-color: var(--db-yellow) !important;
        color: white !important;
    }

    .badge.bg-warning {
        background: linear-gradient(135deg, #0d2137, #1a365d) !important;
        border-color: var(--db-yellow) !important;
        color: white !important;
    }

    .badge.bg-danger {
        background: linear-gradient(135deg, #0d2137, #1a365d) !important;
        border-color: var(--db-yellow) !important;
        color: white !important;
    }

    .badge.bg-secondary {
        background: linear-gradient(135deg, #0d2137, #1a365d) !important;
        border-color: var(--db-yellow) !important;
        color: white !important;
    }

    /* Button styling */
    .btn-outline-secondary {
        border-color: var(--db-dark-blue-soft) !important;
        color: var(--db-dark-blue-primary) !important;
    }

    .btn-outline-secondary:hover {
        background: var(--db-dark-blue-primary) !important;
        border-color: var(--db-dark-blue-primary) !important;
        color: white !important;
    }

    .btn-outline-primary {
        border-color: var(--db-dark-blue-primary) !important;
        color: var(--db-dark-blue-primary) !important;
    }

    .btn-outline-primary:hover {
        background: var(--db-dark-blue-primary) !important;
        border-color: var(--db-dark-blue-primary) !important;
        color: white !important;
    }

    /* Print styles */
    @media print {
        .no-print {
            display: none !important;
        }
        .main-content {
            background: white !important;
            margin: 0 !important;
            padding: 20px !important;
        }
        .card {
            box-shadow: none !important;
            border: 1px solid #ddd !important;
        }
        #qrcodeCanvas {
            border-color: #333 !important;
        }
        .badge {
            border-color: #FFD700 !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
    }

    /* Responsive */
    @media (max-width: 768px) {
        .main-content {
            padding: 0.5rem 0.75rem 1rem 0.75rem;
        }
        .card-body {
            padding: 1.5rem;
        }
        #qrcodeCanvas {
            padding: 10px;
        }
        #qrcodeCanvas img,
        #qrcodeCanvas canvas {
            width: 180px !important;
            height: 180px !important;
        }
    }

    @media (max-width: 576px) {
        .main-content {
            padding: 0.25rem 0.5rem 0.75rem 0.5rem;
        }
        .card-body {
            padding: 1rem;
        }
        #qrcodeCanvas img,
        #qrcodeCanvas canvas {
            width: 150px !important;
            height: 150px !important;
        }
        .breadcrumb-bar {
            flex-direction: column;
            gap: 0.5rem;
            align-items: flex-start;
        }
    }

    .main-content,
    .orlms-main-content {
        margin-left: 286px !important;
        padding-top: 0.5rem !important;
        padding-left: 1.5rem !important;
        padding-right: 1.5rem !important;
        min-height: 100vh !important;
        position: relative !important;
        transition: margin-left 0.25s ease !important;
    }

    body.sidebar-collapsed .main-content,
    body.sidebar-collapsed .orlms-main-content,
    .main-content.sidebar-collapsed {
        margin-left: 74px !important;
    }

    /* When sidebar is completely hidden on mobile */
    @media (max-width: 992px) {
        .main-content {
            margin-left: 0 !important;
            max-width: 100% !important;
            padding: 15px !important;
        }
    }
</style>

<link href="<?= e(vendorAsset('qrcodejs/qrcode.min.js', 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js')) ?>" rel="preload" as="script">
<div class="app-wrapper">
  <?php include __DIR__ . '/../../layouts/sidebar.php'; ?>

  <div class="main-content">
    <div class="breadcrumb-bar d-flex justify-content-between align-items-center no-print">
      <a href="index.php" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> Back to Stakeholders</a>
      <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
        <button class="btn btn-outline-primary btn-sm" id="btnDownloadQr"><i class="bi bi-download"></i> Download PNG</button>
      </div>
    </div>

    <div class="d-flex justify-content-center mt-4">
      <div class="card" style="max-width: 420px;">
        <div class="card-body text-center p-4">
          <h5 class="mb-1"><?= e(APP_NAME) ?></h5>
          <div class="text-muted small mb-3">Stakeholder Identification QR Code</div>

          <div id="qrcodeCanvas" class="d-flex justify-content-center my-3"></div>

          <h6 class="fw-bold mb-0"><?= e($stakeholder['full_name']) ?></h6>
          <div class="text-muted small"><?= e($stakeholder['organization'] ?: $stakeholder['category_name'] ?: '-') ?></div>
          <div class="font-monospace small mt-2 border rounded py-1 bg-light"><?= e($stakeholder['code_value']) ?></div>
          <div class="mt-2"><?= statusBadge($stakeholder['status']) ?></div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="<?= e(vendorAsset('qrcodejs/qrcode.min.js', 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js')) ?>"></script>
<script>
const qrEl = document.getElementById('qrcodeCanvas');
new QRCode(qrEl, {
  text: <?= json_encode($stakeholder['code_value']) ?>,
  width: 220,
  height: 220,
  correctLevel: QRCode.CorrectLevel.M
});

document.getElementById('btnDownloadQr').addEventListener('click', function () {
  setTimeout(() => {
    const img = qrEl.querySelector('img') || qrEl.querySelector('canvas');
    const link = document.createElement('a');
    link.download = 'qr-<?= e($stakeholder['code_value']) ?>.png';
    link.href = img.tagName === 'CANVAS' ? img.toDataURL('image/png') : img.src;
    link.click();
  }, 100);
});
</script>

<?php include __DIR__ . '/../../layouts/footer.php'; ?>