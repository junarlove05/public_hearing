<?php
/**
 * layouts/sidebar.php
 * ------------------------------------------------------------------
 * Left sidebar navigation matched 100% to ORLMS design system
 * ------------------------------------------------------------------
 */

$activeMenu = $activeMenu ?? '';
$role = currentRole();

$menuItems = [
    ['key'=>'dashboard','label'=>'Dashboard','icon'=>'bi-speedometer2',
     'url'=>'/dashboard.php','roles'=>[ROLE_ADMIN,ROLE_STAFF,ROLE_COMMITTEE,ROLE_STAKEHOLDER,ROLE_PUBLIC],
     'permission'=>'lph.dashboard.view'],

    ['key'=>'hearings','label'=>'Hearing Schedule','icon'=>'bi-calendar-event',
     'url'=>'/modules/hearings/index.php','roles'=>[ROLE_ADMIN,ROLE_STAFF,ROLE_COMMITTEE,ROLE_STAKEHOLDER,ROLE_PUBLIC],
     'permission'=>'lph.hearings.view'],

    ['key'=>'stakeholders','label'=>'Stakeholders & Invitations','icon'=>'bi-people',
     'url'=>'/modules/stakeholders/index.php','roles'=>[ROLE_ADMIN,ROLE_STAFF],
     'permission'=>'lph.stakeholders.view'],

    ['key'=>'attendance','label'=>'Attendance Tracking','icon'=>'bi-qr-code-scan',
     'url'=>'/modules/attendance/index.php','roles'=>[ROLE_ADMIN,ROLE_STAFF,ROLE_COMMITTEE],
     'permission'=>'lph.attendance.view'],

    ['key'=>'feedback','label'=>'Public Feedback','icon'=>'bi-chat-square-text',
     'url'=>'/modules/feedback/index.php','roles'=>[ROLE_ADMIN,ROLE_STAFF,ROLE_COMMITTEE,ROLE_STAKEHOLDER,ROLE_PUBLIC],
     'permission'=>'lph.feedback.submit'],

    ['key'=>'issues','label'=>'Issue Logging','icon'=>'bi-exclamation-triangle',
     'url'=>'/modules/issues/index.php','roles'=>[ROLE_ADMIN,ROLE_STAFF,ROLE_COMMITTEE],
     'permission'=>'lph.issues.view'],

    ['key'=>'actions','label'=>'Response & Actions','icon'=>'bi-list-check',
     'url'=>'/modules/actions/index.php','roles'=>[ROLE_ADMIN,ROLE_STAFF,ROLE_COMMITTEE],
     'permission'=>'lph.actions.view'],

    ['key'=>'reports','label'=>'Reports','icon'=>'bi-file-earmark-bar-graph',
     'url'=>'/reports/index.php','roles'=>[ROLE_ADMIN,ROLE_STAFF],
     'permission'=>'lph.reports.view'],

    ['key'=>'activity_logs','label'=>'Activity Logs','icon'=>'bi-clock-history',
     'url'=>'/pages/activity_logs.php','roles'=>[ROLE_ADMIN],
     'permission'=>'lph.activity_logs.view'],

    ['key'=>'users','label'=>'User Management','icon'=>'bi-person-gear',
     'url'=>'/pages/users.php','roles'=>[ROLE_ADMIN],
     'permission'=>'lph.users.manage'],

    ['key'=>'system_health','label'=>'System Health','icon'=>'bi-heart-pulse',
     'url'=>'/pages/system_health.php','roles'=>[ROLE_ADMIN],
     'permission'=>'lph.system_health.view'],
];
?>

<aside class="orlms-sidebar sidebar" id="orlmsSidebar">
  <div class="orlms-sidebar-brand sidebar-brand">
    <img src="<?= e(APP_URL . '/assets/images/logo.png') ?>" alt="City of Manila Seal" class="orlms-sidebar-logo sidebar-logo" style="width:60px !important;height:60px !important;max-width:60px !important;max-height:60px !important;object-fit:contain;">
    <div><strong>LPH-CMS</strong><small>Legislative Public Hearing Management</small></div>
  </div>

  <nav class="orlms-sidebar-nav sidebar-navigation">
    <div class="orlms-sidebar-section sidebar-section-label">Overview</div>
    <?php foreach ($menuItems as $item): ?>
      <?php
        if (!in_array($role, $item['roles'], true)) continue;
        if (
            !empty($item['permission'])
            && function_exists('hasPermission')
            && !hasPermission($item['permission'])
        ) continue;
      ?>
      <?php if ($item['key'] === 'hearings'): ?>
        <div class="orlms-sidebar-section sidebar-section-label">Public Hearing Operations</div>
      <?php elseif ($item['key'] === 'activity_logs'): ?>
        <div class="orlms-sidebar-section sidebar-section-label">Administration</div>
      <?php endif; ?>
      <a class="orlms-sidebar-link sidebar-link <?= $activeMenu === $item['key'] ? 'active' : '' ?>" href="<?= e(APP_URL . $item['url']) ?>" title="<?= e($item['label']) ?>">
        <i class="bi <?= e($item['icon']) ?>"></i><span><?= e($item['label']) ?></span>
        <?php if ($activeMenu === $item['key']): ?><b></b><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="orlms-sidebar-footer sidebar-session-card" title="Shared Session Enabled">
    <i class="bi bi-shield-check"></i>
    <div><strong>Shared Access Enabled</strong><small>Role: <?= e(ucfirst((string)$role)) ?></small></div>
  </div>
</aside>
<div class="orlms-sidebar-backdrop sidebar-backdrop" id="orlmsSidebarBackdrop"></div>