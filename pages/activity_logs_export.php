<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requirePermission('lph.activity_logs.view');

$pdo=db();

$search=clean($_GET['search']??'');
$userId=(int)($_GET['user_id']??0);
$action=clean($_GET['action']??'');
$scope=clean($_GET['scope']??'lph');
$dateFrom=clean($_GET['date_from']??'');
$dateTo=clean($_GET['date_to']??'');

$where=[];$params=[];

if($scope==='lph'){$where[]='al.system_id=:system';$params[':system']=lphSystemId();}
elseif($scope==='legacy'){$where[]='al.system_id IS NULL';}

if($search!==''){
 $where[]='(al.details LIKE :s1 OR al.action LIKE :s2 OR u.full_name LIKE :s3 OR al.ip_address LIKE :s4)';
 $like='%'.$search.'%';$params[':s1']=$like;$params[':s2']=$like;$params[':s3']=$like;$params[':s4']=$like;
}
if($userId>0){$where[]='al.user_id=:user';$params[':user']=$userId;}
if($action!==''){$where[]='al.action=:action';$params[':action']=$action;}
if($dateFrom!==''){$where[]='DATE(al.created_at)>=:df';$params[':df']=$dateFrom;}
if($dateTo!==''){$where[]='DATE(al.created_at)<=:dt';$params[':dt']=$dateTo;}

$whereSql=$where?'WHERE '.implode(' AND ',$where):'';

$stmt=$pdo->prepare(
 "SELECT al.id,al.created_at,u.full_name,u.email,al.action,al.details,
         s.name system_name,al.ip_address,al.user_agent
  FROM activity_logs al
  LEFT JOIN users u ON u.id=al.user_id
  LEFT JOIN systems s ON s.id=al.system_id
  {$whereSql}
  ORDER BY al.created_at DESC,al.id DESC"
);
$stmt->execute($params);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="lph_activity_logs_'.date('Ymd_His').'.csv"');

$out=fopen('php://output','w');
fputcsv($out,['ID','Date/Time','User','Email','Action','Details','System','IP Address','User Agent']);

while($r=$stmt->fetch()){
 fputcsv($out,[
   $r['id'],$r['created_at'],$r['full_name']?:'Anonymous / System',$r['email']?:'',
   $r['action'],$r['details'],$r['system_name']?:'Legacy / Unassigned',
   $r['ip_address'],$r['user_agent']
 ]);
}
fclose($out);

logActivity(currentUserId(),'Export Activity Logs','Exported filtered activity logs as CSV.');
exit;
