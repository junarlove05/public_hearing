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
require_once __DIR__ . '/../../includes/AI/CEFAIAnalysisManager.php';
require_once __DIR__ . '/../../includes/AI/AIServiceFactory.php';
requireRole([ROLE_ADMIN, ROLE_STAFF]);

$pageTitle  = 'AI Sentiment Analytics';
$activeMenu = 'ai_sentiment';
$activeTab  = 'ai_analytics';

$lphAiAvailable = AIAnalysisManager::tablesExist();
$cefAiAvailable = CEFAIAnalysisManager::tablesExist();
$aiAvailable = $lphAiAvailable || $cefAiAvailable;

$health = ['available'=>false,'message'=>'AI database tables are not set up yet.'];
if($aiAvailable){
    try{
        $health=AIServiceFactory::make()->healthCheck();
    }catch(Throwable $e){
        $health=['available'=>false,'message'=>'Unable to check AI service status: '.$e->getMessage()];
    }
}

$pdo=db();
$totals=['Positive'=>0,'Neutral'=>0,'Negative'=>0];
$totalAnalyzed=0;
$flaggedCount=0;
$pendingCount=0;
$failedCount=0;
$complaintCount=0;
$urgencyTotals=['Low'=>0,'Medium'=>0,'High'=>0,'Critical'=>0];
$topCategories=[];
$topKeywords=[];
$topRiskKeywords=[];
$pendingItems=[];
$priorityQueue=[];
$reviewTotals=['Pending'=>0,'Accepted'=>0,'Manual Review'=>0,'Dismissed'=>0];
$lphAnalyzed=0;
$cefAnalyzed=0;
$aiReviewReady=$aiAvailable;

