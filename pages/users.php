<?php
/**
 * pages/users.php
 * ------------------------------------------------------------------
 * User Management (RBAC administration). Administrator-only.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_ADMIN]);

$pageTitle  = 'User Management';
$activeMenu = 'users';
$pdo = db();

$roles = $pdo->query('SELECT id, name FROM roles ORDER BY id')->fetchAll();

include __DIR__ . '/../layouts/header.php';
?>
<style>
    /* User Management - Dark Theme */
    :root {
        --um-dark-900: #0F172A;
        --um-dark-800: #1E293B;
        --um-dark-700: #334155;
        --um-amber: #F59E0B;
        --um-amber-light: #FBBF24;
        --um-white: #FFFFFF;
        --um-gray-100: #F1F5F9;
        --um-gray-200: #E2E8F0;
        --um-gray-300: #CBD5E1;
        --um-gray-400: #94A3B8;
        --um-gray-500: #64748B;
        --um-gray-600: #475569;
        --um-emerald: #10B981;
        --um-rose: #F43F5E;
        --um-violet: #8B5CF6;
        --um-cyan: #06B6D4;
        --um-orange: #F97316;
        --um-teal: #14B8A6;
        --um-indigo: #6366F1;
    }

    /* Breadcrumb Bar */
    .breadcrumb-bar {
        background: var(--um-white);
        border-left: 4px solid var(--um-amber);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
        padding: 1rem 1.5rem;
        border-radius: 12px;
        margin-bottom: 1.5rem;
    }

    .breadcrumb-bar h5 {
        color: var(--um-dark-900);
        font-weight: 700;
    }

    .breadcrumb-bar h5 i {
        color: var(--um-amber);
    }

    .breadcrumb-bar .text-muted {
        color: var(--um-gray-500) !important;
    }

    /* Buttons */
    .btn-primary {
        background: linear-gradient(135deg, var(--um-dark-900) 0%, var(--um-dark-800) 100%);
        border: 1px solid rgba(245, 158, 11, 0.15);
        color: var(--um-white);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        border-radius: 10px;
        font-weight: 600;
        padding: 0.45rem 1.25rem;
        transition: all 0.3s ease;
    }

    .btn-primary i {
        color: var(--um-amber);
    }

    .btn-primary:hover {
        border-color: var(--um-amber);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.25);
        color: var(--um-white);
        transform: translateY(-2px);
    }

    /* Cards */
    .card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 2px 15px rgba(0, 0, 0, 0.06);
        background: var(--um-white);
        transition: all 0.3s ease;
        overflow: hidden;
    }

    .card:hover {
        box-shadow: 0 4px 25px rgba(0, 0, 0, 0.1);
    }

    .card-body {
        padding: 1.25rem 1.5rem;
        background: var(--um-white);
    }

    /* Form Controls */
    .form-control,
    .form-select {
        border: 2px solid var(--um-gray-200);
        border-radius: 10px;
        padding: 0.5rem 1rem;
        font-size: 0.875rem;
        transition: all 0.3s ease;
        background: var(--um-gray-100);
        color: var(--um-dark-900);
        font-weight: 500;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: var(--um-amber);
        box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.12);
        background: var(--um-white);
    }

    .form-control::placeholder {
        color: var(--um-gray-400);
        font-weight: 400;
    }

    .form-control-sm,
    .form-select-sm {
        font-size: 0.8rem;
        padding: 0.4rem 0.75rem;
    }

    .form-text {
        color: var(--um-gray-500) !important;
        font-size: 0.75rem;
    }

    /* Table */
    #usersTableWrap {
        background: var(--um-white);
        border-radius: 16px;
        overflow: hidden;
    }

    .table {
        margin-bottom: 0;
    }

    .table thead th {
        background: linear-gradient(135deg, var(--um-dark-900) 0%, var(--um-dark-800) 100%);
        color: var(--um-white) !important;
        border-bottom: 4px solid var(--um-amber);
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
        color: var(--um-amber);
        margin-right: 0.4rem;
        font-size: 0.9rem;
    }

    .table thead th,
    .table thead th *,
    .table thead th span,
    .table thead th div {
        color: var(--um-white) !important;
    }

    .table tbody td {
        padding: 0.85rem 1.25rem;
        vertical-align: middle;
        color: var(--um-dark-900);
        border-bottom: 1px solid var(--um-gray-200);
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
        background: var(--um-emerald) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
    }

    .badge.bg-warning {
        background: var(--um-amber) !important;
        color: var(--um-dark-900);
        box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
    }

    .badge.bg-danger {
        background: var(--um-rose) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(244, 63, 94, 0.3);
    }

    .badge.bg-info {
        background: var(--um-cyan) !important;
        color: white;
        box-shadow: 0 2px 8px rgba(6, 182, 212, 0.3);
    }

    .badge.bg-secondary {
        background: var(--um-gray-500) !important;
        color: white;
    }

    /* Modal */
    .modal-content {
        border: none;
        border-radius: 20px;
        box-shadow: 0 25px 70px rgba(0, 0, 0, 0.25);
        overflow: hidden;
        border: 1px solid var(--um-gray-200);
    }

    .modal-header {
        background: linear-gradient(135deg, var(--um-dark-900) 0%, var(--um-dark-800) 100%);
        color: var(--um-white);
        padding: 1.25rem 1.75rem;
        border-bottom: 4px solid var(--um-amber);
    }

    .modal-header .modal-title {
        color: var(--um-white);
        font-weight: 700;
    }

    .modal-header .modal-title i {
        color: var(--um-amber);
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
        background: var(--um-gray-100);
    }

    .modal-footer {
        background: var(--um-white);
        padding: 1rem 1.75rem;
        border-top: 1px solid var(--um-gray-200);
    }

    .modal-footer .btn-secondary {
        background: var(--um-gray-200);
        border: none;
        color: var(--um-dark-900);
        border-radius: 10px;
        padding: 0.5rem 1.5rem;
        font-weight: 500;
        transition: all 0.3s ease;
    }

    .modal-footer .btn-secondary:hover {
        background: var(--um-gray-300);
        transform: translateY(-2px);
    }

    .modal-body .form-label {
        font-weight: 600;
        color: var(--um-dark-900);
        font-size: 0.85rem;
    }

    .modal-body .form-label .text-danger {
        color: var(--um-rose);
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
        color: var(--um-amber);
        background: rgba(245, 158, 11, 0.08);
    }

    .btn-action.edit:hover {
        background: var(--um-amber);
        color: var(--um-dark-900);
        box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
    }

    .btn-action.delete {
        color: var(--um-rose);
        background: rgba(244, 63, 94, 0.08);
    }

    .btn-action.delete:hover {
        background: var(--um-rose);
        color: white;
        box-shadow: 0 4px 12px rgba(244, 63, 94, 0.3);
    }

    .btn-action.view {
        color: var(--um-cyan);
        background: rgba(6, 182, 212, 0.08);
    }

    .btn-action.view:hover {
        background: var(--um-cyan);
        color: white;
        box-shadow: 0 4px 12px rgba(6, 182, 212, 0.3);
    }

    /* Pagination */
    .pagination .page-link {
        color: var(--um-dark-900);
        border-color: var(--um-gray-200);
        transition: all 0.3s ease;
        font-weight: 500;
        border-radius: 8px;
        margin: 0 2px;
    }

    .pagination .page-link:hover {
        background: var(--um-amber);
        color: var(--um-dark-900);
        border-color: var(--um-amber);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(245, 158, 11, 0.2);
    }

    .pagination .page-item.active .page-link {
        background: linear-gradient(135deg, var(--um-dark-900) 0%, var(--um-dark-800) 100%);
        border-color: var(--um-amber);
        color: var(--um-white);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
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
        .modal-body {
            padding: 1.25rem;
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
     /* ============================================
       MAIN CONTENT - Adjust based on sidebar state
       ============================================ */
    .main-content {
        margin-left: 260px !important;
        transition: margin-left 0.3s ease !important;
        padding: 20px !important;
        min-height: calc(100vh - 72px) !important;
        margin-top: 10px !important;
        width: auto !important;
        max-width: calc(100% - 260px) !important;
    }

    .main-content.sidebar-collapsed {
        margin-left: 72px !important;
        max-width: calc(100% - 72px) !important;
    }

    @media (max-width: 992px) {
        .main-content {
            margin-left: 0 !important;
            max-width: 100% !important;
            padding: 15px !important;
        }
    }
</style>
<div class="app-wrapper">
  <?php include __DIR__ . '/../layouts/sidebar.php'; ?>

  <div class="main-content">
    <div class="breadcrumb-bar d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <h5 class="mb-0"><i class="bi bi-person-gear"></i> User Management</h5>
        <small class="text-muted">Manage system accounts and role-based access</small>
      </div>
      <div class="no-print">
        <button type="button" class="btn btn-primary btn-sm" id="btnAddUser"><i class="bi bi-person-plus"></i> Add User</button>
      </div>
    </div>

    <div class="card mb-3 no-print">
      <div class="card-body">
        <div class="row g-2">
          <div class="col-md-5">
            <input type="text" class="form-control form-control-sm" id="searchInput" placeholder="Search name or email...">
          </div>
          <div class="col-md-4">
            <select class="form-select form-select-sm" id="roleFilter">
              <option value="">All Roles</option>
              <?php foreach ($roles as $r): ?>
                <option value="<?= (int)$r['id'] ?>"><?= e($r['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
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
      <div id="usersTableWrap">
        <?php include __DIR__ . '/users_table.php'; ?>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="userModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="userForm">
        <?= csrfField() ?>
        <input type="hidden" name="id" id="u_id" value="0">
        <div class="modal-header">
          <h5 class="modal-title" id="userModalTitle"><i class="bi bi-person-plus"></i> Add User</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Full Name <span class="text-danger">*</span></label>
            <input type="text" name="full_name" id="u_full_name" class="form-control" required maxlength="150">
          </div>
          <div class="mb-3">
            <label class="form-label">Email <span class="text-danger">*</span></label>
            <input type="email" name="email" id="u_email" class="form-control" required maxlength="150">
          </div>
          <div class="mb-3">
            <label class="form-label">Role <span class="text-danger">*</span></label>
            <select name="role_id" id="u_role" class="form-select" required>
              <?php foreach ($roles as $r): ?>
                <option value="<?= (int)$r['id'] ?>"><?= e($r['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Status</label>
            <select name="status" id="u_status" class="form-select">
              <option value="Active">Active</option>
              <option value="Inactive">Inactive</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Password <span id="u_password_required" class="text-danger">*</span></label>
            <input type="password" name="password" id="u_password" class="form-control" minlength="8" placeholder="Minimum 8 characters">
            <div class="form-text" id="u_password_hint">Leave blank to keep the current password.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Save User</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = [APP_URL . '/assets/js/users.js'];
include __DIR__ . '/../layouts/footer.php';
?>