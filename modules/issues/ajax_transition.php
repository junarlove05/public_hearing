<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false,'Invalid request method.');
requireCsrf();

$pdo=db();
$id=(int)($_POST['id']??0);
$status=clean($_POST['status']??'');
$note=clean($_POST['note']??'');
$resolution=trim((string)($_POST['resolution_summary']??''));

if ($resolution === '' && $note !== '') {
    $resolution = $note;
}

$allowed=['Open','In Progress','Resolved','Closed'];
if(!in_array($status,$allowed,true)) {
    jsonResponse(false,'Invalid issue status.');
}

$stmt=$pdo->prepare('SELECT * FROM hearing_issues WHERE id=:id FOR UPDATE');
try{
 $pdo->beginTransaction();
 $stmt->execute([':id'=>$id]);
 $issue=$stmt->fetch();
 if(!$issue){
     $pdo->rollBack();
     jsonResponse(false,'Issue not found.');
 }

 $isAdmin = (function_exists('isAdmin') && isAdmin()) || (isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === 1);
 $isAssignedUser = (function_exists('currentUserId') && currentUserId() && (int)($issue['assigned_user_id'] ?? 0) === currentUserId());
 if (!canManage() && !$isAdmin && !$isAssignedUser) {
   $pdo->rollBack();
   jsonResponse(false,'You do not have permission to update issue workflow.');
 }

 $statusChanged = ($issue['status'] !== $status);

 if (!$statusChanged && $note === '') {
   $pdo->rollBack();
   jsonResponse(true, 'No changes were detected.', ['status' => $status]);
 }

 if (in_array($status,['Resolved','Closed'],true) && $resolution === '' && $note === '') {
   $resolution = "Marked as {$status} by " . (currentUser()['full_name'] ?? 'Assigned Staff');
   $note = $resolution;
 }

 $resolvedAt = in_array($status, ['Resolved', 'Closed'], true)
   ? ($issue['resolved_at'] ?: date('Y-m-d H:i:s')) : null;
 $resolvedBy = in_array($status, ['Resolved', 'Closed'], true)
   ? ($issue['resolved_by'] ?: currentUserId()) : null;
 $closedAt = $status === 'Closed' ? ($issue['closed_at'] ?: date('Y-m-d H:i:s')) : null;

 $pdo->prepare(
  'UPDATE hearing_issues
   SET status = :status,
       resolution_summary = CASE WHEN :summary <> "" THEN :summary_val ELSE resolution_summary END,
       resolved_by = :resolved_by,
       resolved_at = :resolved_at,
       closed_at = :closed_at,
       updated_at = NOW()
   WHERE id = :id'
 )->execute([
  ':status'      => $status,
  ':summary'     => $resolution,
  ':summary_val' => $resolution ?: null,
  ':resolved_by' => $resolvedBy,
  ':resolved_at' => $resolvedAt,
  ':closed_at'   => $closedAt,
  ':id'          => $id,
 ]);

 // If reopened, clear closure timestamps
 if (!in_array($status, ['Resolved', 'Closed'], true)) {
   $pdo->prepare('UPDATE hearing_issues SET closed_at = NULL, resolved_at = NULL WHERE id = :id')->execute([':id' => $id]);
 }

 if ($statusChanged && $note !== '') {
   $historyText = "Status changed from {$issue['status']} to {$status}. Note: {$note}";
 } elseif ($statusChanged) {
   $historyText = "Status changed from {$issue['status']} to {$status}." . ($resolution !== '' ? ' Resolution: ' . $resolution : '');
 } else {
   $historyText = $note;
 }

 $pdo->prepare(
  'INSERT INTO hearing_issue_history(issue_id, note, created_by, created_at)
   VALUES(:id, :note, :user, NOW())'
 )->execute([
  ':id'   => $id,
  ':note' => $historyText,
  ':user' => currentUserId(),
 ]);

 if ($issue['feedback_id'] && in_array($status, ['Resolved', 'Closed'], true)) {
   $pdo->prepare(
    "UPDATE feedback
     SET status = CASE WHEN status IN ('New', 'Under Review', 'Validated') THEN 'Validated' ELSE status END,
         updated_at = NOW()
     WHERE id = :fid"
   )->execute([':fid' => $issue['feedback_id']]);
 }

 logActivity(currentUserId(), 'Issue Workflow',
   "{$issue['reference_number']} status {$issue['status']} -> {$status}." . ($note !== '' ? " Note: {$note}" : ''));

 $pdo->commit();
 jsonResponse(true, 'Issue status and progress note updated successfully.', ['status' => $status]);
 }catch(Throwable $e){
  if($pdo->inTransaction())$pdo->rollBack();
  jsonResponse(false,APP_DEBUG?$e->getMessage():'Unable to update issue workflow.');
 }