if($aiAvailable){
    $analysisSources=[];
    if($lphAiAvailable){
        $analysisSources[]="SELECT 'LPH' source_type,sentiment,urgency_level,analysis_scope,flagged_for_review,review_status,status,recommended_category,keywords,risk_keywords FROM feedback_ai_analysis";
    }
    if($cefAiAvailable){
        $analysisSources[]="SELECT 'CEF' source_type,sentiment,urgency_level,analysis_scope,flagged_for_review,review_status,status,recommended_category,keywords,risk_keywords FROM lph_cef_ai_analysis";
    }

    $union=implode(' UNION ALL ',$analysisSources);

    if($union!==''){
        $bySentiment=$pdo->query(
            "SELECT sentiment,COUNT(*) total FROM ({$union}) x WHERE status='completed' AND sentiment IS NOT NULL GROUP BY sentiment"
        )->fetchAll();
        foreach($bySentiment as $row){
            if(isset($totals[$row['sentiment']]))$totals[$row['sentiment']]=(int)$row['total'];
        }
        $totalAnalyzed=array_sum($totals);

        $flaggedCount=(int)$pdo->query(
            "SELECT COUNT(*) FROM ({$union}) x WHERE flagged_for_review=1"
        )->fetchColumn();
        $pendingCount=(int)$pdo->query(
            "SELECT COUNT(*) FROM ({$union}) x WHERE status='pending'"
        )->fetchColumn();
        $failedCount=(int)$pdo->query(
            "SELECT COUNT(*) FROM ({$union}) x WHERE status='failed'"
        )->fetchColumn();
        $complaintCount=(int)$pdo->query(
            "SELECT COUNT(*) FROM ({$union}) x WHERE status='completed' AND analysis_scope='Complaint'"
        )->fetchColumn();

        $byUrgency=$pdo->query(
            "SELECT urgency_level,COUNT(*) total FROM ({$union}) x WHERE status='completed' AND urgency_level IS NOT NULL GROUP BY urgency_level"
        )->fetchAll();
        foreach($byUrgency as $row){
            if(isset($urgencyTotals[$row['urgency_level']]))$urgencyTotals[$row['urgency_level']]=(int)$row['total'];
        }

        $reviewRows=$pdo->query(
            "SELECT review_status,COUNT(*) total FROM ({$union}) x WHERE status='completed' GROUP BY review_status"
        )->fetchAll();
        foreach($reviewRows as $row){
            $key=(string)($row['review_status']??'Pending');
            if(isset($reviewTotals[$key]))$reviewTotals[$key]=(int)$row['total'];
        }

        $topCategories=$pdo->query(
            "SELECT recommended_category,COUNT(*) total FROM ({$union}) x WHERE status='completed' AND recommended_category IS NOT NULL AND recommended_category<>'' GROUP BY recommended_category ORDER BY total DESC LIMIT 10"
        )->fetchAll();

        $keywordCounts=[];
        $riskKeywordCounts=[];
        $keywordRows=$pdo->query(
            "SELECT keywords,risk_keywords FROM ({$union}) x WHERE status='completed'"
        )->fetchAll();
        foreach($keywordRows as $row){
            $kws=json_decode((string)($row['keywords']??''),true);
            if(is_array($kws))foreach($kws as $kw){
                $kw=trim(mb_strtolower((string)$kw));
                if($kw!=='')$keywordCounts[$kw]=($keywordCounts[$kw]??0)+1;
            }
            $risks=json_decode((string)($row['risk_keywords']??''),true);
            if(is_array($risks))foreach($risks as $kw){
                $kw=trim(mb_strtolower((string)$kw));
                if($kw!=='')$riskKeywordCounts[$kw]=($riskKeywordCounts[$kw]??0)+1;
            }
        }
        arsort($keywordCounts);
        arsort($riskKeywordCounts);
        $topKeywords=array_slice($keywordCounts,0,20,true);
        $topRiskKeywords=array_slice($riskKeywordCounts,0,20,true);
    }

    if($lphAiAvailable){
        $lphAnalyzed=(int)$pdo->query("SELECT COUNT(*) FROM feedback_ai_analysis WHERE status='completed'")->fetchColumn();

        $lphPending=$pdo->query(
            "SELECT f.id
             FROM feedback f
             LEFT JOIN feedback_categories fc ON fc.id=f.category_id
             LEFT JOIN users su ON su.id=f.user_id
             LEFT JOIN roles sr ON sr.id=su.role_id
             LEFT JOIN feedback_ai_analysis ai ON ai.feedback_id=f.id
             WHERE (ai.feedback_id IS NULL OR ai.status='pending')
               AND (
                    LOWER(COALESCE(fc.name,''))='complaint'
                    OR f.user_id IS NULL
                    OR f.stakeholder_id IS NOT NULL
                    OR sr.name IN ('Registered Stakeholder','Public User')
               )
             ORDER BY COALESCE(ai.created_at,f.submitted_at),f.id
             LIMIT 3"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach($lphPending as $id)$pendingItems[]=['source'=>'lph','id'=>(int)$id];
    }

    if($cefAiAvailable){
        $cefAnalyzed=(int)$pdo->query("SELECT COUNT(*) FROM lph_cef_ai_analysis WHERE status='completed'")->fetchColumn();

        $remaining=max(0,3-count($pendingItems));
        if($remaining>0){
            $cefPending=$pdo->query(
                "SELECT s.id
                 FROM cef_submissions s
                 LEFT JOIN lph_cef_ai_analysis ai ON ai.submission_id=s.id
                 WHERE s.deleted_at IS NULL
                   AND s.submission_type IN ('Feedback','Complaint')
                   AND (ai.submission_id IS NULL OR ai.status='pending')
                 ORDER BY COALESCE(ai.created_at,s.created_at),s.id
                 LIMIT {$remaining}"
            )->fetchAll(PDO::FETCH_COLUMN);
            foreach($cefPending as $id)$pendingItems[]=['source'=>'cef','id'=>(int)$id];
        }

    }

    $priorityParts=[];
    if($lphAiAvailable){
        $priorityParts[]=
            "SELECT
                'LPH' source_type,
                ai.feedback_id record_id,
                CONCAT('LPH Feedback #',ai.feedback_id) reference_number,
                f.subject,
                ai.analysis_scope,ai.sentiment,ai.urgency_level,ai.urgency_score,
                ai.summary,ai.review_status,ai.flagged_for_review,ai.analyzed_at
             FROM feedback_ai_analysis ai
             JOIN feedback f ON f.id=ai.feedback_id
             WHERE ai.status='completed'
               AND (
                    ai.flagged_for_review=1
                    OR ai.urgency_level IN ('High','Critical')
                    OR (ai.sentiment='Negative' AND ai.review_status IN ('Pending','Manual Review'))
               )";
    }
    if($cefAiAvailable){
        $priorityParts[]=
            "SELECT
                'Citizen Portal' source_type,
                ai.submission_id record_id,
                s.reference_number,
                s.title subject,
                ai.analysis_scope,ai.sentiment,ai.urgency_level,ai.urgency_score,
                ai.summary,ai.review_status,ai.flagged_for_review,ai.analyzed_at
             FROM lph_cef_ai_analysis ai
             JOIN cef_submissions s ON s.id=ai.submission_id
             WHERE ai.status='completed'
               AND s.deleted_at IS NULL
               AND s.status NOT IN ('Withdrawn','Closed')
               AND (
                    ai.flagged_for_review=1
                    OR ai.urgency_level IN ('High','Critical')
                    OR (ai.sentiment='Negative' AND ai.review_status IN ('Pending','Manual Review'))
               )";
    }
    if($priorityParts){
        $prioritySql=implode(' UNION ALL ',$priorityParts);
        $priorityQueue=$pdo->query(
            "SELECT * FROM ({$prioritySql}) q
             ORDER BY
                CASE urgency_level WHEN 'Critical' THEN 1 WHEN 'High' THEN 2 WHEN 'Medium' THEN 3 ELSE 4 END,
                flagged_for_review DESC,
                analyzed_at DESC
             LIMIT 20"
        )->fetchAll();
    }
}

$hideHeader = true;
$hideFooter = true;

include __DIR__ . '/../../layouts/header.php';
?>
<link rel="stylesheet" href="<?= e(APP_URL.'/assets/css/lph-workflow-final.css') ?>">
<style>
/* Hide top header bar & footer on dashboard */
.topnav,
.app-footer,
footer,
.orlms-footer {
    display: none !important;
}
body {
    margin: 0 !important;
    padding-top: 0 !important;
    background-color: #f8fafc !important;
}
.app-wrapper {
    padding-top: 0 !important;
}

/* SIDEBAR OPEN (Default State: 286px Width) */
.sidebar,
.orlms-sidebar {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    bottom: 0 !important;
    width: 286px !important;
    z-index: 1030 !important;
    padding-top: 0 !important;
    transition: width 0.25s ease !important;
}

/* MAIN CONTENT ALIGNMENT (Beside Open Sidebar: margin-left 286px, minimal top padding 0.5rem) */
.main-content,
.orlms-main-content {
    margin-left: 286px !important;
    padding-top: 0.5rem !important;
    padding-left: 1.5rem !important;
    padding-right: 1.5rem !important;
    padding-bottom: 2.5rem !important;
    min-height: 100vh !important;
    position: relative !important;
    transition: margin-left 0.25s ease !important;
}

/* COLLAPSED SIDEBAR - ICON ONLY MODE (74px) */
body.sidebar-collapsed {
    --side: 74px !important;
    --gov-sidebar-width: 74px !important;
}

body.sidebar-collapsed .sidebar,
body.sidebar-collapsed .orlms-sidebar,
.sidebar.collapsed,
.orlms-sidebar.collapsed {
    width: 74px !important;
    min-width: 74px !important;
    max-width: 74px !important;
    transform: none !important;
}

body.sidebar-collapsed .main-content,
body.sidebar-collapsed .orlms-main-content {
    margin-left: 74px !important;
}

body.sidebar-collapsed .sidebar-brand div,
body.sidebar-collapsed .orlms-sidebar-brand div,
body.sidebar-collapsed .sidebar-navigation span,
body.sidebar-collapsed .orlms-sidebar-nav span,
body.sidebar-collapsed .orlms-sidebar-section,
body.sidebar-collapsed .sidebar-section-title,
body.sidebar-collapsed .orlms-sidebar-link > span,
body.sidebar-collapsed .orlms-sidebar-link > em,
body.sidebar-collapsed .orlms-sidebar-footer div {
    display: none !important;
}

body.sidebar-collapsed .sidebar-brand,
body.sidebar-collapsed .orlms-sidebar-brand {
    padding: 0.8rem 0.4rem !important;
    justify-content: center !important;
}

body.sidebar-collapsed .orlms-sidebar-logo,
body.sidebar-collapsed .sidebar-logo {
    width: 42px !important;
    height: 42px !important;
    max-width: 42px !important;
    max-height: 42px !important;
}

body.sidebar-collapsed .sidebar-navigation a,
body.sidebar-collapsed .orlms-sidebar-link {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0.75rem 0 !important;
    margin: 0.25rem 0.4rem !important;
    text-align: center !important;
}

body.sidebar-collapsed .sidebar-navigation i,
body.sidebar-collapsed .orlms-sidebar-link i {
    font-size: 1.4rem !important;
    margin: 0 !important;
    display: inline-block !important;
    visibility: visible !important;
    opacity: 1 !important;
}

body.sidebar-collapsed .orlms-sidebar-footer {
    justify-content: center !important;
    padding: 0.6rem 0 !important;
}

/* Mobile Responsiveness */
@media (max-width: 1050px) {
    .main-content,
    .orlms-main-content,
    body.sidebar-collapsed .main-content,
    body.sidebar-collapsed .orlms-main-content {
        margin-left: 0 !important;
        padding-left: 1rem !important;
        padding-right: 1rem !important;
    }
}

/* Stat KPI Cards Uniform Height & Size */
.lphwf-stats-row {
    display: flex;
    flex-wrap: wrap;
    align-items: stretch;
}
.lphwf-stats-row > div {
    display: flex;
}
.lphwf-stat {
    display: flex !important;
    align-items: center !important;
    gap: 0.7rem !important;
    width: 100% !important;
    height: 100% !important;
    min-height: 92px !important;
    padding: 0.75rem 0.8rem !important;
    background: #ffffff !important;
    border: 1px solid #e2e8f0 !important;
    border-bottom: 3px solid #a97900 !important;
    border-radius: 11px !important;
    box-sizing: border-box !important;
    box-shadow: 0 2px 6px rgba(10, 22, 40, 0.04) !important;
    transition: transform 0.2s ease, box-shadow 0.2s ease !important;
}
.lphwf-stat:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(10, 22, 40, 0.08) !important;
}
.lphwf-stat i {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 40px !important;
    height: 40px !important;
    min-width: 40px !important;
    flex-shrink: 0 !important;
    color: #1a3a5c !important;
    background: #eef5fb !important;
    border-radius: 9px !important;
    font-size: 1.2rem !important;
}
.lphwf-stat > div {
    flex: 1 1 auto !important;
    min-width: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    justify-content: center !important;
}
.lphwf-stat strong {
    display: block !important;
    color: #0a1628 !important;
    font-size: 1.25rem !important;
    font-weight: 700 !important;
    line-height: 1.2 !important;
    margin-bottom: 2px !important;
}
.lphwf-stat small {
    display: block !important;
    color: #64748b !important;
    font-size: 0.65rem !important;
    font-weight: 600 !important;
    line-height: 1.25 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.2px !important;
    word-break: break-word !important;
}

