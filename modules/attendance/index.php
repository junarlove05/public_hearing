<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
if (!hasPermission('lph.attendance.manage')) redirect(APP_URL.'/dashboard.php');

$pdo=db();$pageTitle='Attendance Tracking';$activeMenu='attendance';
$hearingId=(int)($_GET['hearing_id']??0);

$hearings=$pdo->query(
 "SELECT id,reference_number,title,hearing_date,status
  FROM hearings
  WHERE status IN ('Upcoming','Ongoing','Completed')
  ORDER BY hearing_date DESC,hearing_time DESC"
)->fetchAll();

$rows=[];$hearing=null;
if($hearingId>0){
 $hs=$pdo->prepare('SELECT * FROM hearings WHERE id=:id');$hs->execute([':id'=>$hearingId]);$hearing=$hs->fetch();

 $stmt=$pdo->prepare(
  "SELECT r.id registration_id,r.registration_code,r.registration_status,r.attendance_type,
          s.id stakeholder_id,s.full_name,s.email,s.organization,
          a.id attendance_id,a.status attendance_status,a.checked_in_at,a.checked_out_at,
          a.check_in_method,a.remarks,q.code_value
   FROM registrations r
   JOIN stakeholders s ON s.id=r.stakeholder_id
   LEFT JOIN attendance a ON a.registration_id=r.id
   LEFT JOIN qr_codes q ON q.registration_id=r.id
   WHERE r.hearing_id=:hid AND r.registration_status='Approved'
   ORDER BY s.full_name"
 );
 $stmt->execute([':hid'=>$hearingId]);$rows=$stmt->fetchAll();
}

$present=0;$absent=0;$excused=0;
foreach($rows as $r){if($r['attendance_status']==='Present')$present++;elseif($r['attendance_status']==='Absent')$absent++;elseif($r['attendance_status']==='Excused')$excused++;}

include __DIR__.'/../../layouts/header.php';
?>
<link rel="stylesheet" href="<?= e(APP_URL.'/assets/css/lph-complete-modules.css') ?>">
<div class="app-wrapper"><?php include __DIR__.'/../../layouts/sidebar.php'; ?><div class="main-content">
<?php include __DIR__ . '/../../layouts/top_controls.php'; ?>
<div class="lphx-head"><div><div class="lphx-eyebrow"><i class="bi bi-qr-code-scan"></i> Step 4</div><h1>Attendance Tracking</h1><p>Manage approved participant check-in, checkout, absence, excused attendance and QR-ready code scanning with an audit trail.</p></div>
<form method="get"><select name="hearing_id" class="form-select" onchange="this.form.submit()"><option value="">Select hearing</option><?php foreach($hearings as $h): ?><option value="<?= (int)$h['id'] ?>" <?= $hearingId===(int)$h['id']?'selected':'' ?>><?= e(($h['reference_number']?:'').' '.$h['title'].' · '.formatDate($h['hearing_date'])) ?></option><?php endforeach; ?></select></form></div>

<?php if($hearing): ?>
<div class="row g-3 mb-3">
<?php foreach([['Approved Roster',count($rows),'bi-people'],['Present',$present,'bi-person-check'],['Absent',$absent,'bi-person-x'],['Excused',$excused,'bi-person-dash']] as [$l,$v,$i]): ?>
<div class="col-6 col-lg-3"><div class="lphx-stat"><i class="bi <?= e($i) ?>"></i><div><strong><?= (int)$v ?></strong><small><?= e($l) ?></small></div></div></div>
<?php endforeach; ?>
</div>

<div class="lphx-scan-box mb-3">
<div class="row g-2 align-items-end">
<div class="col-lg-8"><label class="form-label fw-semibold">QR / Registration Code Scanner</label><input id="scanCode" class="form-control" autocomplete="off" placeholder="Scan QR code or enter REG-/QR- code"></div>
<div class="col-lg-2"><button id="btnFindCode" class="btn btn-outline-primary w-100"><i class="bi bi-search"></i> Find</button></div>
<div class="col-lg-2"><button id="btnScanCheckIn" class="btn btn-primary w-100" disabled><i class="bi bi-qr-code-scan"></i> Check In</button></div>
</div>
<div id="scanResult" class="small mt-2 text-muted">Physical barcode/QR scanners can type directly into this field; camera scanning can be added later without changing the backend.</div>
</div>

