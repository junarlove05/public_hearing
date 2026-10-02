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

<?php
// Group users by role and status
$pendingUsers = [];
$inactiveUsers = [];
$adminUsers = [];
$staffUsers = [];
$committeeUsers = [];
$publicUsers = [];
$otherUsers = [];

foreach ($users as $u) {
    $uStatus = strtolower(trim((string)($u['status'] ?? '')));
    $accessStatus = strtolower(trim((string)($u['lph_access_status'] ?? '')));
    $roleName = strtolower(trim((string)($u['role_name'] ?? '')));

    if ($uStatus === 'pending' || $accessStatus === 'pending') {
        $pendingUsers[] = $u;
    } elseif ($uStatus === 'inactive' || $accessStatus === 'inactive') {
        $inactiveUsers[] = $u;
    } elseif (stripos($roleName, 'admin') !== false) {
        $adminUsers[] = $u;
    } elseif (stripos($roleName, 'staff') !== false) {
        $staffUsers[] = $u;
    } elseif (stripos($roleName, 'committee') !== false) {
        $committeeUsers[] = $u;
    } elseif (stripos($roleName, 'public') !== false || stripos($roleName, 'stakeholder') !== false) {
        $publicUsers[] = $u;
    } else {
        $otherUsers[] = $u;
    }
}

if (!function_exists('renderUserCard')) {
    function renderUserCard(string $title, string $icon, string $badgeClass, array $list, string $emptyMsg): void {
        ?>
        <div class="card lpha-card mb-4 shadow-sm">
          <div class="card-header d-flex justify-content-between align-items-center py-2.5 px-3 bg-white">
            <span class="d-flex align-items-center gap-2 fw-bold text-dark">
              <i class="bi <?= e($icon) ?> fs-5"></i> <?= e($title) ?>
            </span>
            <span class="badge <?= e($badgeClass) ?> px-2.5 py-1.5"><?= count($list) ?> result(s)</span>
          </div>
          <div class="table-responsive">
            <table class="table table-hover lpha-table mb-0 align-middle">
              <thead>
                <tr>
                  <th>User</th>
                  <th>Role / Office</th>
                  <th>Account</th>
                  <th>LPH Access</th>
                  <th>Permissions</th>
                  <th>Last Login</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($list)): ?>
                  <tr><td colspan="7" class="text-center text-muted py-4 fst-italic"><?= e($emptyMsg) ?></td></tr>
                <?php else: ?>
                  <?php foreach ($list as $u): ?>
                  <tr>
                    <td>
                      <strong><?= e($u['full_name']) ?></strong>
                      <?php if ((int)$u['id'] === (int)currentUserId()): ?>
                        <span class="badge text-bg-warning ms-1">You</span>
                      <?php endif; ?>
                      <div class="small text-muted"><?= e($u['email']) ?><?= $u['username'] ? ' · @' . e($u['username']) : '' ?></div>
                    </td>
                    <td>
                      <span class="fw-semibold text-dark"><?= e($u['role_name']) ?></span>
                      <div class="small text-muted"><?= e($u['office_name'] ?: 'No office assigned') ?></div>
                    </td>
                    <td>
                      <span class="badge text-bg-<?= $u['status'] === 'Active' ? 'success' : ($u['status'] === 'Pending' ? 'warning' : 'secondary') ?>">
                        <?= e($u['status']) ?>
                      </span>
                    </td>
                    <td>
                      <span class="lpha-access <?= $u['lph_access_status'] === 'Active' ? 'active' : ($u['lph_access_status'] === 'Pending' ? 'pending' : 'inactive') ?>">
                        <i class="bi bi-shield-check"></i><?= e($u['lph_access_status']) ?>
                      </span>
                      <div class="small text-muted mt-1"><?= e($u['lph_access_level']) ?></div>
                    </td>
                    <td>
                      <strong><?= (int)$u['lph_permission_count'] ?></strong>
                      <div class="small text-muted">from role</div>
                    </td>
                    <td class="small"><?= $u['last_login_at'] ? formatDateTime($u['last_login_at']) : '<span class="text-muted">Never</span>' ?></td>
                    <td class="text-end">
                      <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-primary btn-edit-user" data-id="<?= (int)$u['id'] ?>" title="Edit User">
                          <i class="bi bi-pencil"></i>
                        </button>
                      </div>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php
    }
}
?>

