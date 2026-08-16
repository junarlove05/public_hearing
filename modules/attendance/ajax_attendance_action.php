<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';

requireLogin();
if (!hasPermission('lph.attendance.manage')) {
    jsonResponse(false,'You do not have permission to manage attendance.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false,'Invalid request method.');
requireCsrf();

$pdo=db();
$registrationId=(int)($_POST['registration_id']??0);
$action=clean($_POST['action']??'');
$method=clean($_POST['check_in_method']??'Manual');
$remarks=trim((string)($_POST['remarks']??''));

$allowedActions=['check_in','check_out','absent','excused','undo'];
if(!in_array($action,$allowedActions,true)) jsonResponse(false,'Invalid attendance action.');

$stmt=$pdo->prepare(
 'SELECT r.*,s.full_name,h.title hearing_title,h.status hearing_status
  FROM registrations r
  JOIN stakeholders s ON s.id=r.stakeholder_id
  JOIN hearings h ON h.id=r.hearing_id
  WHERE r.id=:id'
);
$stmt->execute([':id'=>$registrationId]);
$r=$stmt->fetch();
if(!$r) jsonResponse(false,'Registration not found.');
if($r['registration_status']!=='Approved') jsonResponse(false,'Only approved registrations can be processed for attendance.');

$aStmt=$pdo->prepare('SELECT * FROM attendance WHERE stakeholder_id=:sid AND hearing_id=:hid LIMIT 1');
$aStmt->execute([':sid'=>$r['stakeholder_id'],':hid'=>$r['hearing_id']]);
$existing=$aStmt->fetch();
$oldStatus=$existing['status']??null;

try{
 $pdo->beginTransaction();

 if($action==='undo'){
   if($existing){
     $pdo->prepare('DELETE FROM attendance WHERE id=:id')->execute([':id'=>$existing['id']]);
   }
   $newStatus=null;
   $attendanceId=null;
 } else {
   $newStatus=match($action){
     'check_in'=>'Present',
     'check_out'=>'Present',
     'absent'=>'Absent',
     'excused'=>'Excused',
     default=>'Present',
   };

   if($existing){
     if($action==='check_out'){
       $pdo->prepare(
         'UPDATE attendance
          SET checked_out_at=NOW(),remarks=:remarks,verified_by=:user,updated_at=NOW()
          WHERE id=:id'
       )->execute([':remarks'=>$remarks?:$existing['remarks'],':user'=>currentUserId(),':id'=>$existing['id']]);
     } else {
       $pdo->prepare(
         'UPDATE attendance
          SET registration_id=:rid,status=:status,attendance_type=:atype,
              check_in_method=:method,checked_in_at=:checked_in,
              checked_out_at=NULL,verified_by=:user,remarks=:remarks,updated_at=NOW()
          WHERE id=:id'
       )->execute([
         ':rid'=>$registrationId,':status'=>$newStatus,':atype'=>$r['attendance_type']?:'On-site',
         ':method'=>$method,':checked_in'=>$action==='check_in'?date('Y-m-d H:i:s'):null,
         ':user'=>currentUserId(),':remarks'=>$remarks?:null,':id'=>$existing['id']
       ]);
     }
     $attendanceId=(int)$existing['id'];
   } else {
     $pdo->prepare(
       'INSERT INTO attendance
        (stakeholder_id,hearing_id,registration_id,status,checked_in_at,created_at,
         attendance_type,check_in_method,checked_out_at,verified_by,remarks,updated_at)
        VALUES
        (:sid,:hid,:rid,:status,:checked_in,NOW(),:atype,:method,NULL,:user,:remarks,NOW())'
     )->execute([
       ':sid'=>$r['stakeholder_id'],':hid'=>$r['hearing_id'],':rid'=>$registrationId,
       ':status'=>$newStatus,':checked_in'=>$action==='check_in'?date('Y-m-d H:i:s'):null,
       ':atype'=>$r['attendance_type']?:'On-site',':method'=>$method,':user'=>currentUserId(),
       ':remarks'=>$remarks?:null
     ]);
     $attendanceId=(int)$pdo->lastInsertId();
   }
 }

 if(lphTableExists($pdo,'attendance_event_history')){
   $pdo->prepare(
     'INSERT INTO attendance_event_history
      (attendance_id,stakeholder_id,hearing_id,registration_id,action,previous_status,
       new_status,check_in_method,remarks,actor_user_id,created_at)
      VALUES (:aid,:sid,:hid,:rid,:action,:old,:new,:method,:remarks,:user,NOW())'
   )->execute([
     ':aid'=>$attendanceId,':sid'=>$r['stakeholder_id'],':hid'=>$r['hearing_id'],
     ':rid'=>$registrationId,':action'=>$action,':old'=>$oldStatus,':new'=>$newStatus,
     ':method'=>$method,':remarks'=>$remarks?:null,':user'=>currentUserId()
   ]);
 }

 $pdo->prepare(
   'INSERT INTO attendance_logs
    (stakeholder_id,hearing_id,registration_id,action,actor_user_id,check_in_method,notes,created_at)
    VALUES (:sid,:hid,:rid,:action,:user,:method,:notes,NOW())'
 )->execute([
   ':sid'=>$r['stakeholder_id'],':hid'=>$r['hearing_id'],':rid'=>$registrationId,
   ':action'=>$action,':user'=>currentUserId(),':method'=>$method,':notes'=>$remarks?:null
 ]);

 if($action==='check_in'){
   $pdo->prepare(
     'UPDATE qr_codes SET used_at=COALESCE(used_at,NOW()),status="Used"
      WHERE registration_id=:rid'
   )->execute([':rid'=>$registrationId]);
 }

 logActivity(currentUserId(),'Attendance '.ucwords(str_replace('_',' ',$action)),
   "{$r['full_name']} · {$r['hearing_title']} · {$method}");

 $pdo->commit();
 jsonResponse(true,'Attendance updated successfully.');
}catch(Throwable $e){
 if($pdo->inTransaction())$pdo->rollBack();
 error_log('Attendance update error: '.$e->getMessage());
 jsonResponse(false,APP_DEBUG?$e->getMessage():'Unable to update attendance.');
}
