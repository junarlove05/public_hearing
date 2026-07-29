<?php
/**
 * modules/stakeholders/tabs.php
 * ------------------------------------------------------------------
 * Shared sub-navigation for the Stakeholder Invitation & Registration
 * module (Stakeholders / Invitations / Registrations). Included by
 * index.php, invitations.php, and registrations.php.
 * Expects $activeTab to be set by the including page.
 * ------------------------------------------------------------------
 */
$activeTab = $activeTab ?? 'stakeholders';
?>
<ul class="nav nav-tabs mb-3 no-print">
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'stakeholders' ? 'active' : '' ?>" href="<?= e(APP_URL) ?>/modules/stakeholders/index.php">
      <i class="bi bi-people"></i> Stakeholders
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'invitations' ? 'active' : '' ?>" href="<?= e(APP_URL) ?>/modules/stakeholders/invitations.php">
      <i class="bi bi-envelope-paper"></i> Invitations
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'registrations' ? 'active' : '' ?>" href="<?= e(APP_URL) ?>/modules/stakeholders/registrations.php">
      <i class="bi bi-clipboard-check"></i> Registrations
    </a>
  </li>
</ul>
