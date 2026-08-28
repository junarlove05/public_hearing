<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';

requireLogin();

$pdo=db();
$pageTitle='Public Feedback';
$activeMenu='feedback';
$canReview=hasPermission('lph.feedback.review');

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

$feedback=$pdo->query(
 "SELECT f.*,fc.name category_name,h.title hearing_title,h.reference_number hearing_reference,
         li.reference_number legislative_reference,li.title legislative_title,
         vu.full_name validated_by_name,ru.full_name replied_by_name
  FROM feedback f
  LEFT JOIN feedback_categories fc ON fc.id=f.category_id
  LEFT JOIN hearings h ON h.id=f.hearing_id
  LEFT JOIN legislative_items li ON li.id=f.legislative_item_id
  LEFT JOIN users vu ON vu.id=f.validated_by
  LEFT JOIN users ru ON ru.id=f.replied_by
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
<div class="d-flex gap-2 flex-wrap"><a class="btn btn-outline-secondary" href="surveys.php"><i class="bi bi-ui-checks-grid"></i> Surveys</a><button class="btn btn-primary" id="btnNewFeedback"><i class="bi bi-chat-left-text"></i> Submit Feedback</button></div>
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

<div class="card lphx-card">
<div class="card-header"><i class="bi bi-inboxes"></i> Feedback Register</div>
<div class="table-responsive">
<table class="table table-hover lphx-table mb-0">
<thead><tr><th>Submitted</th><th>Citizen / Position</th><th>Subject / Message</th><th>Context</th><th>Status</th><?php if($canReview): ?><th class="text-end">Review</th><?php endif; ?></tr></thead>
<tbody>
<?php if(!$feedback): ?><tr><td colspan="<?= $canReview?6:5 ?>"><div class="lphx-empty"><i class="bi bi-chat-square-text"></i>No feedback submitted yet.</div></td></tr><?php endif; ?>
<?php foreach($feedback as $f): ?>
<tr>
<td><?= formatDateTime($f['submitted_at']) ?></td>
<td><strong><?= $f['is_anonymous']?'Anonymous':e($f['name']) ?></strong><div class="small text-muted"><?= e($f['feedback_position']?:'Comment') ?> · <?= e($f['category_name']?:'Uncategorized') ?></div></td>
<td><strong><?= e($f['subject']?:'(No subject)') ?></strong><div class="lphx-feedback-message"><?= e(mb_strimwidth($f['message'],0,180,'…')) ?></div><?php if(!empty($f['reply_text'])): ?><div class="small text-success mt-1"><i class="bi bi-reply"></i> Official response recorded</div><?php endif; ?></td>
<td><?php if($f['hearing_title']): ?><div><i class="bi bi-calendar-event"></i> <?= e($f['hearing_reference'].' '.$f['hearing_title']) ?></div><?php endif; ?><?php if($f['legislative_reference']): ?><div class="small text-muted"><i class="bi bi-file-text"></i> <?= e($f['legislative_reference']) ?></div><?php endif; ?></td>
<td><span class="badge text-bg-light"><?= e($f['status']) ?></span><div class="small text-muted"><?= e($f['visibility']) ?></div></td>
<?php if($canReview): ?><td class="text-end"><button class="btn btn-sm btn-outline-primary btn-review-feedback" data-row='<?= e(json_encode($f,JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'><i class="bi bi-search"></i> Review</button></td><?php endif; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
</div></div>

<div class="modal fade" id="feedbackModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
<form id="feedbackForm"><?= csrfField() ?>
<div class="modal-header bg-dark text-white"><h5 class="modal-title">Submit Public Feedback</h5><button class="btn-close btn-close-white" type="button" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><div class="row g-3">
<div class="col-md-6"><label class="form-label">Name *</label><input class="form-control" name="name" required></div>
<div class="col-md-6"><label class="form-label">Email *</label><input type="email" class="form-control" name="email" required></div>
<div class="col-md-6"><label class="form-label">Related Hearing</label><select class="form-select" name="hearing_id"><option value="">None</option><?php foreach($hearings as $h): ?><option value="<?= (int)$h['id'] ?>"><?= e(($h['reference_number']?:'').' '.$h['title']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-6"><label class="form-label">Related Legislative Item</label><select class="form-select" name="legislative_item_id"><option value="">None</option><?php foreach($items as $i): ?><option value="<?= (int)$i['id'] ?>"><?= e('['.$i['type_name'].'] '.$i['reference_number'].' - '.$i['title']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><label class="form-label">Category</label><select class="form-select" name="category_id"><option value="">Uncategorized</option><?php foreach($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><label class="form-label">Position</label><select class="form-select" name="feedback_position"><option>Comment</option><option>Support</option><option>Oppose</option><option>Neutral</option></select></div>
<?php if($canReview): ?><div class="col-md-4"><label class="form-label">Visibility</label><select class="form-select" name="visibility"><option>Internal</option><option>Public</option><option>Restricted</option></select></div><?php endif; ?>
<div class="col-12"><label class="form-label">Subject</label><input class="form-control" name="subject"></div>
<div class="col-12"><label class="form-label">Message *</label><textarea class="form-control" name="message" rows="5" required></textarea></div>
<div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_anonymous" id="anon"><label class="form-check-label" for="anon">Display this feedback as anonymous after moderation</label></div></div>
</div></div>
<div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Submit Feedback</button></div>
</form></div></div></div>

<?php if($canReview): ?>
<div class="modal fade" id="reviewModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
<form id="reviewForm"><?= csrfField() ?><input type="hidden" name="id" id="rv_id">
<div class="modal-header bg-dark text-white"><h5 class="modal-title">Review Feedback</h5><button class="btn-close btn-close-white" type="button" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<div id="rv_message" class="lphx-feedback-message p-3 bg-light rounded mb-3"></div>
<div class="row g-3">
<div class="col-md-6"><label class="form-label">Status</label><select class="form-select" name="status" id="rv_status"><option>New</option><option>Under Review</option><option>Validated</option><option>Responded</option><option>Rejected</option><option>Archived</option></select></div>
<div class="col-md-6"><label class="form-label">Visibility</label><select class="form-select" name="visibility" id="rv_visibility"><option>Internal</option><option>Public</option><option>Restricted</option></select></div>
<div class="col-12"><label class="form-label">Official Response</label><textarea class="form-control" name="reply_text" id="rv_reply" rows="5" placeholder="Record the official response or resolution."></textarea></div>
</div>
</div>
<div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save Review</button></div>
</form></div></div></div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded',function(){
 const fmodal=new bootstrap.Modal(document.getElementById('feedbackModal'));
 btnNewFeedback.onclick=()=>{feedbackForm.reset();fmodal.show();};
 feedbackForm.onsubmit=async e=>{e.preventDefault();const r=await appPostForm(APP_URL+'/modules/feedback/ajax_submit.php',feedbackForm);if(r.success){appToast('success',r.message);setTimeout(()=>location.reload(),400);}else if(!r.session_expired)Swal.fire('Feedback Error',r.message,'error');};
 <?php if($canReview): ?>
 const rmodal=new bootstrap.Modal(document.getElementById('reviewModal'));
 document.querySelectorAll('.btn-review-feedback').forEach(b=>b.onclick=function(){const r=JSON.parse(this.dataset.row);rv_id.value=r.id;rv_status.value=r.status||'New';rv_visibility.value=r.visibility||'Internal';rv_reply.value=r.reply_text||'';rv_message.textContent=(r.subject?'Subject: '+r.subject+'\n\n':'')+r.message;rmodal.show();});
 reviewForm.onsubmit=async e=>{e.preventDefault();const r=await appPostForm(APP_URL+'/modules/feedback/ajax_review.php',reviewForm);if(r.success){appToast('success',r.message);setTimeout(()=>location.reload(),400);}else if(!r.session_expired)Swal.fire('Review Error',r.message,'error');};
 <?php endif; ?>
});
</script>
<?php include __DIR__.'/../../layouts/footer.php'; ?>
