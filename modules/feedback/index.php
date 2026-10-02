<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';
require_once __DIR__ . '/../../config/ai_config.php';
require_once __DIR__ . '/../../includes/AI/AIAnalysisManager.php';
require_once __DIR__ . '/../../includes/AI/CEFAIAnalysisManager.php';

requireLogin();

$pdo=db();
lphEnsureMultiDayAttendanceSchema($pdo);
$pageTitle='Public Feedback';
$activeMenu='feedback';
$canReview=hasPermission('lph.feedback.review');
$aiAvailable=AI_ENABLED && AIAnalysisManager::tablesExist();
$aiReviewReady=$aiAvailable && AIAnalysisManager::reviewFeaturesReady();
$cefAiAvailable=AI_ENABLED && CEFAIAnalysisManager::tablesExist();
$cefRecords=[];

if($cefAiAvailable){
    $cefRecords=$pdo->query(
        "SELECT
            s.id,s.reference_number,s.submission_type,s.title,s.details,
            s.priority_level,s.source_channel,s.status submission_status,
            s.created_at,c.name category_name,
            ai.status ai_status,ai.sentiment,ai.urgency_level,
            ai.flagged_for_review,ai.review_status,ai.summary,
            ai.reviewed_at,air_u.full_name reviewed_by_name,
            hi.id linked_issue_id,hi.reference_number linked_issue_ref
         FROM cef_submissions s
         LEFT JOIN hearing_issues hi ON hi.cef_submission_id=s.id
         LEFT JOIN cef_categories c ON c.id=s.category_id
         LEFT JOIN lph_cef_ai_analysis ai ON ai.submission_id=s.id
         LEFT JOIN users air_u ON air_u.id=ai.reviewed_by
         WHERE s.deleted_at IS NULL
           AND s.submission_type IN ('Feedback','Complaint')
         ORDER BY s.created_at DESC,s.id DESC
         LIMIT 20"
    )->fetchAll();
}

$categories=$pdo->query('SELECT id,name FROM feedback_categories ORDER BY name')->fetchAll();
$hearings=$pdo->query(
 "SELECT id,reference_number,title,hearing_date,status
  FROM hearings
  WHERE status<>'Cancelled'
  ORDER BY hearing_date DESC
  LIMIT 200"
)->fetchAll();

$items=$pdo->query(
 "SELECT li.id,li.reference_number,li.title,lit.name type_name
  FROM legislative_items li
  JOIN legislative_item_types lit ON lit.id=li.item_type_id
  WHERE li.deleted_at IS NULL
  ORDER BY li.created_at DESC
  LIMIT 300"
)->fetchAll();

