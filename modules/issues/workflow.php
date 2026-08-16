<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pdo=db();$id=(int)($_GET['id']??0);
$stmt=$pdo->prepare(
 "SELECT i.*,ic.name category_name,h.reference_number hearing_reference,h.title hearing_title,
         li.reference_number legislative_reference,li.title legislative_title,
         o.name assigned_office,u.full_name assigned_user,
         f.subject feedback_subject,f.message feedback_message
  FROM hearing_issues i
  LEFT JOIN hearing_issue_categories ic ON ic.id=i.category_id
  LEFT JOIN hearings h ON h.id=i.hearing_id
  LEFT JOIN legislative_items li ON li.id=i.legislative_item_id
  LEFT JOIN offices o ON o.id=i.assigned_office_id
  LEFT JOIN users u ON u.id=i.assigned_user_id
  LEFT JOIN feedback f ON f.id=i.feedback_id
  WHERE i.id=:id"
);
$stmt->execute([':id'=>$id]);$issue=$stmt->fetch();
if(!$issue){setFlash('danger','Issue not found.');redirect(APP_URL.'/modules/issues/index.php');}

$actions=$pdo->prepare(
 'SELECT a.*,COALESCE(o.name,u.full_name) assignee
  FROM hearing_actions a
  LEFT JOIN offices o ON o.id=a.assigned_office_id
  LEFT JOIN users u ON u.id=a.assigned_user_id
  WHERE a.issue_id=:id ORDER BY a.created_at DESC'
);$actions->execute([':id'=>$id]);$actionRows=$actions->fetchAll();

$responses=$pdo->prepare(
 'SELECT r.*,pu.full_name prepared_by_name,au.full_name approved_by_name
  FROM hearing_responses r
  LEFT JOIN users pu ON pu.id=r.prepared_by
  LEFT JOIN users au ON au.id=r.approved_by
  WHERE r.issue_id=:id ORDER BY r.created_at DESC'
);$responses->execute([':id'=>$id]);$responseRows=$responses->fetchAll();

$history=$pdo->prepare(
 'SELECT h.*,u.full_name FROM hearing_issue_history h
  LEFT JOIN users u ON u.id=h.created_by
  WHERE h.issue_id=:id ORDER BY h.created_at DESC'
);$history->execute([':id'=>$id]);$historyRows=$history->fetchAll();

$docs=[];
try{$d=$pdo->prepare('SELECT * FROM hearing_issue_documents WHERE issue_id=:id ORDER BY uploaded_at DESC');$d->execute([':id'=>$id]);$docs=$d->fetchAll();}catch(Throwable $e){}

$pageTitle=$issue['reference_number'];$activeMenu='issues';
include __DIR__.'/../../layouts/header.php';
?>
<link rel="stylesheet" href="<?= e(APP_URL.'/assets/css/lph-workflow-final.css') ?>">
<div class="app-wrapper"><?php include __DIR__.'/../../layouts/sidebar.php'; ?><div class="main-content">
<div class="lphwf-head"><div><a href="index.php" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Issue Log</a><div class="lphwf-eyebrow mt-2"><?= e($issue['reference_number']) ?></div><h1><?= e($issue['title']) ?></h1><p><?= e($issue['category_name']?:'Uncategorized') ?> · <?= e($issue['priority']) ?> priority · <?= e($issue['assigned_office']?:$issue['assigned_user']?:'Unassigned') ?></p></div><div class="d-flex gap-2"><a href="<?= e(APP_URL) ?>/modules/actions/index.php?issue_id=<?= $id ?>" class="btn btn-outline-primary"><i class="bi bi-list-check"></i> Actions</a><a href="<?= e(APP_URL) ?>/modules/actions/responses.php?issue_id=<?= $id ?>" class="btn btn-primary"><i class="bi bi-reply"></i> Responses</a></div></div>

<div class="lphwf-stage mb-3">
<?php foreach([['Open','Logged'],['In Progress','Assigned / Investigated'],['Resolved','Resolution Recorded'],['Closed','Final Closure'],['Response','Official Public Response']] as [$s,$d]): ?><div><strong><?= e($s) ?></strong><small><?= e($d) ?></small></div><?php endforeach; ?>
</div>

