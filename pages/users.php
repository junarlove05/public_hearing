<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requirePermission('lph.users.manage');

$pageTitle='User Management';
$activeMenu='users';
$pdo=db();
$systemId=lphSystemId();

$search=clean($_GET['search']??'');
$roleFilter=(int)($_GET['role_id']??0);
$statusFilter=clean($_GET['status']??'');
$accessFilter=clean($_GET['access']??'');

$where=['u.deleted_at IS NULL'];
$params=[':system'=>$systemId];

if($search!==''){
    $where[]='(u.full_name LIKE :s1 OR u.email LIKE :s2 OR u.username LIKE :s3)';
    $like='%'.$search.'%';
    $params[':s1']=$like;$params[':s2']=$like;$params[':s3']=$like;
}
if($roleFilter>0){$where[]='u.role_id=:role';$params[':role']=$roleFilter;}
if($statusFilter!==''){$where[]='u.status=:status';$params[':status']=$statusFilter;}
if($accessFilter!==''){$where[]="COALESCE(usa.status,'Legacy')=:access";$params[':access']=$accessFilter;}

$sql="
SELECT
    u.id,u.username,u.full_name,u.email,u.phone,u.role_id,u.office_id,u.status,
    u.created_at,u.last_login_at,u.updated_at,
    r.name role_name,o.name office_name,
    usa.id system_access_id,
    COALESCE(usa.status,'Legacy') lph_access_status,
    COALESCE(usa.access_level,'Legacy / Inherited') lph_access_level,
    (
      SELECT COUNT(*)
      FROM role_permissions rp
      JOIN permissions p ON p.id=rp.permission_id
      WHERE rp.role_id=u.role_id AND p.code LIKE 'lph.%'
    ) lph_permission_count
FROM users u
JOIN roles r ON r.id=u.role_id
LEFT JOIN offices o ON o.id=u.office_id
LEFT JOIN user_system_access usa
       ON usa.user_id=u.id
      AND usa.system_id=:system
WHERE ".implode(' AND ',$where)."
ORDER BY u.full_name,u.id
";

$stmt=$pdo->prepare($sql);
$stmt->execute($params);
$users=$stmt->fetchAll();

$roles=$pdo->query('SELECT id,name FROM roles ORDER BY id')->fetchAll();
$offices=$pdo->query("SELECT id,name FROM offices WHERE status='Active' ORDER BY name")->fetchAll();

$stats=$pdo->prepare(
 "SELECT
    COUNT(*) total,
    SUM(u.status='Active') active_users,
    SUM(COALESCE(usa.status,'Legacy')='Active') explicit_access,
    SUM(COALESCE(usa.status,'Legacy')='Inactive') denied_access
  FROM users u
  LEFT JOIN user_system_access usa
    ON usa.user_id=u.id AND usa.system_id=:system
  WHERE u.deleted_at IS NULL"
);
$stats->execute([':system'=>$systemId]);
$summary=$stats->fetch();

include __DIR__.'/../layouts/header.php';
?>
<link rel="stylesheet" href="<?= e(APP_URL.'/assets/css/lph-admin-final.css') ?>">

<div class="app-wrapper">
<?php include __DIR__.'/../layouts/sidebar.php'; ?>
<div class="main-content">
<?php include __DIR__ . '/../layouts/top_controls.php'; ?>

<div class="lpha-head">
  <div>
    <div class="lpha-eyebrow"><i class="bi bi-person-gear"></i> Step 9 · Administration</div>
    <h1>User Management</h1>
    <p>Administer shared legislative users while explicitly controlling Public Hearing subsystem access, role, office and account status.</p>
  </div>
  <button class="btn btn-primary" id="btnNewUser"><i class="bi bi-person-plus"></i> New User</button>
</div>

<div class="row g-3 mb-3">
<?php foreach([
 ['Users',$summary['total']??0,'bi-people'],
 ['Active Accounts',$summary['active_users']??0,'bi-person-check'],
 ['Explicit LPH Access',$summary['explicit_access']??0,'bi-shield-check'],
 ['LPH Access Denied',$summary['denied_access']??0,'bi-shield-x'],
] as [$label,$value,$icon]): ?>
<div class="col-6 col-lg-3"><div class="lpha-stat"><i class="bi <?= e($icon) ?>"></i><div><strong><?= (int)$value ?></strong><small><?= e($label) ?></small></div></div></div>
<?php endforeach; ?>
</div>

