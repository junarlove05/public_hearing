<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pdo=db();$hid=(int)($_GET['hearing_id']??0);
$h=$pdo->prepare('SELECT * FROM hearings WHERE id=:id');$h->execute([':id'=>$hid]);$hearing=$h->fetch();
if(!$hearing)exit('Hearing not found.');

$stmt=$pdo->prepare(
 "SELECT s.full_name,s.organization,r.registration_code,r.attendance_type,
         a.status,a.checked_in_at,a.checked_out_at,a.check_in_method,a.remarks
  FROM registrations r JOIN stakeholders s ON s.id=r.stakeholder_id
  LEFT JOIN attendance a ON a.registration_id=r.id
  WHERE r.hearing_id=:hid AND r.registration_status='Approved'
  ORDER BY s.full_name"
);
$stmt->execute([':hid'=>$hid]);$rows=$stmt->fetchAll();
?>
<!doctype html><html><head><meta charset="utf-8"><title>Attendance</title>
<style>body{font:12px Arial;margin:30px;color:#111827}h2{margin:0}.head{text-align:center;border-bottom:3px solid #0f2137;padding-bottom:12px;margin-bottom:15px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ccc;padding:6px}th{background:#eee}</style></head>
<body onload="window.print()"><div class="head"><strong><?= e(APP_NAME) ?></strong><h2>Attendance Sheet</h2><div><?= e($hearing['title']) ?> · <?= formatDate($hearing['hearing_date']) ?></div></div>
<table><thead><tr><th>Participant</th><th>Registration</th><th>Type</th><th>Status</th><th>Check In</th><th>Check Out</th><th>Method</th><th>Remarks</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr><td><?= e($r['full_name']) ?><br><small><?= e($r['organization']?:'') ?></small></td><td><?= e($r['registration_code']) ?></td><td><?= e($r['attendance_type']) ?></td><td><?= e($r['status']?:'Not Marked') ?></td><td><?= $r['checked_in_at']?formatDateTime($r['checked_in_at']):'—' ?></td><td><?= $r['checked_out_at']?formatDateTime($r['checked_out_at']):'—' ?></td><td><?= e($r['check_in_method']?:'—') ?></td><td><?= e($r['remarks']?:'') ?></td></tr><?php endforeach; ?>
</tbody></table></body></html>
