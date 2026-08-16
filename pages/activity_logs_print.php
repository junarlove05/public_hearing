<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requirePermission('lph.activity_logs.view');

$pdo=db();
$scope=clean($_GET['scope']??'lph');
$dateFrom=clean($_GET['date_from']??'');
$dateTo=clean($_GET['date_to']??'');

$where=[];$params=[];
if($scope==='lph'){$where[]='al.system_id=:system';$params[':system']=lphSystemId();}
elseif($scope==='legacy'){$where[]='al.system_id IS NULL';}
if($dateFrom!==''){$where[]='DATE(al.created_at)>=:df';$params[':df']=$dateFrom;}
if($dateTo!==''){$where[]='DATE(al.created_at)<=:dt';$params[':dt']=$dateTo;}
$whereSql=$where?'WHERE '.implode(' AND ',$where):'';

$stmt=$pdo->prepare(
 "SELECT al.created_at,u.full_name,al.action,al.details,s.name system_name,al.ip_address
  FROM activity_logs al
  LEFT JOIN users u ON u.id=al.user_id
  LEFT JOIN systems s ON s.id=al.system_id
  {$whereSql}
  ORDER BY al.created_at DESC
  LIMIT 2000"
);
$stmt->execute($params);
$rows=$stmt->fetchAll();
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>LPH Activity Logs</title>
<style>body{font:11px Arial;margin:25px;color:#111827}.head{text-align:center;border-bottom:3px solid #0f2137;padding-bottom:12px;margin-bottom:15px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #d1d5db;padding:5px;vertical-align:top}th{background:#f3f4f6}</style></head>
<body onload="window.print()">
<div class="head"><strong><?= e(APP_NAME) ?></strong><h2>Activity Log Report</h2><div>Generated <?= date('F j, Y g:i A') ?></div></div>
<table><thead><tr><th>Date/Time</th><th>User</th><th>Action</th><th>Details</th><th>System</th><th>IP</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr><td><?= e($r['created_at']) ?></td><td><?= e($r['full_name']?:'Anonymous / System') ?></td><td><?= e($r['action']) ?></td><td><?= e($r['details']?:'') ?></td><td><?= e($r['system_name']?:'Legacy / Unassigned') ?></td><td><?= e($r['ip_address']?:'') ?></td></tr><?php endforeach; ?>
</tbody></table></body></html>
