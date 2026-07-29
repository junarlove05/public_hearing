<?php
/**
 * modules/feedback/index.php
 * ------------------------------------------------------------------
 * Public Feedback Collection (Module 4, part 1 of 2 — see surveys.php
 * for the Survey CRUD half). Administrators/Staff/Committee members
 * see the full management dashboard (search/filter/reply/delete);
 * Stakeholders/Public Users see a submission form plus their own
 * feedback history. The same submission form and AJAX endpoint also
 * power the fully public modules/feedback/submit.php (no login).
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pageTitle  = 'Public Feedback';
$activeMenu = 'feedback';
$activeTab  = 'feedback';
$pdo = db();

$categories = $pdo->query('SELECT id, name FROM feedback_categories ORDER BY name')->fetchAll();
$manager = canManage() || hasRole([ROLE_COMMITTEE]);

// Quick stats for the top cards (visible to managers).
$stats = [];
if ($manager) {
    $stats['total'] = (int)$pdo->query('SELECT COUNT(*) FROM feedback')->fetchColumn();
    $stats['new'] = (int)$pdo->query("SELECT COUNT(*) FROM feedback WHERE status = 'New'")->fetchColumn();
    $stats['replied'] = (int)$pdo->query("SELECT COUNT(*) FROM feedback WHERE status = 'Replied'")->fetchColumn();
    $stats['closed'] = (int)$pdo->query("SELECT COUNT(*) FROM feedback WHERE status = 'Closed'")->fetchColumn();
}

include __DIR__ . '/../../layouts/header.php';
?>
<style>
    /* Feedback - Dark Cards, Gray Labels, Colored Icons */
    :root {
        --fb-dark-900: #0F172A;
        --fb-dark-800: #1E293B;
        --fb-dark-700: #334155;
        --fb-amber: #F59E0B;
        --fb-amber-light: #FBBF24;
        --fb-white: #FFFFFF;
        --fb-gray-300: #CBD5E1;
        --fb-gray-400: #94A3B8;
        --fb-gray-500: #64748B;
        --fb-gray-600: #475569;
        --fb-emerald: #10B981;
        --fb-rose: #F43F5E;
        --fb-violet: #8B5CF6;
        --fb-cyan: #06B6D4;
        --fb-indigo: #6366F1;
        --fb-orange: #F97316;
        --fb-teal: #14B8A6;
    }

    /* Breadcrumb Bar */
    .breadcrumb-bar {
        background: var(--fb-white);
        border-left: 4px solid var(--fb-amber);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
        padding: 1rem 1.5rem;
        border-radius: 12px;
        margin-bottom: 1.5rem;
    }

    .breadcrumb-bar h5 {
        color: var(--fb-dark-900);
        font-weight: 700;
    }

    .breadcrumb-bar h5 i {
        color: var(--fb-amber);
    }

    .breadcrumb-bar .text-muted {
        color: var(--fb-gray-500) !important;
    }

    /* Stat Cards - Dark with Gray Labels & Colored Icons */
    .stat-card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
        cursor: default;
        padding: 1.25rem 1.5rem !important;
        min-height: 120px;
    }

    .stat-card::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, var(--fb-amber-light), var(--fb-amber), var(--fb-amber-light));
        opacity: 0.8;
        transition: opacity 0.3s ease, height 0.3s ease;
    }

    .stat-card:hover::after {
        opacity: 1;
        height: 5px;
        box-shadow: 0 0 25px rgba(245, 158, 11, 0.4);
    }

    .stat-card::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -30%;
        width: 80%;
        height: 200%;
        background: linear-gradient(135deg, rgba(255, 255, 255, 0.04), transparent 60%);
        border-radius: 50%;
        transform: rotate(25deg) scale(0);
        transition: all 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        pointer-events: none;
    }

    .stat-card:hover::before {
        transform: rotate(25deg) scale(1);
    }

    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.25);
    }

    .stat-card .stat-icon {
        font-size: 1.75rem;
        opacity: 1;
        margin-bottom: 0.5rem;
        display: block;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
    }

    .stat-card .stat-icon.icon-amber { color: #FBBF24 !important; }
    .stat-card .stat-icon.icon-emerald { color: #34D399 !important; }
    .stat-card .stat-icon.icon-rose { color: #FB7185 !important; }
    .stat-card .stat-icon.icon-violet { color: #A78BFA !important; }
    .stat-card .stat-icon.icon-cyan { color: #22D3EE !important; }
    .stat-card .stat-icon.icon-indigo { color: #818CF8 !important; }
    .stat-card .stat-icon.icon-orange { color: #FB923C !important; }
    .stat-card .stat-icon.icon-teal { color: #2DD4BF !important; }
    .stat-card .stat-icon.icon-white { color: #FFFFFF !important; }

    .stat-card .stat-value {
        font-size: 2.2rem;
        font-weight: 800;
        line-height: 1.2;
        margin-bottom: 0.25rem;
        color: var(--fb-gray-600);
        text-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        letter-spacing: -0.5px;
    }

    .stat-card .stat-label {
        font-size: 0.75rem;
        opacity: 1;
        font-weight: 500;
        color: #94A3B8 !important;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        text-shadow: none;
    }

    /* Card Colors - Dark */
    .bg-gov-blue {
        background: linear-gradient(135deg, #0F172A 0%, #1E293B 100%);
    }

    .bg-gov-accent {
        background: linear-gradient(135deg, #1E293B 0%, #334155 100%);
    }

    .bg-gov-teal {
        background: linear-gradient(135deg, #134E4A 0%, #115E59 100%);
    }

    .bg-gov-purple {
        background: linear-gradient(135deg, #4C1D95 0%, #5B21B6 100%);
    }

    .bg-gov-amber {
        background: linear-gradient(135deg, #92400E 0%, #78350F 100%);
    }

    .bg-gov-rose {
        background: linear-gradient(135deg, #881337 0%, #9F1239 100%);
    }

    /* Cards */
    .card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 2px 15px rgba(0, 0, 0, 0.06);
        background: var(--fb-white);
    }

    .card:hover {
        box-shadow: 0 4px 25px rgba(0, 0, 0, 0.1);
    }

    .card-body {
        background: var(--fb-white);
    }

    .card-header {
        background: linear-gradient(135deg, var(--fb-dark-900) 0%, var(--fb-dark-800) 100%);
        color: var(--fb-white);
        border-bottom: 3px solid var(--fb-amber);
        padding: 0.75rem 1.25rem;
    }

    .card-header i {
        color: var(--fb-amber);
    }

    .card-header a.small {
        color: var(--fb-amber) !important;
    }

    .card-header a.small:hover {
        color: var(--fb-white) !important;
    }

    /* Buttons */
    .btn-primary {
        background: linear-gradient(135deg, var(--fb-dark-900) 0%, var(--fb-dark-800) 100%);
        border: 1px solid rgba(245, 158, 11, 0.15);
        color: var(--fb-white);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        border-radius: 10px;
        font-weight: 600;
        padding: 0.45rem 1.25rem;
        transition: all 0.3s ease;
    }

    .btn-primary i {
        color: var(--fb-amber);
    }

    .btn-primary:hover {
        border-color: var(--fb-amber);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.25);
        color: var(--fb-white);
        transform: translateY(-2px);
    }

    .btn-secondary {
        background: var(--fb-gray-300);
        border: none;
        color: var(--fb-dark-900);
        border-radius: 10px;
        font-weight: 500;
        transition: all 0.3s ease;
    }

    .btn-secondary:hover {
        background: var(--fb-gray-400);
        transform: translateY(-2px);
    }

    .btn-outline-primary {
        border: 2px solid var(--fb-dark-900);
        color: var(--fb-dark-900);
        background: transparent;
        border-radius: 10px;
        transition: all 0.3s ease;
        font-weight: 500;
    }

    .btn-outline-primary:hover {
        background: var(--fb-dark-900);
        color: var(--fb-white);
        transform: translateY(-2px);
    }

    .btn-outline-primary i {
        color: var(--fb-amber);
    }

    /* Form Controls */
    .form-control,
    .form-select {
        border: 2px solid var(--fb-gray-300);
        border-radius: 10px;
        padding: 0.5rem 1rem;
        font-size: 0.875rem;
        transition: all 0.3s ease;
        background: #FAFAFA;
        color: var(--fb-dark-900);
        font-weight: 500;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: var(--fb-amber);
        box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.12);
        background: var(--fb-white);
    }

    .form-control::placeholder {
        color: var(--fb-gray-400);
        font-weight: 400;
    }

    .form-control-sm,
    .form-select-sm {
        font-size: 0.8rem;
        padding: 0.4rem 0.75rem;
    }

    /* Table */
    #feedbackTableWrap {
        background: var(--fb-white);
        border-radius: 16px;
        overflow: hidden;
    }

    .table {
        margin-bottom: 0;
    }

    .table thead th {
        background: linear-gradient(135deg, var(--fb-dark-900) 0%, var(--fb-dark-800) 100%);
        color: var(--fb-white) !important;
        border-bottom: 4px solid var(--fb-amber);
        font-weight: 600;
        padding: 0.85rem 1.25rem;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        border-color: transparent;
        position: sticky;
        top: 0;
        z-index: 10;
    }

    .table thead th i {
        color: var(--fb-amber);
        margin-right: 0.4rem;
        font-size: 0.9rem;
    }

    .table thead th,
    .table thead th *,
    .table thead th span,
    .table thead th div {
        color: var(--fb-white) !important;
    }

    .table tbody td {
        padding: 0.85rem 1.25rem;
        vertical-align: middle;
        color: var(--fb-dark-900);
        border-bottom: 1px solid var(--fb-gray-300);
        font-size: 0.9rem;
        transition: background 0.2s ease;
    }

    .table tbody tr {
        transition: all 0.2s ease;
    }

    .table tbody tr:hover {
        background: #FFFBEB;
        transform: scale(1.002);
    }

    .table tbody tr:last-child td {
        border-bottom: none;
    }

    /* Badges */
    .table .badge {
        font-weight: 600;
        padding: 0.3rem 0.8rem;
        border-radius: 20px;
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .badge.bg-success {
        background: var(--fb-emerald) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
    }

    .badge.bg-warning {
        background: var(--fb-amber) !important;
        color: var(--fb-dark-900);
        box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
    }

    .badge.bg-danger {
        background: var(--fb-rose) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(244, 63, 94, 0.3);
    }

    .badge.bg-info {
        background: var(--fb-cyan) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(6, 182, 212, 0.3);
    }

    .badge.bg-secondary {
        background: var(--fb-gray-500) !important;
        color: white;
    }

    /* Modal */
    .modal-content {
        border: none;
        border-radius: 20px;
        box-shadow: 0 25px 70px rgba(0, 0, 0, 0.25);
        overflow: hidden;
        border: 1px solid var(--fb-gray-300);
    }

    .modal-header {
        background: linear-gradient(135deg, var(--fb-dark-900) 0%, var(--fb-dark-800) 100%);
        color: var(--fb-white);
        padding: 1.25rem 1.75rem;
        border-bottom: 4px solid var(--fb-amber);
    }

    .modal-header .modal-title {
        color: var(--fb-white);
        font-weight: 700;
    }

    .modal-header .modal-title i {
        color: var(--fb-amber);
        margin-right: 0.6rem;
        background: rgba(245, 158, 11, 0.15);
        padding: 0.3rem 0.5rem;
        border-radius: 8px;
    }

    .modal-header .btn-close {
        filter: brightness(0) invert(1);
        opacity: 0.7;
        transition: all 0.3s ease;
    }

    .modal-header .btn-close:hover {
        opacity: 1;
        transform: rotate(90deg);
    }

    .modal-body {
        padding: 1.75rem;
        background: var(--fb-gray-100);
    }

    .modal-footer {
        background: var(--fb-white);
        padding: 1rem 1.75rem;
        border-top: 1px solid var(--fb-gray-300);
    }

    .modal-body .form-label {
        font-weight: 600;
        color: var(--fb-dark-900);
        font-size: 0.85rem;
    }

    .modal-body .form-label .text-danger {
        color: var(--fb-rose);
    }

    /* Pagination */
    .pagination .page-link {
        color: var(--fb-dark-900);
        border-color: var(--fb-gray-300);
        transition: all 0.3s ease;
        font-weight: 500;
        border-radius: 8px;
        margin: 0 2px;
    }

    .pagination .page-link:hover {
        background: var(--fb-amber);
        color: var(--fb-dark-900);
        border-color: var(--fb-amber);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(245, 158, 11, 0.2);
    }

    .pagination .page-item.active .page-link {
        background: linear-gradient(135deg, var(--fb-dark-900) 0%, var(--fb-dark-800) 100%);
        border-color: var(--fb-amber);
        color: var(--fb-white);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    }

    /* Responsive */
    @media (max-width: 768px) {
        .stat-card .stat-value {
            font-size: 1.8rem;
        }
        .stat-card .stat-icon {
            font-size: 1.5rem;
        }
        .stat-card .stat-label {
            font-size: 0.65rem;
        }
        .breadcrumb-bar {
            flex-direction: column;
            gap: 0.5rem;
            align-items: flex-start;
        }
        .card-header {
            font-size: 0.9rem;
        }
        .table thead th,
        .table tbody td {
            padding: 0.6rem 0.8rem;
            font-size: 0.8rem;
        }
    }

    @media (max-width: 576px) {
        .stat-card {
            padding: 1rem !important;
            min-height: 100px;
        }
        .stat-card .stat-value {
            font-size: 1.5rem;
        }
        .stat-card .stat-label {
            font-size: 0.6rem;
        }
        .breadcrumb-bar h5 {
            font-size: 0.95rem;
        }
        .breadcrumb-bar .text-muted {
            font-size: 0.75rem;
        }
        .card-body {
            padding: 0.75rem;
        }
        .form-control,
        .form-select {
            font-size: 0.75rem;
            padding: 0.3rem 0.6rem;
        }
        .table thead th,
        .table tbody td {
            padding: 0.4rem 0.6rem;
            font-size: 0.7rem;
        }
    }
    .main-content {
    margin-left: 260px !important;
    transition: margin-left 0.3s ease !important;
    padding: 20px !important;
    min-height: calc(100vh - 72px) !important;
    margin-top: 10px !important;
    width: auto !important;
    max-width: calc(100% - 260px) !important;
}

/* When sidebar is collapsed (72px) */
.main-content.sidebar-collapsed {
    margin-left: 72px !important;
    max-width: calc(100% - 72px) !important;
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
<div class="app-wrapper">
  <?php include __DIR__ . '/../../layouts/sidebar.php'; ?>

  <div class="main-content">
    <div class="breadcrumb-bar d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <h5 class="mb-0"><i class="bi bi-chat-square-text"></i> Public Feedback Collection</h5>
        <small class="text-muted">Submit and manage public feedback and survey responses</small>
      </div>
      <div class="no-print">
        <button type="button" class="btn btn-primary btn-sm" id="btnNewFeedback"><i class="bi bi-plus-circle"></i> Submit Feedback</button>
      </div>
    </div>

    <?php include __DIR__ . '/tabs.php'; ?>

    <?php if ($manager): ?>
      <!-- ===== Manager view: stats + full table ===== -->
      <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg-3">
          <div class="card stat-card bg-gov-blue p-3">
            <i class="bi bi-chat-square-text stat-icon icon-amber"></i>
            <div class="stat-value"><?= $stats['total'] ?></div>
            <div class="stat-label">Total Feedback</div>
          </div>
        </div>
        <div class="col-sm-6 col-lg-3">
          <div class="card stat-card bg-gov-accent p-3">
            <i class="bi bi-envelope stat-icon icon-indigo"></i>
            <div class="stat-value"><?= $stats['new'] ?></div>
            <div class="stat-label">New</div>
          </div>
        </div>
        <div class="col-sm-6 col-lg-3">
          <div class="card stat-card bg-gov-teal p-3">
            <i class="bi bi-reply stat-icon icon-emerald"></i>
            <div class="stat-value"><?= $stats['replied'] ?></div>
            <div class="stat-label">Replied</div>
          </div>
        </div>
        <div class="col-sm-6 col-lg-3">
          <div class="card stat-card bg-gov-purple p-3">
            <i class="bi bi-check2-circle stat-icon icon-violet"></i>
            <div class="stat-value"><?= $stats['closed'] ?></div>
            <div class="stat-label">Closed</div>
          </div>
        </div>
      </div>

      <div class="card mb-3 no-print">
        <div class="card-body">
          <div class="row g-2">
            <div class="col-md-4">
              <input type="text" class="form-control form-control-sm" id="searchInput" placeholder="Search name, email, subject, message...">
            </div>
            <div class="col-md-2">
              <select class="form-select form-select-sm" id="statusFilter">
                <option value="">All Status</option>
                <?php foreach (['New', 'Reviewed', 'Replied', 'Closed'] as $s): ?>
                  <option value="<?= e($s) ?>"><?= e($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <select class="form-select form-select-sm" id="categoryFilter">
                <option value="">All Categories</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <select class="form-select form-select-sm" id="sentimentFilter">
                <option value="">All Sentiment</option>
                <option value="Positive" <?= ($_GET['sentiment'] ?? '') === 'Positive' ? 'selected' : '' ?>>🟢 Positive</option>
                <option value="Neutral" <?= ($_GET['sentiment'] ?? '') === 'Neutral' ? 'selected' : '' ?>>🟡 Neutral</option>
                <option value="Negative" <?= ($_GET['sentiment'] ?? '') === 'Negative' ? 'selected' : '' ?>>🔴 Negative</option>
              </select>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div id="feedbackTableWrap">
          <?php include __DIR__ . '/table.php'; ?>
        </div>
      </div>
    <?php else: ?>
      <!-- ===== Non-manager view: my submissions ===== -->
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span><i class="bi bi-list-ul"></i> My Feedback Submissions</span>
          <a href="track.php" class="small"><i class="bi bi-reply"></i> View replies</a>
        </div>
        <?php include __DIR__ . '/my_feedback_table.php'; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ===== Submit Feedback Modal ===== -->
<div class="modal fade" id="feedbackFormModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="feedbackForm">
        <?= csrfField() ?>
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-chat-square-text"></i> Submit Feedback</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Your Name <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" required value="<?= e(currentUser()['full_name'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Email <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control" required value="<?= e(currentUser()['email'] ?? '') ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Category</label>
              <select name="category_id" class="form-select">
                <option value="">-- Select Category --</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Subject</label>
              <input type="text" name="subject" class="form-control" maxlength="255">
            </div>
            <div class="col-12">
              <label class="form-label">Message <span class="text-danger">*</span></label>
              <textarea name="message" class="form-control" rows="4" required></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i> Submit</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($manager): ?>
<!-- ===== View / Reply Modal ===== -->
<div class="modal fade" id="viewFeedbackModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-envelope-open"></i> Feedback Detail</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <table class="table table-borderless mb-3">
          <tr><th class="text-muted small" style="width:25%;">From</th><td id="vf_from"></td></tr>
          <tr><th class="text-muted small">Category</th><td id="vf_category"></td></tr>
          <tr><th class="text-muted small">Subject</th><td id="vf_subject"></td></tr>
          <tr><th class="text-muted small">Status</th><td id="vf_status"></td></tr>
          <tr><th class="text-muted small">Submitted</th><td id="vf_date"></td></tr>
        </table>
        <div class="border rounded p-3 bg-light small mb-3" id="vf_message"></div>

        <div id="vf_existing_reply" class="d-none mb-3">
          <div class="fw-semibold small text-success mb-1"><i class="bi bi-reply-fill"></i> Previous Reply</div>
          <div class="border border-success rounded p-3 small" style="background:#f0fff4;" id="vf_existing_reply_text"></div>
          <div class="text-muted small mt-1" id="vf_existing_reply_meta"></div>
        </div>

        <div id="vf_ai_panel" class="d-none mb-3">
          <div class="card border-primary">
            <div class="card-header bg-primary bg-opacity-10 d-flex justify-content-between align-items-center py-2">
              <span class="fw-semibold small"><i class="bi bi-robot"></i> AI Sentiment Analysis</span>
              <button type="button" class="btn btn-outline-primary btn-sm py-0" id="btnReanalyze" title="Re-run AI analysis"><i class="bi bi-arrow-repeat"></i> Re-analyze</button>
            </div>
            <div class="card-body py-2" id="vf_ai_body">
              <!-- populated by JS -->
            </div>
          </div>
        </div>
        <div id="vf_ai_pending" class="alert alert-secondary small d-none mb-3">
          <span class="spinner-border spinner-border-sm"></span> AI analysis is still in progress for this feedback — check back shortly, or
          <button type="button" class="btn btn-link btn-sm p-0 align-baseline" id="btnReanalyzeInline">run it now</button>.
        </div>
        <div id="vf_ai_unavailable" class="alert alert-light border small d-none mb-3">
          <i class="bi bi-info-circle"></i> AI sentiment analysis has not been run for this feedback yet.
          <button type="button" class="btn btn-outline-primary btn-sm ms-2" id="btnAnalyzeNow"><i class="bi bi-robot"></i> Analyze Now</button>
        </div>

        <div class="d-flex gap-2 mb-3">
          <button type="button" class="btn btn-outline-primary btn-sm" data-mark-status="Reviewed">Mark Reviewed</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" data-mark-status="Closed">Mark Closed</button>
        </div>

        <hr>
        <label class="form-label fw-semibold"><i class="bi bi-reply"></i> Compose Reply</label>
        <textarea id="vf_reply_text" class="form-control" rows="5" placeholder="Type your reply to the submitter here..."></textarea>
        <div class="form-text">This reply is recorded in the system's activity log for audit purposes and marks the feedback as Replied. Copy it into your email client to actually send it to the submitter.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary" id="btnSendReply"><i class="bi bi-send"></i> Save Reply &amp; Mark Replied</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$extraJs = [APP_URL . '/assets/js/feedback.js'];
include __DIR__ . '/../../layouts/footer.php';
?>