<div class="card lphx-card">
<div class="card-header d-flex justify-content-between"><span><i class="bi bi-list-check"></i> <?= e($hearing['title']) ?></span><a href="print.php?hearing_id=<?= $hearingId ?>" target="_blank" class="btn btn-sm btn-outline-light"><i class="bi bi-printer"></i> Print</a></div>
<div>
<?php if(!$rows): ?><div class="lphx-empty"><i class="bi bi-people"></i>No approved registrations for this hearing.</div><?php endif; ?>
<?php foreach($rows as $r): ?>
<div class="lphx-roster-row">
<div><strong><?= e($r['full_name']) ?></strong><div class="small text-muted"><?= e($r['organization']?:$r['email']) ?><br><span class="lphx-code"><?= e($r['registration_code']) ?></span></div></div>
<div><span class="badge text-bg-<?= $r['attendance_status']==='Present'?'success':($r['attendance_status']==='Absent'?'danger':($r['attendance_status']==='Excused'?'secondary':'light')) ?>"><?= e($r['attendance_status']?:'Not Marked') ?></span></div>
<div class="small"><?= $r['checked_in_at']?formatDateTime($r['checked_in_at']):'—' ?><br><span class="text-muted"><?= e($r['check_in_method']?:'') ?></span></div>
<div class="btn-group btn-group-sm">
<button class="btn btn-outline-success att-action" data-id="<?= (int)$r['registration_id'] ?>" data-action="check_in">In</button>
<button class="btn btn-outline-primary att-action" data-id="<?= (int)$r['registration_id'] ?>" data-action="check_out">Out</button>
<button class="btn btn-outline-danger att-action" data-id="<?= (int)$r['registration_id'] ?>" data-action="absent">Absent</button>
<button class="btn btn-outline-secondary att-action" data-id="<?= (int)$r['registration_id'] ?>" data-action="excused">Excused</button>
</div>
</div>
<?php endforeach; ?>
</div></div>
<?php else: ?>
<div class="lphx-empty"><i class="bi bi-calendar-check"></i>Select a hearing to open its attendance roster.</div>
<?php endif; ?>
</div></div>

<?php if($hearing): ?>
<script>
document.addEventListener('DOMContentLoaded',function(){
 const csrf='<?= e(csrfToken()) ?>';
 async function act(id,action,method='Manual'){
  const fd=new FormData();fd.append('csrf_token',csrf);fd.append('registration_id',id);fd.append('action',action);fd.append('check_in_method',method);
  const r=await fetch(APP_URL+'/modules/attendance/ajax_attendance_action.php',{method:'POST',body:fd}).then(x=>x.json());
  if(r.success){appToast('success',r.message);setTimeout(()=>location.reload(),350);}else Swal.fire('Attendance Error',r.message,'error');
 }
 document.querySelectorAll('.att-action').forEach(b=>b.onclick=()=>act(b.dataset.id,b.dataset.action,'Manual'));
 let found=null;
 async function findCode(){const code=scanCode.value.trim();if(!code)return;const r=await appGet(APP_URL+'/modules/attendance/ajax_scan.php?code='+encodeURIComponent(code));
  if(r.success){found=r.registration;scanResult.innerHTML='<strong>'+found.full_name+'</strong> · '+found.hearing_title+' · '+found.registration_code;btnScanCheckIn.disabled=false;}else{found=null;btnScanCheckIn.disabled=true;scanResult.textContent=r.message;}}
 btnFindCode.onclick=findCode;scanCode.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();findCode();}});
 btnScanCheckIn.onclick=()=>{if(found)act(found.registration_id,'check_in','QR');};
});
</script>
<?php endif; ?>
<?php include __DIR__.'/../../layouts/footer.php'; ?>
