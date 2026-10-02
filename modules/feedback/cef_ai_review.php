<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/ai_config.php';
require_once __DIR__ . '/../../includes/AI/CEFAIAnalysisManager.php';
requireLogin();
$canReview = hasPermission('lph.feedback.review') || in_array(currentRole(), [ROLE_ADMIN, ROLE_STAFF], true);

$pageTitle='Citizen Portal AI Review';
$activeMenu='ai_sentiment';
$pdo=db();

$id=(int)($_GET['id']??0);
if($id<=0){
    http_response_code(404);
    exit('Invalid Citizen Portal submission id.');
}

$stmt=$pdo->prepare(
    "SELECT
        s.*,
        c.name category_name,
        cf.feedback_kind,
        cf.service_area,
        cf.desired_outcome,
        cf.citizen_rating,
        cc.affected_service,
        cc.incident_datetime,
        cc.urgency_level citizen_reported_urgency
     FROM cef_submissions s
     LEFT JOIN cef_categories c ON c.id=s.category_id
     LEFT JOIN cef_feedback_submissions cf ON cf.submission_id=s.id
     LEFT JOIN cef_complaints cc ON cc.submission_id=s.id
     WHERE s.id=:id
       AND s.deleted_at IS NULL
       AND s.submission_type IN ('Feedback','Complaint')
     LIMIT 1"
);
$stmt->execute([':id'=>$id]);
$submission=$stmt->fetch();

if(!$submission){
    http_response_code(404);
    exit('Citizen Portal feedback/complaint was not found.');
}

$analysis=CEFAIAnalysisManager::getAnalysis($id);
$history=CEFAIAnalysisManager::getHistory($id);

$linkedIssueStmt = $pdo->prepare("SELECT id, reference_number, status, title FROM hearing_issues WHERE cef_submission_id = :id LIMIT 1");
$linkedIssueStmt->execute([':id' => $id]);
$linkedIssue = $linkedIssueStmt->fetch();

$hearingsList = $pdo->query("SELECT id, reference_number, title, hearing_date FROM hearings WHERE status <> 'Cancelled' ORDER BY hearing_date DESC LIMIT 50")->fetchAll();
$categoriesList = $pdo->query("SELECT id, name FROM hearing_issue_categories ORDER BY name")->fetchAll();
$officesList = $pdo->query("SELECT id, name FROM offices WHERE status = 'Active' ORDER BY name")->fetchAll();

include __DIR__ . '/../../layouts/header.php';
?>
<div class="app-wrapper">
<?php include __DIR__ . '/../../layouts/sidebar.php'; ?>
<div class="main-content">
<?php include __DIR__ . '/../../layouts/top_controls.php'; ?>

<div class="container-fluid py-3">
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
<div>
<div class="text-uppercase small text-muted fw-semibold">Citizen Portal / CEPFMS</div>
<h3 class="mb-1"><?= e($submission['reference_number']) ?> · <?= e($submission['title']) ?></h3>
<div class="text-muted"><?= e($submission['submission_type']) ?> · <?= e($submission['category_name']?:'Uncategorized') ?> · <?= e($submission['source_channel']) ?> · <i class="bi bi-clock text-primary"></i> Submitted: <?= formatDateTime($submission['created_at']) ?></div>
</div>
<div class="d-flex align-items-center gap-2 flex-wrap">
  <?php if($linkedIssue): ?>
    <a class="btn btn-outline-success fw-semibold" href="<?= e(APP_URL.'/modules/issues/view.php?id='.(int)$linkedIssue['id']) ?>">
      <i class="bi bi-link-45deg me-1"></i> Issue: <?= e($linkedIssue['reference_number']) ?>
      <span class="badge text-bg-success ms-1"><?= e($linkedIssue['status']) ?></span>
    </a>
  <?php elseif($canReview): ?>
    <button class="btn btn-primary fw-semibold shadow-sm" data-bs-toggle="modal" data-bs-target="#endorseIssueModal">
      <i class="bi bi-arrow-right-circle me-1"></i> Endorse as Hearing Issue
    </button>
  <?php endif; ?>
  <a class="btn btn-outline-secondary" href="index.php"><i class="bi bi-arrow-left"></i> Back to Feedback</a>
