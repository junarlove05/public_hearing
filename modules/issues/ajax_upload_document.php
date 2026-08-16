<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
if (!canManage()) jsonResponse(false,'You do not have permission to upload issue documents.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false,'Invalid request method.');
requireCsrf();

$pdo=db();
$issueId=(int)($_POST['issue_id']??0);
$type=clean($_POST['document_type']??'Supporting Document');
$description=trim((string)($_POST['description']??''));
$visibility=clean($_POST['visibility']??'Internal');

if(!in_array($visibility,['Public','Internal','Restricted'],true))jsonResponse(false,'Invalid visibility.');
$q=$pdo->prepare('SELECT reference_number FROM hearing_issues WHERE id=:id');$q->execute([':id'=>$issueId]);$ref=$q->fetchColumn();
if(!$ref)jsonResponse(false,'Issue not found.');
if(empty($_FILES['document']))jsonResponse(false,'Choose a document.');

$result=handleUpload($_FILES['document'],'issues');
if(!$result['success'])jsonResponse(false,$result['message']);

try{
 $pdo->prepare(
  'INSERT INTO hearing_issue_documents
   (issue_id,file_name,file_path,document_type,description,visibility,uploaded_by,uploaded_at)
   VALUES(:issue,:name,:path,:type,:description,:visibility,:user,NOW())'
 )->execute([
  ':issue'=>$issueId,':name'=>$result['file_name'],':path'=>$result['file_path'],
  ':type'=>$type?:'Supporting Document',':description'=>$description?:null,
  ':visibility'=>$visibility,':user'=>currentUserId()
 ]);
 $pdo->prepare(
  'INSERT INTO hearing_issue_history(issue_id,note,created_by,created_at)
   VALUES(:id,:note,:user,NOW())'
 )->execute([':id'=>$issueId,':note'=>'Uploaded issue document "'.$result['file_name'].'".',':user'=>currentUserId()]);
 logActivity(currentUserId(),'Upload Issue Document',"Uploaded {$result['file_name']} to {$ref}.");
 jsonResponse(true,'Issue document uploaded.');
}catch(Throwable $e){
 @unlink(rtrim(UPLOAD_DIR,'/').'/'.$result['file_path']);
 jsonResponse(false,APP_DEBUG?$e->getMessage():'Unable to record issue document.');
}
