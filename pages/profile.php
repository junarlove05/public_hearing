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
$adminPolicy = ($user && $user['role_name'] === 'Administrator') 
    ? lphGetAdminPasswordPolicyStatus((int)$user['id']) 
    : null;

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
        --pr-indigo: #0F2137;
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

    /* Cooldown Tracker Styling */
    .cooldown-timer-box {
        background: linear-gradient(135deg, #071426 0%, #1e293b 100%);
        border: 1px solid #334155;
        border-radius: 12px;
        padding: 1.1rem;
        text-align: center;
        color: #fff;
        margin-bottom: 1rem;
    }

    .cooldown-countdown-text {
        font-family: 'Courier New', Courier, monospace;
        font-size: 1.65rem;
        font-weight: 800;
        letter-spacing: 2px;
        color: #FBBF24;
        margin: 0.4rem 0;
    }

    .cooldown-countdown-text.expired {
        color: #ef4444;
        animation: blinkWarning 1.5s infinite;
    }

    @keyframes blinkWarning {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.4; }
    }

    /* Responsive */
    @media (max-width: 768px) {
        .breadcrumb-bar { padding: 1rem; }
        .card-header { font-size: 0.9rem; }
        .col-lg-5 .card { margin-bottom: 1rem; }
    }
</style>
<div class="app-wrapper">
  <?php include __DIR__ . '/../layouts/sidebar.php'; ?>

  <div class="main-content">
    <?php include __DIR__ . '/../layouts/top_controls.php'; ?>
    <div class="breadcrumb-bar">
      <h5 class="mb-0"><i class="bi bi-person-circle"></i> My Profile</h5>
    </div>

    <div class="row g-3">
      <div class="col-lg-5">
        <!-- Account Information Card -->
        <div class="card mb-3">
          <div class="card-header"><i class="bi bi-info-circle"></i> Account Information</div>
          <div class="card-body">
            <table class="table table-borderless mb-0">
              <tr><th class="text-muted small" style="width:40%;">Full Name</th><td><?= e($user['full_name']) ?></td></tr>
              <tr><th class="text-muted small">Email</th><td><?= e($user['email']) ?></td></tr>
              <tr><th class="text-muted small">Role</th><td><span class="badge bg-primary"><?= e($user['role_name']) ?></span></td></tr>
            </table>
          </div>
        </div>

        <!-- 3-Week Admin Password Policy Cooldown Tracker -->
        <?php if ($adminPolicy && $adminPolicy['is_admin']): ?>
        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-clock-history"></i> Admin Password Rotation Policy</span>
            <?php if ($adminPolicy['has_expired']): ?>
              <span class="badge bg-danger"><i class="bi bi-exclamation-triangle-fill"></i> EXPIRED</span>
            <?php elseif ($adminPolicy['days_remaining'] <= 3): ?>
              <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split"></i> EXPIRING SOON</span>
            <?php else: ?>
              <span class="badge bg-success"><i class="bi bi-shield-check"></i> ACTIVE</span>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <div class="cooldown-timer-box">
              <div class="small text-uppercase text-light opacity-75 fw-bold" style="letter-spacing:1px;font-size:0.75rem;">
                3-Week Rotation Cooldown Timer
              </div>
              <div class="cooldown-countdown-text <?= $adminPolicy['has_expired'] ? 'expired' : '' ?>" id="cooldownDisplay">
                <?= $adminPolicy['has_expired'] ? 'OVERDUE (0d : 00h : 00m : 00s)' : 'Calculating...' ?>
              </div>
              <div class="small opacity-75" style="font-size:0.78rem;">
                <?= $adminPolicy['has_expired'] ? 'Password change is mandatory immediately.' : 'Time left before mandatory password rotation.' ?>
              </div>
            </div>

            <!-- Progress bar -->
            <div class="mb-3">
              <div class="d-flex justify-content-between small text-muted mb-1">
                <span>Cycle Progress (21 Days)</span>
                <span><?= $adminPolicy['percent_elapsed'] ?>% elapsed</span>
              </div>
              <div class="progress" style="height: 8px; border-radius: 6px; background:#e2e8f0;">
                <div 
                  class="progress-bar <?= $adminPolicy['has_expired'] ? 'bg-danger' : ($adminPolicy['percent_elapsed'] > 75 ? 'bg-warning' : 'bg-success') ?>" 
                  role="progressbar" 
                  style="width: <?= $adminPolicy['percent_elapsed'] ?>%;" 
                  aria-valuenow="<?= $adminPolicy['percent_elapsed'] ?>" 
                  aria-valuemin="0" 
                  aria-valuemax="100"
                ></div>
              </div>
            </div>

            <table class="table table-sm table-borderless small mb-0">
              <tr>
                <th class="text-muted" style="width:48%;">Rotation Rule</th>
                <td class="fw-semibold">Every 3 Weeks (21 Days)</td>
              </tr>
              <tr>
                <th class="text-muted">Last Changed</th>
                <td><?= e($adminPolicy['last_changed_at']) ?></td>
              </tr>
              <tr>
                <th class="text-muted">Next Deadline</th>
                <td class="<?= $adminPolicy['has_expired'] ? 'text-danger fw-bold' : '' ?>"><?= e($adminPolicy['deadline_at']) ?></td>
              </tr>
              <tr>
                <th class="text-muted">Status</th>
                <td>
                  <?php if ($adminPolicy['has_expired']): ?>
                    <span class="text-danger fw-bold"><i class="bi bi-x-circle"></i> Expired (Past 21 Days)</span>
                  <?php else: ?>
                    <span class="text-success fw-bold"><i class="bi bi-check-circle"></i> <?= $adminPolicy['days_remaining'] ?> day(s) remaining</span>
                  <?php endif; ?>
                </td>
              </tr>
            </table>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <div class="col-lg-7">
        <!-- Change Password Card -->
        <div class="card">
          <div class="card-header"><i class="bi bi-shield-lock"></i> Change Password</div>
          <div class="card-body">
            <?php if ($adminPolicy && $adminPolicy['has_expired']): ?>
              <div class="alert alert-danger d-flex align-items-center gap-2 mb-3 py-2" role="alert">
                <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                <div class="small">
                  <strong>Password Expired:</strong> Your administrator password has surpassed the 3-week security rotation requirement. Please create a new password below to reset your cooldown timer.
                </div>
              </div>
            <?php endif; ?>

            <form action="<?= e(APP_URL) ?>/auth/process_change_password.php" method="POST">
              <?= csrfField() ?>
              <div class="mb-3">
                <label class="form-label">Current Password</label>
                <input type="password" name="current_password" class="form-control" required placeholder="Enter current password">
              </div>
              <div class="mb-3">
                <label class="form-label">New Password</label>
                <input type="password" name="new_password" class="form-control" minlength="8" required placeholder="Enter new secure password">
                <div class="form-text">Minimum 8 characters. Changing your password resets the 3-week rotation countdown.</div>
              </div>
              <div class="mb-3">
                <label class="form-label">Confirm New Password</label>
                <input type="password" name="confirm_password" class="form-control" minlength="8" required placeholder="Confirm new password">
              </div>
              <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Update Password &amp; Reset Cooldown</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if ($adminPolicy && $adminPolicy['is_admin'] && !$adminPolicy['has_expired']): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    let remainingSecs = <?= (int)$adminPolicy['seconds_remaining'] ?>;
    const cooldownEl = document.getElementById('cooldownDisplay');

    function updateCooldown() {
        if (!cooldownEl) return;

        if (remainingSecs <= 0) {
            cooldownEl.textContent = 'EXPIRED (0d : 00h : 00m : 00s)';
            cooldownEl.classList.add('expired');
            return;
        }

        const days = Math.floor(remainingSecs / 86400);
        const hours = Math.floor((remainingSecs % 86400) / 3600);
        const minutes = Math.floor((remainingSecs % 3600) / 60);
        const seconds = remainingSecs % 60;

        const pad = (n) => String(n).padStart(2, '0');
        cooldownEl.textContent = `${days}d : ${pad(hours)}h : ${pad(minutes)}m : ${pad(seconds)}s`;

        remainingSecs--;
        setTimeout(updateCooldown, 1000);
    }

    updateCooldown();
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../layouts/footer.php'; ?>