</div>
</div>

<?php if($linkedIssue): ?>
<div class="alert alert-success d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3 shadow-sm border-success bg-success-subtle">
  <div class="d-flex align-items-center gap-2">
    <i class="bi bi-check-circle-fill fs-5 text-success"></i>
    <div>
      <strong>Formally Escalated:</strong> This citizen submission has been officially endorsed as <strong>Hearing Issue <?= e($linkedIssue['reference_number']) ?></strong>.
      <span class="text-muted ms-1">(Status: <span class="badge bg-success"><?= e($linkedIssue['status']) ?></span>)</span>
    </div>
  </div>
  <a class="btn btn-sm btn-success fw-semibold" href="<?= e(APP_URL.'/modules/issues/view.php?id='.(int)$linkedIssue['id']) ?>">
    <i class="bi bi-eye me-1"></i> View Issue in Tracker
  </a>
</div>
<?php endif; ?>

<div class="row g-3">
<div class="col-xl-7">
<div class="card mb-3">
<div class="card-header"><i class="bi bi-person-lines-fill"></i> Citizen Submission</div>
<div class="card-body">
<div class="row g-3 mb-3">
<div class="col-md-4"><small class="text-muted d-block">Citizen</small><strong><?= e($submission['citizen_name']?:'Anonymous') ?></strong></div>
<div class="col-md-3"><small class="text-muted d-block">Submitted At</small><strong><i class="bi bi-clock me-1 text-primary"></i><?= formatDateTime($submission['created_at']) ?></strong></div>
<div class="col-md-2"><small class="text-muted d-block">Portal Priority</small><strong><?= e($submission['priority_level']) ?></strong></div>
<div class="col-md-3"><small class="text-muted d-block">Status</small><strong><?= e($submission['status']) ?></strong></div>
</div>
<?php if(!empty($submission['summary'])): ?>
<div class="mb-3"><small class="text-muted d-block">Citizen Summary</small><?= nl2br(e($submission['summary'])) ?></div>
<?php endif; ?>
<div class="mb-3"><small class="text-muted d-block">Details / Message</small><div class="border rounded bg-light p-3"><?= nl2br(e($submission['details'])) ?></div></div>

<?php if($submission['submission_type']==='Complaint'): ?>
<div class="row g-3">
<div class="col-md-4"><small class="text-muted d-block">Citizen-selected Urgency</small><strong><?= e($submission['citizen_reported_urgency']?:'Normal') ?></strong></div>
<div class="col-md-4"><small class="text-muted d-block">Affected Service</small><strong><?= e($submission['affected_service']?:'-') ?></strong></div>
<div class="col-md-4"><small class="text-muted d-block">Incident</small><strong><?= e($submission['incident_datetime']?:'-') ?></strong></div>
</div>
<?php else: ?>
<div class="row g-3">
<div class="col-md-4"><small class="text-muted d-block">Feedback Kind</small><strong><?= e($submission['feedback_kind']?:'General Feedback') ?></strong></div>
<div class="col-md-4"><small class="text-muted d-block">Service Area</small><strong><?= e($submission['service_area']?:'-') ?></strong></div>
<div class="col-md-4"><small class="text-muted d-block">Citizen Rating</small><strong><?= e((string)($submission['citizen_rating']?:'-')) ?></strong></div>
</div>
<?php endif; ?>
</div>
</div>
</div>

