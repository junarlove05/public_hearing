<?php
/**
 * layouts/sidebar.php
 * ------------------------------------------------------------------
 * Left sidebar navigation with Coastal Blue theme
 * When collapsed, only icons remain visible
 * ------------------------------------------------------------------
 */

$activeMenu = $activeMenu ?? '';
$role = currentRole();

/**
 * Each nav item: key, label, icon, url, roles allowed to see it.
 */
$menuItems = [
    ['key' => 'dashboard',     'label' => 'Dashboard',              'icon' => 'bi-speedometer2',   'url' => '/dashboard.php',
        'roles' => [ROLE_ADMIN, ROLE_STAFF, ROLE_COMMITTEE, ROLE_STAKEHOLDER, ROLE_PUBLIC]],

    ['key' => 'hearings',      'label' => 'Hearing Schedule',       'icon' => 'bi-calendar-event', 'url' => '/modules/hearings/index.php',
        'roles' => [ROLE_ADMIN, ROLE_STAFF, ROLE_COMMITTEE, ROLE_STAKEHOLDER, ROLE_PUBLIC]],

    ['key' => 'stakeholders',  'label' => 'Stakeholders & Invitations', 'icon' => 'bi-people',      'url' => '/modules/stakeholders/index.php',
        'roles' => [ROLE_ADMIN, ROLE_STAFF]],

    ['key' => 'attendance',    'label' => 'Attendance Tracking',    'icon' => 'bi-qr-code-scan',   'url' => '/modules/attendance/index.php',
        'roles' => [ROLE_ADMIN, ROLE_STAFF, ROLE_COMMITTEE]],

    ['key' => 'feedback',      'label' => 'Public Feedback',        'icon' => 'bi-chat-square-text', 'url' => '/modules/feedback/index.php',
        'roles' => [ROLE_ADMIN, ROLE_STAFF, ROLE_COMMITTEE, ROLE_STAKEHOLDER, ROLE_PUBLIC]],

    ['key' => 'issues',        'label' => 'Issue Logging',          'icon' => 'bi-exclamation-triangle', 'url' => '/modules/issues/index.php',
        'roles' => [ROLE_ADMIN, ROLE_STAFF, ROLE_COMMITTEE]],

    ['key' => 'actions',       'label' => 'Response & Actions',     'icon' => 'bi-list-check',     'url' => '/modules/actions/index.php',
        'roles' => [ROLE_ADMIN, ROLE_STAFF, ROLE_COMMITTEE]],

    ['key' => 'reports',       'label' => 'Reports',                'icon' => 'bi-file-earmark-bar-graph', 'url' => '/reports/index.php',
        'roles' => [ROLE_ADMIN, ROLE_STAFF]],

    ['key' => 'activity_logs', 'label' => 'Activity Logs',          'icon' => 'bi-clock-history',  'url' => '/pages/activity_logs.php',
        'roles' => [ROLE_ADMIN]],

    ['key' => 'users',         'label' => 'User Management',        'icon' => 'bi-person-gear',    'url' => '/pages/users.php',
        'roles' => [ROLE_ADMIN]],
];
?>

<aside class="sidebar" id="sidebar">
  <!-- Brand Header with Coastal Blue Accent -->
  <div class="sidebar-header">
    <div class="brand-icon-wrapper">
      <i class="bi bi-bank2 brand-icon"></i>
    </div>
    <div class="brand-text">
      <div class="brand-title">LPH-CMS</div>
      <div class="brand-subtitle">Government Portal</div>
    </div>
  </div>

  <!-- Divider -->
  <div class="sidebar-divider"></div>

  <!-- Navigation -->
  <nav class="sidebar-nav">
    <ul class="nav-list">
      <?php foreach ($menuItems as $item): ?>
        <?php if (!in_array($role, $item['roles'], true)) continue; ?>
        <li class="nav-item">
          <a class="nav-link <?= $activeMenu === $item['key'] ? 'active' : '' ?>"
             href="<?= e(APP_URL . $item['url']) ?>">
            <span class="nav-icon-wrapper">
              <i class="bi <?= e($item['icon']) ?>"></i>
            </span>
            <span class="nav-label"><?= e($item['label']) ?></span>
            <?php if ($activeMenu === $item['key']): ?>
              <span class="nav-indicator"></span>
            <?php endif; ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </nav>

  <!-- Toggle Button (Arrow) -->
  <div class="sidebar-toggle-wrapper">
    <button class="sidebar-toggle-btn" id="sidebarToggle" aria-label="Toggle Sidebar">
      <i class="bi bi-chevron-left toggle-icon"></i>
    </button>
  </div>

  <!-- Footer Section -->
  <div class="sidebar-footer">
    <div class="footer-divider"></div>
    <div class="user-badge">
      <i class="bi bi-shield-check"></i>
      <span><?= e(ucfirst($role)) ?></span>
    </div>
  </div>