/* Card & Funnel Dark Gold Lining */
.lphwf-card {
    border-top: 3.5px solid #a97900 !important;
}
.lphwf-funnel a {
    border-bottom: 3.5px solid #a97900 !important;
}

/* Nav Tabs Dark Gold Lining */
.nav-tabs {
    border-bottom: 2px solid rgba(169, 121, 0, 0.3) !important;
    gap: 0.35rem;
}
.nav-tabs .nav-link {
    border: none !important;
    border-bottom: 3px solid transparent !important;
    color: #475569 !important;
    font-weight: 650 !important;
    font-size: 0.84rem !important;
    padding: 0.55rem 1rem !important;
    background: transparent !important;
    transition: all 0.2s ease !important;
    border-radius: 8px 8px 0 0 !important;
}
.nav-tabs .nav-link:hover {
    color: #a97900 !important;
    background: rgba(169, 121, 0, 0.05) !important;
    border-bottom: 3px solid rgba(169, 121, 0, 0.4) !important;
}
.nav-tabs .nav-link.active {
    color: #a97900 !important;
    font-weight: 800 !important;
    border-bottom: 3px solid #a97900 !important;
    background: rgba(169, 121, 0, 0.07) !important;
}

/* Period Toggle Buttons */
#trendPeriodToggle .btn-outline-primary {
    border: 1px solid #cbd5e1;
    color: #0f2137;
    background: #ffffff;
    font-size: 0.75rem;
    font-weight: 650;
    padding: 0.25rem 0.65rem;
    transition: all 0.2s ease;
}
#trendPeriodToggle .btn-outline-primary:hover {
    background: rgba(169, 121, 0, 0.08);
    border-color: #a97900;
    color: #a97900;
}
#trendPeriodToggle .btn-outline-primary.active {
    background: #0f2137 !important;
    color: #ffffff !important;
    border-color: #0f2137 !important;
}

