<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
if(!canManage())jsonResponse(false,'You do not have permission to prepare official responses.');
if($_SERVER['REQUEST_METHOD']!=='POST')jsonResponse(false,'Invalid request method.');
requireCsrf();

$pdo=db();
$id=(int)($_POST['id']??0);
$issueId=(int)($_POST['issue_id']??0);
$actionId=(int)($_POST['action_id']??0)?:null;
$text=trim((string)($_POST['response_text']??''));
$visibility=clean($_POST['visibility']??'Internal');
$status=clean($_POST['status']??'Draft');

if($issueId<=0 || $text==='')jsonResponse(false,'Issue and response text are required.');
if(!in_array($visibility,['Public','Internal','Restricted'],true))jsonResponse(false,'Invalid visibility.');
if(!in_array($status,['Draft','For Review','Approved','Published','Withdrawn'],true))jsonResponse(false,'Invalid response status.');

$q=$pdo->prepare('SELECT id,reference_number FROM hearing_issues WHERE id=:id');$q->execute([':id'=>$issueId]);$issue=$q->fetch();
if(!$issue)jsonResponse(false,'Issue not found.');

$existing=null;
if($id>0){$q=$pdo->prepare('SELECT * FROM hearing_responses WHERE id=:id');$q->execute([':id'=>$id]);$existing=$q->fetch();if(!$existing)jsonResponse(false,'Response not found.');}

try{
 $pdo->beginTransaction();

 if($id>0){
  $pdo->prepare(
   'UPDATE hearing_responses
    SET issue_id=:issue,action_id=:action,response_text=:text,visibility=:visibility,
        status=:status,updated_at=NOW()
    WHERE id=:id'
  )->execute([':issue'=>$issueId,':action'=>$actionId,':text'=>$text,':visibility'=>$visibility,':status'=>$status,':id'=>$id]);
  $old=$existing['status']??'Draft';
 }else{
  $ref='RSP-'.date('Y').'-'.strtoupper(substr(bin2hex(random_bytes(6)),0,10));
  $pdo->prepare(
   'INSERT INTO hearing_responses
    (issue_id,action_id,reference_number,response_text,status,visibility,prepared_by,
     reviewed_by,reviewed_at,approved_by,approved_at,published_at,published_by,
     publication_notes,created_at,updated_at)
    VALUES(:issue,:action,:ref,:text,:status,:visibility,:user,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NOW(),NOW())'
  )->execute([
   ':issue'=>$issueId,':action'=>$actionId,':ref'=>$ref,':text'=>$text,
   ':status'=>$status,':visibility'=>$visibility,':user'=>currentUserId()
  ]);
  $id=(int)$pdo->lastInsertId();$old=null;
 }

 $pdo->prepare(
  'INSERT INTO hearing_response_history
   (response_id,previous_status,new_status,details,changed_by,created_at)
   VALUES(:id,:old,:new,:details,:user,NOW())'
 )->execute([
  ':id'=>$id,':old'=>$old,':new'=>$status,
  ':details'=>$existing?'Official response updated.':'Official response drafted from issue '.$issue['reference_number'].'.',
  ':user'=>currentUserId()
 ]);

 logActivity(currentUserId(),$existing?'Update Official Response':'Create Official Response',
   "Response #{$id} for {$issue['reference_number']}.");

 $pdo->commit();
 jsonResponse(true,'Official response saved.',['id'=>$id]);
}catch(Throwable $e){
 if($pdo->inTransaction())$pdo->rollBack();
 jsonResponse(false,APP_DEBUG?$e->getMessage():'Unable to save official response.');
}
