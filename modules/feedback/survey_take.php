<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pdo=db();$id=(int)($_GET['id']??0);
$stmt=$pdo->prepare(
 'SELECT s.*,h.title hearing_title,li.reference_number legislative_reference,li.title legislative_title
  FROM surveys s LEFT JOIN hearings h ON h.id=s.hearing_id
  LEFT JOIN legislative_items li ON li.id=s.legislative_item_id WHERE s.id=:id'
);
$stmt->execute([':id'=>$id]);$survey=$stmt->fetch();
if(!$survey){setFlash('danger','Survey not found.');redirect(APP_URL.'/modules/feedback/surveys.php');}

$q=$pdo->prepare('SELECT * FROM survey_questions WHERE survey_id=:id ORDER BY sequence_number,id');$q->execute([':id'=>$id]);$questions=$q->fetchAll();
$options=[];
foreach($questions as $question){$o=$pdo->prepare('SELECT * FROM survey_question_options WHERE question_id=:id ORDER BY sequence_number,id');$o->execute([':id'=>$question['id']]);$options[$question['id']]=$o->fetchAll();}

$pageTitle=$survey['title'];$activeMenu='feedback';
$open=$survey['status']==='Active'
 && (empty($survey['opens_at'])||time()>=strtotime($survey['opens_at']))
 && (empty($survey['closes_at'])||time()<=strtotime($survey['closes_at']));

include __DIR__.'/../../layouts/header.php';
?>
<link rel="stylesheet" href="<?= e(APP_URL.'/assets/css/lph-complete-modules.css') ?>">
<div class="app-wrapper"><?php include __DIR__.'/../../layouts/sidebar.php'; ?><div class="main-content">
<div class="lphx-head"><div><div class="lphx-eyebrow">Consultation Survey</div><h1><?= e($survey['title']) ?></h1><p><?= e($survey['description']?:'') ?></p></div><a class="btn btn-outline-secondary" href="surveys.php"><i class="bi bi-arrow-left"></i> Surveys</a></div>
<?php if(!$open): ?><div class="alert alert-warning">This survey is currently <?= e($survey['status']) ?> or outside its response window.</div><?php else: ?>
<div class="card lphx-card"><div class="card-header"><i class="bi bi-ui-checks-grid"></i> Response Form</div><div class="card-body">
<form id="takeSurveyForm"><?= csrfField() ?><input type="hidden" name="survey_id" value="<?= $id ?>">
<div class="row g-3 mb-3"><div class="col-md-6"><label class="form-label">Name</label><input class="form-control" name="respondent_name"></div><div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" name="respondent_email"></div></div>
<?php foreach($questions as $n=>$question): ?><div class="lphx-survey-question"><label class="form-label fw-semibold"><?= ($n+1) ?>. <?= e($question['question_text']) ?> <?= $question['is_required']?'<span class="text-danger">*</span>':'' ?></label>
<?php if($question['question_type']==='Long Text'): ?><textarea class="form-control" name="q[<?= (int)$question['id'] ?>]" rows="4"></textarea>
<?php elseif($question['question_type']==='Single Choice'): ?><?php foreach($options[$question['id']] as $o): ?><div class="form-check"><input class="form-check-input" type="radio" name="q[<?= (int)$question['id'] ?>]" value="<?= (int)$o['id'] ?>" id="o<?= (int)$o['id'] ?>"><label class="form-check-label" for="o<?= (int)$o['id'] ?>"><?= e($o['option_text']) ?></label></div><?php endforeach; ?>
<?php elseif($question['question_type']==='Multiple Choice'): ?><?php foreach($options[$question['id']] as $o): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="q[<?= (int)$question['id'] ?>][]" value="<?= (int)$o['id'] ?>" id="o<?= (int)$o['id'] ?>"><label class="form-check-label" for="o<?= (int)$o['id'] ?>"><?= e($o['option_text']) ?></label></div><?php endforeach; ?>
<?php elseif($question['question_type']==='Rating'): ?><input type="number" min="1" max="5" step="1" class="form-control" name="q[<?= (int)$question['id'] ?>]" placeholder="1 to 5">
<?php elseif($question['question_type']==='Number'): ?><input type="number" step="any" class="form-control" name="q[<?= (int)$question['id'] ?>]">
<?php else: ?><input class="form-control" name="q[<?= (int)$question['id'] ?>]"><?php endif; ?>
</div><?php endforeach; ?>
<button class="btn btn-primary"><i class="bi bi-send-check"></i> Submit Survey</button>
</form></div></div>
<script>
document.addEventListener('DOMContentLoaded',function(){takeSurveyForm.onsubmit=async e=>{e.preventDefault();const r=await appPostForm(APP_URL+'/modules/feedback/ajax_survey_submit.php',takeSurveyForm);if(r.success){await Swal.fire('Thank You',r.message,'success');location.href='surveys.php';}else if(!r.session_expired)Swal.fire('Survey Error',r.message,'error');};});
</script>
<?php endif; ?>
</div></div>
<?php include __DIR__.'/../../layouts/footer.php'; ?>