/* Global Primary & Indigo Overrides to Dark Blue (#0F2137) */
.btn-primary {
    background-color: #0F2137 !important;
    border-color: #0F2137 !important;
    color: #ffffff !important;
    box-shadow: 0 2px 6px rgba(15, 33, 55, 0.2) !important;
}
.btn-primary:hover, .btn-primary:focus, .btn-primary:active {
    background-color: #1A3A5C !important;
    border-color: #1A3A5C !important;
    color: #ffffff !important;
    box-shadow: 0 4px 12px rgba(15, 33, 55, 0.3) !important;
}
.btn-outline-primary {
    color: #0F2137 !important;
    border-color: #0F2137 !important;
    background: transparent !important;
}
.btn-outline-primary:hover, .btn-outline-primary:focus, .btn-outline-primary.active, .btn-outline-primary:active {
    background-color: #0F2137 !important;
    border-color: #0F2137 !important;
    color: #ffffff !important;
}
.text-primary {
    color: #0F2137 !important;
}
.badge.bg-primary {
    background-color: #0F2137 !important;
}

/* Keyword Badges */
.keyword-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.35rem 0.75rem;
    background: #ffffff;
    color: #0f2137;
    border: 1px solid #e2e8f0;
    border-left: 3px solid #a97900;
    border-radius: 8px;
    font-size: 0.78rem;
    font-weight: 600;
    margin: 0 0.35rem 0.45rem 0;
    box-shadow: 0 2px 4px rgba(10, 22, 40, 0.03);
    transition: all 0.2s ease;
}
.keyword-badge:hover {
    border-color: #a97900;
    background: #fffdf5;
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(169, 121, 0, 0.12);
}
.keyword-badge .keyword-count {
    color: #a97900;
    background: rgba(169, 121, 0, 0.1);
    padding: 0.1rem 0.4rem;
    border-radius: 99px;
    font-size: 0.7rem;
    font-weight: 700;
}
.keyword-badge-risk {
    border-left-color: #dc2626 !important;
}
.keyword-badge-risk:hover {
    border-color: #dc2626 !important;
    background: #fef2f2;
}
.keyword-badge-risk .keyword-count {
    color: #ffffff !important;
    background: #dc2626 !important;
}
</style>