<div class="card lpha-card mb-3">
<div class="card-body">
<form class="row g-2 align-items-end" method="get">
 <div class="col-lg-4"><label class="form-label small">Search</label><input class="form-control form-control-sm" name="search" value="<?= e($search) ?>" placeholder="Name, email or username"></div>
 <div class="col-lg-2"><label class="form-label small">Role</label><select class="form-select form-select-sm" name="role_id"><option value="">All roles</option><?php foreach($roles as $r): ?><option value="<?= (int)$r['id'] ?>" <?= $roleFilter===(int)$r['id']?'selected':'' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
 <div class="col-lg-2"><label class="form-label small">Account</label><select class="form-select form-select-sm" name="status"><option value="">All</option><option <?= $statusFilter==='Active'?'selected':'' ?>>Active</option><option <?= $statusFilter==='Inactive'?'selected':'' ?>>Inactive</option></select></div>
 <div class="col-lg-2"><label class="form-label small">LPH Access</label><select class="form-select form-select-sm" name="access"><option value="">All</option><option <?= $accessFilter==='Active'?'selected':'' ?>>Active</option><option <?= $accessFilter==='Inactive'?'selected':'' ?>>Inactive</option><option <?= $accessFilter==='Legacy'?'selected':'' ?>>Legacy</option></select></div>
 <div class="col-lg-2"><button class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-search"></i> Filter</button></div>
</form>
</div>
</div>

<div class="card lpha-card">
<div class="card-header d-flex justify-content-between"><span><i class="bi bi-person-vcard"></i> User Registry</span><span><?= count($users) ?> result(s)</span></div>
<div class="table-responsive">
<table class="table table-hover lpha-table mb-0">
<thead><tr><th>User</th><th>Role / Office</th><th>Account</th><th>LPH Access</th><th>Permissions</th><th>Last Login</th><th class="text-end">Actions</th></tr></thead>
<tbody>
<?php if(!$users): ?><tr><td colspan="7" class="text-center text-muted py-5">No users match the filters.</td></tr><?php endif; ?>
<?php foreach($users as $u): ?>
<tr>
<td><strong><?= e($u['full_name']) ?></strong><?php if((int)$u['id']===(int)currentUserId()): ?> <span class="badge text-bg-warning">You</span><?php endif; ?><div class="small text-muted"><?= e($u['email']) ?><?= $u['username']?' · @'.e($u['username']):'' ?></div></td>
<td><?= e($u['role_name']) ?><div class="small text-muted"><?= e($u['office_name']?:'No office assigned') ?></div></td>
<td><span class="badge text-bg-<?= $u['status']==='Active'?'success':'secondary' ?>"><?= e($u['status']) ?></span></td>
<td><span class="lpha-access <?= $u['lph_access_status']==='Active'?'active':'inactive' ?>"><i class="bi bi-shield-check"></i><?= e($u['lph_access_status']) ?></span><div class="small text-muted mt-1"><?= e($u['lph_access_level']) ?></div></td>
<td><strong><?= (int)$u['lph_permission_count'] ?></strong><div class="small text-muted">from role</div></td>
<td><?= $u['last_login_at']?formatDateTime($u['last_login_at']):'Never' ?></td>
<td class="text-end"><div class="btn-group btn-group-sm">
<button class="btn btn-outline-primary btn-edit-user" data-id="<?= (int)$u['id'] ?>"><i class="bi bi-pencil"></i></button>
<button class="btn btn-outline-danger btn-delete-user" data-id="<?= (int)$u['id'] ?>" data-name="<?= e($u['full_name']) ?>"><i class="bi bi-person-x"></i></button>
</div></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>

</div></div>

