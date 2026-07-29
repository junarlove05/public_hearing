<?php
/**
 * pages/403.php
 * ------------------------------------------------------------------
 * Rendered by includes/auth.php::requireRole() when a logged-in user
 * tries to access a module their role isn't permitted to use.
 * ------------------------------------------------------------------
 */
if (!function_exists('e')) { require_once __DIR__ . '/../includes/functions.php'; }
$pageTitle = 'Access Denied';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Access Denied</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="<?= e(vendorAsset('bootstrap/bootstrap.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css')) ?>" rel="stylesheet">
<link href="<?= e(vendorAsset('bootstrap-icons/bootstrap-icons.css', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css')) ?>" rel="stylesheet">
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 bg-light">
<div class="text-center">
  <i class="bi bi-shield-lock display-1 text-danger"></i>
  <h2 class="mt-3">403 &mdash; Access Denied</h2>
  <p class="text-muted">You do not have permission to view this page.</p>
  <a href="<?= defined('APP_URL') ? e(APP_URL) : '/' ?>/dashboard.php" class="btn btn-primary mt-2">
    <i class="bi bi-house"></i> Back to Dashboard
  </a>
</div>
</body>
</html>