<!-- Role & Status Category Navigation Tabs -->
<div class="mb-3">
  <ul class="nav nav-pills gap-1 bg-white p-2 border rounded shadow-sm flex-wrap" id="userPillTabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link active fw-semibold py-1.5 px-3" id="tab-all-link" data-bs-toggle="pill" data-bs-target="#tab-all-content" type="button" role="tab">
        <i class="bi bi-grid-fill me-1 text-primary"></i> All Tables <span class="badge bg-dark ms-1"><?= count($users) ?></span>
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link fw-semibold py-1.5 px-3" id="tab-admin-link" data-bs-toggle="pill" data-bs-target="#tab-admin-content" type="button" role="tab">
        <i class="bi bi-shield-shaded me-1 text-dark"></i> Administrators <span class="badge bg-dark ms-1"><?= count($adminUsers) ?></span>
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link fw-semibold py-1.5 px-3" id="tab-staff-link" data-bs-toggle="pill" data-bs-target="#tab-staff-content" type="button" role="tab">
        <i class="bi bi-briefcase me-1 text-primary"></i> Legislative Staff <span class="badge bg-primary ms-1"><?= count($staffUsers) ?></span>
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link fw-semibold py-1.5 px-3" id="tab-committee-link" data-bs-toggle="pill" data-bs-target="#tab-committee-content" type="button" role="tab">
        <i class="bi bi-people me-1 text-success"></i> Committee Members <span class="badge bg-success ms-1"><?= count($committeeUsers) ?></span>
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link fw-semibold py-1.5 px-3" id="tab-public-link" data-bs-toggle="pill" data-bs-target="#tab-public-content" type="button" role="tab">
        <i class="bi bi-globe me-1 text-info"></i> Public Users <span class="badge bg-info text-dark ms-1"><?= count($publicUsers) ?></span>
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link fw-semibold py-1.5 px-3" id="tab-pending-link" data-bs-toggle="pill" data-bs-target="#tab-pending-content" type="button" role="tab">
        <i class="bi bi-hourglass-split me-1 text-warning"></i> Pending <span class="badge bg-warning text-dark ms-1"><?= count($pendingUsers) ?></span>
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link fw-semibold py-1.5 px-3" id="tab-inactive-link" data-bs-toggle="pill" data-bs-target="#tab-inactive-content" type="button" role="tab">
        <i class="bi bi-person-x me-1 text-danger"></i> Inactive <span class="badge bg-danger ms-1"><?= count($inactiveUsers) ?></span>
      </button>
    </li>
  </ul>
</div>

<div class="tab-content" id="userPillTabsContent">
  <!-- 1. All Separated Tables Tab -->
  <div class="tab-pane fade show active" id="tab-all-content" role="tabpanel">
    <?php renderUserCard('Administrators', 'bi-shield-shaded text-dark', 'bg-dark', $adminUsers, 'No active administrators found.'); ?>
    <?php renderUserCard('Legislative Staff', 'bi-briefcase text-primary', 'bg-primary', $staffUsers, 'No active legislative staff found.'); ?>
    <?php renderUserCard('Committee Members', 'bi-people text-success', 'bg-success', $committeeUsers, 'No active committee members found.'); ?>
    <?php renderUserCard('Public Users & Stakeholders', 'bi-globe text-info', 'bg-info text-dark', $publicUsers, 'No active public users or stakeholders found.'); ?>
    
    <?php if (!empty($otherUsers)): ?>
      <?php renderUserCard('Other Roles', 'bi-person-badge text-secondary', 'bg-secondary', $otherUsers, 'No other users found.'); ?>
    <?php endif; ?>

    <?php renderUserCard('Pending Verification & Access', 'bi-hourglass-split text-warning', 'bg-warning text-dark', $pendingUsers, 'No pending user registrations or pending access requests.'); ?>
    <?php renderUserCard('Inactive Accounts', 'bi-person-x text-danger', 'bg-danger', $inactiveUsers, 'No inactive or deactivated user accounts.'); ?>
  </div>

  <!-- 2. Administrators Tab -->
  <div class="tab-pane fade" id="tab-admin-content" role="tabpanel">
    <?php renderUserCard('Administrators', 'bi-shield-shaded text-dark', 'bg-dark', $adminUsers, 'No active administrators found.'); ?>
  </div>

  <!-- 3. Legislative Staff Tab -->
  <div class="tab-pane fade" id="tab-staff-content" role="tabpanel">
    <?php renderUserCard('Legislative Staff', 'bi-briefcase text-primary', 'bg-primary', $staffUsers, 'No active legislative staff found.'); ?>
  </div>

  <!-- 4. Committee Members Tab -->
  <div class="tab-pane fade" id="tab-committee-content" role="tabpanel">
    <?php renderUserCard('Committee Members', 'bi-people text-success', 'bg-success', $committeeUsers, 'No active committee members found.'); ?>
  </div>

  <!-- 5. Public Users Tab -->
  <div class="tab-pane fade" id="tab-public-content" role="tabpanel">
    <?php renderUserCard('Public Users & Stakeholders', 'bi-globe text-info', 'bg-info text-dark', $publicUsers, 'No active public users or stakeholders found.'); ?>
  </div>

  <!-- 6. Pending Accounts Tab -->
  <div class="tab-pane fade" id="tab-pending-content" role="tabpanel">
    <?php renderUserCard('Pending Verification & Access', 'bi-hourglass-split text-warning', 'bg-warning text-dark', $pendingUsers, 'No pending user registrations or pending access requests.'); ?>
  </div>

  <!-- 7. Inactive Accounts Tab -->
  <div class="tab-pane fade" id="tab-inactive-content" role="tabpanel">
    <?php renderUserCard('Inactive Accounts', 'bi-person-x text-danger', 'bg-danger', $inactiveUsers, 'No inactive or deactivated user accounts.'); ?>
  </div>
