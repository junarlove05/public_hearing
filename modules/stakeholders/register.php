<?php
/**
 * modules/stakeholders/register.php
 * ------------------------------------------------------------------
 * Public Stakeholder Self-Registration Page accessed via QR Code.
 * Requires Valid ID upload and marks the new stakeholder as 'Pending'
 * for Administrator review and verification.
 * ------------------------------------------------------------------
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';

$pdo = db();
lphEnsureMultiDayAttendanceSchema($pdo);
$pageTitle = 'Stakeholder Registration · Public Hearing';

// Fetch active categories
$categories = $pdo->query('SELECT id, name FROM stakeholder_categories ORDER BY id ASC')->fetchAll();

$errors = [];
$success = false;
$submittedData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName     = clean($_POST['full_name'] ?? '');
    $email        = clean($_POST['email'] ?? '');
    $phone        = clean($_POST['phone'] ?? '');
    $organization = clean($_POST['organization'] ?? '');
    $sector       = clean($_POST['sector'] ?? '');
    $categoryId   = (int)($_POST['category_id'] ?? 0) ?: null;
    $address      = clean($_POST['address'] ?? '');
    $consent      = !empty($_POST['consent']);

    $submittedData = [
        'full_name'    => $fullName,
        'email'        => $email,
        'phone'        => $phone,
        'organization' => $organization,
        'sector'       => $sector,
        'category_id'  => $categoryId,
        'address'      => $address,
    ];

    if ($fullName === '') {
        $errors[] = 'Full name is required.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required for official hearing notifications.';
    }
    if ($organization === '') {
        $errors[] = 'Organization, agency, or office name is required.';
    }
    if (!$categoryId) {
        $errors[] = 'Please select a Department or Stakeholder Category.';
    }
    if (!$consent) {
        $errors[] = 'Please confirm that the submitted information and ID are authentic.';
    }

    // Validate Valid ID file upload (MANDATORY)
    if (empty($_FILES['valid_id']) || $_FILES['valid_id']['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Valid ID upload is required for registration verification.';
    }

    // Check duplicate email
    if (empty($errors) && $email !== '') {
        $dupStmt = $pdo->prepare('SELECT id, status FROM stakeholders WHERE email = :email LIMIT 1');
        $dupStmt->execute([':email' => $email]);
        $existing = $dupStmt->fetch();
        if ($existing) {
            if ($existing['status'] === 'Verified') {
                $errors[] = 'An active verified stakeholder is already registered with this email address. Please contact the administrator.';
            } else {
                $errors[] = 'A registration with this email is currently ' . $existing['status'] . '. Please wait for Administrator approval.';
            }
        }
    }

    // Process ID Upload if no errors
    $validIdPath = null;
    if (empty($errors)) {
        if (!function_exists('uploadFile')) {
            require_once __DIR__ . '/../../includes/functions.php';
        }
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
        $uploadResult = function_exists('uploadFile')
            ? uploadFile($_FILES['valid_id'], 'stakeholder_ids', $allowedExtensions, 10 * 1024 * 1024)
            : handleUpload($_FILES['valid_id'], 'stakeholder_ids');
        if (!$uploadResult['success']) {
            $errors[] = $uploadResult['message'] ?? 'Failed to upload Valid ID.';
        } else {
            $validIdPath = $uploadResult['file_path'];
        }
    }

    // Save Record
    if (empty($errors) && $validIdPath) {
        try {
            $insert = $pdo->prepare(
                'INSERT INTO stakeholders 
                 (full_name, email, phone, organization, sector, category_id, address, valid_id_path, status, created_at, updated_at)
                 VALUES (:name, :email, :phone, :org, :sector, :cat, :addr, :id_path, "Pending", NOW(), NOW())'
            );
            $insert->execute([
                ':name'    => $fullName,
                ':email'   => $email,
                ':phone'   => $phone ?: null,
                ':org'     => $organization,
                ':sector'  => $sector ?: null,
                ':cat'     => $categoryId,
                ':addr'    => $address ?: null,
                ':id_path' => $validIdPath,
            ]);

            $newId = (int)$pdo->lastInsertId();
            $success = true;
        } catch (PDOException $e) {
            error_log('Public stakeholder register error: ' . $e->getMessage());
            $errors[] = 'A system error occurred while processing your registration. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($pageTitle) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --lph-primary: #0F2137;
      --lph-gold: #c89523;
      --lph-gold-hover: #b08119;
      --lph-bg: #f8fafc;
    }
    body {
      font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: linear-gradient(135deg, #0A1728 0%, #0F2137 60%, #173559 100%);
      min-height: 100vh;
      color: #334155;
      padding: 2rem 1rem;
    }
    .reg-card {
      background: #ffffff;
      border-radius: 16px;
      box-shadow: 0 20px 45px rgba(0, 0, 0, 0.35);
      border: 1px solid rgba(255, 255, 255, 0.2);
      overflow: hidden;
      max-width: 760px;
      margin: 0 auto;
    }
    .reg-header {
      background: #0F2137;
      color: #ffffff;
      padding: 2.25rem 2rem 1.75rem;
      border-bottom: 3.5px solid var(--lph-gold);
      position: relative;
    }
    .reg-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 0.5px;
      text-transform: uppercase;
      background: rgba(200, 149, 35, 0.2);
      color: #e5b958;
      border: 1px solid rgba(200, 149, 35, 0.4);
      padding: 0.25rem 0.65rem;
      border-radius: 20px;
      margin-bottom: 0.75rem;
    }
    .form-label {
      font-size: 0.8rem;
      font-weight: 600;
      color: #1e293b;
      margin-bottom: 0.35rem;
    }
    .form-control, .form-select {
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      padding: 0.55rem 0.85rem;
      font-size: 0.9rem;
      transition: all 0.2s ease;
    }
    .form-control:focus, .form-select:focus {
      border-color: var(--lph-gold);
      box-shadow: 0 0 0 3px rgba(200, 149, 35, 0.18);
    }
    .upload-box {
      border: 2px dashed #cbd5e1;
      border-radius: 10px;
      background: #f8fafc;
      padding: 1.5rem;
      text-align: center;
      transition: all 0.2s ease;
      cursor: pointer;
    }
    .upload-box:hover, .upload-box.dragover {
      border-color: var(--lph-gold);
      background: #fefce8;
    }
    .btn-submit-reg {
      background: var(--lph-gold);
      color: #0F2137;
      font-weight: 700;
      border: none;
      border-radius: 10px;
      padding: 0.8rem 1.75rem;
      transition: all 0.2s ease;
    }
    .btn-submit-reg:hover {
      background: var(--lph-gold-hover);
      color: #000;
      transform: translateY(-1px);
      box-shadow: 0 6px 16px rgba(200, 149, 35, 0.35);
    }
    .preview-thumb {
      max-height: 160px;
      border-radius: 8px;
      border: 1px solid #e2e8f0;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
      margin-top: 0.75rem;
      display: none;
    }
  </style>
</head>
<body>

<div class="container py-3">
  <div class="reg-card">
    <div class="reg-header text-center">
      <div class="reg-badge"><i class="bi bi-qr-code-scan"></i> QR Registration Portal</div>
      <h3 class="fw-bold mb-1">Stakeholder Registration</h3>
      <p class="text-white-50 mb-0 small">Legislative Public Hearing Subsystem (LPH) · City of Manila</p>
    </div>

    <div class="p-4 p-md-5">
      <?php if ($success): ?>
        <div class="text-center py-4">
          <div class="rounded-circle d-inline-flex align-items-center justify-content-center bg-success bg-opacity-10 text-success mb-3" style="width: 72px; height: 72px;">
            <i class="bi bi-check2-circle fs-1"></i>
          </div>
          <h4 class="fw-bold text-dark">Registration Successfully Submitted!</h4>
          <p class="text-muted mb-4" style="max-width: 520px; margin: 0 auto; font-size: 0.95rem;">
            Thank you, <strong><?= e($fullName) ?></strong>. Your registration and Valid ID have been received and are currently under 
            <span class="badge bg-warning text-dark px-2.5 py-1.5"><i class="bi bi-hourglass-split me-1"></i>Pending Verification</span>.
          </p>

          <div class="card bg-light border-0 text-start p-3.5 mb-4 mx-auto" style="max-width: 520px; border-radius: 10px;">
            <div class="d-flex justify-content-between mb-2 pb-2 border-bottom">
              <span class="small text-muted">Organization / Agency:</span>
              <span class="small fw-bold text-dark"><?= e($organization) ?></span>
            </div>
            <div class="d-flex justify-content-between mb-2 pb-2 border-bottom">
              <span class="small text-muted">Email Address:</span>
              <span class="small fw-semibold text-dark"><?= e($email) ?></span>
            </div>
            <div class="d-flex justify-content-between mb-2 pb-2 border-bottom">
              <span class="small text-muted">Status:</span>
              <span class="badge bg-warning text-dark">Pending Admin Approval</span>
            </div>
            <div class="d-flex justify-content-between">
              <span class="small text-muted">Valid ID:</span>
              <span class="small text-success fw-semibold"><i class="bi bi-file-earmark-check-fill me-1"></i>Uploaded for Review</span>
            </div>
          </div>

          <div class="alert alert-info border-0 d-flex align-items-start gap-2 mb-4 mx-auto text-start" style="max-width: 520px; font-size: 0.85rem;">
            <i class="bi bi-info-circle-fill text-info fs-5 mt-0.5"></i>
            <div>
              <strong>Notice:</strong> The Administrator will review your submitted Valid ID. Once approved, your official Attendance QR Code pass will be issued for public hearings.
            </div>
          </div>

          <a href="<?= e(APP_URL . '/login.php') ?>" class="btn btn-outline-dark px-4 fw-semibold">
            <i class="bi bi-arrow-left me-1"></i> Return to Portal
          </a>
        </div>
      <?php else: ?>

        <?php if (!empty($errors)): ?>
          <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
            <div class="d-flex align-items-center gap-2 mb-1 fw-bold">
              <i class="bi bi-exclamation-triangle-fill"></i> Please correct the following errors:
            </div>
            <ul class="mb-0 ps-3 small">
              <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
              <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>

        <div class="alert alert-light border border-secondary border-opacity-25 d-flex align-items-start gap-2.5 mb-4 py-2.5 px-3">
          <i class="bi bi-shield-check text-primary fs-5 mt-0.5"></i>
          <div class="small text-muted">
            <strong class="text-dark">Public Registration Notice:</strong> All self-registered stakeholders must upload a <strong>Valid ID</strong>. Records are submitted as <strong>Pending</strong> and require Administrator review and approval before activation.
          </div>
        </div>

        <form method="POST" enctype="multipart/form-data" id="publicRegForm">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="full_name" class="form-control" placeholder="Hal. Atty. Eduardo Quintos XIV" value="<?= e($submittedData['full_name'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Email Address <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control" placeholder="official@manila.gov.ph or personal email" value="<?= e($submittedData['email'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Contact / Phone Number <span class="text-muted small">(Optional)</span></label>
              <input type="text" name="phone" class="form-control" placeholder="0917-xxxxxxx / (02) 8521-7505" value="<?= e($submittedData['phone'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Department / Stakeholder Category <span class="text-danger">*</span></label>
              <select name="category_id" class="form-select" required>
                <option value="">-- Select Category --</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int)$c['id'] ?>" <?= ((int)($submittedData['category_id'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>>
                    <?= e(preg_replace('/^[A-H]\s*\.?\s*/', '', $c['name'])) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Organization / Agency / Barangay <span class="text-danger">*</span></label>
              <input type="text" name="organization" class="form-control" placeholder="e.g. Barangay 659-A, Office of the Mayor, MMDA" value="<?= e($submittedData['organization'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Sector / Designation / Title</label>
              <input type="text" name="sector" class="form-control" placeholder="e.g. Barangay Captain, Director, OIC, President" value="<?= e($submittedData['sector'] ?? '') ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Office / Residential Address</label>
              <textarea name="address" class="form-control" rows="2" placeholder="e.g. Manila City Hall, Padre Burgos Ave, Ermita, Manila"><?= e($submittedData['address'] ?? '') ?></textarea>
            </div>

            <!-- MANDATORY VALID ID UPLOAD SECTION -->
            <div class="col-12 mt-4">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <label class="form-label fw-bold text-dark mb-0">
                  <i class="bi bi-person-vcard text-primary me-1"></i> Upload Valid Government / Agency ID <span class="text-danger">*</span>
                </label>
                <span class="text-muted" style="font-size: 0.75rem;">JPG, PNG, PDF (Max 10MB)</span>
              </div>
              
              <div class="upload-box" id="dropArea" onclick="document.getElementById('validIdInput').click();">
                <i class="bi bi-cloud-arrow-up-fill text-muted fs-1 mb-2 d-block"></i>
                <div class="fw-semibold text-dark small" id="uploadLabelText">Click or drag your Valid ID here</div>
                <div class="text-muted" style="font-size: 0.75rem;">(Driver's License, UMID, Passport, PRC ID, Barangay/Company ID)</div>
                <input type="file" name="valid_id" id="validIdInput" class="d-none" accept=".jpg,.jpeg,.png,.pdf" required>
                <img id="previewImage" class="preview-thumb mx-auto" alt="ID Preview">
              </div>
              <div class="form-text text-muted" style="font-size: 0.78rem;">
                <i class="bi bi-shield-lock me-1"></i> Your ID will be used solely for Administrator verification in compliance with the Data Privacy Act.
              </div>
            </div>

            <div class="col-12 mt-3">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="consent" id="consentCheck" required>
                <label class="form-check-label small text-secondary" for="consentCheck">
                  I hereby certify that all information provided and the submitted Valid ID are true, accurate, and correct for Administrator review.
                </label>
              </div>
            </div>

            <div class="col-12 mt-4 pt-2 border-top text-end">
              <button type="submit" class="btn btn-submit-reg w-100 py-2.5 shadow-sm" id="btnSubmitForm">
                <i class="bi bi-send-check-fill me-1"></i> Submit Registration
              </button>
            </div>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const fileInput = document.getElementById('validIdInput');
  const previewImg = document.getElementById('previewImage');
  const labelText = document.getElementById('uploadLabelText');
  const dropArea = document.getElementById('dropArea');

  if (fileInput) {
    fileInput.addEventListener('change', function() {
      if (this.files && this.files[0]) {
        const file = this.files[0];
        labelText.innerHTML = `<span class="text-success fw-bold"><i class="bi bi-file-earmark-check me-1"></i>Selected: ${file.name}</span> (${(file.size / 1024 / 1024).toFixed(2)} MB)`;
        
        if (file.type.startsWith('image/')) {
          const reader = new FileReader();
          reader.onload = function(e) {
            previewImg.src = e.target.result;
            previewImg.style.display = 'block';
          };
          reader.readAsDataURL(file);
        } else {
          previewImg.style.display = 'none';
        }
      }
    });

    ['dragenter', 'dragover'].forEach(eventName => {
      dropArea.addEventListener(eventName, (e) => {
        e.preventDefault();
        e.stopPropagation();
        dropArea.classList.add('dragover');
      });
    });

    ['dragleave', 'drop'].forEach(eventName => {
      dropArea.addEventListener(eventName, (e) => {
        e.preventDefault();
        e.stopPropagation();
        dropArea.classList.remove('dragover');
      });
    });

    dropArea.addEventListener('drop', (e) => {
      const dt = e.dataTransfer;
      const files = dt.files;
      if (files && files.length > 0) {
        fileInput.files = files;
        fileInput.dispatchEvent(new Event('change'));
      }
    });
  }

  const regForm = document.getElementById('publicRegForm');
  if (regForm) {
    regForm.addEventListener('submit', function() {
      const btn = document.getElementById('btnSubmitForm');
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Submitting registration...';
      }
    });
  }
});
</script>
</body>
</html>
