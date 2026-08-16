<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';

requireLogin();

$pdo=db();$pageTitle='Consultation Surveys';$activeMenu='feedback';
$canManageSurveys=hasPermission('lph.surveys.manage');

$hearings=$pdo->query("SELECT id,reference_number,title FROM hearings ORDER BY hearing_date DESC LIMIT 200")->fetchAll();
$items=$pdo->query("SELECT id,reference_number,title FROM legislative_items WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 300")->fetchAll();

$surveys=$pdo->query(
 "SELECT s.*,h.reference_number hearing_reference,h.title hearing_title,
  li.reference_number legislative_reference,
  (SELECT COUNT(*) FROM survey_questions q WHERE q.survey_id=s.id) question_count,
  (SELECT COUNT(*) FROM survey_submissions ss WHERE ss.survey_id=s.id) submission_count
  FROM surveys s
  LEFT JOIN hearings h ON h.id=s.hearing_id
  LEFT JOIN legislative_items li ON li.id=s.legislative_item_id
  ORDER BY s.created_at DESC,s.id DESC"
)->fetchAll();

include __DIR__.'/../../layouts/header.php';
?>
<link rel="stylesheet" href="<?= e(APP_URL.'/assets/css/lph-complete-modules.css') ?>">
<div class="app-wrapper"><?php include __DIR__.'/../../layouts/sidebar.php'; ?><div class="main-content">
<div class="lphx-head"><div><div class="lphx-eyebrow"><i class="bi bi-ui-checks-grid"></i> Step 5 · Surveys</div><h1>Consultation Surveys</h1><p>Create structured questionnaires linked to hearings or legislative items and collect normalized responses.</p></div><div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="index.php"><i class="bi bi-arrow-left"></i> Feedback</a><?php if($canManageSurveys): ?><button class="btn btn-primary" id="btnNewSurvey"><i class="bi bi-plus-circle"></i> New Survey</button><?php endif; ?></div></div>

<div class="card lphx-card"><div class="card-header"><i class="bi bi-card-checklist"></i> Survey Register</div><div class="table-responsive">
<table class="table table-hover lphx-table mb-0"><thead><tr><th>Survey</th><th>Context</th><th>Window</th><th>Status</th><th>Questions</th><th>Responses</th><th class="text-end">Actions</th></tr></thead><tbody>
<?php if(!$surveys): ?><tr><td colspan="7"><div class="lphx-empty"><i class="bi bi-ui-checks-grid"></i>No surveys yet.</div></td></tr><?php endif; ?>
<?php foreach($surveys as $s): ?><tr>
<td><strong><?= e($s['title']) ?></strong><div class="small text-muted"><?= e(mb_strimwidth($s['description']?:'',0,100,'…')) ?></div></td>
<td><?= e($s['hearing_reference']?:$s['legislative_reference']?:'General consultation') ?></td>
<td><div class="small"><?= $s['opens_at']?formatDateTime($s['opens_at']):'Open immediately' ?></div><div class="small text-muted"><?= $s['closes_at']?'Closes '.formatDateTime($s['closes_at']):'No closing date' ?></div></td>
<td><span class="badge text-bg-light"><?= e($s['status']) ?></span></td>
<td><?= (int)$s['question_count'] ?></td>
<td><?= (int)$s['submission_count'] ?></td>
<td class="text-end"><div class="btn-group btn-group-sm"><a class="btn btn-outline-primary" href="survey_take.php?id=<?= (int)$s['id'] ?>"><i class="bi bi-pencil-square"></i></a><a class="btn btn-outline-secondary" href="survey_results.php?id=<?= (int)$s['id'] ?>"><i class="bi bi-bar-chart"></i></a></div></td>
</tr><?php endforeach; ?>
</tbody></table></div></div>
</div></div>