<div class="modal fade" id="userModal" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
<form id="userForm">
<?= csrfField() ?><input type="hidden" name="id" id="u_id" value="0">
<div class="modal-header bg-dark text-white"><h5 class="modal-title"><i class="bi bi-person-gear"></i> User Account</h5><button class="btn-close btn-close-white" type="button" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<div class="row g-3">
<div class="col-md-6"><label class="form-label">Full Name *</label><input class="form-control" name="full_name" id="u_name" required></div>
<div class="col-md-6"><label class="form-label">Username</label><input class="form-control" name="username" id="u_username" maxlength="100"></div>
<div class="col-md-6"><label class="form-label">Email *</label><input type="email" class="form-control" name="email" id="u_email" required></div>
<div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="phone" id="u_phone"></div>
<div class="col-md-6"><label class="form-label">Role *</label><select class="form-select" name="role_id" id="u_role" required><?php foreach($roles as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-6"><label class="form-label">Office</label><select class="form-select" name="office_id" id="u_office"><option value="">No office</option><?php foreach($offices as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['name']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><label class="form-label">Account Status</label><select class="form-select" name="status" id="u_status"><option>Active</option><option>Inactive</option></select></div>
<div class="col-md-4"><label class="form-label">LPH Access</label><select class="form-select" name="lph_access_status" id="u_access"><option>Active</option><option>Inactive</option></select></div>
<div class="col-md-4"><label class="form-label">Access Level</label><select class="form-select" name="access_level" id="u_level"><option>Administrator</option><option>Staff</option><option>Committee</option><option>Stakeholder</option><option>Standard</option></select></div>
<div class="col-12"><label class="form-label">Password <span class="text-muted">(required for new users; leave blank when editing to keep current password)</span></label><input type="password" class="form-control" name="password" id="u_password" autocomplete="new-password"><div class="form-text">New passwords must contain at least 10 characters with uppercase, lowercase and a number.</div></div>
</div>
</div>
<div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit" id="btnSaveUser">Save User</button></div>
</form>
</div></div></div>

<script>
document.addEventListener('DOMContentLoaded',function(){
 const modal=new bootstrap.Modal(document.getElementById('userModal'));
 const form=document.getElementById('userForm');

 function clearForm(){form.reset();u_id.value='0';u_status.value='Active';u_access.value='Active';u_level.value='Standard';u_password.required=true;}
 btnNewUser.onclick=function(){clearForm();modal.show();};

 document.querySelectorAll('.btn-edit-user').forEach(btn=>btn.onclick=async function(){
   const r=await appGet(APP_URL+'/pages/ajax_user_get.php?id='+encodeURIComponent(this.dataset.id));
   if(!r.success){if(!r.session_expired)Swal.fire('User Error',r.message,'error');return;}
   const u=r.user;
   u_id.value=u.id;u_name.value=u.full_name||'';u_username.value=u.username||'';u_email.value=u.email||'';u_phone.value=u.phone||'';
   u_role.value=u.role_id||'';u_office.value=u.office_id||'';u_status.value=u.status||'Active';
   u_access.value=u.lph_access_status||'Active';u_level.value=u.access_level||'Standard';u_password.value='';u_password.required=false;
   modal.show();
 });

 form.onsubmit=async function(e){
   e.preventDefault();
   btnSaveUser.disabled=true;
   const r=await appPostForm(APP_URL+'/pages/ajax_user_save.php',form);
   btnSaveUser.disabled=false;
   if(r.success){appToast('success',r.message);setTimeout(()=>location.reload(),350);}
   else if(!r.session_expired)Swal.fire('Unable to Save User',r.message,'error');
 };

 document.querySelectorAll('.btn-delete-user').forEach(btn=>btn.onclick=async function(){
   const ask=await Swal.fire({title:'Deactivate user?',text:'This keeps audit history and shared records intact. The account and LPH access will be disabled.',icon:'warning',showCancelButton:true,confirmButtonText:'Deactivate'});
   if(!ask.isConfirmed)return;
   const fd=new FormData();fd.append('csrf_token','<?= e(csrfToken()) ?>');fd.append('id',this.dataset.id);
   const r=await fetch(APP_URL+'/pages/ajax_user_delete.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(x=>x.json());
   if(r.success){appToast('success',r.message);setTimeout(()=>location.reload(),350);}else Swal.fire('Unable to Deactivate',r.message,'error');
 });
});
</script>
<?php include __DIR__.'/../layouts/footer.php'; ?>