<div class="col-xl-5">
<div class="card mb-3">
<div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
<span><i class="bi bi-robot"></i> Ollama Sentiment &amp; Urgency</span>
<?php if($canReview): ?>
<button class="btn btn-sm btn-primary" id="btnAnalyzeCef"><i class="bi bi-stars"></i> <?= ($analysis&&($analysis['status']??'')==='completed')?'Re-analyze':'Analyze' ?></button>
<?php else: ?>
<span class="badge bg-secondary-subtle text-secondary border px-2 py-1"><i class="bi bi-eye me-1"></i> Read-Only View</span>
<?php endif; ?>
</div>
<div class="card-body" id="cefAiResult">
<?php if(!$analysis): ?>
<div class="text-muted">Not analyzed yet.</div>
<?php elseif(($analysis['status']??'')==='failed'): ?>
<div class="alert alert-warning mb-0"><?= e($analysis['error_message']?:'AI analysis failed.') ?></div>
<?php elseif(($analysis['status']??'')!=='completed'): ?>
<div class="text-muted">AI status: <?= e($analysis['status']??'pending') ?></div>
<?php else: ?>
<?php
$sClass=$analysis['sentiment']==='Negative'?'danger':($analysis['sentiment']==='Positive'?'success':'warning');
$uClass=in_array($analysis['urgency_level'],['High','Critical'],true)?'danger':($analysis['urgency_level']==='Medium'?'warning':'secondary');
?>
<div class="d-flex gap-2 flex-wrap mb-3">
<span class="badge text-bg-<?= e($sClass) ?>">Sentiment: <?= e($analysis['sentiment']) ?></span>
<span class="badge text-bg-<?= e($uClass) ?>">Urgency: <?= e($analysis['urgency_level']) ?> <?= $analysis['urgency_score']!==null?'('.(int)round((float)$analysis['urgency_score']).'%)':'' ?></span>
<span class="badge text-bg-light border">Confidence: <?= (int)round((float)$analysis['confidence_score']) ?>%</span>
<?php if((int)$analysis['flagged_for_review']===1): ?><span class="badge text-bg-danger"><i class="bi bi-flag-fill"></i> Staff review</span><?php endif; ?>
</div>
<p><strong>Summary:</strong><br><?= e($analysis['summary']?:'-') ?></p>
<p><strong>Keywords:</strong><br><?= e(implode(', ',$analysis['keywords']??[])) ?></p>
<p><strong>Risk / Urgency Keywords:</strong><br><?= e(implode(', ',$analysis['risk_keywords']??[])) ?></p>
<p><strong>Recommended Category:</strong><br><?= e($analysis['recommended_category']?:'-') ?></p>
<?php if(!empty($analysis['suggested_response'])): ?>
<p class="mb-0"><strong>Suggested Staff Response:</strong></p>
<div class="border rounded p-2 bg-light small"><?= nl2br(e($analysis['suggested_response'])) ?></div>
<?php endif; ?>
<?php endif; ?>
</div>
</div>

<?php if($canReview && $analysis && ($analysis['status']??'')==='completed'): ?>
<div class="card mb-3 border shadow-sm">
<div class="card-header bg-light"><i class="bi bi-shield-check text-primary me-1"></i> Admin AI Verification &amp; Accuracy Check</div>
<div class="card-body">
<div class="small text-muted mb-2">Review whether the AI Ollama analysis of the citizen report is accurate. You may verify or adjust the sentiment and urgency ratings.</div>

<?php if(!empty($analysis['reviewed_by_name']) || (!empty($analysis['review_status']) && $analysis['review_status'] !== 'Pending')): ?>
<div class="alert alert-info py-2 px-3 mb-3 small d-flex align-items-center justify-content-between flex-wrap gap-2">
  <div>
    <i class="bi bi-person-check-fill text-primary me-1"></i>
    <strong>Verified by:</strong> <span class="fw-semibold"><?= e($analysis['reviewed_by_name'] ?: 'Staff / Admin') ?></span>
    <?php if(!empty($analysis['reviewed_at'])): ?>
      <span class="text-muted ms-1">· <i class="bi bi-clock me-1"></i><?= formatDateTime($analysis['reviewed_at']) ?></span>
    <?php endif; ?>
  </div>
  <span class="badge bg-primary"><?= e($analysis['review_status'] ?? 'Verified') ?></span>
</div>
<?php endif; ?>

