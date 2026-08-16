<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';

requireLogin();
if (!canManage()) jsonResponse(false,'You do not have permission to manage surveys.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false,'Invalid request method.');
requireCsrf();

$pdo=db();

$id=(int)($_POST['id']??0);
$title=clean($_POST['title']??'');
$description=trim((string)($_POST['description']??''));
$hearingId=(int)($_POST['hearing_id']??0)?:null;
$legislativeItemId=(int)($_POST['legislative_item_id']??0)?:null;
$status=clean($_POST['status']??'Draft');
$opensAt=clean($_POST['opens_at']??'')?:null;
$closesAt=clean($_POST['closes_at']??'')?:null;

$questionTexts=$_POST['question_text']??[];
$questionTypes=$_POST['question_type']??[];
$questionRequired=$_POST['question_required']??[];
$questionOptions=$_POST['question_options']??[];

$allowedStatus=['Draft','Active','Closed','Archived'];
$allowedTypes=['Text','Long Text','Single Choice','Multiple Choice','Rating','Number'];

$errors=[];
if($title==='')$errors[]='Survey title is required.';
if(!in_array($status,$allowedStatus,true))$errors[]='Invalid survey status.';
if($opensAt && $closesAt && strtotime($closesAt)<=strtotime($opensAt))$errors[]='Survey closing time must be after opening time.';

if(!is_array($questionTexts))$questionTexts=[];
if($id===0 && !$questionTexts)$errors[]='Add at least one survey question.';

foreach($questionTexts as $i=>$text){
 $text=trim((string)$text);
 if($text==='')continue;
 $type=$questionTypes[$i]??'Text';
 if(!in_array($type,$allowedTypes,true))$errors[]='Invalid question type.';
 if(in_array($type,['Single Choice','Multiple Choice'],true)){
   $opts=array_values(array_filter(array_map('trim',preg_split('/\r\n|\r|\n/',(string)($questionOptions[$i]??'')))));
   if(count($opts)<2)$errors[]='Choice questions need at least two options.';
 }
}

if($errors)jsonResponse(false,implode(' ',$errors));

$existing=null;$submissionCount=0;
if($id>0){
 $q=$pdo->prepare('SELECT * FROM surveys WHERE id=:id');$q->execute([':id'=>$id]);$existing=$q->fetch();
 if(!$existing)jsonResponse(false,'Survey not found.');
 $c=$pdo->prepare('SELECT COUNT(*) FROM survey_submissions WHERE survey_id=:id');$c->execute([':id'=>$id]);$submissionCount=(int)$c->fetchColumn();
}

try{
 $pdo->beginTransaction();

 if($id>0){
  $pdo->prepare(
    'UPDATE surveys SET title=:title,description=:description,hearing_id=:hearing,
     legislative_item_id=:item,status=:status,opens_at=:opens,closes_at=:closes,updated_at=NOW()
     WHERE id=:id'
  )->execute([
    ':title'=>$title,':description'=>$description?:null,':hearing'=>$hearingId,
    ':item'=>$legislativeItemId,':status'=>$status,':opens'=>$opensAt,':closes'=>$closesAt,':id'=>$id
  ]);

  if($submissionCount===0 && $questionTexts){
    $pdo->prepare('DELETE FROM survey_questions WHERE survey_id=:id')->execute([':id'=>$id]);
  }
 }else{
  $pdo->prepare(
    'INSERT INTO surveys
     (title,description,status,created_at,hearing_id,legislative_item_id,created_by,opens_at,closes_at,updated_at)
     VALUES (:title,:description,:status,NOW(),:hearing,:item,:user,:opens,:closes,NOW())'
  )->execute([
    ':title'=>$title,':description'=>$description?:null,':status'=>$status,
    ':hearing'=>$hearingId,':item'=>$legislativeItemId,':user'=>currentUserId(),
    ':opens'=>$opensAt,':closes'=>$closesAt
  ]);
  $id=(int)$pdo->lastInsertId();
 }

 if(($id>0 && $submissionCount===0) && $questionTexts){
  $seq=1;
  foreach($questionTexts as $i=>$text){
   $text=trim((string)$text);if($text==='')continue;
   $type=$questionTypes[$i]??'Text';
   $required=((string)($questionRequired[$i]??'0'))==='1'?1:0;

   $pdo->prepare(
     'INSERT INTO survey_questions
      (survey_id,question_text,question_type,is_required,sequence_number,created_at)
      VALUES (:survey,:text,:type,:required,:seq,NOW())'
   )->execute([
     ':survey'=>$id,':text'=>$text,':type'=>$type,':required'=>$required,':seq'=>$seq++
   ]);
   $qid=(int)$pdo->lastInsertId();

   if(in_array($type,['Single Choice','Multiple Choice'],true)){
    $opts=array_values(array_filter(array_map('trim',preg_split('/\r\n|\r|\n/',(string)($questionOptions[$i]??'')))));
    $oseq=1;
    foreach($opts as $opt){
     $pdo->prepare(
       'INSERT INTO survey_question_options
        (question_id,option_text,sequence_number)
        VALUES (:qid,:text,:seq)'
     )->execute([':qid'=>$qid,':text'=>$opt,':seq'=>$oseq++]);
    }
   }
  }
 }

 lphSurveyHistory($pdo,$id,$existing?'Update':'Create',
   $existing ? "Survey updated. Status {$existing['status']} -> {$status}." : 'Survey created.');
 logActivity(currentUserId(),$existing?'Update Survey':'Create Survey',"Survey #{$id} - {$title}");

 $pdo->commit();

 $message=$submissionCount>0
   ? 'Survey metadata updated. Questions were preserved because responses already exist.'
   : 'Survey saved successfully.';

 jsonResponse(true,$message,['id'=>$id]);
}catch(Throwable $e){
 if($pdo->inTransaction())$pdo->rollBack();
 error_log('Survey save error: '.$e->getMessage());
 jsonResponse(false,APP_DEBUG?$e->getMessage():'Unable to save survey.');
}