</aside>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<style>
/* ============================================
   SIDEBAR STYLES - Coastal Blue Theme
   ============================================ */

/* Coastal Blue Color Palette */
:root {
    --cb-midnight-dark: #0A1628;
    --cb-midnight: #0F2137;
    --cb-midnight-blue: #1A3A5C;
    --cb-midnight-soft: #2C5282;
    --cb-midnight-pale: #4A7EB5;
    --cb-midnight-lighter: #6B9BC7;
    --cb-light-bg: #F0F4F8;
    --cb-white: #ffffff;
    --cb-gold: #F5C842;
    --cb-gold-light: #F7D95A;
    --cb-teal: #14B8A6;
    --cb-text-light: #E2E8F0;
}

.sidebar {
  width: 260px;
  height: 100vh;
  position: fixed;
  top: 0;
  left: 0;
  background: linear-gradient(180deg, #0A1628 0%, #0F2137 40%, #1A3A5C 100%);
  color: #ffffff;
  display: flex;
  flex-direction: column;
  padding: 0;
  z-index: 1000;
  transition: width 0.3s ease, transform 0.3s ease;
  box-shadow: 2px 0 20px rgba(10, 22, 40, 0.3);
  border-right: 1px solid rgba(74, 126, 181, 0.15);
  overflow: hidden;
}

/* ===== HEADER ===== */
.sidebar-header {
  padding: 24px 20px 20px 20px;
  display: flex;
  align-items: center;
  gap: 14px;
  border-bottom: 1px solid rgba(74, 126, 181, 0.15);
  transition: all 0.3s ease;
  white-space: nowrap;
}

.brand-icon-wrapper {
  width: 44px;
  height: 44px;
  background: linear-gradient(135deg, #4A7EB5, #2C5282);
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  box-shadow: 0 4px 12px rgba(74, 126, 181, 0.3);
  transition: all 0.3s ease;
}

.brand-icon {
  font-size: 22px;
  color: #ffffff;
  transition: all 0.3s ease;
}

.brand-text {
  line-height: 1.2;
  transition: all 0.3s ease;
  overflow: hidden;
}

.brand-title {
  font-size: 18px;
  font-weight: 700;
  color: #ffffff;
  letter-spacing: 0.5px;
}

.brand-subtitle {
  font-size: 11px;
  color: rgba(255, 255, 255, 0.5);
  font-weight: 400;
  letter-spacing: 0.3px;
}

/* ===== DIVIDER ===== */
.sidebar-divider {
  height: 1px;
  background: linear-gradient(90deg, transparent, rgba(74, 126, 181, 0.2), transparent);
  margin: 0 20px;
  transition: all 0.3s ease;
}

/* ===== NAVIGATION ===== */
.sidebar-nav {
  flex: 1;
  padding: 16px 12px 12px 12px;
  overflow-y: auto;
  transition: all 0.3s ease;
}

.sidebar-nav::-webkit-scrollbar {
  width: 4px;
}

.sidebar-nav::-webkit-scrollbar-track {
  background: transparent;
}

.sidebar-nav::-webkit-scrollbar-thumb {
  background: rgba(74, 126, 181, 0.3);
  border-radius: 10px;
}

.nav-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.nav-item {
  width: 100%;
}

.nav-link {
  display: flex;
  align-items: center;
  padding: 11px 16px;
  border-radius: 10px;
  text-decoration: none;
  font-size: 14px;
  font-weight: 500;
  transition: all 0.2s ease;
  position: relative;
  gap: 14px;
  white-space: nowrap;
  color: rgba(255, 255, 255, 0.7);
}

.nav-link:hover {
  background: rgba(74, 126, 181, 0.15);
  color: #ffffff;
  transform: translateX(4px);
}

.nav-link.active {
  background: rgba(245, 200, 66, 0.12);
  color: #F5C842;
  box-shadow: inset 0 0 0 1px rgba(245, 200, 66, 0.1);
}

/* ===== LABEL COLORS ===== */
.nav-label {
  flex: 1;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  color: rgba(255, 255, 255, 0.8);
  font-weight: 500;
  transition: all 0.3s ease;
}

.nav-link:hover .nav-label {
  color: #ffffff;
}

.nav-link.active .nav-label {
  color: #F5C842;
  font-weight: 600;
}

/* ===== ICON COLORS ===== */
.nav-icon-wrapper {
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 8px;
  font-size: 18px;
  flex-shrink: 0;
  transition: all 0.2s ease;
  color: rgba(255, 255, 255, 0.6);
}

.nav-link:hover .nav-icon-wrapper {
  background: rgba(255, 255, 255, 0.08);
  color: #ffffff;
}

.nav-link.active .nav-icon-wrapper {
  background: rgba(245, 200, 66, 0.15);
  color: #F5C842;
}

/* ===== TOGGLE BUTTON (Arrow) ===== */
.sidebar-toggle-wrapper {
  padding: 8px 20px 8px 20px;
  display: flex;
  justify-content: flex-end;
  transition: all 0.3s ease;
}

.sidebar-toggle-btn {
  width: 36px;
  height: 36px;
  border: none;
  border-radius: 8px;
  background: rgba(74, 126, 181, 0.15);
  color: #ffffff;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.3s ease;
  flex-shrink: 0;
}

.sidebar-toggle-btn:hover {
  background: rgba(74, 126, 181, 0.25);
  transform: scale(1.05);
}

.sidebar-toggle-btn .toggle-icon {
  font-size: 18px;
  transition: transform 0.3s ease;
}

/* ===== COLLAPSED STATE - ICONS REMAIN VISIBLE ===== */
.sidebar.collapsed {
  width: 72px;
  background: linear-gradient(180deg, #0A1628 0%, #0F2137 50%, #1A3A5C 100%);
  border-right: 1px solid rgba(74, 126, 181, 0.2);
}

/* Hide text elements but keep icons */
.sidebar.collapsed .brand-text,
.sidebar.collapsed .nav-label,
.sidebar.collapsed .nav-indicator,
.sidebar.collapsed .user-badge span,
.sidebar.collapsed .sidebar-divider,
.sidebar.collapsed .footer-divider,
.sidebar.collapsed .brand-subtitle {
  display: none !important;
}

/* Keep brand icon centered - ICON REMAINS VISIBLE */
.sidebar.collapsed .sidebar-header {
  padding: 16px 12px;
  justify-content: center;
  border-bottom: 1px solid rgba(74, 126, 181, 0.15);
}

.sidebar.collapsed .brand-icon-wrapper {
  background: rgba(74, 126, 181, 0.2);
  width: 38px;
  height: 38px;
}

.sidebar.collapsed .brand-icon {
  color: #ffffff;
  font-size: 18px;
}

/* Navigation icons centered - ICONS REMAIN VISIBLE */
.sidebar.collapsed .sidebar-nav {
  padding: 8px 6px;
}

.sidebar.collapsed .nav-link {
  padding: 10px;
  justify-content: center;
  gap: 0;
  transform: none !important;
}

.sidebar.collapsed .nav-link:hover {
  transform: none !important;
  background: rgba(74, 126, 181, 0.12);
}

.sidebar.collapsed .nav-link.active {
  background: rgba(245, 200, 66, 0.12);
}

/* ICONS REMAIN VISIBLE AND STYLED */
.sidebar.collapsed .nav-icon-wrapper {
  width: 36px;
  height: 36px;
  font-size: 20px;
  color: rgba(255, 255, 255, 0.8) !important;
  background: transparent !important;
  display: flex !important;
  align-items: center;
  justify-content: center;
}

.sidebar.collapsed .nav-link:hover .nav-icon-wrapper {
  color: #ffffff !important;
  background: rgba(255, 255, 255, 0.08) !important;
}

.sidebar.collapsed .nav-link.active .nav-icon-wrapper {
  color: #F5C842 !important;
  background: rgba(245, 200, 66, 0.12) !important;
}

/* Toggle button centered - Arrow faces RIGHT when collapsed */
.sidebar.collapsed .sidebar-toggle-wrapper {
  padding: 8px 12px;
  justify-content: center;
}

.sidebar.collapsed .sidebar-toggle-btn {
  background: rgba(74, 126, 181, 0.15);
  color: #ffffff;
}

.sidebar.collapsed .sidebar-toggle-btn:hover {
  background: rgba(74, 126, 181, 0.25);
}

/* Arrow faces RIGHT when collapsed (points to expand) */
.sidebar.collapsed .sidebar-toggle-btn .toggle-icon {
  transform: rotate(0deg);
}

/* Footer with just icon - ICON REMAINS VISIBLE */
.sidebar.collapsed .sidebar-footer {
  padding: 8px 12px 16px 12px;
}

.sidebar.collapsed .user-badge {
  justify-content: center;
  padding: 8px;
  background: rgba(74, 126, 181, 0.10);
  border: 1px solid rgba(74, 126, 181, 0.10);
}

.sidebar.collapsed .user-badge i {
  font-size: 18px;
  color: #ffffff;
  display: flex !important;
}

/* ===== FOOTER ===== */
.sidebar-footer {
  padding: 12px 20px 20px 20px;
  margin-top: auto;
  transition: all 0.3s ease;
}

.footer-divider {
  height: 1px;
  background: linear-gradient(90deg, transparent, rgba(74, 126, 181, 0.2), transparent);
  margin-bottom: 14px;
  transition: all 0.3s ease;
}

.user-badge {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 14px;
  background: rgba(74, 126, 181, 0.08);
  border-radius: 8px;
  font-size: 13px;
  border: 1px solid rgba(74, 126, 181, 0.06);
  transition: all 0.3s ease;
  color: rgba(255, 255, 255, 0.7);
}

.user-badge i {
  color: #F5C842;
  font-size: 16px;
}

.user-badge span {
  font-weight: 500;
  text-transform: capitalize;
  color: rgba(255, 255, 255, 0.8);
  transition: all 0.3s ease;
}

.nav-indicator {
  width: 4px;
  height: 24px;
  background: linear-gradient(180deg, #F5C842, #F7D95A);
  border-radius: 4px;
  flex-shrink: 0;
  animation: pulseIndicator 2s ease-in-out infinite;
}

@keyframes pulseIndicator {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}

/* ===== TOOLTIP FOR COLLAPSED STATE ===== */
.sidebar.collapsed .nav-link {
  position: relative;
}

.sidebar.collapsed .nav-link:hover::after {
  content: attr(data-tooltip);
  position: absolute;
  left: 72px;
  top: 50%;
  transform: translateY(-50%);
  background: #0F2137;
  color: #ffffff;
  padding: 6px 14px;
  border-radius: 6px;
  font-size: 13px;
  font-weight: 500;
  white-space: nowrap;
  box-shadow: 0 4px 12px rgba(0,0,0,0.3);
  border: 1px solid rgba(74, 126, 181, 0.2);
  z-index: 100;
  pointer-events: none;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 992px) {
  .sidebar {
    transform: translateX(-100%);
    width: 280px;
  }
  
  .sidebar.active {
    transform: translateX(0);
  }
  
  .sidebar.collapsed {
    width: 72px;
    transform: translateX(0);
  }
  
  .sidebar-overlay.active {
    display: block;
  }
}

/* ===== SIDEBAR OVERLAY FOR MOBILE ===== */
.sidebar-overlay {
  display: none;
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.4);
  z-index: 999;
  backdrop-filter: blur(4px);
}

.sidebar-overlay.active {
  display: block;
}
</style>

<script>
// Add data-tooltip attributes for responsive tooltips
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.nav-link').forEach(function(link) {
    const label = link.querySelector('.nav-label');
    if (label) {
      link.setAttribute('data-tooltip', label.textContent.trim());
    }
  });
});

