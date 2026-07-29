<?php
/**
 * modules/feedback/surveys.php
 * ------------------------------------------------------------------
 * Survey Management (Module 4, part 2 of 2). CRUD for surveys; each
 * survey has a shareable public response form (survey_form.php) and
 * a management view of its responses (survey_responses.php).
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pageTitle  = 'Surveys';
$activeMenu = 'feedback';
$activeTab  = 'surveys';

include __DIR__ . '/../../layouts/header.php';
?>
<style>
    /* Surveys - Dark Cards, Gray Labels, Colored Icons */
    :root {
        --sv-dark-900: #0F172A;
        --sv-dark-800: #1E293B;
        --sv-dark-700: #334155;
        --sv-amber: #F59E0B;
        --sv-amber-light: #FBBF24;
        --sv-white: #FFFFFF;
        --sv-gray-100: #F1F5F9;
        --sv-gray-200: #E2E8F0;
        --sv-gray-300: #CBD5E1;
        --sv-gray-400: #94A3B8;
        --sv-gray-500: #64748B;
        --sv-gray-600: #475569;
        --sv-emerald: #10B981;
        --sv-rose: #F43F5E;
        --sv-violet: #8B5CF6;
        --sv-cyan: #06B6D4;
        --sv-indigo: #6366F1;
        --sv-orange: #F97316;
        --sv-teal: #14B8A6;
    }

    /* Breadcrumb Bar */
    .breadcrumb-bar {
        background: var(--sv-white);
        border-left: 4px solid var(--sv-amber);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
        padding: 1rem 1.5rem;
        border-radius: 12px;
        margin-bottom: 1.5rem;
    }

    .breadcrumb-bar h5 {
        color: var(--sv-dark-900);
        font-weight: 700;
    }

    .breadcrumb-bar h5 i {
        color: var(--sv-amber);
    }

    .breadcrumb-bar .text-muted {
        color: var(--sv-gray-500) !important;
    }

    /* Cards */
    .card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 2px 15px rgba(0, 0, 0, 0.06);
        background: var(--sv-white);
        transition: all 0.3s ease;
    }

    .card:hover {
        box-shadow: 0 4px 25px rgba(0, 0, 0, 0.1);
    }

    .card-body {
        background: var(--sv-white);
        padding: 1.25rem 1.5rem;
    }

    /* Buttons */
    .btn-primary {
        background: linear-gradient(135deg, var(--sv-dark-900) 0%, var(--sv-dark-800) 100%);
        border: 1px solid rgba(245, 158, 11, 0.15);
        color: var(--sv-white);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        border-radius: 10px;
        font-weight: 600;
        padding: 0.45rem 1.25rem;
        transition: all 0.3s ease;
    }

    .btn-primary i {
        color: var(--sv-amber);
    }

    .btn-primary:hover {
        border-color: var(--sv-amber);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.25);
        color: var(--sv-white);
        transform: translateY(-2px);
    }

    .btn-secondary {
        background: var(--sv-gray-200);
        border: none;
        color: var(--sv-dark-900);
        border-radius: 10px;
        font-weight: 500;
        transition: all 0.3s ease;
        padding: 0.45rem 1.25rem;
    }

    .btn-secondary:hover {
        background: var(--sv-gray-300);
        transform: translateY(-2px);
    }

    /* Form Controls */
    .form-control,
    .form-select {
        border: 2px solid var(--sv-gray-200);
        border-radius: 10px;
        padding: 0.5rem 1rem;
        font-size: 0.875rem;
        transition: all 0.3s ease;
        background: var(--sv-gray-100);
        color: var(--sv-dark-900);
        font-weight: 500;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: var(--sv-amber);
        box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.12);
        background: var(--sv-white);
    }

    .form-control::placeholder {
        color: var(--sv-gray-400);
        font-weight: 400;
    }

    .form-control-sm,
    .form-select-sm {
        font-size: 0.8rem;
        padding: 0.4rem 0.75rem;
    }

    /* Table */
    #surveysTableWrap {
        background: var(--sv-white);
        border-radius: 16px;
        overflow: hidden;
    }

    .table {
        margin-bottom: 0;
    }

    .table thead th {
        background: linear-gradient(135deg, var(--sv-dark-900) 0%, var(--sv-dark-800) 100%);
        color: var(--sv-white) !important;
        border-bottom: 4px solid var(--sv-amber);
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
        color: var(--sv-amber);
        margin-right: 0.4rem;
        font-size: 0.9rem;
    }

    .table thead th,
    .table thead th *,
    .table thead th span,
    .table thead th div {
        color: var(--sv-white) !important;
    }

    .table tbody td {
        padding: 0.85rem 1.25rem;
        vertical-align: middle;
        color: var(--sv-dark-900);
        border-bottom: 1px solid var(--sv-gray-200);
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
        background: var(--sv-emerald) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
    }

    .badge.bg-warning {
        background: var(--sv-amber) !important;
        color: var(--sv-dark-900);
        box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
    }

    .badge.bg-danger {
        background: var(--sv-rose) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(244, 63, 94, 0.3);
    }

    .badge.bg-info {
        background: var(--sv-cyan) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(6, 182, 212, 0.3);
    }

    .badge.bg-secondary {
        background: var(--sv-gray-500) !important;
        color: white;
    }

    /* Modal */
    .modal-content {
        border: none;
        border-radius: 20px;
        box-shadow: 0 25px 70px rgba(0, 0, 0, 0.25);
        overflow: hidden;
        border: 1px solid var(--sv-gray-200);
    }

    .modal-header {
        background: linear-gradient(135deg, var(--sv-dark-900) 0%, var(--sv-dark-800) 100%);
        color: var(--sv-white);
        padding: 1.25rem 1.75rem;
        border-bottom: 4px solid var(--sv-amber);
    }

    .modal-header .modal-title {
        color: var(--sv-white);
        font-weight: 700;
    }

    .modal-header .modal-title i {
        color: var(--sv-amber);
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
        background: var(--sv-gray-100);
    }

    .modal-footer {
        background: var(--sv-white);
        padding: 1rem 1.75rem;
        border-top: 1px solid var(--sv-gray-200);
    }

    .modal-body .form-label {
        font-weight: 600;
        color: var(--sv-dark-900);
        font-size: 0.85rem;
    }

    .modal-body .form-label .text-danger {
        color: var(--sv-rose);
    }

    /* Pagination */
    .pagination .page-link {
        color: var(--sv-dark-900);
        border-color: var(--sv-gray-200);
        transition: all 0.3s ease;
        font-weight: 500;
        border-radius: 8px;
        margin: 0 2px;
    }

    .pagination .page-link:hover {
        background: var(--sv-amber);
        color: var(--sv-dark-900);
        border-color: var(--sv-amber);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(245, 158, 11, 0.2);
    }

    .pagination .page-item.active .page-link {
        background: linear-gradient(135deg, var(--sv-dark-900) 0%, var(--sv-dark-800) 100%);
        border-color: var(--sv-amber);
        color: var(--sv-white);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    }

    /* Action buttons */
    .btn-action {
        padding: 0.25rem 0.6rem;
        border-radius: 8px;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        border: none;
        font-size: 0.85rem;
        margin: 0 0.15rem;
    }

    .btn-action:hover {
        transform: scale(1.15);
    }

    .btn-action.edit {
        color: var(--sv-amber);
        background: rgba(245, 158, 11, 0.08);
    }

    .btn-action.edit:hover {
        background: var(--sv-amber);
        color: var(--sv-dark-900);
        box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
    }

    .btn-action.delete {
        color: var(--sv-rose);
        background: rgba(244, 63, 94, 0.08);
    }

    .btn-action.delete:hover {
        background: var(--sv-rose);
        color: white;
        box-shadow: 0 4px 12px rgba(244, 63, 94, 0.3);
    }

    .btn-action.view {
        color: var(--sv-cyan);
        background: rgba(6, 182, 212, 0.08);
    }

    .btn-action.view:hover {
        background: var(--sv-cyan);
        color: white;
        box-shadow: 0 4px 12px rgba(6, 182, 212, 0.3);
    }

    /* Responsive */
    @media (max-width: 768px) {
        .breadcrumb-bar {
            flex-direction: column;
            gap: 0.5rem;
            align-items: flex-start;
            padding: 1rem;
        }
        .table thead th,
        .table tbody td {
            padding: 0.6rem 0.8rem;
            font-size: 0.8rem;
        }
    }

    @media (max-width: 576px) {
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
        .btn-action {
            padding: 0.15rem 0.4rem;
            font-size: 0.7rem;
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
      <?php if (canManage()): ?>
      <div class="no-print">
        <button type="button" class="btn btn-primary btn-sm" id="btnAddSurvey"><i class="bi bi-plus-circle"></i> New Survey</button>
      </div>
      <?php endif; ?>
    </div>

    <?php include __DIR__ . '/tabs.php'; ?>

    <div class="card mb-3 no-print">
      <div class="card-body">
        <div class="row g-2">
          <div class="col-md-8">
            <input type="text" class="form-control form-control-sm" id="searchInput" placeholder="Search survey title or description...">
          </div>
          <div class="col-md-4">
            <select class="form-select form-select-sm" id="statusFilter">
              <option value="">All Status</option>
              <option value="Active">Active</option>
              <option value="Inactive">Inactive</option>
            </select>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div id="surveysTableWrap">
        <?php include __DIR__ . '/surveys_table.php'; ?>
      </div>
    </div>
  </div>
</div>

<?php if (canManage()): ?>
<div class="modal fade" id="surveyModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="surveyForm">
        <?= csrfField() ?>
        <input type="hidden" name="id" id="sv_id" value="0">
        <div class="modal-header">
          <h5 class="modal-title" id="surveyModalTitle"><i class="bi bi-plus-circle"></i> New Survey</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Title <span class="text-danger">*</span></label>
            <input type="text" name="title" id="sv_title" class="form-control" required maxlength="255">
          </div>
          <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea name="description" id="sv_description" class="form-control" rows="3" placeholder="Shown to respondents above the response field."></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Status</label>
            <select name="status" id="sv_status" class="form-select">
              <option value="Active">Active</option>
              <option value="Inactive">Inactive</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Save Survey</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$extraJs = [APP_URL . '/assets/js/surveys.js'];
include __DIR__ . '/../../layouts/footer.php';
?>