<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';
requireLogin();

$pdo=db();$pageTitle='Stakeholder Registrations';$activeMenu='stakeholders';
$hearingFilter=(int)($_GET['hearing_id']??0);
$hearings=$pdo->query("SELECT id,reference_number,title,hearing_date,status FROM hearings WHERE status IN ('Upcoming','Ongoing') ORDER BY hearing_date")->fetchAll();
$stakeholders=$pdo->query("SELECT id,full_name,email,status FROM stakeholders WHERE status IN ('Pending','Verified') ORDER BY full_name")->fetchAll();

$sql="SELECT r.*,s.full_name,s.email,s.organization,h.title hearing_title,h.reference_number,h.hearing_date,
      q.code_value,q.status qr_status
      FROM registrations r JOIN stakeholders s ON s.id=r.stakeholder_id
      LEFT JOIN hearings h ON h.id=r.hearing_id
      LEFT JOIN qr_codes q ON q.registration_id=r.id
      ".($hearingFilter>0?"WHERE r.hearing_id=:hid ":"")."
      ORDER BY r.registered_at DESC,r.id DESC";
$stmt=$pdo->prepare($sql);$stmt->execute($hearingFilter>0?[':hid'=>$hearingFilter]:[]);$rows=$stmt->fetchAll();

include __DIR__.'/../../layouts/header.php';
?>
<link rel="stylesheet" href="<?= e(APP_URL.'/assets/css/lph-complete-modules.css') ?>">
<div class="app-wrapper"><?php include __DIR__.'/../../layouts/sidebar.php'; ?><div class="main-content">
<div class="lphx-head"><div><div class="lphx-eyebrow">Step 3 · Registration</div><h1>Stakeholder Registrations</h1><p>Enforce hearing deadlines and capacity, approve or reject participants, and automatically issue QR-ready attendance credentials.</p></div><a class="btn btn-outline-secondary" href="index.php"><i class="bi bi-arrow-left"></i> Stakeholders</a></div>

<?php if(canManage()): ?>
<div class="card lphx-card mb-3"><div class="card-header"><i class="bi bi-person-plus"></i> Staff Registration</div><div class="card-body">
<form id="registrationForm" class="row g-2"><?= csrfField() ?>
<div class="col-lg-5"><select name="stakeholder_id" class="form-select" required><option value="">Select stakeholder</option><?php foreach($stakeholders as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['full_name'].' · '.$s['email']) ?></option><?php endforeach; ?></select></div>
<div class="col-lg-4"><select name="hearing_id" class="form-select" required><option value="">Select hearing</option><?php foreach($hearings as $h): ?><option value="<?= (int)$h['id'] ?>" <?= $hearingFilter===(int)$h['id']?'selected':'' ?>><?= e(($h['reference_number']?:'').' '.$h['title'].' · '.formatDate($h['hearing_date'])) ?></option><?php endforeach; ?></select></div>
<div class="col-lg-2"><select name="attendance_type" class="form-select"><option>On-site</option><option>Online</option><option>Hybrid</option></select></div>
<div class="col-lg-1"><button class="btn btn-primary w-100"><i class="bi bi-plus"></i></button></div>
</form></div></div>
<?php endif; ?>

<div class="card lphx-card"><div class="card-header"><i class="bi bi-person-check"></i> Registration Register</div><div class="table-responsive">
<table class="table table-hover lphx-table mb-0"><thead><tr><th>Code</th><th>Stakeholder</th><th>Hearing</th><th>Attendance</th><th>Status</th><th>QR Credential</th><th class="text-end">Decision</th></tr></thead><tbody>
<?php if(!$rows): ?><tr><td colspan="7"><div class="lphx-empty"><i class="bi bi-person-check"></i>No registrations found.</div></td></tr><?php endif; ?>
<?php foreach($rows as $r): ?><tr>
<td><span class="lphx-code"><?= e($r['registration_code']?:'—') ?></span></td>
<td><strong><?= e($r['full_name']) ?></strong><div class="small text-muted"><?= e($r['email']) ?></div></td>
<td><?= e($r['hearing_title']?:'—') ?><div class="small text-muted"><?= !empty($r['hearing_date'])?formatDate($r['hearing_date']):'' ?></div></td>
<td><?= e($r['attendance_type']?:'On-site') ?></td>
<td><span class="badge text-bg-<?= $r['registration_status']==='Approved'?'success':($r['registration_status']==='Rejected'?'danger':'warning') ?>"><?= e($r['registration_status']) ?></span><?php if($r['rejection_reason']): ?><div class="small text-danger"><?= e($r['rejection_reason']) ?></div><?php endif; ?></td>
<td><?php if($r['code_value']): ?><span class="lphx-code"><?= e($r['code_value']) ?></span><?php else: ?><span class="text-muted small">Issued after approval</span><?php endif; ?></td>
<td class="text-end"><?php if(canManage()): ?><div class="btn-group btn-group-sm"><button class="btn btn-outline-success btn-reg-status" data-id="<?= (int)$r['id'] ?>" data-status="Approved">Approve</button><button class="btn btn-outline-danger btn-reg-status" data-id="<?= (int)$r['id'] ?>" data-status="Rejected">Reject</button><button class="btn btn-outline-secondary btn-reg-status" data-id="<?= (int)$r['id'] ?>" data-status="Cancelled">Cancel</button></div><?php endif; ?></td>
</tr><?php endforeach; ?>
</tbody></table></div></div>
</div></div>
<script>
document.addEventListener('DOMContentLoaded',function(){
 const form=document.getElementById('registrationForm');
 if(form) form.onsubmit=async e=>{e.preventDefault();const r=await appPostForm(APP_URL+'/modules/stakeholders/ajax_registration_create.php',form);if(r.success){appToast('success',r.message);setTimeout(()=>location.reload(),400);}else if(!r.session_expired)Swal.fire('Registration Error',r.message,'error');};
 document.querySelectorAll('.btn-reg-status').forEach(b=>b.onclick=async function(){
   let reason='';if(this.dataset.status==='Rejected'){const p=await Swal.fire({title:'Rejection reason',input:'textarea',showCancelButton:true,inputValidator:v=>!v?'Reason is required':undefined});if(!p.isConfirmed)return;reason=p.value;}
   const fd=new FormData();fd.append('csrf_token',document.querySelector('[name=csrf_token]')?.value||'');fd.append('id',this.dataset.id);fd.append('status',this.dataset.status);fd.append('rejection_reason',reason);
   const r=await fetch(APP_URL+'/modules/stakeholders/ajax_registration_status.php',{method:'POST',body:fd}).then(x=>x.json());if(r.success)location.reload();else Swal.fire('Update Failed',r.message,'error');
 });
});
</script>
<?php include __DIR__.'/../../layouts/footer.php'; ?>