<div class="mb-2">
<label class="form-label small fw-semibold text-secondary mb-1">Admin Decision</label>
<select class="form-select form-select-sm" id="cefReviewStatus">
  <option value="Accepted" <?= ($analysis['review_status']??'Pending')==='Accepted'?'selected':'' ?>>Verified Accurate (AI is correct)</option>
  <option value="Corrected" <?= ($analysis['review_status']??'Pending')==='Corrected'?'selected':'' ?>>Adjust / Correct AI Assessment</option>
  <option value="Manual Review" <?= ($analysis['review_status']??'Pending')==='Manual Review'?'selected':'' ?>>Needs Manual Review</option>
  <option value="Dismissed" <?= ($analysis['review_status']??'Pending')==='Dismissed'?'selected':'' ?>>Dismiss / Inaccurate Analysis</option>
  <option value="Pending" <?= ($analysis['review_status']??'Pending')==='Pending'?'selected':'' ?>>Pending Check</option>
</select>
</div>

<div class="row g-2 mb-2">
  <div class="col-6">
    <label class="form-label small fw-semibold text-secondary mb-1">Sentiment Override</label>
    <select class="form-select form-select-sm" id="cefOverrideSentiment">
      <option value="">Keep AI Sentiment (<?= e($analysis['sentiment']??'Neutral') ?>)</option>
      <option value="Positive" <?= ($analysis['sentiment']??'')==='Positive'?'selected':'' ?>>Positive</option>
      <option value="Neutral" <?= ($analysis['sentiment']??'')==='Neutral'?'selected':'' ?>>Neutral</option>
      <option value="Negative" <?= ($analysis['sentiment']??'')==='Negative'?'selected':'' ?>>Negative</option>
    </select>
  </div>
  <div class="col-6">
    <label class="form-label small fw-semibold text-secondary mb-1">Urgency Override</label>
    <select class="form-select form-select-sm" id="cefOverrideUrgency">
      <option value="">Keep AI Urgency (<?= e($analysis['urgency_level']??'Low') ?>)</option>
      <option value="Low" <?= ($analysis['urgency_level']??'')==='Low'?'selected':'' ?>>Low</option>
      <option value="Medium" <?= ($analysis['urgency_level']??'')==='Medium'?'selected':'' ?>>Medium</option>
      <option value="High" <?= ($analysis['urgency_level']??'')==='High'?'selected':'' ?>>High</option>
      <option value="Critical" <?= ($analysis['urgency_level']??'')==='Critical'?'selected':'' ?>>Critical</option>
    </select>
  </div>
</div>

<div class="mb-2">
<label class="form-label small fw-semibold text-secondary mb-1">Admin Verification Notes</label>
<textarea class="form-control form-control-sm mb-2" id="cefReviewNotes" rows="2" placeholder="e.g. Tone was sarcastic, confirmed broken light complaint, urgency updated..."><?= e($analysis['review_notes']??'') ?></textarea>
</div>
<button class="btn btn-success fw-semibold w-100 shadow-sm" id="btnSaveCefReview"><i class="bi bi-check-lg me-1"></i> Save AI Verification</button>
</div>
</div>
<?php endif; ?>
</div>
</div>

<div class="card mt-3">
<div class="card-header"><i class="bi bi-clock-history"></i> Re-analysis History</div>
<div class="card-body">
<?php if(!$history): ?><div class="text-muted">No previous AI versions.</div><?php endif; ?>
<?php foreach($history as $h): ?>
<div class="border rounded p-2 mb-2 bg-light small">
<div class="d-flex justify-content-between gap-2 flex-wrap"><strong>Version <?= (int)$h['analysis_version'] ?></strong><span><?= e(($h['sentiment']?:'-').' · '.($h['urgency_level']?:'-').' urgency') ?></span></div>
<div class="text-muted"><?= e((string)($h['analyzed_at']?:$h['archived_at'])) ?></div>
<div class="mt-1"><?= e($h['summary']?:$h['error_message']?:'-') ?></div>
<div class="mt-2 pt-2 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <strong>Staff Review:</strong> 
    <span class="badge <?= ($h['review_status'] ?? 'Pending') === 'Accepted' ? 'bg-success' : (($h['review_status'] ?? '') === 'Corrected' ? 'bg-info text-dark' : (($h['review_status'] ?? '') === 'Dismissed' ? 'bg-danger' : 'bg-secondary')) ?>">
      <?= e($h['review_status'] ?: 'Pending') ?>
    </span>
    <?php if (!empty($h['reviewed_by_name'])): ?>
      <span class="ms-1 text-dark">
        · Verified by: <i class="bi bi-person-check-fill text-primary"></i> <strong><?= e($h['reviewed_by_name']) ?></strong>
        <?php if (!empty($h['reviewed_at'])): ?>
          <span class="text-muted">(<?= formatDateTime($h['reviewed_at']) ?>)</span>
        <?php endif; ?>
      </span>
    <?php elseif (($h['review_status'] ?? 'Pending') !== 'Pending'): ?>
      <span class="ms-1 text-muted">· Verified by: Staff</span>
    <?php endif; ?>
  </div>
  <?php if (!empty($h['archived_by_name'])): ?>
    <div class="text-muted" style="font-size: 0.8rem;">
      <i class="bi bi-arrow-repeat text-secondary"></i> Re-analyzed by: <strong><?= e($h['archived_by_name']) ?></strong>
      <?php if (!empty($h['archived_at'])): ?>
        <span>(<?= formatDateTime($h['archived_at']) ?>)</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php if (!empty($h['review_notes'])): ?>
  <div class="mt-1 text-muted ps-2 border-start border-2 border-primary">
    <em>Notes:</em> <?= e($h['review_notes']) ?>
  </div>