<div class="row g-3">
<div class="col-xl-8">
<div class="card lphwf-card mb-3"><div class="card-header">Issue Details</div><div class="card-body"><div class="lphwf-message"><?= e($issue['description']) ?></div>
<hr><div class="row g-2 small"><div class="col-md-4"><strong>Status:</strong> <?= e($issue['status']) ?></div><div class="col-md-4"><strong>Due:</strong> <?= $issue['due_at']?formatDateTime($issue['due_at']):'—' ?></div><div class="col-md-4"><strong>Hearing:</strong> <?= e($issue['hearing_reference']?:'—') ?></div></div>
<?php if($issue['resolution_summary']): ?><div class="alert alert-success mt-3 mb-0"><strong>Resolution</strong><div><?= nl2br(e($issue['resolution_summary'])) ?></div></div><?php endif; ?>
</div></div>

<?php if(canManage()): ?>
<div class="card lphwf-card mb-3"><div class="card-header">Workflow Transition</div><div class="card-body"><form id="transitionForm" class="row g-2"><?= csrfField() ?><input type="hidden" name="id" value="<?= $id ?>"><div class="col-md-3"><select class="form-select" name="status"><?php foreach(['Open','In Progress','Resolved','Closed'] as $s): ?><option <?= $issue['status']===$s?'selected':'' ?>><?= e($s) ?></option><?php endforeach; ?></select></div><div class="col-md-7"><textarea class="form-control" name="resolution_summary" rows="2" placeholder="Resolution summary required for Resolved / Closed"><?= e($issue['resolution_summary']?:'') ?></textarea></div><div class="col-md-2"><button class="btn btn-primary w-100">Save</button></div></form></div></div>
<?php endif; ?>

<div class="card lphwf-card mb-3"><div class="card-header">Linked Actions</div><div class="table-responsive"><table class="table lphwf-table mb-0"><thead><tr><th>Reference</th><th>Action</th><th>Assignee</th><th>Deadline</th><th>Status</th></tr></thead><tbody><?php if(!$actionRows): ?><tr><td colspan="5" class="lphwf-empty">No actions linked yet.</td></tr><?php endif; ?><?php foreach($actionRows as $a): ?><tr><td><span class="lphwf-code"><?= e($a['reference_number']) ?></span></td><td><a href="<?= e(APP_URL) ?>/modules/actions/view.php?id=<?= (int)$a['id'] ?>"><?= e($a['title']) ?></a></td><td><?= e($a['assignee']?:'Unassigned') ?></td><td><?= $a['deadline']?formatDate($a['deadline']):'—' ?></td><td><?= e($a['status']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>

<div class="card lphwf-card"><div class="card-header">Official Responses</div><div class="table-responsive"><table class="table lphwf-table mb-0"><thead><tr><th>Reference</th><th>Status</th><th>Visibility</th><th>Prepared</th><th>Published</th></tr></thead><tbody><?php if(!$responseRows): ?><tr><td colspan="5" class="lphwf-empty">No official responses yet.</td></tr><?php endif; ?><?php foreach($responseRows as $r): ?><tr><td><?= e($r['reference_number']?:'Response #'.$r['id']) ?></td><td><?= e($r['status']??'Draft') ?></td><td><?= e($r['visibility']) ?></td><td><?= e($r['prepared_by_name']?:'—') ?></td><td><?= $r['published_at']?formatDateTime($r['published_at']):'—' ?></td></tr><?php endforeach; ?></tbody></table></div></div>
</div>

<div class="col-xl-4">
<?php if($issue['feedback_id']): ?><div class="card lphwf-card mb-3"><div class="card-header">Source Feedback #<?= (int)$issue['feedback_id'] ?></div><div class="card-body"><strong><?= e($issue['feedback_subject']?:'Public Feedback') ?></strong><div class="lphwf-message mt-2"><?= e($issue['feedback_message']) ?></div></div></div><?php endif; ?>

<div class="card lphwf-card"><div class="card-header">Issue History</div><div class="card-body lphwf-timeline"><?php foreach($historyRows as $h): ?><div><strong><?= e($h['note']) ?></strong><small><?= formatDateTime($h['created_at']) ?> · <?= e($h['full_name']?:'System') ?></small></div><?php endforeach; ?></div></div>
</div>
</div>
</div></div>
<?php if(canManage()): ?><script>document.addEventListener('DOMContentLoaded',()=>{transitionForm.onsubmit=async e=>{e.preventDefault();const r=await appPostForm(APP_URL+'/modules/issues/ajax_transition.php',transitionForm);if(r.success){appToast('success',r.message);setTimeout(()=>location.reload(),350);}else if(!r.session_expired)Swal.fire('Workflow Error',r.message,'error');};});</script><?php endif; ?>
<?php include __DIR__.'/../../layouts/footer.php'; ?>
