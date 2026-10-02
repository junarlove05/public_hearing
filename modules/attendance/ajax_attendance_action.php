<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';

requireLogin();
if (!isAdmin()) {
    jsonResponse(false, 'Attendance recording (Time In / Time Out) is restricted to System Administrators.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false,'Invalid request method.');
requireCsrf();

$pdo=db();
$registrationId=(int)($_POST['registration_id']??0);
$action=clean($_POST['action']??'');
$method=clean($_POST['check_in_method']??'Manual');
$remarks=trim((string)($_POST['remarks']??''));
$sessionDate=clean($_POST['session_date']??'');

$allowedActions=['check_in','check_out','absent','excused','undo'];
if(!in_array($action,$allowedActions,true)) jsonResponse(false,'Invalid attendance action.');

$stmt=$pdo->prepare(
 'SELECT r.*,s.full_name,h.title hearing_title,h.status hearing_status,h.hearing_date,h.end_date
  FROM registrations r
  JOIN stakeholders s ON s.id=r.stakeholder_id
  JOIN hearings h ON h.id=r.hearing_id
  WHERE r.id=:id'
);
$stmt->execute([':id'=>$registrationId]);
$r=$stmt->fetch();
if(!$r) jsonResponse(false,'Registration not found.');
if($r['registration_status']!=='Approved') jsonResponse(false,'Only approved registrations can be processed for attendance.');

// Determine and validate session date
if ($sessionDate === '') {
    $todayStr = date('Y-m-d');
    $startDate = $r['hearing_date'];
    $endDate = !empty($r['end_date']) ? $r['end_date'] : $startDate;
    if ($todayStr >= $startDate && $todayStr <= $endDate) {
        $sessionDate = $todayStr;
    } else {
        $sessionDate = $startDate;
    }
}

$dayInfo = lphGetHearingDayInfo($pdo, (int)$r['hearing_id'], $sessionDate, $r);
if (!$dayInfo['valid']) {
    jsonResponse(false, 'The selected date (' . htmlspecialchars($sessionDate) . ') is not part of the schedule for this hearing.');
}

// Enforce closure check: if attendance is closed for this day, reject changes
if (lphIsDayAttendanceClosed($pdo, (int)$r['hearing_id'], $sessionDate, $r)) {
    $blockReason = lphGetDayAttendanceBlockReason($pdo, (int)$r['hearing_id'], $sessionDate, $r);
    jsonResponse(false, $blockReason ?: 'Attendance for this date is closed.');
}

// Enforce approved invitation requirement: only approved invitations can be processed for attendance
$invStmt = $pdo->prepare(
    'SELECT id, status FROM invitations 
     WHERE stakeholder_id = :sid AND hearing_id = :hid 
       AND (session_date = :sdate OR session_date IS NULL) 
     LIMIT 1'
);
$invStmt->execute([':sid' => $r['stakeholder_id'], ':hid' => $r['hearing_id'], ':sdate' => $sessionDate]);
$inv = $invStmt->fetch();
if (!$inv || !in_array($inv['status'], ['Accepted', 'Approved'], true)) {
    jsonResponse(false, 'Attendance denied: Only stakeholders with an approved / accepted invitation can be processed for attendance.');
}

// Find existing attendance record specifically for this stakeholder, hearing, AND session date
$aStmt=$pdo->prepare('SELECT * FROM attendance WHERE stakeholder_id=:sid AND hearing_id=:hid AND attendance_date=:adate LIMIT 1');
$aStmt->execute([':sid'=>$r['stakeholder_id'], ':hid'=>$r['hearing_id'], ':adate'=>$sessionDate]);
$existing=$aStmt->fetch();
$oldStatus=$existing['status']??null;

// IMMUTABILITY GUARD: Once timed out, attendance is permanently locked
if ($existing && !empty($existing['checked_out_at'])) {
    jsonResponse(false, 'Attendance record is already timed out and permanently locked. It can no longer be modified.');
}

// IMMUTABILITY GUARD: Once timed in, status cannot be changed to Absent, Excused, or undone
if ($existing && !empty($existing['checked_in_at'])) {
    if ($action !== 'check_out') {
        jsonResponse(false, 'Attendance has already been timed in and is locked. Status cannot be altered.');
    }
}

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

    $now = date('Y-m-d H:i:s');
    if($existing){
      if($action==='check_out'){
        $pdo->prepare(
          'UPDATE attendance
           SET checked_out_at=:cout,
               checked_in_at=COALESCE(checked_in_at, :cin),
               status="Present",
               remarks=:remarks,verified_by=:user,updated_at=NOW()
           WHERE id=:id'
        )->execute([
          ':cout'=>$now,
          ':cin'=>$now,
          ':remarks'=>$remarks?:$existing['remarks'],
          ':user'=>currentUserId(),
          ':id'=>$existing['id']
        ]);
      } else {
        $pdo->prepare(
          'UPDATE attendance
           SET registration_id=:rid,status=:status,attendance_type=:atype,
               check_in_method=:method,checked_in_at=:checked_in,
               checked_out_at=NULL,verified_by=:user,remarks=:remarks,updated_at=NOW()
           WHERE id=:id'
        )->execute([
          ':rid'=>$registrationId,':status'=>$newStatus,':atype'=>$r['attendance_type']?:'On-site',
          ':method'=>$method,':checked_in'=>$action==='check_in'?$now:null,
          ':user'=>currentUserId(),':remarks'=>$remarks?:null,':id'=>$existing['id']
        ]);
      }
      $attendanceId=(int)$existing['id'];
    } else {
      $cin = ($action==='check_in' || $action==='check_out') ? $now : null;
      $cout = ($action==='check_out') ? $now : null;
      $pdo->prepare(
        'INSERT INTO attendance
         (stakeholder_id,hearing_id,attendance_date,registration_id,status,checked_in_at,created_at,
          attendance_type,check_in_method,checked_out_at,verified_by,remarks,updated_at)
         VALUES
         (:sid,:hid,:adate,:rid,:status,:checked_in,NOW(),:atype,:method,:checked_out,:user,:remarks,NOW())'
      )->execute([
        ':sid'=>$r['stakeholder_id'],':hid'=>$r['hearing_id'],':adate'=>$sessionDate,':rid'=>$registrationId,
        ':status'=>$newStatus,':checked_in'=>$cin,
        ':atype'=>$r['attendance_type']?:'On-site',':method'=>$method,':checked_out'=>$cout,
        ':user'=>currentUserId(),':remarks'=>$remarks?:null
      ]);
      $attendanceId=(int)$pdo->lastInsertId();
    }
  }

  if(lphTableExists($pdo,'attendance_event_history')){
    $pdo->prepare(
      'INSERT INTO attendance_event_history
       (attendance_id,stakeholder_id,hearing_id,registration_id,session_date,action,previous_status,
        new_status,check_in_method,remarks,actor_user_id,created_at)
       VALUES (:aid,:sid,:hid,:rid,:sdate,:action,:old,:new,:method,:remarks,:user,NOW())'
    )->execute([
      ':aid'=>$attendanceId,':sid'=>$r['stakeholder_id'],':hid'=>$r['hearing_id'],
      ':rid'=>$registrationId,':sdate'=>$sessionDate,':action'=>$action,':old'=>$oldStatus,':new'=>$newStatus,
      ':method'=>$method,':remarks'=>$remarks?:null,':user'=>currentUserId()
    ]);
  }

  $pdo->prepare(
    'INSERT INTO attendance_logs
     (stakeholder_id,hearing_id,registration_id,session_date,action,actor_user_id,check_in_method,notes,created_at)
     VALUES (:sid,:hid,:rid,:sdate,:action,:user,:method,:notes,NOW())'
  )->execute([
    ':sid'=>$r['stakeholder_id'],':hid'=>$r['hearing_id'],':rid'=>$registrationId,':sdate'=>$sessionDate,
    ':action'=>$action,':user'=>currentUserId(),':method'=>$method,':notes'=>$remarks?:null
  ]);

  // Track last used timestamp for QR code
  $pdo->prepare(
    'UPDATE qr_codes SET used_at=NOW()
     WHERE (registration_id=:rid OR (stakeholder_id=:sid AND registration_id IS NULL))'
  )->execute([':rid'=>$registrationId, ':sid'=>$r['stakeholder_id']]);

  // If this attendee had a hearing invitation, automatically mark it Accepted
  if ($action === 'check_in' || $action === 'check_out') {
    $pdo->prepare(
      'UPDATE invitations SET status="Accepted", responded_at=COALESCE(responded_at, NOW()), updated_at=NOW()
       WHERE stakeholder_id=:sid AND hearing_id=:hid AND status NOT IN ("Accepted", "Declined")'
    )->execute([':sid'=>$r['stakeholder_id'], ':hid'=>$r['hearing_id']]);
  }

  logActivity(currentUserId(),'Attendance '.ucwords(str_replace('_',' ',$action)),
    "{$r['full_name']} · {$r['hearing_title']} ({$sessionDate}) · {$method}");

  $pdo->commit();

  $niceTime = date('h:i A');
  $respMsg = match($action) {
    'check_in' => "{$r['full_name']} timed in successfully at {$niceTime} ({$sessionDate}).",
    'check_out' => "{$r['full_name']} timed out successfully at {$niceTime} ({$sessionDate}).",
    'absent' => "{$r['full_name']} marked absent for {$sessionDate}.",
    'excused' => "{$r['full_name']} marked excused for {$sessionDate}.",
    'undo' => "Attendance cleared for {$r['full_name']} on {$sessionDate}.",
    default => 'Attendance updated successfully.'
  };

  jsonResponse(true, $respMsg, [
    'action' => $action,
    'registration_id' => $registrationId,
    'full_name' => $r['full_name'],
    'status' => $newStatus,
    'time' => $niceTime,
    'session_date' => $sessionDate,
  ]);
}catch(Throwable $e){
  if($pdo->inTransaction())$pdo->rollBack();
  error_log('Attendance update error: '.$e->getMessage());
  jsonResponse(false,APP_DEBUG?$e->getMessage():'Unable to update attendance.');
}
