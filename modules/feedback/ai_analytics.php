<?php
/**
 * modules/feedback/ai_analytics.php
 * ------------------------------------------------------------------
 * AI Sentiment Analytics dashboard: totals by sentiment, trend charts
 * (day/week/month/year, switchable), sentiment distribution (pie),
 * top discussed topics (bar), top keywords, and an Ollama health
 * status banner.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/ai_config.php';
require_once __DIR__ . '/../../includes/AI/AIAnalysisManager.php';
require_once __DIR__ . '/../../includes/AI/AIServiceFactory.php';
requireRole([ROLE_ADMIN, ROLE_STAFF]);

$pageTitle  = 'AI Sentiment Analytics';
$activeMenu = 'feedback';
$activeTab  = 'ai_analytics';

$aiAvailable = AIAnalysisManager::tablesExist();
$health = ['available' => false, 'message' => 'AI database tables are not set up yet.'];

if ($aiAvailable) {
    try {
        $health = AIServiceFactory::make()->healthCheck();
    } catch (Throwable $e) {
        $health = ['available' => false, 'message' => 'Unable to check AI service status: ' . $e->getMessage()];
    }
}

$pdo = db();
$totals = ['Positive' => 0, 'Neutral' => 0, 'Negative' => 0];
$totalAnalyzed = 0;
$flaggedCount = 0;
$pendingCount = 0;
$failedCount = 0;
$topCategories = [];
$topKeywords = [];

if ($aiAvailable) {
    $bySentiment = $pdo->query(
        "SELECT sentiment, COUNT(*) AS total FROM feedback_ai_analysis WHERE status = 'completed' AND sentiment IS NOT NULL GROUP BY sentiment"
    )->fetchAll();
    foreach ($bySentiment as $row) {
        if (isset($totals[$row['sentiment']])) $totals[$row['sentiment']] = (int)$row['total'];
    }
    $totalAnalyzed = array_sum($totals);

    $flaggedCount = (int)$pdo->query("SELECT COUNT(*) FROM feedback_ai_analysis WHERE flagged_for_review = 1")->fetchColumn();
    $pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM feedback_ai_analysis WHERE status = 'pending'")->fetchColumn();
    $failedCount  = (int)$pdo->query("SELECT COUNT(*) FROM feedback_ai_analysis WHERE status = 'failed'")->fetchColumn();

    // Most discussed topics (AI-recommended categories).
    $topCategories = $pdo->query(
        "SELECT recommended_category, COUNT(*) AS total
         FROM feedback_ai_analysis
         WHERE status = 'completed' AND recommended_category IS NOT NULL AND recommended_category != ''
         GROUP BY recommended_category ORDER BY total DESC LIMIT 10"
    )->fetchAll();

    // Top keywords: tally frequency across all analyzed feedback in PHP
    // (simpler and more portable than JSON aggregation functions, which
    // vary across MySQL/MariaDB versions).
    $keywordCounts = [];
    $kwStmt = $pdo->query("SELECT keywords FROM feedback_ai_analysis WHERE status = 'completed' AND keywords IS NOT NULL");
    foreach ($kwStmt->fetchAll() as $row) {
        $kws = json_decode($row['keywords'], true);
        if (!is_array($kws)) continue;
        foreach ($kws as $kw) {
            $kw = trim(mb_strtolower($kw));
            if ($kw === '') continue;
            $keywordCounts[$kw] = ($keywordCounts[$kw] ?? 0) + 1;
        }
    }
    arsort($keywordCounts);
    $topKeywords = array_slice($keywordCounts, 0, 20, true);
}

include __DIR__ . '/../../layouts/header.php';
?>
<style>
    /* ============================================================
       AI ANALYTICS - Coastal Blue Theme
       Colors: Midnight Blue, Gold, White, Teal
       ============================================================ */
    :root {
        --ai-midnight-dark: #0A1628;
        --ai-midnight: #0F2137;
        --ai-midnight-blue: #1A3A5C;
        --ai-midnight-soft: #2C5282;
        --ai-midnight-pale: #4A7EB5;
        --ai-midnight-lighter: #6B9BC7;
        --ai-white: #FFFFFF;
        --ai-off-white: #F5F8FA;
        --ai-gray-100: #F1F5F9;
        --ai-gray-200: #E2E8F0;
        --ai-gray-300: #CBD5E1;
        --ai-gray-400: #94A3B8;
        --ai-gray-500: #64748B;
        --ai-gray-600: #475569;
        --ai-gold: #F5C842;
        --ai-gold-light: #F7D95A;
        --ai-gold-dark: #D4A820;
        --ai-emerald: #10B981;
        --ai-rose: #F43F5E;
        --ai-violet: #8B5CF6;
        --ai-cyan: #06B6D4;
        --ai-teal: #14B8A6;
        --ai-indigo: #6366F1;
        --ai-green: #34D399;
        --ai-yellow: #FBBF24;
        --ai-red: #FB7185;
    }

    /* ===== MAIN CONTENT ===== */
    .main-content {
        padding: 0.5rem 1.5rem 1.5rem 1.5rem;
        margin-top: 20px;
        min-height: calc(100vh - 72px);
        background: var(--ai-off-white);
    }

    /* ===== BREADCRUMB BAR ===== */
    .breadcrumb-bar {
        background: var(--ai-white);
        border-left: 4px solid var(--ai-gold);
        box-shadow: 0 2px 15px rgba(10, 22, 40, 0.06);
        padding: 1.25rem 1.75rem;
        border-radius: 16px;
        margin-bottom: 1.5rem;
        position: relative;
        overflow: hidden;
    }

    .breadcrumb-bar::after {
        content: '';
        position: absolute;
        top: 0;
        right: 0;
        width: 200px;
        height: 100%;
        background: linear-gradient(135deg, transparent 0%, rgba(245, 200, 66, 0.05) 100%);
        pointer-events: none;
    }

    .breadcrumb-bar h5 {
        color: var(--ai-midnight);
        font-weight: 800;
        font-size: 1.1rem;
        letter-spacing: -0.3px;
        margin-bottom: 0.1rem;
    }

    .breadcrumb-bar h5 i {
        color: var(--ai-gold);
        background: rgba(245, 200, 66, 0.1);
        padding: 0.4rem;
        border-radius: 10px;
        margin-right: 0.5rem;
    }

    .breadcrumb-bar .text-muted {
        color: var(--ai-gray-500) !important;
        font-weight: 400;
        font-size: 0.85rem;
    }

    /* ===== STAT CARDS ===== */
    .stat-card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 4px 20px rgba(10, 22, 40, 0.08);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
        cursor: default;
        padding: 1.25rem 1.5rem !important;
        min-height: 120px;
        background: var(--ai-white);
        border: 1px solid rgba(10, 22, 40, 0.06);
    }

    .stat-card::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, var(--ai-gold-dark), var(--ai-gold), var(--ai-gold-light));
        opacity: 0.6;
        transition: opacity 0.3s ease, height 0.3s ease;
    }

    .stat-card:hover::after {
        opacity: 1;
        height: 5px;
        box-shadow: 0 0 25px rgba(245, 200, 66, 0.3);
    }

    .stat-card::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -30%;
        width: 80%;
        height: 200%;
        background: linear-gradient(135deg, rgba(10, 22, 40, 0.03), transparent 60%);
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
        box-shadow: 0 8px 30px rgba(10, 22, 40, 0.12);
        border-color: rgba(245, 200, 66, 0.15);
    }

    .stat-card .stat-icon {
        font-size: 1.75rem;
        opacity: 1;
        margin-bottom: 0.5rem;
        display: block;
    }

    .stat-card .stat-icon.icon-green { color: var(--ai-green) !important; }
    .stat-card .stat-icon.icon-yellow { color: var(--ai-yellow) !important; }
    .stat-card .stat-icon.icon-red { color: var(--ai-red) !important; }
    .stat-card .stat-icon.icon-violet { color: var(--ai-violet) !important; }

    .stat-card .stat-value {
        font-size: 2.2rem;
        font-weight: 800;
        line-height: 1.2;
        margin-bottom: 0.25rem;
        color: var(--ai-midnight);
        letter-spacing: -0.5px;
    }

    .stat-card .stat-label {
        font-size: 0.75rem;
        opacity: 1;
        font-weight: 500;
        color: var(--ai-gray-500) !important;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        text-shadow: none;
    }

    /* ===== CARD BACKGROUNDS ===== */
    .bg-gov-white {
        background: var(--ai-white);
    }

    .bg-gov-midnight-light {
        background: linear-gradient(135deg, #F0F4F8 0%, #E2E8F0 100%);
    }

    .bg-gov-midnight-pale {
        background: linear-gradient(135deg, #E8EEF5 0%, #D5DEE7 100%);
    }

    .bg-gov-gold-light {
        background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
        border-bottom: 4px solid var(--ai-gold);
    }

    .bg-gov-teal {
        background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%);
        border-bottom: 4px solid var(--ai-teal);
    }

    /* ===== ALERTS ===== */
    .alert {
        border: none;
        border-radius: 12px;
        padding: 0.75rem 1.25rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .alert-success {
        background: #ECFDF5;
        color: #065F46;
        border-left: 4px solid var(--ai-emerald);
    }

    .alert-success i {
        color: var(--ai-emerald);
    }

    .alert-warning {
        background: #FFFBEB;
        color: #92400E;
        border-left: 4px solid var(--ai-gold);
    }

    .alert-warning i {
        color: var(--ai-gold);
    }

    .alert-info {
        background: #EFF6FF;
        color: #1E40AF;
        border-left: 4px solid var(--ai-cyan);
    }

    .alert-info i {
        color: var(--ai-cyan);
    }

    .alert-light {
        background: var(--ai-gray-100);
        color: var(--ai-midnight);
        border-left: 4px solid var(--ai-gray-400);
    }

    /* ===== CARDS ===== */
    .card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 2px 20px rgba(10, 22, 40, 0.05);
        background: var(--ai-white);
        transition: all 0.3s ease;
        border: 1px solid rgba(10, 22, 40, 0.06);
    }

    .card:hover {
        box-shadow: 0 4px 30px rgba(10, 22, 40, 0.08);
        transform: translateY(-2px);
        border-color: rgba(245, 200, 66, 0.12);
    }

    .card-header {
        background: linear-gradient(135deg, var(--ai-midnight-dark) 0%, var(--ai-midnight-blue) 100%);
        color: var(--ai-white);
        font-weight: 600;
        padding: 0.75rem 1.25rem;
        border-bottom: 3px solid var(--ai-gold);
        border-radius: 16px 16px 0 0 !important;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .card-header i {
        color: var(--ai-gold);
        font-size: 1.1rem;
        margin-right: 0.5rem;
    }

    .card-header span {
        display: flex;
        align-items: center;
    }

    .card-body {
        padding: 1.25rem;
        background: var(--ai-white);
    }

    /* ===== BUTTONS ===== */
    .btn-outline-primary {
        border: 2px solid var(--ai-gray-200);
        color: var(--ai-gray-600);
        background: transparent;
        border-radius: 8px;
        transition: all 0.3s ease;
        font-weight: 500;
        padding: 0.3rem 0.8rem;
        font-size: 0.75rem;
    }

    .btn-outline-primary:hover {
        background: var(--ai-off-white);
        border-color: var(--ai-gold);
        color: var(--ai-midnight);
        transform: translateY(-2px);
    }

    .btn-outline-primary.active {
        background: linear-gradient(135deg, var(--ai-midnight-dark) 0%, var(--ai-midnight-blue) 100%);
        color: var(--ai-white);
        border-color: var(--ai-gold);
    }

    .btn-outline-primary i {
        color: var(--ai-gold);
    }

    .btn-group .btn-outline-primary:first-child {
        border-radius: 8px 0 0 8px;
    }

    .btn-group .btn-outline-primary:last-child {
        border-radius: 0 8px 8px 0;
    }

    /* ===== KEYWORD BADGES ===== */
    .keyword-badge {
        display: inline-block;
        background: linear-gradient(135deg, var(--ai-midnight-dark) 0%, var(--ai-midnight-blue) 100%);
        color: var(--ai-white);
        padding: 0.4rem 0.9rem;
        border-radius: 20px;
        font-weight: 500;
        font-size: 0.8rem;
        border: 1px solid rgba(245, 200, 66, 0.15);
        transition: all 0.3s ease;
        margin: 0 0.3rem 0.5rem 0;
    }

    .keyword-badge:hover {
        border-color: var(--ai-gold);
        transform: scale(1.05);
        box-shadow: 0 4px 15px rgba(245, 200, 66, 0.15);
    }

    .keyword-badge .keyword-count {
        color: var(--ai-gold);
        font-weight: 600;
        margin-left: 0.3rem;
    }

    /* ===== RESPONSIVE ===== */
    @media (max-width: 768px) {
        .breadcrumb-bar {
            padding: 1rem 1.25rem;
        }

        .breadcrumb-bar h5 {
            font-size: 0.95rem;
        }

        .stat-card .stat-value {
            font-size: 1.8rem;
        }
        .stat-card .stat-icon {
            font-size: 1.5rem;
        }
        .stat-card .stat-label {
            font-size: 0.65rem;
        }
        .card-header {
            font-size: 0.9rem;
            flex-direction: column;
            align-items: flex-start;
        }
        .card-header .btn-group {
            width: 100%;
        }
        .card-header .btn-group .btn {
            flex: 1;
            font-size: 0.65rem;
            padding: 0.2rem 0.4rem;
        }
    }

    @media (max-width: 576px) {
        .main-content {
            padding: 0.25rem 0.5rem 0.75rem 0.5rem;
        }

        .breadcrumb-bar {
            padding: 0.75rem 1rem;
        }

        .breadcrumb-bar h5 {
            font-size: 0.85rem;
        }

        .breadcrumb-bar .text-muted {
            font-size: 0.7rem;
        }

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
        .card-body {
            padding: 0.75rem;
        }
        .keyword-badge {
            font-size: 0.65rem;
            padding: 0.25rem 0.6rem;
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
        margin-top: 72px !important;
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
  <?php include __DIR__ . '/../../layouts/sidebar.php'; ?>

  <div class="main-content">
    <!-- ===== BREADCRUMB BAR ===== -->
    <div class="breadcrumb-bar d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <h5 class="mb-0"><i class="bi bi-robot"></i> AI Sentiment Analytics</h5>
        <small class="text-muted">Powered locally by Ollama (<?= e(OLLAMA_MODEL) ?>) — no data leaves this server</small>
      </div>
    </div>

    <?php include __DIR__ . '/tabs.php'; ?>

    <!-- ===== AI Service Health Banner ===== -->
    <div class="alert <?= $health['available'] ? 'alert-success' : 'alert-warning' ?> d-flex align-items-center gap-2">
      <i class="bi <?= $health['available'] ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> fs-5"></i>
      <div><strong>AI Service Status:</strong> <?= e($health['message']) ?></div>
    </div>

    <?php if (!$aiAvailable): ?>
      <div class="alert alert-info">
        <i class="bi bi-info-circle fs-5"></i>
        Run <code>database/migration_002_ai_sentiment_analysis.sql</code> once to enable AI sentiment analytics.
      </div>
    <?php else: ?>

    <!-- ===== STAT CARDS ===== -->
    <div class="row g-3 mb-3">
      <div class="col-sm-6 col-lg-3">
        <div class="card stat-card bg-gov-white p-3">
          <i class="bi bi-emoji-smile stat-icon icon-green"></i>
          <div class="stat-value">🟢 <?= $totals['Positive'] ?></div>
          <div class="stat-label">Positive Feedback</div>
        </div>
      </div>
      <div class="col-sm-6 col-lg-3">
        <div class="card stat-card bg-gov-gold-light p-3">
          <i class="bi bi-emoji-neutral stat-icon icon-yellow"></i>
          <div class="stat-value">🟡 <?= $totals['Neutral'] ?></div>
          <div class="stat-label">Neutral Feedback</div>
        </div>
      </div>
      <div class="col-sm-6 col-lg-3">
        <div class="card stat-card bg-gov-white p-3">
          <i class="bi bi-emoji-frown stat-icon icon-red"></i>
          <div class="stat-value">🔴 <?= $totals['Negative'] ?></div>
          <div class="stat-label">Negative Feedback</div>
        </div>
      </div>
      <div class="col-sm-6 col-lg-3">
        <div class="card stat-card bg-gov-midnight-light p-3">
          <i class="bi bi-flag stat-icon icon-violet"></i>
          <div class="stat-value"><?= $flaggedCount ?></div>
          <div class="stat-label">Flagged for Review</div>
        </div>
      </div>
    </div>

    <?php if ($pendingCount > 0 || $failedCount > 0): ?>
    <div class="alert alert-light border small mb-3">
      <?php if ($pendingCount > 0): ?><span class="me-3"><span class="spinner-border spinner-border-sm"></span> <?= $pendingCount ?> analysis in progress</span><?php endif; ?>
      <?php if ($failedCount > 0): ?><span class="text-danger">⚠️ <?= $failedCount ?> analysis failed — reopen those feedback entries and click "Re-analyze"</span><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ===== TREND CHART ===== -->
    <div class="card mb-3">
      <div class="card-header">
        <span><i class="bi bi-graph-up"></i> Sentiment Trend</span>
        <div class="btn-group btn-group-sm" id="trendPeriodToggle">
          <button type="button" class="btn btn-outline-primary active" data-period="day">Daily</button>
          <button type="button" class="btn btn-outline-primary" data-period="week">Weekly</button>
          <button type="button" class="btn btn-outline-primary" data-period="month">Monthly</button>
          <button type="button" class="btn btn-outline-primary" data-period="year">Yearly</button>
        </div>
      </div>
      <div class="card-body"><canvas id="trendChart" height="90"></canvas></div>
    </div>

    <!-- ===== DISTRIBUTION & TOPICS ===== -->
    <div class="row g-3 mb-3">
      <div class="col-lg-4">
        <div class="card h-100">
          <div class="card-header"><i class="bi bi-pie-chart"></i> Sentiment Distribution</div>
          <div class="card-body"><canvas id="pieChart" height="220"></canvas></div>
        </div>
      </div>
      <div class="col-lg-8">
        <div class="card h-100">
          <div class="card-header"><i class="bi bi-bar-chart"></i> Most Discussed Topics</div>
          <div class="card-body"><canvas id="topicsChart" height="220"></canvas></div>
        </div>
      </div>
    </div>

    <!-- ===== TOP KEYWORDS ===== -->
    <div class="card">
      <div class="card-header"><i class="bi bi-tags"></i> Top Keywords</div>
      <div class="card-body">
        <?php if (empty($topKeywords)): ?>
          <p class="text-muted small mb-0">No keywords extracted yet.</p>
        <?php else: ?>
          <?php $maxCount = max($topKeywords); ?>
          <?php foreach ($topKeywords as $kw => $count): ?>
            <?php $size = 0.75 + (($count / $maxCount) * 0.9); ?>
            <span class="keyword-badge" style="font-size:<?= round($size, 2) ?>rem;">
              <?= e($kw) ?> <span class="keyword-count">(<?= $count ?>)</span>
            </span>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <?php endif; ?>
  </div>
</div>

<script>
window.SENTIMENT_TOTALS = <?= json_encode($totals) ?>;
window.TOPIC_LABELS = <?= json_encode(array_column($topCategories, 'recommended_category')) ?>;
window.TOPIC_COUNTS = <?= json_encode(array_map('intval', array_column($topCategories, 'total'))) ?>;
</script>
<?php
$extraJs = [APP_URL . '/assets/js/ai-analytics.js'];
include __DIR__ . '/../../layouts/footer.php';
?>