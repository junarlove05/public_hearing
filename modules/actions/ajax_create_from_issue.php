<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
if(!canManage())jsonResponse(false,'You do not have permission to create actions.');
if($_SERVER['REQUEST_METHOD']!=='POST')jsonResponse(false,'Invalid request method.');
requireCsrf();

$pdo=db();
$issueId=(int)($_POST['issue_id']??0);
$title=clean($_POST['title']??'');
$description=trim((string)($_POST['description']??''));
$officeId=(int)($_POST['assigned_office_id']??0)?:null;
$userId=(int)($_POST['assigned_user_id']??0)?:null;
$deadline=clean($_POST['deadline']??'')?:null;

if($issueId<=0 || $title==='')jsonResponse(false,'Issue and action title are required.');

$i=$pdo->prepare('SELECT reference_number,title,status FROM hearing_issues WHERE id=:id');
$i->execute([':id'=>$issueId]);$issue=$i->fetch();
if(!$issue)jsonResponse(false,'Issue not found.');
if($issue['status']==='Closed')jsonResponse(false,'Closed issues cannot receive new actions.');

try{
 $pdo->beginTransaction();
 $ref='ACT-'.date('Y').'-'.strtoupper(substr(bin2hex(random_bytes(6)),0,10));
 $pdo->prepare(
  'INSERT INTO hearing_actions
   (issue_id,reference_number,title,description,assigned_office_id,assigned_user_id,
    status,deadline,completed_at,created_by,created_at,updated_at)
   VALUES(:issue,:ref,:title,:description,:office,:assignee,"Pending",:deadline,NULL,:user,NOW(),NOW())'
 )->execute([
  ':issue'=>$issueId,':ref'=>$ref,':title'=>$title,':description'=>$description?:null,
  ':office'=>$officeId,':assignee'=>$userId,':deadline'=>$deadline,':user'=>currentUserId()
 ]);
 $id=(int)$pdo->lastInsertId();

 $pdo->prepare(
  'INSERT INTO hearing_action_updates
   (action_id,previous_status,new_status,update_text,updated_by,created_at)
   VALUES(:id,NULL,"Pending",:text,:user,NOW())'
 )->execute([':id'=>$id,':text'=>"Action created from issue {$issue['reference_number']}.",':user'=>currentUserId()]);

 if($officeId||$userId){
  $pdo->prepare(
   'INSERT INTO hearing_action_assignments
    (action_id,assigned_office_id,assigned_user_id,assigned_by,remarks,assigned_at)
    VALUES(:id,:office,:assignee,:user,"Initial assignment.",NOW())'
  )->execute([':id'=>$id,':office'=>$officeId,':assignee'=>$userId,':user'=>currentUserId()]);
 }

 if($issue['status']==='Open'){
  $pdo->prepare('UPDATE hearing_issues SET status="In Progress",updated_at=NOW() WHERE id=:id')->execute([':id'=>$issueId]);
  $pdo->prepare(
   'INSERT INTO hearing_issue_history(issue_id,note,created_by,created_at)
    VALUES(:id,:note,:user,NOW())'
  )->execute([':id'=>$issueId,':note'=>"Action {$ref} created; issue moved to In Progress.",':user'=>currentUserId()]);
 }

 logActivity(currentUserId(),'Issue to Action',"Created {$ref} from {$issue['reference_number']}.");
 $pdo->commit();
 jsonResponse(true,'Action created from issue.',['id'=>$id,'reference_number'=>$ref]);
}catch(Throwable $e){
 if($pdo->inTransaction())$pdo->rollBack();
 jsonResponse(false,APP_DEBUG?$e->getMessage():'Unable to create action.');
}
