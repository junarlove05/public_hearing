<?php
/**
 * layouts/top_controls.php
 * ------------------------------------------------------------------
 * Shared Top Bar Controls for Dashboard & All Modules
 * (3-Line Sidebar Toggle + Subsystems Navigation + Admin Profile)
 * ------------------------------------------------------------------
 */
$currentUser = currentUser() ?? [];
?>
<div class="d-flex align-items-center justify-content-between gap-2 mb-2 w-100">
  <!-- 3-Line Hamburger Sidebar Toggle Button -->
  <button type="button" class="btn btn-sm btn-white border shadow-sm d-flex align-items-center justify-content-center" id="sidebarToggleBtn" title="Toggle Sidebar" onclick="document.body.classList.toggle('sidebar-collapsed');" style="width: 36px; height: 36px; border-radius: 8px; background: #fff; color: #0F172A; cursor: pointer;">
    <i class="bi bi-list fs-5"></i>
  </button>

  <!-- Right Controls Section -->
  <div class="d-flex align-items-center gap-2">
    <!-- Subsystems Switcher Dropdown -->
    <div class="dropdown">
      <button class="btn btn-sm btn-outline-secondary dropdown-toggle d-flex align-items-center gap-2 shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="border-radius: 8px; font-weight: 600; background: #fff;">
        <i class="bi bi-grid-3x3-gap-fill text-warning"></i>
        <span>Subsystems</span>
      </button>
      <ul class="dropdown-menu dropdown-menu-end shadow-sm border p-2 mt-2" style="min-width: 230px; font-size: 0.825rem; border-radius: 10px; z-index: 1050;">
        <li><a class="dropdown-item rounded py-1.5" href="<?= e(APP_URL) ?>/index.php"><i class="bi bi-house-door me-2 text-primary"></i>Main Portal</a></li>
        <li><hr class="dropdown-divider my-1"></li>
        <li><a class="dropdown-item rounded py-1.5" href="http://localhost/orlms/" target="_blank">#1 Ordinance & Resolution</a></li>
        <li><a class="dropdown-item rounded py-1.5" href="http://localhost/slmms/" target="_blank">#2 Session & Meeting</a></li>
        <li><a class="dropdown-item rounded py-1.5" href="http://localhost/lacms/" target="_blank">#3 Agenda & Calendar</a></li>
        <li><a class="dropdown-item rounded py-1.5" href="http://localhost/cmas/" target="_blank">#4 Committee Management</a></li>
        <li><a class="dropdown-item rounded py-1.5" href="http://localhost/vqdss/" target="_blank">#5 Voting & Quorum</a></li>
        <li><a class="dropdown-item rounded py-1.5" href="http://localhost/lrdms/" target="_blank">#6 Records & Documents</a></li>
        <li><a class="dropdown-item rounded py-1.5 fw-bold text-primary active" href="<?= e(APP_URL) ?>/dashboard.php">#7 Public Hearing</a></li>
        <li><a class="dropdown-item rounded py-1.5" href="http://localhost/lahrs/" target="_blank">#8 Archives & Repository</a></li>
        <li><a class="dropdown-item rounded py-1.5" href="http://localhost/lrpaies/" target="_blank">#9 Research & Policy</a></li>
        <li><a class="dropdown-item rounded py-1.5" href="http://localhost/cepfms/" target="_blank">#10 Citizen Engagement</a></li>
      </ul>
    </div>

    <!-- Admin / User Profile Dropdown -->
    <div class="dropdown">
      <a href="#" class="d-flex align-items-center text-dark text-decoration-none gap-2 px-3 py-1.5 bg-white border rounded-3 shadow-sm" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-person-circle fs-5 text-primary"></i>
        <div class="d-flex flex-column text-start lh-1">
          <strong style="font-size: 0.825rem; color: #0F172A;"><?= e($currentUser['full_name'] ?? 'Admin') ?></strong>
          <small style="font-size: 0.65rem; color: #64748B;"><?= e($currentUser['role_name'] ?? 'Administrator') ?></small>
        </div>
        <i class="bi bi-chevron-down ms-1 text-muted" style="font-size: 0.75rem;"></i>
      </a>
      <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 mt-2" style="font-size: 0.85rem; z-index: 1050;">
        <li><a class="dropdown-item py-2" href="<?= e(APP_URL) ?>/pages/profile.php"><i class="bi bi-person me-2 text-primary"></i> My Profile</a></li>
        <li><hr class="dropdown-divider my-1"></li>
        <li><a class="dropdown-item py-2 text-danger" href="<?= e(APP_URL) ?>/logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
      </ul>
    </div>
  </div>
</div>
