<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requirePermission('lph.users.manage');

$id=(int)($_GET['id']??0);
if($id<=0)jsonResponse(false,'Invalid user id.');

$stmt=db()->prepare(
 'SELECT
    u.id,u.username,u.full_name,u.email,u.phone,u.role_id,u.office_id,u.status,
    COALESCE(usa.status,"Active") lph_access_status,
    COALESCE(usa.access_level,"Standard") access_level
  FROM users u
  LEFT JOIN user_system_access usa
    ON usa.user_id=u.id AND usa.system_id=:system
  WHERE u.id=:id AND u.deleted_at IS NULL'
);
$stmt->execute([':system'=>lphSystemId(),':id'=>$id]);
$user=$stmt->fetch();

if(!$user)jsonResponse(false,'User not found.');

jsonResponse(true,'',['user'=>$user]);
