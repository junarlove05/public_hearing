<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pdo=db();$id=(int)($_GET['id']??0);
$s=$pdo->prepare('SELECT * FROM surveys WHERE id=:id');$s->execute([':id'=>$id]);$survey=$s->fetch();
if(!$survey){setFlash('danger','Survey not found.');redirect(APP_URL.'/modules/feedback/surveys.php');}

$q=$pdo->prepare('SELECT * FROM survey_questions WHERE survey_id=:id ORDER BY sequence_number,id');$q->execute([':id'=>$id]);$questions=$q->fetchAll();
$c=$pdo->prepare('SELECT COUNT(*) FROM survey_submissions WHERE survey_id=:id');$c->execute([':id'=>$id]);$submissions=(int)$c->fetchColumn();

$results=[];
foreach($questions as $question){
 if(in_array($question['question_type'],['Single Choice','Multiple Choice'],true)){
  $st=$pdo->prepare(
   'SELECT o.option_text,COUNT(a.id) total
    FROM survey_question_options o
    LEFT JOIN survey_answers a ON a.option_id=o.id
    WHERE o.question_id=:qid
    GROUP BY o.id,o.option_text,o.sequence_number
    ORDER BY o.sequence_number,o.id'
  );$st->execute([':qid'=>$question['id']]);$results[$question['id']]=$st->fetchAll();
 }elseif(in_array($question['question_type'],['Rating','Number'],true)){
  $st=$pdo->prepare('SELECT COUNT(*) total,AVG(numeric_value) average,MIN(numeric_value) minimum,MAX(numeric_value) maximum FROM survey_answers WHERE question_id=:qid AND numeric_value IS NOT NULL');
  $st->execute([':qid'=>$question['id']]);$results[$question['id']]=$st->fetch();
 }else{
  $st=$pdo->prepare('SELECT answer_text FROM survey_answers WHERE question_id=:qid AND answer_text IS NOT NULL ORDER BY id DESC LIMIT 20');
  $st->execute([':qid'=>$question['id']]);$results[$question['id']]=$st->fetchAll();
 }
}

$pageTitle='Survey Results';$activeMenu='feedback';
include __DIR__.'/../../layouts/header.php';
?>
<link rel="stylesheet" href="<?= e(APP_URL.'/assets/css/lph-complete-modules.css') ?>">
<div class="app-wrapper"><?php include __DIR__.'/../../layouts/sidebar.php'; ?><div class="main-content">
<div class="lphx-head"><div><div class="lphx-eyebrow">Survey Analytics</div><h1><?= e($survey['title']) ?></h1><p><?= $submissions ?> submitted response(s).</p></div><a class="btn btn-outline-secondary" href="surveys.php"><i class="bi bi-arrow-left"></i> Surveys</a></div>
<?php foreach($questions as $n=>$question): ?><div class="card lphx-card mb-3"><div class="card-header"><?= ($n+1) ?>. <?= e($question['question_text']) ?></div><div class="card-body">
<?php if(in_array($question['question_type'],['Single Choice','Multiple Choice'],true)): ?><table class="table table-sm"><thead><tr><th>Option</th><th>Responses</th></tr></thead><tbody><?php foreach($results[$question['id']] as $r): ?><tr><td><?= e($r['option_text']) ?></td><td><?= (int)$r['total'] ?></td></tr><?php endforeach; ?></tbody></table>
<?php elseif(in_array($question['question_type'],['Rating','Number'],true)): ?><?php $r=$results[$question['id']]; ?><div class="row text-center"><div class="col"><strong><?= (int)$r['total'] ?></strong><div class="small text-muted">Answers</div></div><div class="col"><strong><?= $r['average']!==null?round((float)$r['average'],2):'—' ?></strong><div class="small text-muted">Average</div></div><div class="col"><strong><?= e($r['minimum']??'—') ?></strong><div class="small text-muted">Min</div></div><div class="col"><strong><?= e($r['maximum']??'—') ?></strong><div class="small text-muted">Max</div></div></div>
<?php else: ?><?php if(!$results[$question['id']]): ?><div class="text-muted">No answers yet.</div><?php endif; ?><?php foreach($results[$question['id']] as $r): ?><div class="p-2 border-bottom"><?= e($r['answer_text']) ?></div><?php endforeach; ?><?php endif; ?>
</div></div><?php endforeach; ?>
</div></div>
<?php include __DIR__.'/../../layouts/footer.php'; ?>