<div class="app-wrapper">
  <?php include __DIR__ . '/../../layouts/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../layouts/top_controls.php'; ?>

    <div class="lphwf-head">
      <div>
        <div class="lphwf-eyebrow">Subsystem #7 · Step 5 · AI Intelligence Analytics</div>
        <h1>AI Sentiment &amp; Urgency Analytics</h1>
        <p>Cross-source AI feedback &amp; complaint monitoring across Public Hearings and Citizen Portal / CEPFMS · Powered by <?= AI_PROVIDER === 'gemini' ? 'Google Gemini AI (' . e(GEMINI_MODEL) . ')' : 'Ollama (' . e(OLLAMA_MODEL) . ')' ?>.</p>
      </div>
      <?php if($aiAvailable): ?>
      <div class="d-flex gap-2 flex-wrap align-items-center">
        <button type="button" class="btn btn-outline-primary" id="btnOpenAiSettings" onclick="openAiSettingsModal()">
          <i class="bi bi-gear-fill me-1"></i> Configure AI / API Key
        </button>
        <a class="btn btn-outline-secondary" href="export_ai_csv.php">
          <i class="bi bi-filetype-csv"></i> Export AI CSV
        </a>
        <button
          type="button"
          class="btn btn-primary"
          id="btnAnalyzePending"
          <?= empty($pendingItems)?'disabled':'' ?>
        >
          <i class="bi bi-stars"></i>
          Analyze Next <?= min(3,count($pendingItems)) ?>
        </button>
      </div>
      <?php endif; ?>
    </div>

    <?php include __DIR__ . '/tabs.php'; ?>

    <!-- AI Service Status Card -->
    <div class="card lphwf-card mb-3" style="border-top: 3.5px solid #a97900 !important; border-left: 4.5px solid #a97900 !important;">
      <div class="card-body py-2 px-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
          <i class="bi <?= $health['available'] ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-warning' ?> fs-5"></i>
          <div>
            <strong style="color: #0f2137; font-size: 0.85rem;">AI Service Status (<?= strtoupper(AI_PROVIDER) ?>):</strong>
            <span class="text-secondary small ms-1"><?= e($health['message']) ?></span>
          </div>
        </div>
        <div class="d-flex align-items-center gap-2 small text-muted">
          <span class="badge" style="background: rgba(15, 33, 55, 0.06); color: #0f2137; border: 1px solid #e2e8f0; border-radius: 6px; padding: 4px 8px;">
            <i class="bi bi-stars me-1" style="color: #0F2137;"></i>Provider: <?= strtoupper(AI_PROVIDER) ?> · Model: <?= AI_PROVIDER === 'gemini' ? e(GEMINI_MODEL) : e(OLLAMA_MODEL) ?>
          </span>
          <?php if($aiAvailable): ?>
          <span class="badge" style="background: rgba(169, 121, 0, 0.08); color: #a97900; border: 1px solid rgba(169, 121, 0, 0.2); border-radius: 6px; padding: 4px 8px;">
            <i class="bi bi-diagram-3 me-1"></i>Combined: LPH (<?= (int)$lphAnalyzed ?>) · Portal (<?= (int)$cefAnalyzed ?>)
          </span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if (!$aiAvailable): ?>
      <div class="alert alert-info">
        <i class="bi bi-info-circle fs-5"></i>
        Run <code>database/migration_006_lph_ai_sentiment_urgency_fixed.sql</code> once to enable AI sentiment analytics.
      </div>
    <?php else: ?>

    <!-- Summary Funnel Strip -->
    <div class="lphwf-funnel mb-3">
      <a><strong style="color: #0F2137;"><?= $totalAnalyzed ?></strong><span>Total Analyzed</span></a>
      <a><strong style="color: #a97900;"><?= $totals['Positive'] ?></strong><span>Positive Sentiment</span></a>
      <a><strong style="color: #0F2137;"><?= $totals['Neutral'] ?></strong><span>Neutral Sentiment</span></a>
      <a><strong style="color: #dc2626;"><?= $totals['Negative'] ?></strong><span>Negative Sentiment</span></a>
      <a><strong style="color: #a97900;"><?= $flaggedCount ?></strong><span>Flagged For Review</span></a>
    </div>

    <!-- Primary KPI Stat Cards (Uniform Dark Gold Lining) -->
    <div class="row g-3 mb-3 lphwf-stats-row">
      <?php foreach([
        ['Positive Feedback', $totals['Positive'], 'bi-emoji-smile'],
        ['Neutral Feedback', $totals['Neutral'], 'bi-emoji-neutral'],
        ['Negative Feedback', $totals['Negative'], 'bi-emoji-frown'],
        ['Flagged for Review', $flaggedCount, 'bi-flag'],
        ['Analyzed Complaints', $complaintCount, 'bi-chat-left-dots'],
        ['Critical Urgency', $urgencyTotals['Critical'], 'bi-lightning-charge'],
      ] as [$label, $val, $icon]): ?>
      <div class="col-6 col-md-4 col-xl-2 d-flex">
        <div class="lphwf-stat w-100 h-100" style="border-bottom: 3.5px solid #a97900 !important;">
          <i class="bi <?= e($icon) ?>"></i>
          <div>
            <strong><?= $val ?></strong>
            <small><?= e($label) ?></small>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if($aiReviewReady): ?>
    <!-- Secondary Review & Pipeline Row (Uniform Dark Gold Lining) -->
    <div class="row g-3 mb-3 lphwf-stats-row">
      <?php foreach([
        ['Low Urgency', $urgencyTotals['Low'], 'bi-shield-check'],
        ['Medium Urgency', $urgencyTotals['Medium'], 'bi-exclamation-circle'],
        ['High Urgency', $urgencyTotals['High'], 'bi-exclamation-triangle'],
        ['AI Review Pending', $reviewTotals['Pending'], 'bi-hourglass-split'],
        ['AI Accepted', $reviewTotals['Accepted'], 'bi-check-circle'],
        ['AI Dismissed', $reviewTotals['Dismissed'], 'bi-x-circle'],
      ] as [$label, $val, $icon]): ?>
      <div class="col-6 col-md-4 col-xl-2 d-flex">
        <div class="lphwf-stat w-100 h-100" style="border-bottom: 3.5px solid #a97900 !important;">
          <i class="bi <?= e($icon) ?>"></i>
          <div>
            <strong><?= $val ?></strong>
            <small><?= e($label) ?></small>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($pendingCount > 0 || $failedCount > 0): ?>
    <div class="alert alert-light border small mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2" style="border-radius: 10px; background: #ffffff;">
      <div class="d-flex align-items-center gap-3">
        <?php if ($pendingCount > 0): ?>
          <span class="d-inline-flex align-items-center gap-2 fw-semibold" style="color: #0F2137;">
            <span class="spinner-border spinner-border-sm"></span> <?= $pendingCount ?> analysis queued in progress
          </span>
        <?php endif; ?>
        <?php if ($failedCount > 0): ?>
          <span class="text-danger fw-semibold">
            <i class="bi bi-exclamation-circle-fill me-1"></i> <?= $failedCount ?> analysis failed — reopen entries to re-analyze
          </span>
        <?php endif; ?>
      </div>
      <span class="text-muted small">Automatic background batch processor</span>
    </div>
    <?php endif; ?>

    <!-- Trend Chart Card -->
    <div class="card lphwf-card mb-3" style="border-top: 3.5px solid #a97900; box-shadow: 0 4px 18px rgba(10,22,40,0.06);">
      <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2" style="color: #0F2137; font-weight: 750; background: #ffffff !important; border-bottom: 1px solid #e2e8f0 !important;">
        <div class="d-flex align-items-center">
          <span style="display:inline-block;width:4px;height:16px;background:#a97900;border-radius:2px;margin-right:8px;"></span>
          <span>Sentiment Timeline Trend</span>
        </div>
        <div class="d-flex align-items-center gap-2">
          <div class="btn-group btn-group-sm" id="trendPeriodToggle">
            <button type="button" class="btn btn-outline-primary active" data-period="day">Daily</button>
            <button type="button" class="btn btn-outline-primary" data-period="week">Weekly</button>
            <button type="button" class="btn btn-outline-primary" data-period="month">Monthly</button>
            <button type="button" class="btn btn-outline-primary" data-period="year">Yearly</button>
          </div>
          <span class="badge" style="background: rgba(169, 121, 0, 0.12); color: #8A6400; border: 1px solid rgba(169, 121, 0, 0.3); font-size: 0.7rem; font-weight: 650; border-radius: 6px; padding: 4px 8px;">
            <i class="bi bi-graph-up me-1"></i>Trend
          </span>
        </div>
      </div>
      <div class="card-body py-3" style="position: relative;">
        <canvas id="trendChart" height="90"></canvas>
      </div>
    </div>

    <?php if($aiReviewReady): ?>
    <!-- Priority Staff Review Queue -->
    <div class="card lphwf-card mb-3" style="border-top: 3.5px solid #a97900; box-shadow: 0 4px 18px rgba(10,22,40,0.06);">
      <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2" style="color: #0F2137; font-weight: 750; background: #ffffff !important; border-bottom: 1px solid #e2e8f0 !important;">
        <div class="d-flex align-items-center">
          <span style="display:inline-block;width:4px;height:16px;background:#a97900;border-radius:2px;margin-right:8px;"></span>
          <span>Priority Staff Review Queue</span>
        </div>
        <span class="badge" style="background: rgba(169, 121, 0, 0.12); color: #8A6400; border: 1px solid rgba(169, 121, 0, 0.3); font-size: 0.7rem; font-weight: 650; border-radius: 6px; padding: 4px 8px;">
          <i class="bi bi-shield-exclamation me-1"></i>Flagged &amp; High Urgency
        </span>
      </div>
      <div class="table-responsive">
        <table class="table lphwf-table table-hover mb-0">
          <thead>
            <tr>
              <th>Feedback &amp; Subject</th>
              <th>AI Sentiment &amp; Urgency</th>
              <th>AI Extracted Summary</th>
              <th>Review Status</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if(!$priorityQueue): ?>
            <tr><td colspan="5" class="lphwf-empty py-4">No priority AI cases waiting for staff attention.</td></tr>
          <?php endif; ?>
          <?php foreach($priorityQueue as $q): ?>
            <tr>
              <td>
                <span class="lphwf-code me-1"><?= e($q['reference_number']?:('#'.(int)$q['record_id'])) ?></span>
                <strong><?= e($q['subject']?:'No subject') ?></strong>
                <div class="small text-muted mt-1">
                  <span class="badge bg-light text-dark border"><?= e($q['source_type']) ?></span>
                  <?= e($q['analysis_scope']?:'Citizen Feedback') ?>
                  <span class="ms-2 text-secondary"><i class="bi bi-clock"></i> <?= formatDateTime($q['analyzed_at']) ?></span>
                </div>
              </td>
              <td>
                <?php
                  $sColor = $q['sentiment']==='Negative'
                    ? 'background: #dc2626; color: #ffffff; border: 1px solid #b91c1c;'
                    : ($q['sentiment']==='Positive'
                      ? 'background: rgba(169, 121, 0, 0.12); color: #8A6400; border: 1px solid rgba(169, 121, 0, 0.3);'
                      : 'background: rgba(15, 33, 55, 0.08); color: #0F2137; border: 1px solid rgba(15, 33, 55, 0.2);');
                  $uColor = in_array($q['urgency_level'],['High','Critical'],true)
                    ? 'background: #dc2626; color: #ffffff; border: 1px solid #b91c1c;'
                    : ($q['urgency_level']==='Medium'
                      ? 'background: rgba(169, 121, 0, 0.12); color: #8A6400; border: 1px solid rgba(169, 121, 0, 0.3);'
                      : 'background: rgba(15, 33, 55, 0.08); color: #0F2137; border: 1px solid rgba(15, 33, 55, 0.2);');
                ?>
                <span class="badge me-1" style="<?= $sColor ?> border-radius: 6px; font-weight: 650; font-size: 0.72rem; padding: 4px 8px;">
                  <?= e($q['sentiment']?:'Neutral') ?>
                </span>
                <span class="badge" style="<?= $uColor ?> border-radius: 6px; font-weight: 650; font-size: 0.72rem; padding: 4px 8px;">
                  <?= e(($q['urgency_level']?:'Low').' urgency') ?>
                </span>
              </td>
              <td class="small" style="max-width: 380px;"><?= e(mb_strimwidth((string)($q['summary']??''),0,130,'…')) ?></td>
              <td><span class="badge text-bg-light border"><?= e($q['review_status']?:'Pending') ?></span></td>
              <td class="text-end">
                <?php if($q['source_type']==='Citizen Portal'): ?>
                <a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL.'/modules/feedback/cef_ai_review.php?id='.(int)$q['record_id']) ?>" style="border-radius: 6px; padding: 3px 10px; font-size: 0.78rem;">
                  <i class="bi bi-search me-1"></i> Review
                </a>
                <?php else: ?>
                <a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL.'/modules/feedback/index.php?review='.(int)$q['record_id']) ?>" style="border-radius: 6px; padding: 3px 10px; font-size: 0.78rem;">
                  <i class="bi bi-search me-1"></i> Review
                </a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- Distribution, Urgency & Topics -->
    <div class="row g-3 mb-3">
      <div class="col-lg-4">
        <div class="card lphwf-card h-100" style="border-top: 3.5px solid #a97900; box-shadow: 0 4px 18px rgba(10,22,40,0.06);">
          <div class="card-header d-flex align-items-center justify-content-between" style="color: #0F2137; font-weight: 750; background: #ffffff !important; border-bottom: 1px solid #e2e8f0 !important;">
            <div class="d-flex align-items-center">
              <span style="display:inline-block;width:4px;height:16px;background:#a97900;border-radius:2px;margin-right:8px;"></span>
              <span>Sentiment Distribution</span>
            </div>
            <span class="badge" style="background: rgba(169, 121, 0, 0.12); color: #8A6400; border: 1px solid rgba(169, 121, 0, 0.3); font-size: 0.7rem; font-weight: 650; border-radius: 6px; padding: 4px 8px;">
              <i class="bi bi-pie-chart me-1"></i>Shares
            </span>
          </div>
          <div class="card-body d-flex align-items-center justify-content-center p-3" style="position: relative; min-height: 240px;">
            <canvas id="pieChart"></canvas>
          </div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="card lphwf-card h-100" style="border-top: 3.5px solid #a97900; box-shadow: 0 4px 18px rgba(10,22,40,0.06);">
          <div class="card-header d-flex align-items-center justify-content-between" style="color: #0F2137; font-weight: 750; background: #ffffff !important; border-bottom: 1px solid #e2e8f0 !important;">
            <div class="d-flex align-items-center">
              <span style="display:inline-block;width:4px;height:16px;background:#a97900;border-radius:2px;margin-right:8px;"></span>
              <span>Urgency Breakdown</span>
            </div>
            <span class="badge" style="background: rgba(169, 121, 0, 0.12); color: #8A6400; border: 1px solid rgba(169, 121, 0, 0.3); font-size: 0.7rem; font-weight: 650; border-radius: 6px; padding: 4px 8px;">
              <i class="bi bi-speedometer2 me-1"></i>Severity
            </span>
          </div>
          <div class="card-body d-flex align-items-center justify-content-center p-3" style="position: relative; min-height: 240px;">
            <canvas id="urgencyChart"></canvas>
          </div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="card lphwf-card h-100" style="border-top: 3.5px solid #a97900; box-shadow: 0 4px 18px rgba(10,22,40,0.06);">
          <div class="card-header d-flex align-items-center justify-content-between" style="color: #0F2137; font-weight: 750; background: #ffffff !important; border-bottom: 1px solid #e2e8f0 !important;">
            <div class="d-flex align-items-center">
              <span style="display:inline-block;width:4px;height:16px;background:#a97900;border-radius:2px;margin-right:8px;"></span>
              <span>Most Discussed Topics</span>
            </div>
            <span class="badge" style="background: rgba(169, 121, 0, 0.12); color: #8A6400; border: 1px solid rgba(169, 121, 0, 0.3); font-size: 0.7rem; font-weight: 650; border-radius: 6px; padding: 4px 8px;">
              <i class="bi bi-bar-chart me-1"></i>Categories
            </span>
          </div>
          <div class="card-body p-3" style="position: relative; min-height: 240px;">
            <canvas id="topicsChart"></canvas>
          </div>
        </div>
      </div>
    </div>

    <!-- Top Keywords -->
    <div class="row g-3">
      <div class="col-lg-6">
        <div class="card lphwf-card h-100" style="border-top: 3.5px solid #a97900; box-shadow: 0 4px 18px rgba(10,22,40,0.06);">
          <div class="card-header d-flex align-items-center justify-content-between" style="color: #0F2137; font-weight: 750; background: #ffffff !important; border-bottom: 1px solid #e2e8f0 !important;">
            <div class="d-flex align-items-center">
              <span style="display:inline-block;width:4px;height:16px;background:#a97900;border-radius:2px;margin-right:8px;"></span>
              <span>Top Topic Keywords</span>
            </div>
            <span class="badge" style="background: rgba(169, 121, 0, 0.12); color: #8A6400; border: 1px solid rgba(169, 121, 0, 0.3); font-size: 0.7rem; font-weight: 650; border-radius: 6px; padding: 4px 8px;">
              <i class="bi bi-tags me-1"></i>Topics
            </span>
          </div>
          <div class="card-body p-3">
            <?php if (empty($topKeywords)): ?>
              <p class="text-muted small mb-0">No topic keywords extracted yet.</p>
            <?php else: ?>
              <div class="d-flex flex-wrap">
                <?php foreach ($topKeywords as $kw => $count): ?>
                  <span class="keyword-badge">
                    <i class="bi bi-tag-fill me-1" style="color: #a97900; font-size: 0.7rem;"></i>
                    <?= e($kw) ?> <span class="keyword-count"><?= $count ?></span>
                  </span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card lphwf-card h-100" style="border-top: 3.5px solid #a97900; box-shadow: 0 4px 18px rgba(10,22,40,0.06);">
          <div class="card-header d-flex align-items-center justify-content-between" style="color: #0F2137; font-weight: 750; background: #ffffff !important; border-bottom: 1px solid #e2e8f0 !important;">
            <div class="d-flex align-items-center">
              <span style="display:inline-block;width:4px;height:16px;background:#a97900;border-radius:2px;margin-right:8px;"></span>
              <span>Top Risk &amp; Urgency Keywords</span>
            </div>
            <span class="badge" style="background: rgba(169, 121, 0, 0.12); color: #8A6400; border: 1px solid rgba(169, 121, 0, 0.3); font-size: 0.7rem; font-weight: 650; border-radius: 6px; padding: 4px 8px;">
              <i class="bi bi-exclamation-triangle me-1"></i>Risk Signals
            </span>
          </div>
          <div class="card-body p-3">
            <?php if (empty($topRiskKeywords)): ?>
              <p class="text-muted small mb-0">No risk or urgency keywords extracted yet.</p>
            <?php else: ?>
              <div class="d-flex flex-wrap">
                <?php foreach ($topRiskKeywords as $kw => $count): ?>
                  <span class="keyword-badge keyword-badge-risk">
                    <i class="bi bi-shield-alert me-1" style="color: #dc2626; font-size: 0.7rem;"></i>
                    <?= e($kw) ?> <span class="keyword-count"><?= $count ?></span>
                  </span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <?php endif; ?>
  </div>
