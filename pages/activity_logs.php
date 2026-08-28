<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requirePermission('lph.activity_logs.view');

$pageTitle='Activity Logs';
$activeMenu='activity_logs';
$pdo=db();

$search=clean($_GET['search']??'');
$userId=(int)($_GET['user_id']??0);
$action=clean($_GET['action']??'');
$scope=clean($_GET['scope']??'lph');
$dateFrom=clean($_GET['date_from']??'');
$dateTo=clean($_GET['date_to']??'');
$page=max(1,(int)($_GET['page']??1));
$perPage=30;

$where=[];$params=[];

if($scope==='lph'){
    $where[]='al.system_id=:system';
    $params[':system']=lphSystemId();
}elseif($scope==='legacy'){
    $where[]='al.system_id IS NULL';
}

if($search!==''){
    $where[]='(al.details LIKE :s1 OR al.action LIKE :s2 OR u.full_name LIKE :s3 OR al.ip_address LIKE :s4)';
    $like='%'.$search.'%';
    $params[':s1']=$like;$params[':s2']=$like;$params[':s3']=$like;$params[':s4']=$like;
}
if($userId>0){$where[]='al.user_id=:user';$params[':user']=$userId;}
if($action!==''){$where[]='al.action=:action';$params[':action']=$action;}
if($dateFrom!==''){$where[]='DATE(al.created_at)>=:df';$params[':df']=$dateFrom;}
if($dateTo!==''){$where[]='DATE(al.created_at)<=:dt';$params[':dt']=$dateTo;}

$whereSql=$where?'WHERE '.implode(' AND ',$where):'';

$c=$pdo->prepare(
 "SELECT COUNT(*)
  FROM activity_logs al
  LEFT JOIN users u ON u.id=al.user_id
  {$whereSql}"
);
$c->execute($params);
$total=(int)$c->fetchColumn();

$offset=($page-1)*$perPage;