$aiSelect=$aiAvailable
    ? ",ai.analysis_scope ai_analysis_scope,ai.sentiment ai_sentiment,
       ai.confidence_score ai_confidence_score,ai.urgency_level ai_urgency_level,
       ai.urgency_score ai_urgency_score,ai.keyword_score ai_keyword_score,
       ai.summary ai_summary,ai.keywords ai_keywords,ai.risk_keywords ai_risk_keywords,
       ai.recommended_category ai_recommended_category,
       ai.suggested_response ai_suggested_response,
       ai.flagged_for_review ai_flagged_for_review,
       ai.status ai_status,ai.error_message ai_error_message"
       .($aiReviewReady
         ? ",ai.review_status ai_review_status,ai.review_notes ai_review_notes,
            ai.reviewed_by ai_reviewed_by,ai.reviewed_at ai_reviewed_at,
            air_u.full_name ai_reviewed_by_name,
            ai.analysis_version ai_analysis_version,
            (SELECT COUNT(*) FROM feedback_ai_analysis_history hh
             WHERE hh.feedback_id=f.id) ai_history_count"
         : ",NULL ai_review_status,NULL ai_review_notes,NULL ai_reviewed_by,
            NULL ai_reviewed_at,NULL ai_reviewed_by_name,1 ai_analysis_version,0 ai_history_count")
    : ",NULL ai_analysis_scope,NULL ai_sentiment,NULL ai_confidence_score,
       NULL ai_urgency_level,NULL ai_urgency_score,NULL ai_keyword_score,
       NULL ai_summary,NULL ai_keywords,NULL ai_risk_keywords,
       NULL ai_recommended_category,NULL ai_suggested_response,
       0 ai_flagged_for_review,NULL ai_status,NULL ai_error_message,
       NULL ai_review_status,NULL ai_review_notes,NULL ai_reviewed_by,
       NULL ai_reviewed_at,NULL ai_reviewed_by_name,1 ai_analysis_version,0 ai_history_count";

$aiJoin=$aiAvailable
    ? "LEFT JOIN feedback_ai_analysis ai ON ai.feedback_id=f.id
       LEFT JOIN users air_u ON air_u.id=ai.reviewed_by"
    : "";

$feedback=$pdo->query(
 "SELECT f.*,fc.name category_name,h.title hearing_title,h.reference_number hearing_reference,
         li.reference_number legislative_reference,li.title legislative_title,
         vu.full_name validated_by_name,ru.full_name replied_by_name,
         sr.name submitter_role,
         CASE
           WHEN LOWER(COALESCE(fc.name,''))='complaint'
             OR f.user_id IS NULL
             OR f.stakeholder_id IS NOT NULL
             OR sr.name IN ('Registered Stakeholder','Public User')
           THEN 1 ELSE 0
         END ai_eligible
         {$aiSelect}
  FROM feedback f
  LEFT JOIN feedback_categories fc ON fc.id=f.category_id
  LEFT JOIN hearings h ON h.id=f.hearing_id
  LEFT JOIN legislative_items li ON li.id=f.legislative_item_id
  LEFT JOIN users vu ON vu.id=f.validated_by
  LEFT JOIN users ru ON ru.id=f.replied_by
  LEFT JOIN users su ON su.id=f.user_id
  LEFT JOIN roles sr ON sr.id=su.role_id
  {$aiJoin}
  ORDER BY f.submitted_at DESC,f.id DESC"
)->fetchAll();

$stats=['total'=>0,'new'=>0,'review'=>0,'validated'=>0,'responded'=>0];
$s=$pdo->query(
 "SELECT COUNT(*) total,
  SUM(status='New') new,
  SUM(status='Under Review') review,
  SUM(status='Validated') validated,
  SUM(status='Responded') responded
  FROM feedback"
)->fetch();
foreach($stats as $k=>$v)$stats[$k]=(int)($s[$k]??0);

include __DIR__.'/../../layouts/header.php';
?>
<link rel="stylesheet" href="<?= e(APP_URL.'/assets/css/lph-complete-modules.css') ?>">
<div class="app-wrapper"><?php include __DIR__.'/../../layouts/sidebar.php'; ?><div class="main-content">
<?php include __DIR__ . '/../../layouts/top_controls.php'; ?>

<div class="lphx-head">
<div><div class="lphx-eyebrow"><i class="bi bi-chat-square-text"></i> Step 5</div><h1>Public Feedback Collection</h1><p>Collect structured public comments, positions, hearing feedback and legislative input, then validate and record official responses.</p></div>
<div class="d-flex gap-2 flex-wrap"><?php if($canReview&&$aiAvailable): ?><a class="btn btn-outline-secondary" href="ai_analytics.php"><i class="bi bi-robot"></i> AI Sentiment</a><?php endif; ?><a class="btn btn-outline-secondary" href="surveys.php"><i class="bi bi-ui-checks-grid"></i> Surveys</a><button class="btn btn-primary" id="btnNewFeedback"><i class="bi bi-chat-left-text"></i> Submit Feedback</button></div>
</div>

<div class="row g-3 mb-3">
<?php foreach([
 ['Total',$stats['total'],'bi-chat-dots'],
 ['New',$stats['new'],'bi-stars'],
 ['Under Review',$stats['review'],'bi-search'],
 ['Validated',$stats['validated'],'bi-patch-check'],
 ['Responded',$stats['responded'],'bi-reply-all'],
] as [$l,$v,$i]): ?><div class="col-6 col-md"><div class="lphx-stat"><i class="bi <?= e($i) ?>"></i><div><strong><?= $v ?></strong><small><?= e($l) ?></small></div></div></div><?php endforeach; ?>
</div>

<?php if($cefAiAvailable): ?>
<div class="card lphx-card mb-3">
<div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
<span><i class="bi bi-people"></i> Citizen Portal / CEPFMS Feedback &amp; Complaints</span>
<small class="text-light opacity-75">Directly read from CEPFMS—no duplicate LPH feedback records</small>
</div>
<div class="table-responsive">
<table class="table table-hover lphx-table mb-0 align-middle">
<thead><tr><th>Reference</th><th>Submitted</th><th>Type</th><th>Title / Message</th><th>Portal Status</th><th>AI Result &amp; Admin Verification</th><th class="text-end">View &amp; Verify</th></tr></thead>
<tbody>
<?php if(!$cefRecords): ?><tr><td colspan="7"><div class="lphx-empty"><i class="bi bi-inboxes"></i>No Citizen Portal feedback or complaints found.</div></td></tr><?php endif; ?>
<?php foreach($cefRecords as $r): ?>
<tr>
<td><strong><?= e($r['reference_number']) ?></strong><div class="small text-muted"><?= e($r['source_channel']) ?></div></td>
<td><span class="small text-muted text-nowrap"><i class="bi bi-clock me-1 text-primary"></i><?= formatDateTime($r['created_at']) ?></span></td>
<td><span class="badge text-bg-<?= $r['submission_type']==='Complaint'?'danger':'info' ?>"><?= e($r['submission_type']) ?></span><div class="small text-muted"><?= e($r['category_name']?:'Uncategorized') ?></div></td>
<td><strong><?= e($r['title']) ?></strong><div class="small text-muted"><?= e(mb_strimwidth((string)$r['details'],0,110,'…')) ?></div></td>
<td><span class="badge text-bg-light border"><?= e($r['submission_status']) ?></span><div class="small text-muted">Priority: <?= e($r['priority_level']) ?></div></td>
<td>
<?php if(($r['ai_status']??'')==='completed'): ?>
<?php
$sClass=$r['sentiment']==='Negative'?'danger':($r['sentiment']==='Positive'?'success':'warning');
$uClass=in_array($r['urgency_level'],['High','Critical'],true)?'danger':($r['urgency_level']==='Medium'?'warning':'secondary');
$cRevStatus=$r['review_status']??'';
?>
<div class="d-flex flex-column gap-1">
  <div class="d-flex gap-1 flex-wrap align-items-center">
    <span class="badge text-bg-<?= e($sClass) ?>"><i class="bi bi-robot me-1"></i><?= e($r['sentiment']) ?></span>
    <span class="badge text-bg-<?= e($uClass) ?>"><?= e(($r['urgency_level']?:'Low').' urgency') ?></span>
    <?php if((int)($r['flagged_for_review']??0)===1): ?><span class="badge text-bg-danger" title="Flagged for manual review"><i class="bi bi-flag-fill"></i></span><?php endif; ?>
  </div>
  <div>
    <?php if($cRevStatus==='Accepted'): ?>
      <span class="badge bg-success-subtle text-success border border-success-subtle" title="Admin verified AI classification is accurate"><i class="bi bi-patch-check-fill me-1"></i>Verified by Admin</span>
    <?php elseif($cRevStatus==='Corrected'): ?>
      <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle" title="Admin adjusted/corrected AI sentiment or urgency"><i class="bi bi-pencil-square me-1"></i>Adjusted by Admin</span>
    <?php elseif($cRevStatus==='Manual Review'): ?>
      <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle" title="Needs staff manual review"><i class="bi bi-exclamation-circle-fill me-1"></i>Needs Staff Review</span>
    <?php elseif($cRevStatus==='Dismissed'): ?>
      <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle" title="Dismissed / Inaccurate AI"><i class="bi bi-x-circle me-1"></i>Dismissed</span>
    <?php else: ?>
      <span class="badge bg-warning-subtle text-dark border border-warning-subtle" title="AI analyzed, awaiting admin verification"><i class="bi bi-clock-history me-1"></i>Awaiting Admin Check</span>
    <?php endif; ?>
  </div>
</div>
<?php elseif(($r['ai_status']??'')==='failed'): ?>
<span class="badge text-bg-danger"><i class="bi bi-exclamation-triangle me-1"></i>AI failed</span>
<?php else: ?>
<span class="badge text-bg-light border text-muted">Pending Analysis</span>
<?php endif; ?>
</td>
<td class="text-end">
  <div class="d-inline-flex gap-1 align-items-center">
    <?php if(!empty($r['linked_issue_id'])): ?>
      <a class="btn btn-sm btn-outline-success" href="<?= e(APP_URL) ?>/modules/issues/view.php?id=<?= (int)$r['linked_issue_id'] ?>" title="View Linked Issue">
        <i class="bi bi-link-45deg"></i> <?= e($r['linked_issue_ref']) ?>
      </a>
    <?php endif; ?>
    <a class="btn btn-sm btn-outline-primary" href="cef_ai_review.php?id=<?= (int)$r['id'] ?>" title="View &amp; Verify Submission"><i class="bi bi-shield-check me-1"></i> Verify</a>
  </div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<?php endif; ?>

<div class="card lphx-card">
<div class="card-header"><i class="bi bi-inboxes"></i> Feedback Register</div>
<div class="table-responsive">
<table class="table table-hover lphx-table mb-0">
<thead><tr><th>Submitted</th><th>Citizen / Position</th><th>Subject / Message</th><th>Context</th><th>Status</th><th>AI Sentiment &amp; Verification</th><th class="text-end">View &amp; Verify</th></tr></thead>
<tbody>
<?php if(!$feedback): ?><tr><td colspan="7"><div class="lphx-empty"><i class="bi bi-chat-square-text"></i>No feedback submitted yet.</div></td></tr><?php endif; ?>
<?php foreach($feedback as $f): ?>
<tr>
<td><?= formatDateTime($f['submitted_at']) ?></td>
<td><strong><?= $f['is_anonymous']?'Anonymous':e($f['name']) ?></strong><div class="small text-muted"><?= e($f['feedback_position']?:'Comment') ?> · <?= e($f['category_name']?:'Uncategorized') ?></div></td>
<td><strong><?= e($f['subject']?:'(No subject)') ?></strong><div class="lphx-feedback-message"><?= e(mb_strimwidth($f['message'],0,180,'…')) ?></div><?php if(!empty($f['reply_text'])): ?><div class="small text-success mt-1"><i class="bi bi-reply"></i> Official response recorded</div><?php endif; ?></td>
<td><?php if($f['hearing_title']): ?><div><i class="bi bi-calendar-event"></i> <?= e($f['hearing_reference'].' '.$f['hearing_title']) ?></div><?php endif; ?><?php if($f['legislative_reference']): ?><div class="small text-muted"><i class="bi bi-file-text"></i> <?= e($f['legislative_reference']) ?></div><?php endif; ?></td>
<td><span class="badge text-bg-light"><?= e($f['status']) ?></span><div class="small text-muted"><?= e($f['visibility']) ?></div></td>
<td>
<?php if(!$aiAvailable): ?>
<span class="badge text-bg-light">AI unavailable</span>
<?php elseif((int)($f['ai_eligible']??0)!==1): ?>
<span class="badge text-bg-light">Not eligible</span>
<?php elseif(($f['ai_status']??'')==='completed'): ?>
<?php
$sentimentClass=($f['ai_sentiment']??'')==='Negative'?'danger':(($f['ai_sentiment']??'')==='Positive'?'success':'warning');
$urgencyClass=in_array($f['ai_urgency_level']??'', ['High','Critical'], true)?'danger':(($f['ai_urgency_level']??'')==='Medium'?'warning':'secondary');
$revStatus=$f['ai_review_status']??'';
?>
<div class="d-flex flex-column gap-1">
<div class="d-flex gap-1 flex-wrap align-items-center">
<span class="badge text-bg-<?= e($sentimentClass) ?>"><i class="bi bi-robot me-1"></i><?= e($f['ai_sentiment']??'Neutral') ?></span>
<span class="badge text-bg-<?= e($urgencyClass) ?>"><?= e(($f['ai_urgency_level']??'Low').' urgency') ?></span>
<?php if((int)($f['ai_flagged_for_review']??0)===1): ?><span class="badge text-bg-danger" title="Flagged for manual review"><i class="bi bi-flag-fill"></i></span><?php endif; ?>
</div>
<div>
<?php if($revStatus==='Accepted'): ?>
<span class="badge bg-success-subtle text-success border border-success-subtle" title="Admin verified AI classification is accurate"><i class="bi bi-patch-check-fill me-1"></i>Verified by Admin</span>
<?php elseif($revStatus==='Corrected'): ?>
<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle" title="Admin adjusted/corrected AI sentiment or urgency"><i class="bi bi-pencil-square me-1"></i>Adjusted by Admin</span>
<?php elseif($revStatus==='Manual Review'): ?>
<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle" title="Needs staff manual review"><i class="bi bi-exclamation-circle-fill me-1"></i>Needs Staff Review</span>
<?php elseif($revStatus==='Dismissed'): ?>
<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle" title="Dismissed / Inaccurate AI"><i class="bi bi-x-circle me-1"></i>Dismissed</span>
<?php else: ?>
<span class="badge bg-warning-subtle text-dark border border-warning-subtle" title="AI analyzed, awaiting admin verification"><i class="bi bi-clock-history me-1"></i>Awaiting Admin Check</span>
<?php endif; ?>
</div>
</div>
<?php elseif(($f['ai_status']??'')==='failed'): ?>
<span class="badge text-bg-danger"><i class="bi bi-exclamation-triangle me-1"></i>AI failed</span>
<?php elseif(($f['ai_status']??'')==='pending'): ?>
<span class="badge text-bg-info"><span class="spinner-border spinner-border-sm me-1"></span>Analyzing</span>
<?php else: ?>
<span class="badge text-bg-light text-muted">Not analyzed</span>
<?php endif; ?>
</td>
<td class="text-end"><button type="button" class="btn btn-sm btn-outline-primary btn-review-feedback" data-id="<?= (int)$f['id'] ?>" data-row='<?= e(json_encode($f,JSON_HEX_APOS|JSON_HEX_QUOT)) ?>' title="View &amp; Verify Feedback"><i class="bi bi-eye"></i></button></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
</div></div><div class="modal fade" id="feedbackModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: 820px;">
    <div class="modal-content border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
      <form id="feedbackForm">
        <?= csrfField() ?>
        <div class="modal-header py-3 px-4 bg-light border-bottom">
          <div class="d-flex align-items-center gap-2.5">
            <div class="rounded-circle d-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary" style="width: 36px; height: 36px;">
              <i class="bi bi-chat-left-text fs-5"></i>
            </div>
            <div>
              <h5 class="modal-title fw-bold text-dark mb-0" style="font-size: 1.05rem;">Submit Public Feedback</h5>
              <small class="text-muted" style="font-size: 0.8rem;">Record public perspective on active hearings and ordinances</small>
            </div>
          </div>
          <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Name <span class="text-danger">*</span></label>
              <input class="form-control" name="name" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Email <span class="text-danger">*</span></label>
              <input type="email" class="form-control" name="email" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Related Hearing</label>
              <select class="form-select" name="hearing_id">
                <option value="">None</option>
                <?php foreach($hearings as $h): ?>
                  <option value="<?= (int)$h['id'] ?>"><?= e(($h['reference_number']?:'').' '.$h['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Related Legislative Item</label>
              <select class="form-select" name="legislative_item_id">
                <option value="">None</option>
                <?php foreach($items as $i): ?>
                  <option value="<?= (int)$i['id'] ?>"><?= e('['.$i['type_name'].'] '.$i['reference_number'].' - '.$i['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold text-secondary mb-1">Category</label>
              <select class="form-select" name="category_id">
                <option value="">Uncategorized</option>
                <?php foreach($categories as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold text-secondary mb-1">Position</label>
              <select class="form-select" name="feedback_position">
                <option>Comment</option>
                <option>Support</option>
                <option>Oppose</option>
                <option>Neutral</option>
              </select>
            </div>
            <?php if($canReview): ?>
              <div class="col-md-4">
                <label class="form-label small fw-semibold text-secondary mb-1">Visibility</label>
                <select class="form-select" name="visibility">
                  <option>Internal</option>
                  <option>Public</option>
                  <option>Restricted</option>
                </select>
              </div>
            <?php endif; ?>
            <div class="col-12">
              <label class="form-label small fw-semibold text-secondary mb-1">Subject</label>
              <input class="form-control" name="subject" placeholder="Concise topic or title">
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold text-secondary mb-1">Message <span class="text-danger">*</span></label>
              <textarea class="form-control" name="message" rows="4" placeholder="Enter complete stakeholder or citizen statement..." required></textarea>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_anonymous" id="anon">
                <label class="form-check-label small text-muted" for="anon">Display this feedback as anonymous after moderation</label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer py-2.5 px-4 bg-light border-top d-flex justify-content-end gap-2">
          <button class="btn btn-light border px-3" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary px-4 fw-semibold shadow-sm" type="submit"><i class="bi bi-send me-1"></i> Submit Feedback</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if($canReview): ?>
<div class="modal fade" id="reviewModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: 860px;">
    <div class="modal-content border-0 shadow" style="border-radius: 14px; overflow: hidden;">
      <form id="reviewForm">
        <?= csrfField() ?>
        <input type="hidden" name="id" id="rv_id">
        <div class="modal-header py-3 px-4 bg-light border-bottom">
          <div class="d-flex align-items-center gap-2.5">
            <div class="rounded-circle d-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary" style="width: 38px; height: 38px;">
              <i class="bi bi-shield-check fs-5"></i>
            </div>
            <div>
              <h5 class="modal-title fw-bold text-dark mb-0" style="font-size: 1.05rem;">Review &amp; Verify Public Feedback</h5>
              <small class="text-muted" style="font-size: 0.8rem;">Examine citizen perspective, verify Ollama AI sentiment &amp; urgency accuracy, and record official response</small>
            </div>
          </div>
          <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4">
          <!-- Citizen Feedback Message -->
          <div class="mb-3">
            <label class="form-label small fw-bold text-secondary text-uppercase mb-1" style="font-size: 0.75rem; letter-spacing: 0.5px;"><i class="bi bi-chat-quote me-1"></i> Citizen Statement / Message</label>
            <div id="rv_message" class="lphx-feedback-message p-3 bg-light rounded border" style="white-space: pre-wrap; font-size: 0.95rem; line-height: 1.5;"></div>
          </div>

          <?php if($aiAvailable): ?>
          <!-- Ollama AI Analysis Card & Human-in-the-Loop Verification -->
          <div class="card mb-4 border shadow-sm" style="border-radius: 10px; border-color: #cbd5e1;">
            <div class="card-header bg-white py-2.5 px-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
              <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle py-1.5 px-2">
                  <i class="bi bi-robot me-1"></i> Ollama AI Analysis
                </span>
                <span id="rv_ai_verified_tag"></span>
              </div>
              <div class="d-flex gap-2 align-items-center">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnAiHistory">
                  <i class="bi bi-clock-history me-1"></i> History <span id="rv_ai_history_count" class="badge text-bg-secondary ms-1">0</span>
                </button>
                <button type="button" class="btn btn-sm btn-primary" id="btnAnalyzeFeedback">
                  <i class="bi bi-cpu me-1"></i> <span id="btnAnalyzeText">Analyze with Ollama</span>
                </button>
              </div>
            </div>
            <div class="card-body p-3">
              <div id="rv_ai_result" class="small">
                <span class="text-muted"><i class="bi bi-info-circle me-1"></i> Loading analysis...</span>
              </div>

              <!-- Collapsible AI History -->
              <div id="rv_ai_history" class="mt-3 p-3 bg-white border rounded d-none" style="max-height: 250px; overflow-y: auto;"></div>

              <!-- Human-in-the-Loop Admin Verification Panel -->
              <div id="rv_ai_review_controls" class="mt-3 pt-3 border-top d-none">
                <div class="p-3 rounded-3 bg-light border border-secondary-subtle">
                  <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
                    <span class="fw-bold text-dark small">
                      <i class="bi bi-shield-check text-success me-1"></i> Admin AI Verification &amp; Accuracy Check
                    </span>
                    <span class="badge bg-white text-muted border small"><i class="bi bi-person-check me-1"></i>Human Verification</span>
                  </div>
                  <p class="small text-muted mb-3" style="font-size: 0.82rem;">
                    Review whether the Ollama AI analysis and assessment is accurate. You may verify it as accurate, or adjust/correct the sentiment and urgency before finalizing the official status.
                  </p>

                  <div class="row g-2 mb-2">
                    <div class="col-md-4">
                      <label class="form-label small fw-semibold text-secondary mb-1">Admin Decision</label>
                      <select class="form-select form-select-sm" id="rv_ai_review_status">
                        <option value="Accepted">Verified Accurate (AI is correct)</option>
                        <option value="Corrected">Adjust / Correct AI Assessment</option>
                        <option value="Manual Review">Needs Review / Ambiguous</option>
                        <option value="Dismissed">Dismiss / Inaccurate Analysis</option>
                        <option value="Pending">Pending Check</option>
                      </select>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label small fw-semibold text-secondary mb-1">Override Sentiment <span class="text-muted fw-normal">(Optional)</span></label>
                      <select class="form-select form-select-sm" id="rv_ai_override_sentiment">
                        <option value="">Keep AI Sentiment</option>
                        <option value="Positive">Positive</option>
                        <option value="Neutral">Neutral</option>
                        <option value="Negative">Negative</option>
                      </select>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label small fw-semibold text-secondary mb-1">Override Urgency <span class="text-muted fw-normal">(Optional)</span></label>
                      <select class="form-select form-select-sm" id="rv_ai_override_urgency">
                        <option value="">Keep AI Urgency</option>
                        <option value="Low">Low Urgency</option>
                        <option value="Medium">Medium Urgency</option>
                        <option value="High">High Urgency</option>
                        <option value="Critical">Critical Urgency</option>
                      </select>
                    </div>
                  </div>

                  <div class="mb-2">
                    <label class="form-label small fw-semibold text-secondary mb-1">Admin Verification Notes / Remarks</label>
                    <textarea class="form-control form-control-sm" id="rv_ai_review_notes" rows="2" placeholder="e.g. Tone was sarcastic, confirmed citizen complaint about garbage collection, urgency elevated..."></textarea>
                  </div>

                  <div class="d-flex justify-content-between align-items-center pt-1 flex-wrap gap-2">
                    <div id="rv_ai_last_reviewed" class="small text-muted" style="font-size: 0.78rem;"></div>
                    <button type="button" class="btn btn-sm btn-success fw-semibold shadow-sm px-3" id="btnSaveAiReview">
                      <i class="bi bi-check-lg me-1"></i> Save AI Verification
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <?php endif; ?>

          <!-- Official Moderation & Response -->
          <div class="card border shadow-sm" style="border-radius: 10px; border-color: #cbd5e1;">
            <div class="card-header bg-white py-2.5 px-3 border-bottom">
              <span class="fw-bold small text-dark"><i class="bi bi-sliders me-1"></i> Feedback Disposition &amp; Official Response</span>
            </div>
            <div class="card-body p-3">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label small fw-semibold text-secondary mb-1">Status</label>
                  <select class="form-select" name="status" id="rv_status">
                    <option>New</option>
                    <option>Under Review</option>
                    <option>Validated</option>
                    <option>Responded</option>
                    <option>Rejected</option>
                    <option>Archived</option>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold text-secondary mb-1">Visibility</label>
                  <select class="form-select" name="visibility" id="rv_visibility">
                    <option>Internal</option>
                    <option>Public</option>
                    <option>Restricted</option>
                  </select>
                </div>
                <div class="col-12">
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small fw-semibold text-secondary mb-0">Official Response</label>
                    <span class="small text-muted" style="font-size: 0.75rem;">You can adopt the AI suggested response from above</span>
                  </div>
                  <textarea class="form-control" name="reply_text" id="rv_reply" rows="3" placeholder="Record the official response or resolution..."></textarea>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer py-2.5 px-4 bg-light border-top d-flex justify-content-between align-items-center">
          <button class="btn btn-light border px-3" type="button" data-bs-dismiss="modal">Close</button>
          <button class="btn btn-primary px-4 fw-semibold shadow-sm" type="submit"><i class="bi bi-check2-circle me-1"></i> Save Disposition &amp; Response</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded',function(){
 const fbModalEl = document.getElementById('feedbackModal');
 const fmodal = fbModalEl ? new bootstrap.Modal(fbModalEl) : null;
 const btnNew = document.getElementById('btnNewFeedback');
 const fForm = document.getElementById('feedbackForm');
 if(btnNew && fmodal && fForm) btnNew.onclick=()=>{fForm.reset();fmodal.show();};
 if(fForm) fForm.onsubmit=async e=>{e.preventDefault();const r=await appPostForm(APP_URL+'/modules/feedback/ajax_submit.php',fForm);if(r.success){appToast('success',r.message);setTimeout(()=>location.reload(),400);}else if(!r.session_expired)Swal.fire('Feedback Error',r.message,'error');};
 <?php if($canReview): ?>
 const rmodalEl = document.getElementById('reviewModal');
 const rmodal = rmodalEl ? new bootstrap.Modal(rmodalEl) : null;
 let currentFeedbackRow=null;

 function parseList(value){
   if(Array.isArray(value))return value;
   if(!value)return [];
   try{const parsed=JSON.parse(value);return Array.isArray(parsed)?parsed:[];}catch(e){return [];}
 }

 function esc(value){
   const d=document.createElement('div');
   d.textContent=value==null?'':String(value);
   return d.innerHTML;
 }

 function renderAi(analysis){
   const box=document.getElementById('rv_ai_result');
   const verifiedTag=document.getElementById('rv_ai_verified_tag');
   const reviewControls=document.getElementById('rv_ai_review_controls');
   const analyzeText=document.getElementById('btnAnalyzeText');
   const historyCount=document.getElementById('rv_ai_history_count');
   const lastReviewed=document.getElementById('rv_ai_last_reviewed');

   if(!box)return;

   if(!analysis||!analysis.status){
     box.innerHTML='<div class="text-muted p-2"><i class="bi bi-info-circle me-1"></i> This feedback has not been analyzed by Ollama AI yet. Click <strong>Analyze with Ollama</strong> above to run AI sentiment &amp; urgency classification.</div>';
     if(verifiedTag)verifiedTag.innerHTML='';
     if(reviewControls)reviewControls.classList.add('d-none');
     if(analyzeText)analyzeText.textContent='Analyze with Ollama';
     if(historyCount)historyCount.textContent='0';
     return;
   }

   if(analysis.status==='pending'){
     box.innerHTML='<div class="text-info p-2"><span class="spinner-border spinner-border-sm me-2"></span> Running local Ollama AI model inference... Please wait.</div>';
     if(verifiedTag)verifiedTag.innerHTML='<span class="badge bg-info-subtle text-info border">Analyzing...</span>';
     if(reviewControls)reviewControls.classList.add('d-none');
     return;
   }

   if(analysis.status==='failed'){
     box.innerHTML='<div class="alert alert-danger mb-0 py-2"><i class="bi bi-exclamation-triangle-fill me-1"></i> <strong>AI Error:</strong> '+esc(analysis.error_message||'AI analysis failed.')+'</div>';
     if(verifiedTag)verifiedTag.innerHTML='<span class="badge bg-danger-subtle text-danger border">AI Failed</span>';
     if(reviewControls)reviewControls.classList.add('d-none');
     if(analyzeText)analyzeText.textContent='Re-analyze with Ollama';
     return;
   }

   if(analyzeText)analyzeText.textContent='Re-analyze with Ollama';

   const sentiment=analysis.sentiment||'Neutral';
   const urgency=analysis.urgency_level||'Low';
   const sClass=sentiment==='Negative'?'danger':(sentiment==='Positive'?'success':'warning');
   const uClass=(urgency==='High'||urgency==='Critical')?'danger':(urgency==='Medium'?'warning':'secondary');
   const keywords=parseList(analysis.keywords);
   const riskKeywords=parseList(analysis.risk_keywords);
   const revStatus=analysis.review_status||'Pending';

   if(verifiedTag){
     if(revStatus==='Accepted'){
       verifiedTag.innerHTML='<span class="badge bg-success-subtle text-success border border-success-subtle"><i class="bi bi-patch-check-fill me-1"></i>Verified Accurate by Admin</span>';
     }else if(revStatus==='Corrected'){
       verifiedTag.innerHTML='<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle"><i class="bi bi-pencil-square me-1"></i>Adjusted by Admin</span>';
     }else if(revStatus==='Manual Review'){
       verifiedTag.innerHTML='<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"><i class="bi bi-exclamation-circle-fill me-1"></i>Needs Staff Review</span>';
     }else if(revStatus==='Dismissed'){
       verifiedTag.innerHTML='<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle"><i class="bi bi-x-circle me-1"></i>Dismissed / Inaccurate</span>';
     }else{
       verifiedTag.innerHTML='<span class="badge bg-warning-subtle text-dark border border-warning-subtle"><i class="bi bi-clock-history me-1"></i>Awaiting Admin Check</span>';
     }
   }

   box.innerHTML=`
     <div class="p-3 bg-white rounded border mb-2">
       <div class="d-flex gap-2 flex-wrap align-items-center mb-2">
         <span class="badge text-bg-${sClass} px-2.5 py-1.5 fs-6"><i class="bi bi-robot me-1"></i> Sentiment: ${esc(sentiment)}</span>
         <span class="badge text-bg-${uClass} px-2.5 py-1.5 fs-6"><i class="bi bi-speedometer2 me-1"></i> Urgency: ${esc(urgency)} ${analysis.urgency_score!=null?'('+Math.round(Number(analysis.urgency_score))+'%)':''}</span>
         <span class="badge text-bg-light border px-2 py-1"><i class="bi bi-bullseye me-1"></i> Confidence: ${analysis.confidence_score!=null?Math.round(Number(analysis.confidence_score))+'%':'-'}</span>
         <span class="badge text-bg-light border px-2 py-1">Version ${esc(analysis.analysis_version||1)}</span>
         ${Number(analysis.flagged_for_review||0)===1?'<span class="badge text-bg-danger"><i class="bi bi-flag-fill me-1"></i> Flagged for review</span>':''}
       </div>

       <div class="mb-2">
         <strong class="text-secondary small d-block">AI Executive Summary:</strong>
         <div class="text-dark mt-0.5">${esc(analysis.summary||'-')}</div>
       </div>

       <div class="row g-2 mb-2">
         <div class="col-md-6">
           <strong class="text-secondary small d-block mb-1">Keywords:</strong>
           <div>${keywords.length?keywords.map(k=>'<span class="badge bg-light text-dark border me-1 mb-1">'+esc(k)+'</span>').join(''):'<span class="text-muted small">None</span>'}</div>
         </div>
         <div class="col-md-6">
           <strong class="text-secondary small d-block mb-1">Risk / Urgency Indicators:</strong>
           <div>${riskKeywords.length?riskKeywords.map(k=>'<span class="badge bg-danger-subtle text-danger border border-danger-subtle me-1 mb-1"><i class="bi bi-exclamation-triangle me-1"></i>'+esc(k)+'</span>').join(''):'<span class="text-muted small">None detected</span>'}</div>
         </div>
       </div>

       <div class="mb-2">
         <strong class="text-secondary small">Recommended Category:</strong>
         <span class="badge bg-secondary-subtle text-secondary border ms-1">${esc(analysis.recommended_category||'General Feedback')}</span>
       </div>

       ${analysis.suggested_response?`
       <div class="mt-3 pt-2 border-top">
         <div class="d-flex justify-content-between align-items-center mb-1">
           <strong class="text-secondary small"><i class="bi bi-magic me-1"></i> AI Suggested Response Draft:</strong>
           <button type="button" class="btn btn-sm btn-outline-primary" id="btnUseAiReply"><i class="bi bi-clipboard-check me-1"></i> Adopt into Official Response</button>
         </div>
         <div class="p-2.5 rounded bg-light border text-dark font-monospace small" style="white-space: pre-wrap;">${esc(analysis.suggested_response)}</div>
       </div>`:''}
     </div>
   `;

   const use=document.getElementById('btnUseAiReply');
   if(use)use.onclick=()=>{
     rv_reply.value=analysis.suggested_response||'';
     rv_reply.focus();
     appToast('success','AI suggested response copied to Official Response field. You can edit it before saving.');
   };

   if(reviewControls){
     reviewControls.classList.remove('d-none');
     const reviewStatus=document.getElementById('rv_ai_review_status');
     const reviewNotes=document.getElementById('rv_ai_review_notes');
     const overrideSentiment=document.getElementById('rv_ai_override_sentiment');
     const overrideUrgency=document.getElementById('rv_ai_override_urgency');

     if(reviewStatus)reviewStatus.value=analysis.review_status||'Accepted';
     if(reviewNotes)reviewNotes.value=analysis.review_notes||'';
     if(overrideSentiment)overrideSentiment.value=analysis.sentiment||'';
     if(overrideUrgency)overrideUrgency.value=analysis.urgency_level||'';

     if(historyCount)historyCount.textContent=String(analysis.history_count||analysis.ai_history_count||0);

     if(lastReviewed){
       if(analysis.reviewed_at){
         const reviewer=analysis.reviewed_by_name?esc(analysis.reviewed_by_name):'Admin';
         lastReviewed.innerHTML=`<i class="bi bi-info-circle me-1"></i> Last reviewed by <strong>${reviewer}</strong> on ${esc(analysis.reviewed_at)}`;
       }else{
         lastReviewed.innerHTML=`<i class="bi bi-clock me-1"></i> AI analysis awaiting admin verification`;
       }
     }
   }
 }

 document.addEventListener('click', async function(e){
    const b = e.target.closest('.btn-review-feedback');
    if(!b) return;
    e.preventDefault();

    let r = null;
    if(b.dataset.row){
      try{
        r = JSON.parse(b.dataset.row);
      }catch(err){
        console.warn('Failed to parse data-row:', err);
      }
    }

    const fid = r ? r.id : b.dataset.id;
    if(!r && fid){
      try{
        const res = await fetch(APP_URL + '/modules/feedback/ajax_get.php?id=' + encodeURIComponent(fid), {
          headers: {'X-Requested-With': 'XMLHttpRequest'}
        });
        const d = await res.json();
        if(d.success && d.feedback){
          r = d.feedback;
          if(d.ai_analysis){
            r.ai_status = d.ai_analysis.status;
            r.ai_sentiment = d.ai_analysis.sentiment;
            r.ai_urgency_level = d.ai_analysis.urgency_level;
            r.ai_confidence_score = d.ai_analysis.confidence_score;
            r.ai_urgency_score = d.ai_analysis.urgency_score;
            r.ai_summary = d.ai_analysis.summary;
            r.ai_keywords = d.ai_analysis.keywords;
            r.ai_risk_keywords = d.ai_analysis.risk_keywords;
            r.ai_recommended_category = d.ai_analysis.recommended_category;
            r.ai_suggested_response = d.ai_analysis.suggested_response;
            r.ai_review_status = d.ai_analysis.review_status;
            r.ai_review_notes = d.ai_analysis.review_notes;
          }
        }
      }catch(err){
        console.error('Failed to load feedback details:', err);
      }
    }

    if(!r) return;
    currentFeedbackRow = r;

    const elId = document.getElementById('rv_id');
    const elStatus = document.getElementById('rv_status');
    const elVis = document.getElementById('rv_visibility');
    const elReply = document.getElementById('rv_reply');
    const elMsg = document.getElementById('rv_message');

    if(elId) elId.value = r.id;
    if(elStatus) elStatus.value = r.status || 'New';
    if(elVis) elVis.value = r.visibility || 'Internal';
    if(elReply) elReply.value = r.reply_text || '';
    if(elMsg) elMsg.textContent = (r.subject ? 'Subject: ' + r.subject + '\n\n' : '') + (r.message || '');

    const eligible = Number(r.ai_eligible || 0) === 1;
    const analyzeBtn = document.getElementById('btnAnalyzeFeedback');
    if(analyzeBtn) analyzeBtn.classList.toggle('d-none', !eligible);

    if(!eligible && !r.ai_status){
      const aiBox = document.getElementById('rv_ai_result');
      if(aiBox) aiBox.innerHTML = '<span class=\"text-muted\"><i class=\"bi bi-info-circle\"></i> AI analysis is limited to citizen feedback and Complaint submissions.</span>';
    }else{
      renderAi(r.ai_status ? {
        status: r.ai_status,
        sentiment: r.ai_sentiment,
        confidence_score: r.ai_confidence_score,
        urgency_level: r.ai_urgency_level,
        urgency_score: r.ai_urgency_score,
        keyword_score: r.ai_keyword_score,
        summary: r.ai_summary,
        keywords: r.ai_keywords,
        risk_keywords: r.ai_risk_keywords,
        recommended_category: r.ai_recommended_category,
        suggested_response: r.ai_suggested_response,
        flagged_for_review: r.ai_flagged_for_review,
        error_message: r.ai_error_message,
        review_status: r.ai_review_status,
        review_notes: r.ai_review_notes,
        reviewed_by: r.ai_reviewed_by,
        reviewed_by_name: r.ai_reviewed_by_name,
        reviewed_at: r.ai_reviewed_at,
        analysis_version: r.ai_analysis_version,
        history_count: r.ai_history_count
      } : null);
    }

    if(rmodal) rmodal.show();
  });

 const analyzeBtn=document.getElementById('btnAnalyzeFeedback');
 if(analyzeBtn)analyzeBtn.onclick=async()=>{
   if(!currentFeedbackRow)return;

   const oldHtml=analyzeBtn.innerHTML;
   analyzeBtn.disabled=true;
   analyzeBtn.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span> Analyzing...';
   renderAi({status:'pending'});

   const r=await appPost(APP_URL+'/modules/feedback/ajax_analyze.php',{
     id:currentFeedbackRow.id,
     csrf_token:window.APP_CSRF_TOKEN
   });

   analyzeBtn.disabled=false;
   analyzeBtn.innerHTML=oldHtml;

   if(r.success){
     if(r.ai_analysis){
       Object.assign(currentFeedbackRow, {
         ai_status: r.ai_analysis.status,
         ai_sentiment: r.ai_analysis.sentiment,
         ai_urgency_level: r.ai_analysis.urgency_level,
         ai_confidence_score: r.ai_confidence_score,
         ai_urgency_score: r.ai_urgency_score,
         ai_summary: r.ai_summary,
         ai_keywords: r.ai_keywords,
         ai_risk_keywords: r.ai_risk_keywords,
         ai_recommended_category: r.ai_recommended_category,
         ai_suggested_response: r.ai_suggested_response,
         ai_flagged_for_review: r.ai_flagged_for_review,
         ai_review_status: r.ai_analysis.review_status,
         ai_review_notes: r.ai_analysis.review_notes,
         ai_analysis_version: r.ai_analysis.analysis_version,
         ai_history_count: r.ai_analysis.history_count || currentFeedbackRow.ai_history_count || 0
       });
       renderAi(r.ai_analysis);
     }
     appToast('success',r.message||'Ollama AI analysis complete. Please verify the findings below.');
   }else if(!r.session_expired){
     if(r.ai_analysis)renderAi(r.ai_analysis);
     Swal.fire('Ollama AI Analysis',r.message||'Unable to analyze this feedback.','warning');
   }
 };

 const saveAiReviewBtn=document.getElementById('btnSaveAiReview');
 if(saveAiReviewBtn)saveAiReviewBtn.onclick=async()=>{
   if(!currentFeedbackRow)return;

   const decision=document.getElementById('rv_ai_review_status').value;
   const notes=document.getElementById('rv_ai_review_notes').value;
   const overrideSentiment=document.getElementById('rv_ai_override_sentiment').value;
   const overrideUrgency=document.getElementById('rv_ai_override_urgency').value;

   saveAiReviewBtn.disabled=true;
   const old=saveAiReviewBtn.innerHTML;
   saveAiReviewBtn.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

   const r=await appPost(APP_URL+'/modules/feedback/ajax_review_ai.php',{
     id:currentFeedbackRow.id,
     decision:decision,
     notes:notes,
     override_sentiment:overrideSentiment,
     override_urgency:overrideUrgency,
     csrf_token:window.APP_CSRF_TOKEN
   });

   saveAiReviewBtn.disabled=false;
   saveAiReviewBtn.innerHTML=old;

   if(r.success){
     if(r.ai_analysis){
       r.ai_analysis.history_count=currentFeedbackRow.ai_history_count||0;
       Object.assign(currentFeedbackRow, {
         ai_sentiment: r.ai_analysis.sentiment,
         ai_urgency_level: r.ai_analysis.urgency_level,
         ai_review_status: r.ai_analysis.review_status,
         ai_review_notes: r.ai_analysis.review_notes,
         ai_reviewed_at: r.ai_analysis.reviewed_at,
         ai_reviewed_by_name: r.ai_analysis.reviewed_by_name
       });
       renderAi(r.ai_analysis);
     }
     appToast('success',r.message||'AI verification saved successfully.');
     setTimeout(()=>location.reload(), 600);
   }else if(!r.session_expired){
     Swal.fire('AI Verification Error',r.message||'Unable to save AI review.','warning');
   }
 };

 const aiHistoryBtn=document.getElementById('btnAiHistory');
 if(aiHistoryBtn)aiHistoryBtn.onclick=async()=>{
   if(!currentFeedbackRow)return;
   const box=document.getElementById('rv_ai_history');

   if(!box.classList.contains('d-none')){
     box.classList.add('d-none');
     return;
   }

   box.classList.remove('d-none');
   box.innerHTML='<div class="text-muted small"><span class="spinner-border spinner-border-sm me-1"></span> Loading history...</div>';

   try{
     const res=await fetch(
       APP_URL+'/modules/feedback/ajax_ai_history.php?id='+encodeURIComponent(currentFeedbackRow.id),
       {headers:{'X-Requested-With':'XMLHttpRequest'}}
     );
     const r=await res.json();

     if(!r.success){
       box.innerHTML='<div class="alert alert-warning py-2 mb-0">'+esc(r.message||'Unable to load AI history.')+'</div>';
       return;
     }

     const rows=Array.isArray(r.history)?r.history:[];
     if(!rows.length){
       box.innerHTML='<div class="text-muted small">No previous AI analysis versions yet.</div>';
       return;
     }

     box.innerHTML='<div class="small fw-semibold mb-2">Previous AI Analysis Versions</div>'+
       rows.map(h=>{
         const kws=parseList(h.keywords);
         const risks=parseList(h.risk_keywords);
         return `
           <div class="border rounded p-2 mb-2 bg-light">
             <div class="d-flex justify-content-between flex-wrap gap-2">
               <strong>Version ${esc(h.analysis_version||1)}</strong>
               <span>${esc(h.sentiment||'-')} · ${esc(h.urgency_level||'-')} urgency</span>
             </div>
             <div class="text-muted">${esc(h.analyzed_at||h.archived_at||'')}</div>
             <div class="mt-1">${esc(h.summary||h.error_message||'-')}</div>
             ${kws.length?'<div class="mt-1"><strong>Keywords:</strong> '+kws.map(esc).join(', ')+'</div>':''}
             ${risks.length?'<div class="mt-1"><strong>Risk:</strong> '+risks.map(esc).join(', ')+'</div>':''}
             <div class="mt-1"><strong>Staff Review:</strong> ${esc(h.review_status||'Pending')}${h.reviewed_by_name ? ` · Verified by: <strong>${esc(h.reviewed_by_name)}</strong>` : ''}${h.reviewed_at ? ` (${esc(h.reviewed_at)})` : ''}</div>
              ${h.review_notes ? `<div class="mt-1 text-muted ps-2 border-start border-2 border-primary"><em>Notes:</em> ${esc(h.review_notes)}</div>` : ''}
              ${h.archived_by_name ? `<div class="mt-1 text-muted" style="font-size: 0.78rem;"><i class="bi bi-arrow-repeat text-secondary"></i> Re-analyzed by: <strong>${esc(h.archived_by_name)}</strong>${h.archived_at ? ` (${esc(h.archived_at)})` : ''}</div>` : ''}
           </div>
         `;
       }).join('');
   }catch(e){
     box.innerHTML='<div class="alert alert-warning py-2 mb-0">Unable to load AI history.</div>';
   }
 };

 reviewForm.onsubmit=async e=>{e.preventDefault();const r=await appPostForm(APP_URL+'/modules/feedback/ajax_review.php',reviewForm);if(r.success){appToast('success',r.message);setTimeout(()=>location.reload(),400);}else if(!r.session_expired)Swal.fire('Review Error',r.message,'error');};
 const reviewFromQuery=new URLSearchParams(window.location.search).get('review');
 if(reviewFromQuery){
   const target=[...document.querySelectorAll('.btn-review-feedback')].find(btn=>{
     try{return String(JSON.parse(btn.dataset.row).id)===String(reviewFromQuery);}catch(e){return false;}
   });
   if(target)setTimeout(()=>target.click(),100);
 }
 <?php endif; ?>
});
</script>
<?php include __DIR__.'/../../layouts/footer.php'; ?>
