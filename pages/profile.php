<?php
/**
 * pages/profile.php
 * ------------------------------------------------------------------
 * Displays the logged-in user's profile and a "Change Password" form.
 * Form POSTs to auth/process_change_password.php.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$pageTitle  = 'My Profile';
$activeMenu = '';
$user = currentUser();

include __DIR__ . '/../layouts/header.php';
?>
<style>
    /* Profile - Dark Theme */
    :root {
        --pr-dark-900: #0F172A;
        --pr-dark-800: #1E293B;
        --pr-dark-700: #334155;
        --pr-amber: #F59E0B;
        --pr-amber-light: #FBBF24;
        --pr-white: #FFFFFF;
        --pr-gray-100: #F1F5F9;
        --pr-gray-200: #E2E8F0;
        --pr-gray-300: #CBD5E1;
        --pr-gray-400: #94A3B8;
        --pr-gray-500: #64748B;
        --pr-gray-600: #475569;
        --pr-emerald: #10B981;
        --pr-rose: #F43F5E;
        --pr-violet: #8B5CF6;
        --pr-cyan: #06B6D4;
        --pr-indigo: #6366F1;
    }

    /* Breadcrumb Bar */
    .breadcrumb-bar {
        background: var(--pr-white);
        border-left: 4px solid var(--pr-amber);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
        padding: 1rem 1.5rem;
        border-radius: 12px;
        margin-bottom: 1.5rem;
    }

    .breadcrumb-bar h5 {
        color: var(--pr-dark-900);
        font-weight: 700;
    }

    .breadcrumb-bar h5 i {
        color: var(--pr-amber);
    }

    /* Cards */
    .card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 2px 15px rgba(0, 0, 0, 0.06);
        background: var(--pr-white);
        transition: all 0.3s ease;
        overflow: hidden;
    }

    .card:hover {
        box-shadow: 0 4px 25px rgba(0, 0, 0, 0.1);
    }

    .card-header {
        background: linear-gradient(135deg, var(--pr-dark-900) 0%, var(--pr-dark-800) 100%);
        color: var(--pr-white);
        font-weight: 600;
        padding: 0.75rem 1.25rem;
        border-bottom: 3px solid var(--pr-amber);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .card-header i {
        color: var(--pr-amber);
        font-size: 1.1rem;
    }

    .card-body {
        padding: 1.25rem;
        background: var(--pr-white);
    }

    /* Table */
    .table {
        margin-bottom: 0;
    }

    .table-borderless td,
    .table-borderless th {
        padding: 0.6rem 0;
    }

    .table-borderless tr:first-child td,
    .table-borderless tr:first-child th {
        padding-top: 0;
    }

    .table-borderless tr:last-child td,
    .table-borderless tr:last-child th {
        padding-bottom: 0;
    }

    .table th.text-muted {
        color: var(--pr-gray-500) !important;
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .table td {
        color: var(--pr-dark-900);
        font-weight: 500;
    }

    /* Badge */
    .badge.bg-primary {
        background: linear-gradient(135deg, var(--pr-dark-900) 0%, var(--pr-dark-800) 100%) !important;
        color: var(--pr-white) !important;
        border: 1px solid var(--pr-amber);
        font-weight: 600;
        padding: 0.3rem 0.8rem;
        border-radius: 20px;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    /* Buttons */
    .btn-primary {
        background: linear-gradient(135deg, var(--pr-dark-900) 0%, var(--pr-dark-800) 100%);
        border: 1px solid rgba(245, 158, 11, 0.15);
        color: var(--pr-white);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        border-radius: 10px;
        font-weight: 600;
        padding: 0.45rem 1.25rem;
        transition: all 0.3s ease;
    }

    .btn-primary i {
        color: var(--pr-amber);
    }

    .btn-primary:hover {
        border-color: var(--pr-amber);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.25);
        color: var(--pr-white);
        transform: translateY(-2px);
    }

    /* Form Controls */
    .form-control {
        border: 2px solid var(--pr-gray-200);
        border-radius: 10px;
        padding: 0.5rem 1rem;
        font-size: 0.875rem;
        transition: all 0.3s ease;
        background: var(--pr-gray-100);
        color: var(--pr-dark-900);
        font-weight: 500;
    }

    .form-control:focus {
        border-color: var(--pr-amber);
        box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.12);
        background: var(--pr-white);
    }

    .form-control::placeholder {
        color: var(--pr-gray-400);
        font-weight: 400;
    }

    .form-label {
        font-weight: 600;
        color: var(--pr-dark-900);
        font-size: 0.85rem;
    }

    .form-text {
        color: var(--pr-gray-500) !important;
        font-size: 0.75rem;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .breadcrumb-bar {
            padding: 1rem;
        }
        .card-header {
            font-size: 0.9rem;
        }
        .col-lg-5 .card {
            margin-bottom: 1rem;
        }
    }

    @media (max-width: 576px) {
        .breadcrumb-bar h5 {
            font-size: 0.95rem;
        }
        .card-body {
            padding: 0.75rem;
        }
        .form-control {
            font-size: 0.75rem;
            padding: 0.3rem 0.6rem;
        }
        .table th.text-muted {
            font-size: 0.65rem;
        }
        .table td {
            font-size: 0.8rem;
        }
        .btn-primary {
            font-size: 0.85rem;
            padding: 0.35rem 1rem;
            width: 100%;
        }
    }
</style>
<div class="app-wrapper">
  <?php include __DIR__ . '/../layouts/sidebar.php'; ?>

  <div class="main-content">
    <div class="breadcrumb-bar">
      <h5 class="mb-0"><i class="bi bi-person-circle"></i> My Profile</h5>
    </div>

    <div class="row g-3">
      <div class="col-lg-5">
        <div class="card">
          <div class="card-header"><i class="bi bi-info-circle"></i> Account Information</div>
          <div class="card-body">
            <table class="table table-borderless mb-0">
              <tr><th class="text-muted small" style="width:40%;">Full Name</th><td><?= e($user['full_name']) ?></td></tr>
              <tr><th class="text-muted small">Email</th><td><?= e($user['email']) ?></td></tr>
              <tr><th class="text-muted small">Role</th><td><span class="badge bg-primary"><?= e($user['role_name']) ?></span></td></tr>
            </table>
          </div>
        </div>
      </div>

      <div class="col-lg-7">
        <div class="card">
          <div class="card-header"><i class="bi bi-shield-lock"></i> Change Password</div>
          <div class="card-body">
            <form action="<?= e(APP_URL) ?>/auth/process_change_password.php" method="POST">
              <?= csrfField() ?>
              <div class="mb-3">
                <label class="form-label">Current Password</label>
                <input type="password" name="current_password" class="form-control" required>
              </div>
              <div class="mb-3">
                <label class="form-label">New Password</label>
                <input type="password" name="new_password" class="form-control" minlength="8" required>
                <div class="form-text">Minimum 8 characters.</div>
              </div>
              <div class="mb-3">
                <label class="form-label">Confirm New Password</label>
                <input type="password" name="confirm_password" class="form-control" minlength="8" required>
              </div>
              <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Update Password</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../layouts/footer.php'; ?>