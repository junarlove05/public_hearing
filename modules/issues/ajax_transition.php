<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
if (!canManage()) jsonResponse(false,'You do not have permission to update issue workflow.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false,'Invalid request method.');
requireCsrf();

$pdo=db();
$id=(int)($_POST['id']??0);
$status=clean($_POST['status']??'');
$resolution=trim((string)($_POST['resolution_summary']??''));

$allowed=['Open','In Progress','Resolved','Closed'];
if(!in_array($status,$allowed,true))jsonResponse(false,'Invalid issue status.');
if(in_array($status,['Resolved','Closed'],true) && $resolution===''){
 jsonResponse(false,'Resolution summary is required before resolving or closing an issue.');
}

$stmt=$pdo->prepare('SELECT * FROM hearing_issues WHERE id=:id FOR UPDATE');
try{
 $pdo->beginTransaction();
 $stmt->execute([':id'=>$id]);$issue=$stmt->fetch();
 if(!$issue){$pdo->rollBack();jsonResponse(false,'Issue not found.');}

 $resolvedAt=in_array($status,['Resolved','Closed'],true)
   ? ($issue['resolved_at'] ?: date('Y-m-d H:i:s')) : null;
 $resolvedBy=in_array($status,['Resolved','Closed'],true)
   ? ($issue['resolved_by'] ?: currentUserId()) : null;
 $closedAt=$status==='Closed' ? ($issue['closed_at'] ?: date('Y-m-d H:i:s')) : null;

 $pdo->prepare(
  'UPDATE hearing_issues
   SET status=:status,resolution_summary=:summary,resolved_by=:resolved_by,
       resolved_at=:resolved_at,closed_at=:closed_at,updated_at=NOW()
   WHERE id=:id'
 )->execute([
  ':status'=>$status,
  ':summary'=>$resolution?:null,
  ':resolved_by'=>$resolvedBy,
  ':resolved_at'=>$resolvedAt,
  ':closed_at'=>$closedAt,
  ':id'=>$id
 ]);

 $pdo->prepare(
  'INSERT INTO hearing_issue_history(issue_id,note,created_by,created_at)
   VALUES(:id,:note,:user,NOW())'
 )->execute([
  ':id'=>$id,
  ':note'=>"Status changed from {$issue['status']} to {$status}.".
          ($resolution!=='' ? ' Resolution: '.$resolution : ''),
  ':user'=>currentUserId()
 ]);

 if($issue['feedback_id'] && in_array($status,['Resolved','Closed'],true)){
   $pdo->prepare(
    "UPDATE feedback
     SET status=CASE WHEN status IN ('New','Under Review','Validated') THEN 'Validated' ELSE status END,
         updated_at=NOW()
     WHERE id=:fid"
   )->execute([':fid'=>$issue['feedback_id']]);
 }

 logActivity(currentUserId(),'Issue Workflow',
   "{$issue['reference_number']} status {$issue['status']} -> {$status}.");

 $pdo->commit();
 jsonResponse(true,'Issue workflow updated.');
}catch(Throwable $e){
 if($pdo->inTransaction())$pdo->rollBack();
 jsonResponse(false,APP_DEBUG?$e->getMessage():'Unable to update issue workflow.');
}