</div>

<!-- AI Provider & API Key Modal -->
<div class="modal fade" id="modalAiSettings" tabindex="-1" aria-labelledby="modalAiSettingsLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius: 14px; border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.15);">
      <div class="modal-header" style="background: #0F2137; color: #ffffff; border-radius: 14px 14px 0 0; padding: 1rem 1.25rem;">
        <h5 class="modal-title d-flex align-items-center gap-2" id="modalAiSettingsLabel" style="font-size: 1.05rem; font-weight: 700;">
          <i class="bi bi-stars" style="color: #a97900;"></i> AI Intelligence &amp; Cloud Settings
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="formAiSettings" onsubmit="saveAiSettings(event)">
        <div class="modal-body p-3 p-md-4">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

          <div class="mb-3">
            <label class="form-label fw-bold text-dark small">AI Engine Provider</label>
            <div class="d-flex gap-2">
              <div class="form-check p-2 border rounded flex-fill" style="background: #f8fafc;">
                <input class="form-check-input ms-1 me-2" type="radio" name="ai_provider" id="provGemini" value="gemini" onchange="toggleProviderFields()">
                <label class="form-check-label fw-semibold" for="provGemini">
                  <i class="bi bi-cloud-check-fill text-primary me-1"></i> Google Gemini (Cloud)
                  <small class="d-block text-muted" style="font-size: 0.72rem;">Fast, accurate, works on any host</small>
                </label>
              </div>
              <div class="form-check p-2 border rounded flex-fill" style="background: #f8fafc;">
                <input class="form-check-input ms-1 me-2" type="radio" name="ai_provider" id="provOllama" value="ollama" onchange="toggleProviderFields()">
                <label class="form-check-label fw-semibold" for="provOllama">
                  <i class="bi bi-cpu-fill text-secondary me-1"></i> Ollama (Local)
                  <small class="d-block text-muted" style="font-size: 0.72rem;">Local offline model (requires local daemon)</small>
                </label>
              </div>
            </div>
          </div>

          <div id="geminiFields">
            <div class="mb-3">
              <label class="form-label fw-bold text-dark small">Google Gemini API Key</label>
              <div class="input-group">
                <span class="input-group-text bg-light"><i class="bi bi-key-fill text-warning"></i></span>
                <input type="password" class="form-control" name="gemini_api_key" id="cfgGeminiKey" placeholder="Paste your AI Studio API key here (AIza...)">
                <button class="btn btn-outline-secondary" type="button" onclick="toggleApiKeyVisibility()"><i class="bi bi-eye" id="iconEye"></i></button>
              </div>
              <small class="text-muted d-block mt-1" style="font-size: 0.75rem;">
                Free API keys available at <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">Google AI Studio</a>.
              </small>
            </div>

            <div class="mb-3">
              <label class="form-label fw-bold text-dark small">Gemini Model</label>
              <select class="form-select" name="gemini_model" id="cfgGeminiModel">
                <option value="gemini-3.8-flash" selected>gemini-3.8-flash (Latest, Recommended)</option>
                <option value="gemini-2.5-flash">gemini-2.5-flash (Fast)</option>
                <option value="gemini-3-flash-preview">gemini-3-flash-preview (Preview)</option>
              </select>
            </div>
          </div>

          <div id="ollamaFields" style="display: none;">
            <div class="alert alert-warning py-2 px-3 small">
              <i class="bi bi-exclamation-triangle-fill me-1"></i> Ollama requires <code>http://127.0.0.1:11434</code> running locally with the model installed.
            </div>
          </div>

          <div id="aiTestFeedback" class="mt-2" style="display: none;"></div>
        </div>
        <div class="modal-footer bg-light px-3 py-2 d-flex justify-content-between">
          <button type="button" class="btn btn-outline-secondary btn-sm" onclick="testAiConnection()">
            <i class="bi bi-activity me-1"></i> Test Connection
          </button>
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary btn-sm" id="btnSaveAiCfg">
              <i class="bi bi-check-lg me-1"></i> Save AI Settings
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
window.SENTIMENT_TOTALS = <?= json_encode($totals) ?>;
window.URGENCY_TOTALS = <?= json_encode($urgencyTotals) ?>;
window.TOPIC_LABELS = <?= json_encode(array_column($topCategories, 'recommended_category')) ?>;
window.TOPIC_COUNTS = <?= json_encode(array_map('intval', array_column($topCategories, 'total'))) ?>;
window.AI_PENDING_ITEMS = <?= json_encode($pendingItems) ?>;

