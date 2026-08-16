<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
if (!canManage()) jsonResponse(false,'You do not have permission to convert feedback into issues.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false,'Invalid request method.');
requireCsrf();

$pdo=db();
$feedbackId=(int)($_POST['feedback_id']??0);
$categoryId=(int)($_POST['category_id']??0)?:null;
$priority=clean($_POST['priority']??'Medium');
$title=clean($_POST['title']??'');
$dueAt=clean($_POST['due_at']??'')?:null;
$assignedOfficeId=(int)($_POST['assigned_office_id']??0)?:null;

if(!in_array($priority,['Low','Medium','High','Critical'],true))jsonResponse(false,'Invalid priority.');
if($title==='')jsonResponse(false,'Issue title is required.');

$f=$pdo->prepare(
 'SELECT f.*,h.legislative_item_id hearing_legislative_item
  FROM feedback f
  LEFT JOIN hearings h ON h.id=f.hearing_id
  WHERE f.id=:id'
);
$f->execute([':id'=>$feedbackId]);
$feedback=$f->fetch();
if(!$feedback)jsonResponse(false,'Feedback not found.');

$dup=$pdo->prepare('SELECT id,reference_number FROM hearing_issues WHERE feedback_id=:id LIMIT 1');
$dup->execute([':id'=>$feedbackId]);
if($existing=$dup->fetch()){
 jsonResponse(false,'This feedback was already converted to issue '.$existing['reference_number'].'.');
}

if($categoryId){
 $q=$pdo->prepare('SELECT COUNT(*) FROM hearing_issue_categories WHERE id=:id');
 $q->execute([':id'=>$categoryId]);
 if((int)$q->fetchColumn()===0)jsonResponse(false,'Invalid issue category.');
}

try{
 $pdo->beginTransaction();

 $reference='ISS-'.date('Y').'-'.strtoupper(substr(bin2hex(random_bytes(6)),0,10));
 $description=
   "Source: Public Feedback #{$feedbackId}\n".
   ($feedback['subject'] ? "Feedback Subject: {$feedback['subject']}\n" : '').
   "Submitted By: ".($feedback['is_anonymous'] ? 'Anonymous' : $feedback['name'])."\n\n".
   $feedback['message'];

 $legislativeItemId=$feedback['legislative_item_id'] ?: $feedback['hearing_legislative_item'];

 $pdo->prepare(
  'INSERT INTO hearing_issues
   (hearing_id,legislative_item_id,feedback_id,category_id,reference_number,title,
    description,priority,status,assigned_office_id,assigned_user_id,due_at,
    closed_at,created_by,created_at,updated_at)
   VALUES
   (:hearing,:item,:feedback,:category,:ref,:title,:description,:priority,
    "Open",:office,NULL,:due,NULL,:user,NOW(),NOW())'
 )->execute([
  ':hearing'=>$feedback['hearing_id']?:null,
  ':item'=>$legislativeItemId?:null,
  ':feedback'=>$feedbackId,
  ':category'=>$categoryId,
  ':ref'=>$reference,
  ':title'=>$title,
  ':description'=>$description,
  ':priority'=>$priority,
  ':office'=>$assignedOfficeId,
  ':due'=>$dueAt,
  ':user'=>currentUserId(),
 ]);

 $issueId=(int)$pdo->lastInsertId();

 $pdo->prepare(
  'INSERT INTO hearing_issue_history(issue_id,note,created_by,created_at)
   VALUES(:id,:note,:user,NOW())'
 )->execute([
  ':id'=>$issueId,
  ':note'=>"Issue created from Public Feedback #{$feedbackId}.",
  ':user'=>currentUserId()
 ]);

 if($assignedOfficeId){
   $pdo->prepare(
    'INSERT INTO hearing_issue_assignments
     (issue_id,assigned_office_id,assigned_user_id,assigned_by,remarks,assigned_at)
     VALUES(:issue,:office,NULL,:user,"Initial assignment from feedback handoff.",NOW())'
   )->execute([':issue'=>$issueId,':office'=>$assignedOfficeId,':user'=>currentUserId()]);
 }

 $pdo->prepare(
  "UPDATE feedback
   SET status=CASE WHEN status='New' THEN 'Under Review' ELSE status END,
       updated_at=NOW()
   WHERE id=:id"
 )->execute([':id'=>$feedbackId]);

 logActivity(currentUserId(),'Feedback to Issue',
   "Converted feedback #{$feedbackId} to {$reference}.");

 $pdo->commit();

 jsonResponse(true,'Feedback converted to an issue successfully.',[
   'issue_id'=>$issueId,'reference_number'=>$reference
 ]);
}catch(Throwable $e){
 if($pdo->inTransaction())$pdo->rollBack();
 error_log('Feedback issue handoff error: '.$e->getMessage());
 jsonResponse(false,APP_DEBUG?$e->getMessage():'Unable to create issue from feedback.');
}