<?php if($canManageSurveys): ?>
<div class="modal fade" id="surveyModal" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
<form id="surveyForm"><?= csrfField() ?><input type="hidden" name="id" value="0">
<div class="modal-header bg-dark text-white"><h5 class="modal-title">Create Survey</h5><button class="btn-close btn-close-white" type="button" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<div class="row g-3 mb-3">
<div class="col-md-8"><label class="form-label">Title *</label><input class="form-control" name="title" required></div>
<div class="col-md-4"><label class="form-label">Status</label><select class="form-select" name="status"><option>Draft</option><option>Active</option><option>Closed</option><option>Archived</option></select></div>
<div class="col-md-6"><label class="form-label">Hearing</label><select class="form-select" name="hearing_id"><option value="">None</option><?php foreach($hearings as $h): ?><option value="<?= (int)$h['id'] ?>"><?= e(($h['reference_number']?:'').' '.$h['title']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-6"><label class="form-label">Legislative Item</label><select class="form-select" name="legislative_item_id"><option value="">None</option><?php foreach($items as $i): ?><option value="<?= (int)$i['id'] ?>"><?= e($i['reference_number'].' - '.$i['title']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-3"><label class="form-label">Opens At</label><input type="datetime-local" class="form-control" name="opens_at"></div>
<div class="col-md-3"><label class="form-label">Closes At</label><input type="datetime-local" class="form-control" name="closes_at"></div>
<div class="col-md-6"><label class="form-label">Description</label><input class="form-control" name="description"></div>
</div>
<div class="d-flex justify-content-between align-items-center mb-2"><strong>Questions</strong><button type="button" class="btn btn-sm btn-outline-primary" id="btnAddQuestion"><i class="bi bi-plus"></i> Add Question</button></div>
<div id="questionBuilder"></div>
</div>
<div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save Survey</button></div>
</form></div></div></div>
<script>
document.addEventListener('DOMContentLoaded',function(){
 const modal=new bootstrap.Modal(document.getElementById('surveyModal'));const builder=document.getElementById('questionBuilder');
 function addQuestion(){const d=document.createElement('div');d.className='lphx-survey-question';d.innerHTML=`<div class="row g-2"><div class="col-lg-6"><label class="form-label">Question *</label><input class="form-control" name="question_text[]" required></div><div class="col-lg-3"><label class="form-label">Type</label><select class="form-select qtype" name="question_type[]"><option>Text</option><option>Long Text</option><option>Single Choice</option><option>Multiple Choice</option><option>Rating</option><option>Number</option></select></div><div class="col-lg-2"><label class="form-label">Required</label><select class="form-select" name="question_required[]"><option value="0">No</option><option value="1">Yes</option></select></div><div class="col-lg-1 d-flex align-items-end"><button type="button" class="btn btn-outline-danger w-100 remove-q"><i class="bi bi-trash"></i></button></div><div class="col-12 qoptions" hidden><label class="form-label">Options — one per line</label><textarea class="form-control" name="question_options[]" rows="3"></textarea></div><input type="hidden" name="question_options[]" value="" class="fallback-options"></div>`;
 const type=d.querySelector('.qtype'),opts=d.querySelector('.qoptions'),fallback=d.querySelector('.fallback-options');function sync(){const choice=['Single Choice','Multiple Choice'].includes(type.value);opts.hidden=!choice;fallback.disabled=choice;opts.querySelector('textarea').disabled=!choice;}type.onchange=sync;sync();d.querySelector('.remove-q').onclick=()=>d.remove();builder.appendChild(d);}
 btnAddQuestion.onclick=addQuestion;btnNewSurvey.onclick=()=>{surveyForm.reset();builder.innerHTML='';addQuestion();modal.show();};
 surveyForm.onsubmit=async e=>{e.preventDefault();const r=await appPostForm(APP_URL+'/modules/feedback/ajax_survey_save.php',surveyForm);if(r.success){appToast('success',r.message);setTimeout(()=>location.reload(),400);}else if(!r.session_expired)Swal.fire('Survey Error',r.message,'error');};
});
</script>
<?php endif; ?>
<?php include __DIR__.'/../../layouts/footer.php'; ?>