function toggleProviderFields() {
  const isGemini = document.getElementById('provGemini').checked;
  document.getElementById('geminiFields').style.display = isGemini ? 'block' : 'none';
  document.getElementById('ollamaFields').style.display = isGemini ? 'none' : 'block';
}

function toggleApiKeyVisibility() {
  const inp = document.getElementById('cfgGeminiKey');
  const icon = document.getElementById('iconEye');
  if (inp.type === 'password') {
    inp.type = 'text';
    icon.className = 'bi bi-eye-slash';
  } else {
    inp.type = 'password';
    icon.className = 'bi bi-eye';
  }
}

async function openAiSettingsModal() {
  try {
    const res = await fetch('ajax_ai_settings.php?action=get', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await res.json();
    if (data && data.success) {
      const cfg = data.data || {};
      if (cfg.ai_provider === 'ollama') {
        document.getElementById('provOllama').checked = true;
      } else {
        document.getElementById('provGemini').checked = true;
      }
      toggleProviderFields();
      if (cfg.masked_api_key) {
        document.getElementById('cfgGeminiKey').value = cfg.masked_api_key;
      } else {
        document.getElementById('cfgGeminiKey').value = '';
      }
      if (cfg.gemini_model) {
        document.getElementById('cfgGeminiModel').value = cfg.gemini_model;
      }
      document.getElementById('aiTestFeedback').style.display = 'none';
      const m = new bootstrap.Modal(document.getElementById('modalAiSettings'));
      m.show();
    }
  } catch (e) {
    Swal.fire('Error', 'Unable to load AI settings: ' + e.message, 'error');
  }
}

async function testAiConnection() {
  const fb = document.getElementById('aiTestFeedback');
  fb.style.display = 'block';
  fb.className = 'alert alert-info py-2 px-3 small';
  fb.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Testing connection to AI service...';
  try {
    const res = await fetch('ajax_ai_settings.php?action=test', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await res.json();
    if (data && data.success) {
      fb.className = 'alert alert-success py-2 px-3 small';
      fb.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> ' + (data.message || 'AI service connected successfully!');
    } else {
      fb.className = 'alert alert-danger py-2 px-3 small';
      fb.innerHTML = '<i class="bi bi-x-circle-fill me-1"></i> ' + (data.message || 'Connection test failed.');
    }
  } catch (e) {
    fb.className = 'alert alert-danger py-2 px-3 small';
    fb.innerHTML = '<i class="bi bi-x-circle-fill me-1"></i> Network error: ' + e.message;
  }
}

async function saveAiSettings(e) {
  e.preventDefault();
  const form = document.getElementById('formAiSettings');
  const btn = document.getElementById('btnSaveAiCfg');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';
  try {
    const formData = new FormData(form);
    const res = await fetch('ajax_ai_settings.php', {
      method: 'POST',
      body: formData,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await res.json();
    if (data && data.success) {
      bootstrap.Modal.getInstance(document.getElementById('modalAiSettings'))?.hide();
      Swal.fire({
        title: 'Settings Saved',
        text: data.message || 'AI settings updated successfully!',
        icon: 'success',
        timer: 1500,
        showConfirmButton: false
      }).then(() => {
        window.location.reload();
      });
    } else {
      Swal.fire('Error', data.message || 'Failed to save settings', 'error');
    }
  } catch (err) {
    Swal.fire('Error', err.message, 'error');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-check-lg me-1"></i> Save AI Settings';
  }
}
</script>
<?php
$extraJs = [APP_URL . '/assets/js/ai-analytics.js?v=' . time()];
include __DIR__ . '/../../layouts/footer.php';
?>