// Sidebar toggle functionality with correct arrow direction
document.addEventListener('DOMContentLoaded', function() {
    const toggleBtn = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const mainContent = document.querySelector('.main-content');
    
    if (toggleBtn && sidebar && mainContent) {
        toggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            // Toggle sidebar collapsed state
            sidebar.classList.toggle('collapsed');
            
            // Toggle main content class for margin adjustment
            mainContent.classList.toggle('sidebar-collapsed');
            
            // If on mobile, handle overlay
            if (window.innerWidth <= 992) {
                if (sidebar.classList.contains('collapsed')) {
                    sidebar.classList.remove('active');
                    if (overlay) {
                        overlay.classList.remove('active');
                    }
                    document.body.style.overflow = '';
                } else {
                    sidebar.classList.add('active');
                    if (overlay) {
                        overlay.classList.add('active');
                    }
                    document.body.style.overflow = 'hidden';
                }
            }
            
            // Update icon - Arrow faces RIGHT when collapsed, LEFT when expanded
            const icon = this.querySelector('.toggle-icon');
            if (icon) {
                if (sidebar.classList.contains('collapsed')) {
                    // When collapsed, arrow points RIGHT (chevron-right) - click to expand
                    icon.className = 'bi bi-chevron-right toggle-icon';
                } else {
                    // When expanded, arrow points LEFT (chevron-left) - click to collapse
                    icon.className = 'bi bi-chevron-left toggle-icon';
                }
            }
            
            // Force a reflow to ensure the transition works
            void mainContent.offsetWidth;
        });
    }

    // Close sidebar on overlay click (mobile)
    if (overlay) {
        overlay.addEventListener('click', function() {
            sidebar.classList.remove('active');
            sidebar.classList.remove('collapsed');
            overlay.classList.remove('active');
            document.body.style.overflow = '';
            
            // Remove class from main content
            if (mainContent) {
                mainContent.classList.remove('sidebar-collapsed');
            }
            
            // Reset toggle icon to left arrow (expanded state)
            const toggleBtn = document.getElementById('sidebarToggle');
            if (toggleBtn) {
                const icon = toggleBtn.querySelector('.toggle-icon');
                if (icon) {
                    icon.className = 'bi bi-chevron-left toggle-icon';
                }
            }
        });
    }

    // Close sidebar on link click (mobile)
    document.querySelectorAll('.nav-link').forEach(function(link) {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 992) {
                sidebar.classList.remove('active');
                if (overlay) {
                    overlay.classList.remove('active');
                }
                document.body.style.overflow = '';
            }
        });
    });

    // Handle resize
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            if (window.innerWidth > 992) {
                if (sidebar.classList.contains('active')) {
                    sidebar.classList.remove('active');
                    if (overlay) {
                        overlay.classList.remove('active');
                    }
                    document.body.style.overflow = '';
                }
                // Make sure collapsed state works on desktop
                if (sidebar.classList.contains('collapsed')) {
                    mainContent.classList.add('sidebar-collapsed');
                } else {
                    mainContent.classList.remove('sidebar-collapsed');
                }
            }
        }, 200);
    });

    // Escape key to close sidebar
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            if (sidebar.classList.contains('active') || sidebar.classList.contains('collapsed')) {
                sidebar.classList.remove('active');
                sidebar.classList.remove('collapsed');
                if (overlay) {
                    overlay.classList.remove('active');
                }
                document.body.style.overflow = '';
                if (mainContent) {
                    mainContent.classList.remove('sidebar-collapsed');
                }
                // Reset toggle icon to left arrow (expanded state)
                const toggleBtn = document.getElementById('sidebarToggle');
                if (toggleBtn) {
                    const icon = toggleBtn.querySelector('.toggle-icon');
                    if (icon) {
                        icon.className = 'bi bi-chevron-left toggle-icon';
                    }
                }
            }
        }
    });
});
</script>