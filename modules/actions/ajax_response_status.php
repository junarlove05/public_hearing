<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
if(!canManage())jsonResponse(false,'You do not have permission to approve or publish responses.');
if($_SERVER['REQUEST_METHOD']!=='POST')jsonResponse(false,'Invalid request method.');
requireCsrf();

$pdo=db();$id=(int)($_POST['id']??0);$status=clean($_POST['status']??'');
$notes=trim((string)($_POST['publication_notes']??''));

if(!in_array($status,['Draft','For Review','Approved','Published','Withdrawn'],true))jsonResponse(false,'Invalid response status.');

try{
 $pdo->beginTransaction();
 $q=$pdo->prepare('SELECT * FROM hearing_responses WHERE id=:id FOR UPDATE');$q->execute([':id'=>$id]);$r=$q->fetch();
 if(!$r){$pdo->rollBack();jsonResponse(false,'Response not found.');}

 $reviewedBy=$r['reviewed_by'];$reviewedAt=$r['reviewed_at'];
 $approvedBy=$r['approved_by'];$approvedAt=$r['approved_at'];
 $publishedAt=$r['published_at'];$publishedBy=$r['published_by'];

 if(in_array($status,['For Review','Approved','Published'],true)){
  $reviewedBy=$reviewedBy?:currentUserId();$reviewedAt=$reviewedAt?:date('Y-m-d H:i:s');
 }
 if(in_array($status,['Approved','Published'],true)){
  $approvedBy=$approvedBy?:currentUserId();$approvedAt=$approvedAt?:date('Y-m-d H:i:s');
 }
 if($status==='Published'){
  if($r['visibility']!=='Public'){$pdo->rollBack();jsonResponse(false,'Only Public responses can be published.');}
  $publishedAt=$publishedAt?:date('Y-m-d H:i:s');$publishedBy=$publishedBy?:currentUserId();
 }

 $pdo->prepare(
  'UPDATE hearing_responses
   SET status=:status,reviewed_by=:reviewed_by,reviewed_at=:reviewed_at,
       approved_by=:approved_by,approved_at=:approved_at,
       published_at=:published_at,published_by=:published_by,
       publication_notes=:notes,updated_at=NOW()
   WHERE id=:id'
 )->execute([
  ':status'=>$status,':reviewed_by'=>$reviewedBy,':reviewed_at'=>$reviewedAt,
  ':approved_by'=>$approvedBy,':approved_at'=>$approvedAt,
  ':published_at'=>$publishedAt,':published_by'=>$publishedBy,
  ':notes'=>$notes?:$r['publication_notes'],':id'=>$id
 ]);

 $pdo->prepare(
  'INSERT INTO hearing_response_history
   (response_id,previous_status,new_status,details,changed_by,created_at)
   VALUES(:id,:old,:new,:details,:user,NOW())'
 )->execute([
  ':id'=>$id,':old'=>$r['status'],':new'=>$status,
  ':details'=>"Official response status changed from {$r['status']} to {$status}.",
  ':user'=>currentUserId()
 ]);

 if($status==='Published'){
  $pdo->prepare(
   "UPDATE feedback f
    JOIN hearing_issues i ON i.feedback_id=f.id
    SET f.status='Responded',f.reply_text=:reply,f.replied_at=NOW(),
        f.replied_by=:user,f.updated_at=NOW()
    WHERE i.id=:issue"
  )->execute([':reply'=>$r['response_text'],':user'=>currentUserId(),':issue'=>$r['issue_id']]);
 }

 logActivity(currentUserId(),'Official Response Workflow',"Response #{$id}: {$r['status']} -> {$status}.");
 $pdo->commit();
 jsonResponse(true,'Response workflow updated.');
}catch(Throwable $e){
 if($pdo->inTransaction())$pdo->rollBack();
 jsonResponse(false,APP_DEBUG?$e->getMessage():'Unable to update response.');
}
