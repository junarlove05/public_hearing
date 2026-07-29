<?php
/**
 * modules/feedback/tabs.php
 * ------------------------------------------------------------------
 * Shared sub-navigation for the Public Feedback Collection module
 * (Feedback / Surveys / Reports). Expects $activeTab.
 * ------------------------------------------------------------------
 */
$activeTab = $activeTab ?? 'feedback';
?>
<ul class="nav nav-tabs mb-3 no-print">
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'feedback' ? 'active' : '' ?>" href="<?= e(APP_URL) ?>/modules/feedback/index.php">
      <i class="bi bi-chat-square-text"></i> Feedback
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'surveys' ? 'active' : '' ?>" href="<?= e(APP_URL) ?>/modules/feedback/surveys.php">
      <i class="bi bi-ui-checks"></i> Surveys
    </a>
  </li>
  <?php if (canManage()): ?>
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'reports' ? 'active' : '' ?>" href="<?= e(APP_URL) ?>/modules/feedback/report.php">
      <i class="bi bi-bar-chart"></i> Reports &amp; Statistics
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'ai_analytics' ? 'active' : '' ?>" href="<?= e(APP_URL) ?>/modules/feedback/ai_analytics.php">
      <i class="bi bi-robot"></i> AI Sentiment Analytics
    </a>
  </li>
  <?php endif; ?>
</ul>
