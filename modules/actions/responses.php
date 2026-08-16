<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pdo=db();$pageTitle='Official Responses';$activeMenu='actions';
$issueFilter=(int)($_GET['issue_id']??0);

$issues=$pdo->query(
 "SELECT id,reference_number,title,status FROM hearing_issues
  WHERE status<>'Closed' OR id IN (SELECT issue_id FROM hearing_responses)
  ORDER BY created_at DESC LIMIT 500"
)->fetchAll();

$actions=$pdo->query(
 "SELECT id,issue_id,reference_number,title,status FROM hearing_actions
  ORDER BY created_at DESC LIMIT 500"
)->fetchAll();

$sql="SELECT r.*,i.reference_number issue_reference,i.title issue_title,
      a.reference_number action_reference,a.title action_title,
      p.full_name prepared_name,ap.full_name approved_name,pb.full_name published_name
      FROM hearing_responses r
      JOIN hearing_issues i ON i.id=r.issue_id
      LEFT JOIN hearing_actions a ON a.id=r.action_id
      LEFT JOIN users p ON p.id=r.prepared_by
      LEFT JOIN users ap ON ap.id=r.approved_by
      LEFT JOIN users pb ON pb.id=r.published_by
      ".($issueFilter>0?'WHERE r.issue_id=:issue ':'')."
      ORDER BY r.created_at DESC,r.id DESC";
$stmt=$pdo->prepare($sql);$stmt->execute($issueFilter>0?[':issue'=>$issueFilter]:[]);$rows=$stmt->fetchAll();

include __DIR__.'/../../layouts/header.php';
?>
<link rel="stylesheet" href="<?= e(APP_URL.'/assets/css/lph-workflow-final.css') ?>">
<div class="app-wrapper"><?php include __DIR__.'/../../layouts/sidebar.php'; ?><div class="main-content">
<div class="lphwf-head"><div><div class="lphwf-eyebrow">Step 7 · Response Management</div><h1>Official Responses</h1><p>Prepare, review, approve and publish official responses to issues while linking response text to action-tracking and citizen feedback.</p></div><div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="index.php"><i class="bi bi-arrow-left"></i> Actions</a><?php if(canManage()): ?><button class="btn btn-primary" id="btnNewResponse"><i class="bi bi-plus-circle"></i> New Response</button><?php endif; ?></div></div>

<div class="card lphwf-card"><div class="card-header">Response Register</div><div class="table-responsive"><table class="table table-hover lphwf-table mb-0"><thead><tr><th>Reference</th><th>Issue / Action</th><th>Response</th><th>Visibility</th><th>Status</th><th>Published</th><th class="text-end">Workflow</th></tr></thead><tbody>
<?php if(!$rows): ?><tr><td colspan="7" class="lphwf-empty">No official responses yet.</td></tr><?php endif; ?>
<?php foreach($rows as $r): ?><tr>
<td><span class="lphwf-code"><?= e($r['reference_number']?:'Response #'.$r['id']) ?></span></td>
<td><a href="<?= e(APP_URL) ?>/modules/issues/workflow.php?id=<?= (int)$r['issue_id'] ?>"><?= e($r['issue_reference']) ?></a><div class="small text-muted"><?= e($r['action_reference']?:'No action linked') ?></div></td>
<td><div class="lphwf-message"><?= e(mb_strimwidth($r['response_text'],0,180,'…')) ?></div></td>
<td><?= e($r['visibility']) ?></td>
<td><span class="badge text-bg-light"><?= e($r['status']??'Draft') ?></span></td>
<td><?= $r['published_at']?formatDateTime($r['published_at']):'—' ?></td>
<td class="text-end"><?php if(canManage()): ?><div class="btn-group btn-group-sm"><?php foreach(['For Review','Approved','Published','Withdrawn'] as $st): ?><button class="btn btn-outline-secondary response-status" data-id="<?= (int)$r['id'] ?>" data-status="<?= e($st) ?>"><?= e($st) ?></button><?php endforeach; ?></div><?php endif; ?></td>
</tr><?php endforeach; ?>
</tbody></table></div></div>
</div></div>

<?php if(canManage()): ?>
<div class="modal fade" id="responseModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><form id="responseForm"><?= csrfField() ?><input type="hidden" name="id" value="0">
<div class="modal-header bg-dark text-white"><h5 class="modal-title">Prepare Official Response</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><div class="row g-3">
<div class="col-md-7"><label class="form-label">Issue *</label><select class="form-select" name="issue_id" id="rsp_issue" required><option value="">Select issue</option><?php foreach($issues as $i): ?><option value="<?= (int)$i['id'] ?>" <?= $issueFilter===(int)$i['id']?'selected':'' ?>><?= e($i['reference_number'].' - '.$i['title']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-5"><label class="form-label">Linked Action</label><select class="form-select" name="action_id" id="rsp_action"><option value="">None</option><?php foreach($actions as $a): ?><option value="<?= (int)$a['id'] ?>" data-issue="<?= (int)$a['issue_id'] ?>"><?= e($a['reference_number'].' - '.$a['title']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-6"><label class="form-label">Status</label><select class="form-select" name="status"><option>Draft</option><option>For Review</option></select></div>
<div class="col-md-6"><label class="form-label">Visibility</label><select class="form-select" name="visibility"><option>Internal</option><option>Public</option><option>Restricted</option></select></div>
<div class="col-12"><label class="form-label">Official Response *</label><textarea class="form-control" name="response_text" rows="7" required></textarea></div>
</div></div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save Response</button></div></form></div></div></div>
<script>
document.addEventListener('DOMContentLoaded',function(){
 const modal=new bootstrap.Modal(document.getElementById('responseModal'));btnNewResponse.onclick=()=>modal.show();
 rsp_issue.onchange=function(){[...rsp_action.options].forEach(o=>{if(!o.value)return;o.hidden=o.dataset.issue!==this.value;});rsp_action.value='';};
 rsp_issue.dispatchEvent(new Event('change'));
 responseForm.onsubmit=async e=>{e.preventDefault();const r=await appPostForm(APP_URL+'/modules/actions/ajax_response_save.php',responseForm);if(r.success){appToast('success',r.message);setTimeout(()=>location.reload(),350);}else if(!r.session_expired)Swal.fire('Response Error',r.message,'error');};
 document.querySelectorAll('.response-status').forEach(b=>b.onclick=async function(){const fd=new FormData();fd.append('csrf_token','<?= e(csrfToken()) ?>');fd.append('id',this.dataset.id);fd.append('status',this.dataset.status);if(this.dataset.status==='Published'){const p=await Swal.fire({title:'Publish official response?',text:'A public response will also mark linked source feedback as Responded when available.',showCancelButton:true,confirmButtonText:'Publish'});if(!p.isConfirmed)return;}const r=await fetch(APP_URL+'/modules/actions/ajax_response_status.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(x=>x.json());if(r.success)location.reload();else Swal.fire('Workflow Error',r.message,'error');});
});
</script>
<?php endif; ?>
<?php include __DIR__.'/../../layouts/footer.php'; ?>