$stmt=$pdo->prepare(
 "SELECT al.*,u.full_name,u.email,s.name system_name
  FROM activity_logs al
  LEFT JOIN users u ON u.id=al.user_id
  LEFT JOIN systems s ON s.id=al.system_id
  {$whereSql}
  ORDER BY al.created_at DESC,al.id DESC
  LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute($params);
$rows=$stmt->fetchAll();

$users=$pdo->query('SELECT id,full_name FROM users ORDER BY full_name')->fetchAll();
$actions=$pdo->query('SELECT DISTINCT action FROM activity_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);

$statsStmt=$pdo->prepare(
 "SELECT
    COUNT(*) total,
    SUM(DATE(created_at)=CURDATE()) today_logs,
    SUM(action LIKE '%Failed%' OR action LIKE '%Denied%' OR action LIKE '%Rate Limited%') security_events,
    SUM(action LIKE '%Delete%' OR action LIKE '%Deactivate%') destructive_events
  FROM activity_logs
  WHERE system_id=:system"
);
$statsStmt->execute([':system'=>lphSystemId()]);
$summary=$statsStmt->fetch();

$query=$_GET;
unset($query['page']);

include __DIR__.'/../layouts/header.php';
?>
<link rel="stylesheet" href="<?= e(APP_URL.'/assets/css/lph-admin-final.css') ?>">
<div class="app-wrapper"><?php include __DIR__.'/../layouts/sidebar.php'; ?><div class="main-content">
<?php include __DIR__ . '/../layouts/top_controls.php'; ?>

<div class="lpha-head">
<div><div class="lpha-eyebrow"><i class="bi bi-clock-history"></i> Step 9 · Audit Trail</div><h1>Activity Logs</h1><p>Search the subsystem audit trail with user, action, date, system, IP and browser context. New LPH events are explicitly tagged with system_id.</p></div>
<div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="activity_logs_print.php?<?= e(http_build_query($_GET)) ?>" target="_blank"><i class="bi bi-printer"></i> Print</a><a class="btn btn-primary" href="activity_logs_export.php?<?= e(http_build_query($_GET)) ?>"><i class="bi bi-filetype-csv"></i> CSV</a></div>
</div>

<div class="row g-3 mb-3">
<?php foreach([
 ['LPH Logs',$summary['total']??0,'bi-journal-text'],
 ['Today',$summary['today_logs']??0,'bi-calendar-day'],
 ['Security Events',$summary['security_events']??0,'bi-shield-exclamation'],
 ['Delete / Deactivate',$summary['destructive_events']??0,'bi-trash3'],
] as [$label,$value,$icon]): ?>
<div class="col-6 col-lg-3"><div class="lpha-stat"><i class="bi <?= e($icon) ?>"></i><div><strong><?= (int)$value ?></strong><small><?= e($label) ?></small></div></div></div>
<?php endforeach; ?>
</div>

<div class="card lpha-card mb-3"><div class="card-body">
<form class="row g-2 align-items-end">
<div class="col-xl-3"><label class="form-label small">Search</label><input class="form-control form-control-sm" name="search" value="<?= e($search) ?>" placeholder="Action, details, user or IP"></div>
<div class="col-xl-2"><label class="form-label small">User</label><select class="form-select form-select-sm" name="user_id"><option value="">All</option><?php foreach($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $userId===(int)$u['id']?'selected':'' ?>><?= e($u['full_name']) ?></option><?php endforeach; ?></select></div>
<div class="col-xl-2"><label class="form-label small">Action</label><select class="form-select form-select-sm" name="action"><option value="">All</option><?php foreach($actions as $a): ?><option <?= $action===$a?'selected':'' ?>><?= e($a) ?></option><?php endforeach; ?></select></div>
<div class="col-xl-1"><label class="form-label small">Scope</label><select class="form-select form-select-sm" name="scope"><option value="lph" <?= $scope==='lph'?'selected':'' ?>>LPH</option><option value="legacy" <?= $scope==='legacy'?'selected':'' ?>>Legacy</option><option value="all" <?= $scope==='all'?'selected':'' ?>>All</option></select></div>
<div class="col-xl-1"><label class="form-label small">From</label><input type="date" class="form-control form-control-sm" name="date_from" value="<?= e($dateFrom) ?>"></div>
<div class="col-xl-1"><label class="form-label small">To</label><input type="date" class="form-control form-control-sm" name="date_to" value="<?= e($dateTo) ?>"></div>
<div class="col-xl-2"><button class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-funnel"></i> Apply Filters</button></div>
</form>
</div></div>

<div class="card lpha-card">
<div class="card-header d-flex justify-content-between"><span>Audit Register</span><span><?= $total ?> matching event(s)</span></div>
<div class="table-responsive">
<table class="table table-hover lpha-table mb-0">
<thead><tr><th>Date / User</th><th>Action</th><th>Details</th><th>System</th><th>Request Context</th></tr></thead>
<tbody>
<?php if(!$rows): ?><tr><td colspan="5" class="text-center text-muted py-5">No audit events match the filters.</td></tr><?php endif; ?>
<?php foreach($rows as $r): ?>
<tr>
<td><?= formatDateTime($r['created_at']) ?><div class="small text-muted"><?= e($r['full_name']?:'Anonymous / System') ?><?= $r['email']?' · '.e($r['email']):'' ?></div></td>
<td><span class="badge text-bg-light"><?= e($r['action']) ?></span></td>
<td><?= e($r['details']?:'—') ?></td>
<td><?= e($r['system_name']?:($r['system_id']?'System #'.$r['system_id']:'Legacy / Unassigned')) ?></td>
<td><div class="lpha-code"><?= e($r['ip_address']?:'No IP recorded') ?></div><div class="lpha-agent" title="<?= e($r['user_agent']?:'') ?>"><?= e($r['user_agent']?:'No user-agent recorded') ?></div></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php
$totalPages=max(1,(int)ceil($total/$perPage));
if($totalPages>1):
?>
<div class="card-footer d-flex justify-content-between align-items-center">
<span class="small text-muted">Page <?= $page ?> of <?= $totalPages ?></span>
<div class="btn-group btn-group-sm">
<?php if($page>1): $prev=$query;$prev['page']=$page-1; ?><a class="btn btn-outline-secondary" href="?<?= e(http_build_query($prev)) ?>">Previous</a><?php endif; ?>
<?php if($page<$totalPages): $next=$query;$next['page']=$page+1; ?><a class="btn btn-outline-secondary" href="?<?= e(http_build_query($next)) ?>">Next</a><?php endif; ?>
</div>
</div>
<?php endif; ?>
</div>

</div></div>
<?php include __DIR__.'/../layouts/footer.php'; ?>