<?php endif; ?>
</div>
<?php endforeach; ?>
</div>
</div>

</div>
</div>
</div>

<?php if($canReview && !$linkedIssue): ?>
<!-- Endorse Citizen Feedback to Hearing Issue Modal -->
<div class="modal fade" id="endorseIssueModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 12px; overflow: hidden;">
      <form id="endorseIssueForm">
        <?= csrfField() ?>
        <input type="hidden" name="cef_submission_id" value="<?= (int)$id ?>">
        <div class="modal-header py-3 px-4 bg-light border-bottom">
          <div class="d-flex align-items-center gap-2">
            <div class="rounded-circle d-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary" style="width: 38px; height: 38px;">
              <i class="bi bi-arrow-right-circle-fill fs-5"></i>
            </div>
            <div>
              <h5 class="modal-title fw-bold text-dark mb-0">Endorse as Hearing Issue</h5>
              <small class="text-muted">Endorse this citizen report as an official Legislative Hearing Issue.</small>
            </div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4">
          <div class="alert alert-light border small mb-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
              <strong>Source Reference: <?= e($submission['reference_number']) ?></strong>
              <span class="badge text-bg-primary"><?= e($submission['submission_type']) ?></span>
            </div>
            <div class="text-muted mt-1"><?= e(mb_strimwidth((string)$submission['details'], 0, 200, '...')) ?></div>
          </div>

          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label small fw-semibold text-secondary mb-1">Issue Title *</label>
              <input type="text" class="form-control" name="title" id="endorse_title" value="<?= e($submission['title']) ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold text-secondary mb-1">Priority Level *</label>
              <?php
              $defPriority = 'Medium';
              if (!empty($analysis['urgency_level'])) {
                  $defPriority = $analysis['urgency_level'];
              } elseif (!empty($submission['priority_level'])) {
                  $defPriority = in_array($submission['priority_level'], ['Critical','High','Medium','Low'], true) ? $submission['priority_level'] : 'Medium';
              }
              ?>
              <select class="form-select" name="priority" id="endorse_priority">
                <option value="Low" <?= $defPriority === 'Low' ? 'selected' : '' ?>>Low</option>
                <option value="Medium" <?= $defPriority === 'Medium' ? 'selected' : '' ?>>Medium</option>
                <option value="High" <?= $defPriority === 'High' ? 'selected' : '' ?>>High</option>
                <option value="Critical" <?= $defPriority === 'Critical' ? 'selected' : '' ?>>Critical</option>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Link to Public Hearing</label>
              <select class="form-select" name="hearing_id">
                <option value="">— General / Not Hearing Specific —</option>
                <?php foreach($hearingsList as $h): ?>
                  <option value="<?= (int)$h['id'] ?>">
                    <?= e($h['reference_number']) ?> · <?= e(mb_strimwidth($h['title'], 0, 45, '...')) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Issue Category</label>
              <select class="form-select" name="category_id">
                <option value="">— Uncategorized —</option>
                <?php foreach($categoriesList as $cat): ?>
                  <option value="<?= (int)$cat['id'] ?>">
                    <?= e($cat['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Assigned Executive Office</label>
              <select class="form-select" name="assigned_office_id">
                <option value="">— Unassigned (Committee Handled) —</option>
                <?php foreach($officesList as $off): ?>
                  <option value="<?= (int)$off['id'] ?>">
                    <?= e($off['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Target Resolution Date</label>
              <input type="datetime-local" class="form-control" name="due_at">
            </div>

            <div class="col-12">
              <label class="form-label small fw-semibold text-secondary mb-1">Committee Endorsement Notes</label>
              <textarea class="form-control" name="notes" rows="2" placeholder="e.g. Endorsed during committee hearing due to high citizen safety concern..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer py-2.5 px-4 bg-light border-top d-flex justify-content-end gap-2">
          <button type="button" class="btn btn-light border px-3" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary px-4 fw-semibold shadow-sm" id="btnSubmitEndorse">
            <i class="bi bi-check2-circle me-1"></i> Confirm &amp; Create Issue
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded',()=>{
  const analyze=document.getElementById('btnAnalyzeCef');
  if(analyze)analyze.onclick=async()=>{
    const old=analyze.innerHTML;
    analyze.disabled=true;
    analyze.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span> Analyzing...';
    const r=await appPost(APP_URL+'/modules/feedback/ajax_analyze_cef.php',{
      id:<?= (int)$id ?>,
      csrf_token:window.APP_CSRF_TOKEN
    });
    analyze.disabled=false;
    analyze.innerHTML=old;
    if(r.success){appToast('success',r.message);setTimeout(()=>location.reload(),500);}
    else if(!r.session_expired)Swal.fire('Citizen Portal AI',r.message||'Analysis failed.','warning');
  };

  const save=document.getElementById('btnSaveCefReview');
  if(save)save.onclick=async()=>{
    save.disabled=true;
    const oldHtml=save.innerHTML;
    save.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span> Saving...';
    const r=await appPost(APP_URL+'/modules/feedback/ajax_review_cef_ai.php',{
      id:<?= (int)$id ?>,
      decision:document.getElementById('cefReviewStatus').value,
      override_sentiment:document.getElementById('cefOverrideSentiment')?document.getElementById('cefOverrideSentiment').value:'',
      override_urgency:document.getElementById('cefOverrideUrgency')?document.getElementById('cefOverrideUrgency').value:'',
      notes:document.getElementById('cefReviewNotes').value,
      csrf_token:window.APP_CSRF_TOKEN
    });
    save.disabled=false;
    save.innerHTML=oldHtml;
    if(r.success){appToast('success',r.message);setTimeout(()=>location.reload(),400);}
    else if(!r.session_expired)Swal.fire('AI Review',r.message||'Unable to save review.','warning');
  };

  const endorseForm = document.getElementById('endorseIssueForm');
  if(endorseForm){
    endorseForm.onsubmit = async(e)=>{
      e.preventDefault();
      const submitBtn = document.getElementById('btnSubmitEndorse');
      submitBtn.disabled = true;
      const oldHtml = submitBtn.innerHTML;
      submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Creating Issue...';

      const fd = new FormData(endorseForm);
      const payload = Object.fromEntries(fd.entries());

      const res = await appPost(APP_URL + '/modules/issues/ajax_from_cef.php', payload);
      submitBtn.disabled = false;
      submitBtn.innerHTML = oldHtml;

      if(res.success){
        Swal.fire({
          icon: 'success',
          title: 'Issue Created!',
          text: res.message,
          confirmButtonText: 'Open Issue in Tracker',
          showCancelButton: true,
          cancelButtonText: 'Stay on this page'
        }).then((result)=>{
          if(result.isConfirmed && res.view_url){
            window.location.href = res.view_url;
          } else {
            location.reload();
          }
        });
      } else if(!res.session_expired){
        Swal.fire('Error', res.message || 'Unable to endorse issue.', 'error');
      }
    };
  }
});
</script>
<?php include __DIR__ . '/../../layouts/footer.php'; ?>