</div>

</div></div>

<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width: 800px;">
    <div class="modal-content border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
      <form id="userForm">
        <?= csrfField() ?>
        <input type="hidden" name="id" id="u_id" value="0">
        
        <div class="modal-header py-3 px-4 bg-light border-bottom">
          <div class="d-flex align-items-center gap-2.5">
            <div class="rounded-circle d-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary" style="width: 36px; height: 36px;">
              <i class="bi bi-person-gear fs-5"></i>
            </div>
            <div>
              <h5 class="modal-title fw-bold text-dark mb-0" style="font-size: 1.05rem;">User Account Management</h5>
              <small class="text-muted" style="font-size: 0.8rem;">Configure legislative user identity and LPH access</small>
            </div>
          </div>
          <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body p-4 bg-white">
          <!-- Section 1: User Identity -->
          <div class="d-flex align-items-center gap-2 mb-2 pb-1 border-bottom">
            <span class="badge bg-secondary-subtle text-secondary" style="font-size: 0.68rem; letter-spacing: 0.5px;">ACCOUNT IDENTITY</span>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1" style="font-size: 0.76rem;">Full Name *</label>
              <input class="form-control form-control-sm" name="full_name" id="u_name" placeholder="Juan Dela Cruz" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1" style="font-size: 0.76rem;">Username</label>
              <input class="form-control form-control-sm" name="username" id="u_username" maxlength="100" placeholder="jdelacruz">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1" style="font-size: 0.76rem;">Email *</label>
              <input type="email" class="form-control form-control-sm" name="email" id="u_email" placeholder="user@manila.gov.ph" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1" style="font-size: 0.76rem;">Phone</label>
              <input class="form-control form-control-sm" name="phone" id="u_phone" placeholder="0917-000-0000">
            </div>
          </div>

          <!-- Section 2: Role & Subsystem Access -->
          <div class="d-flex align-items-center gap-2 mb-2 pb-1 border-bottom">
            <span class="badge bg-primary-subtle text-primary" style="font-size: 0.68rem; letter-spacing: 0.5px;">ROLE & ACCESS PERMISSIONS</span>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1" style="font-size: 0.76rem;">Role *</label>
              <select class="form-select form-select-sm" name="role_id" id="u_role" required>
                <?php foreach($roles as $r): ?>
                  <option value="<?= (int)$r['id'] ?>"><?= e($r['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1" style="font-size: 0.76rem;">Office</label>
              <select class="form-select form-select-sm" name="office_id" id="u_office">
                <option value="">No office</option>
                <?php foreach($offices as $o): ?>
                  <option value="<?= (int)$o['id'] ?>"><?= e($o['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold text-secondary mb-1" style="font-size: 0.76rem;">Account Status</label>
              <select class="form-select form-select-sm" name="status" id="u_status">
                <option>Active</option>
                <option>Inactive</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold text-secondary mb-1" style="font-size: 0.76rem;">LPH Access</label>
              <select class="form-select form-select-sm" name="lph_access_status" id="u_access">
                <option>Active</option>
                <option>Inactive</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold text-secondary mb-1" style="font-size: 0.76rem;">Access Level</label>
              <select class="form-select form-select-sm" name="access_level" id="u_level">
                <option>Administrator</option>
                <option>Staff</option>
                <option>Committee</option>
                <option>Stakeholder</option>
                <option>Standard</option>
              </select>
            </div>
          </div>

          <!-- Section 3: Password -->
          <div class="p-3 rounded-3 bg-light border">
            <div class="d-flex justify-content-between align-items-center mb-1">
              <label class="form-label small fw-semibold text-secondary mb-0" style="font-size: 0.76rem;">Password</label>
              <span class="text-muted" style="font-size: 0.7rem;">Leave blank on edit to keep current password</span>
            </div>
            <input type="password" class="form-control form-control-sm" name="password" id="u_password" autocomplete="new-password" placeholder="Enter password (min. 10 chars)">
            <div class="text-muted mt-1" style="font-size: 0.68rem;">New passwords must contain at least 10 characters with uppercase, lowercase, and a number.</div>
          </div>
        </div>

        <!-- Clean Footer -->
        <div class="modal-footer py-2.5 px-4 bg-light border-top d-flex justify-content-end gap-2">
          <button class="btn btn-light border px-3" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary px-4 fw-semibold shadow-sm" type="submit" id="btnSaveUser">
            <i class="bi bi-check2-circle me-1"></i> Save User
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

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
});
</script>
<?php include __DIR__.'/../layouts/footer.php'; ?>
