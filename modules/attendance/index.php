<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';

requireLogin();
$canManageAttendance = isAdmin();
$pdo=db();
lphEnsureMultiDayAttendanceSchema($pdo);
$pageTitle = $canManageAttendance ? 'Attendance Tracking (Time In / Time Out)' : 'Attendance Tracking (Attendance Roster)';
$activeMenu='attendance';
$hearingId=(int)($_GET['hearing_id']??0);

$allHearings=$pdo->query(
 "SELECT h.id,h.reference_number,h.title,h.hearing_date,h.end_date,h.hearing_time,h.venue,h.status,
         (SELECT COUNT(DISTINCT i.stakeholder_id) FROM invitations i WHERE i.hearing_id=h.id AND i.status IN ('Accepted', 'Approved')) AS approved_count,
         (SELECT COUNT(DISTINCT a.stakeholder_id) FROM attendance a WHERE a.hearing_id=h.id AND a.checked_in_at IS NOT NULL) AS checked_in_count,
         (SELECT COUNT(DISTINCT a.stakeholder_id) FROM attendance a WHERE a.hearing_id=h.id AND a.checked_out_at IS NOT NULL) AS checked_out_count
  FROM hearings h
  ORDER BY 
    CASE WHEN h.status = 'Ongoing' THEN 1 WHEN h.status = 'Upcoming' THEN 2 ELSE 3 END,
    h.hearing_date DESC, h.hearing_time DESC"
)->fetchAll();
$hearings = $allHearings;

$ongoingCount = 0;
$upcomingCount = 0;
$completedCount = 0;
foreach($allHearings as $hItem) {
  if ($hItem['status'] === 'Ongoing') $ongoingCount++;
  elseif ($hItem['status'] === 'Upcoming') $upcomingCount++;
  elseif ($hItem['status'] === 'Completed') $completedCount++;
}

$rows=[];
$hearing=null;
$allStakeholders=[];
$sessionDays=[];
$isMultiDay=false;
$selectedDate='';
$activeSessionDay=null;
$isDayClosed=false;
$isHearingCompleted=false;
$dayStatus='open';
$dayLabel='Day 1';
$dayFormattedDate='';

if($hearingId>0){
 $hs=$pdo->prepare('SELECT * FROM hearings WHERE id=:id');
 $hs->execute([':id'=>$hearingId]);
 $hearing=$hs->fetch();

 if ($hearing) {
   $isHearingCompleted = ($hearing['status'] === 'Completed');
   if ($isHearingCompleted) {
     $isDayClosed = true;
   }
   // Compute multi-day session days with their daily closure status
   $sessionDays = lphGetHearingSessionDays($hearing, $pdo);
   $isMultiDay = count($sessionDays) > 1;

   // Determine selected session date
   $requestedDate = clean($_GET['date'] ?? '');
   if ($requestedDate !== '') {
     foreach ($sessionDays as $sd) {
       if ($sd['date'] === $requestedDate) {
         $activeSessionDay = $sd;
         break;
       }
     }
   }

   // If no specific date or not matched, default to today if in range
   if (!$activeSessionDay) {
     $todayStr = date('Y-m-d');
     foreach ($sessionDays as $sd) {
       if ($sd['date'] === $todayStr) {
         $activeSessionDay = $sd;
         break;
       }
     }
   }

   // If still not matched, default to first day (or latest active/past)
   if (!$activeSessionDay && !empty($sessionDays)) {
     $activeSessionDay = $sessionDays[0];
   }

   $selectedDate = $activeSessionDay['date'] ?? $hearing['hearing_date'];
   $isDayClosed = $activeSessionDay ? (bool)$activeSessionDay['is_closed'] : false;
   $dayStatus = $activeSessionDay['status'] ?? 'open';
   $dayLabel = $activeSessionDay['day_label'] ?? 'Day 1';
   $dayFormattedDate = $activeSessionDay['formatted_date'] ?? formatDate($selectedDate);

    // Auto-sync: Guarantee that stakeholders invited to this hearing exist in registrations roster with proper status
    $syncInvites = $pdo->prepare(
     "INSERT INTO registrations (stakeholder_id, hearing_id, session_date, registration_code, registration_status, attendance_type, approved_by, approved_at, registered_at)
      SELECT i.stakeholder_id, i.hearing_id, i.session_date, CONCAT('REG-', YEAR(NOW()), '-', UPPER(SUBSTRING(MD5(RAND()), 1, 10))),
             CASE WHEN i.status IN ('Accepted', 'Approved') THEN 'Approved' ELSE 'Pending' END,
             'Invited',
             CASE WHEN i.status IN ('Accepted', 'Approved') THEN 1 ELSE NULL END,
             CASE WHEN i.status IN ('Accepted', 'Approved') THEN NOW() ELSE NULL END,
             NOW()
      FROM invitations i
      WHERE i.hearing_id = :hid1 AND i.status NOT IN ('Declined', 'Cancelled')
        AND NOT EXISTS (
          SELECT 1 FROM registrations r 
          WHERE r.hearing_id = :hid2 
            AND r.stakeholder_id = i.stakeholder_id 
            AND (r.session_date = i.session_date OR (r.session_date IS NULL AND i.session_date IS NULL))
        )"
    );
    $syncInvites->execute([':hid1'=>$hearingId, ':hid2'=>$hearingId]);

    // Keep registrations synchronized: if invitation is NOT accepted, registration_status must reflect true invitation state
    $syncStatus = $pdo->prepare(
      "UPDATE registrations r
       JOIN invitations i ON (i.hearing_id = r.hearing_id AND i.stakeholder_id = r.stakeholder_id AND (i.session_date = r.session_date OR r.session_date IS NULL OR i.session_date IS NULL))
       SET r.registration_status = CASE 
           WHEN i.status IN ('Accepted', 'Approved') THEN 'Approved'
           WHEN i.status = 'Declined' THEN 'Declined'
           WHEN i.status = 'Cancelled' THEN 'Cancelled'
           ELSE 'Pending'
       END
       WHERE r.hearing_id = :hid"
    );
    $syncStatus->execute([':hid'=>$hearingId]);

    // Fetch attendees: ONLY stakeholders with an Accepted/Approved invitation for this hearing and session date!
    $stmt=$pdo->prepare(
     "SELECT COALESCE(MAX(r.id), 0) AS registration_id,
             COALESCE(MAX(r.registration_code), MAX(i.invitation_code)) AS registration_code,
             COALESCE(MAX(r.registration_status), 'Approved') AS registration_status,
             COALESCE(MAX(r.attendance_type), 'Invited') AS attendance_type,
             s.id AS stakeholder_id, s.full_name, s.email, s.organization,
             MAX(i.id) AS invitation_id, MAX(i.invitation_code) AS invitation_code, MAX(i.status) AS invitation_status,
             MAX(a.id) AS attendance_id, MAX(a.status) AS attendance_status, MAX(a.checked_in_at) AS checked_in_at, MAX(a.checked_out_at) AS checked_out_at,
             MAX(a.check_in_method) AS check_in_method, MAX(a.remarks) AS remarks, MAX(a.attendance_date) AS attendance_date,
             COALESCE(MAX(q_stk.code_value), MAX(q_reg.code_value), MAX(i.invitation_code)) AS code_value
      FROM invitations i
      JOIN stakeholders s ON s.id = i.stakeholder_id
      LEFT JOIN registrations r ON (r.hearing_id = i.hearing_id AND r.stakeholder_id = s.id AND (r.session_date = i.session_date OR r.session_date IS NULL OR i.session_date IS NULL))
      LEFT JOIN attendance a ON (a.hearing_id = i.hearing_id AND a.stakeholder_id = s.id AND a.attendance_date = :adate1)
      LEFT JOIN qr_codes q_reg ON q_reg.registration_id = r.id
      LEFT JOIN qr_codes q_stk ON (q_stk.stakeholder_id = s.id AND q_stk.registration_id IS NULL)
      WHERE i.hearing_id = :hid 
        AND i.status IN ('Accepted', 'Approved')
        AND (i.session_date = :adate2 OR i.session_date IS NULL)
      GROUP BY s.id, s.full_name, s.email, s.organization
      ORDER BY s.full_name"
    );
    $stmt->execute([':hid'=>$hearingId, ':adate1'=>$selectedDate, ':adate2'=>$selectedDate]);
    $rows=$stmt->fetchAll();

    // Stakeholders invited but not yet approved (for reference if needed)
    $pendingInvStmt = $pdo->prepare(
      "SELECT s.id, s.full_name, s.organization, s.email, i.status AS invitation_status
       FROM invitations i
       JOIN stakeholders s ON s.id = i.stakeholder_id
       WHERE i.hearing_id = :hid AND i.status NOT IN ('Accepted', 'Approved')
       ORDER BY s.full_name"
    );
    $pendingInvStmt->execute([':hid'=>$hearingId]);
    $unregisteredStakeholders = $pendingInvStmt->fetchAll();
  }
}

$timedIn=0;
$timedOut=0;
$absent=0;
$excused=0;

foreach($rows as $r){
 if(!empty($r['checked_in_at'])) $timedIn++;
 if(!empty($r['checked_out_at'])) $timedOut++;
 if($r['attendance_status']==='Absent') $absent++;
 elseif($r['attendance_status']==='Excused') $excused++;
}

include __DIR__.'/../../layouts/header.php';
?>
<link rel="stylesheet" href="<?= e(APP_URL.'/assets/css/lph-complete-modules.css') ?>">
<script src="<?= e(vendorAsset('html5-qrcode/html5-qrcode.min.js', 'https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js')) ?>"></script>
<style>
#cameraReader { width: 100% !important; border: none !important; }
#cameraReader video { width: 100% !important; max-height: 320px !important; object-fit: cover !important; border-radius: 8px !important; }
#cameraReader__scan_region { background: transparent !important; }
#cameraReader__dashboard_section_csr { display: none !important; }
.session-day-btn { transition: all 0.2s ease; }
.session-day-btn:hover { transform: translateY(-1px); }

/* Hearings Directory (Cards & Table) */
.hearing-card {
  transition: all 0.2s ease-in-out;
  border: 1px solid #e9ecef;
  border-radius: 12px;
  background: #ffffff;
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
  display: flex;
  flex-direction: column;
  height: 100%;
}
.hearing-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
  border-color: #cbd5e1;
}
.hearing-card.ongoing {
  border-top: 3px solid #10b981;
}
.hearing-card.upcoming {
  border-top: 3px solid #3b82f6;
}
.hearing-card.completed {
  border-top: 3px solid #94a3b8;
  background: #fbfcfd;
}
.hearing-title-clamp {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  line-height: 1.4;
  font-size: 0.94rem;
  font-weight: 600;
  color: #0f172a;
  min-height: 2.65rem;
}
.hearing-title-clamp a {
  color: #0f172a;
  transition: color 0.15s ease;
}
.hearing-title-clamp a:hover {
  color: #2563eb;
}
.attendance-stat-strip {
  background: #f8fafc;
  border: 1px solid #f1f5f9;
  border-radius: 8px;
  padding: 0.45rem 0.75rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 0.78rem;
}
.attendance-stat-item {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  color: #64748b;
}
.attendance-stat-item strong {
  font-size: 0.85rem;
  font-weight: 700;
}
.status-pill-badge {
  font-size: 0.72rem;
  font-weight: 600;
  padding: 0.2rem 0.55rem;
  border-radius: 20px;
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  line-height: 1;
}
.status-pill-badge.status-ongoing {
  background: #ecfdf5;
  color: #047857;
  border: 1px solid #a7f3d0;
}
.status-pill-badge.status-upcoming {
  background: #eff6ff;
  color: #1d4ed8;
  border: 1px solid #bfdbfe;
}
.status-pill-badge.status-completed {
  background: #f1f5f9;
  color: #475569;
  border: 1px solid #e2e8f0;
}
.status-pulse {
  width: 7px;
  height: 7px;
  background: #10b981;
  border-radius: 50%;
  display: inline-block;
  box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
  animation: statusPulseAnim 1.8s infinite;
}
@keyframes statusPulseAnim {
  0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
  70% { transform: scale(1); box-shadow: 0 0 0 5px rgba(16, 185, 129, 0); }
  100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}
.filter-btn {
  font-size: 0.82rem;
  font-weight: 500;
  border-radius: 8px;
  padding: 0.35rem 0.75rem;
  border: 1px solid #e2e8f0;
  background: #ffffff;
  color: #475569;
  transition: all 0.15s ease;
}
.filter-btn:hover {
  background: #f8fafc;
  color: #0f172a;
}
.filter-btn.active {
  background: #0f172a !important;
  color: #ffffff !important;
  border-color: #0f172a !important;
  box-shadow: 0 1px 4px rgba(15, 23, 42, 0.15);
}
.view-btn.active {
  background: #0f172a !important;
  color: #ffffff !important;
  border-color: #0f172a !important;
}
.hearing-table-row {
  cursor: pointer;
  transition: background 0.15s ease;
}
.hearing-table-row:hover {
  background: #f8fafc;
}
</style>
<div class="app-wrapper"><?php include __DIR__.'/../../layouts/sidebar.php'; ?><div class="main-content">
<?php include __DIR__ . '/../../layouts/top_controls.php'; ?>
<div class="lphx-head">
  <div>
    <div class="lphx-eyebrow"><i class="bi bi-qr-code-scan"></i> Step 4 · Session Attendance</div>
    <h1><?= $canManageAttendance ? 'Attendance Tracking &amp; QR Scanner' : 'Attendance Tracking (Attendance Roster)' ?></h1>
    <p><?= $canManageAttendance 
      ? 'Scan stakeholder QR passes or registration codes for automatic session <strong>Time In</strong> and <strong>Time Out</strong> with multi-day support and daily closing.' 
      : 'Official session attendance records for registered stakeholders and attendees. Attendance recording (Time In / Time Out) is managed by System Administrators.' ?></p>
  </div>
  <?php if($hearing): ?>
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <a href="index.php" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1.5 shadow-sm" title="View all hearings as cards or table">
      <i class="bi bi-grid-3x3-gap-fill text-primary"></i> <span>All Hearings</span>
    </a>
    <div class="dropdown">
      <button class="btn btn-outline-primary btn-sm dropdown-toggle d-flex align-items-center gap-1 shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-arrow-left-right me-1"></i> Switch Hearing
      </button>
      <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="max-height: 380px; overflow-y: auto; min-width: 320px;">
        <li class="dropdown-header text-uppercase small fw-bold">Select Hearing Session</li>
        <?php foreach($hearings as $h): ?>
          <li>
            <a class="dropdown-item py-2 <?= $hearingId===(int)$h['id'] ? 'active' : '' ?>" href="?hearing_id=<?= (int)$h['id'] ?>">
              <div class="d-flex justify-content-between align-items-center mb-0.5">
                <span class="font-monospace small fw-bold"><?= e($h['reference_number']?:'PHC-'.$h['id']) ?></span>
                <span class="badge <?= $h['status']==='Ongoing'?'bg-success':($h['status']==='Upcoming'?'bg-primary':'bg-secondary') ?>" style="font-size: 0.65rem;"><?= e($h['status']) ?></span>
              </div>
              <div class="small text-truncate" style="max-width: 280px;"><?= e($h['title']) ?></div>
              <div class="small text-muted" style="font-size: 0.72rem;"><?= formatDate($h['hearing_date']) ?></div>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <?php else: ?>
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle px-3 py-2 rounded-pill font-monospace" style="font-size: 0.85rem;">
      <i class="bi bi-calendar3-event me-1.5"></i> <?= count($allHearings) ?> Hearings Available
    </span>
    <a href="history.php" class="btn btn-outline-secondary btn-sm shadow-sm">
      <i class="bi bi-clock-history me-1"></i> Attendance History
    </a>
  </div>
  <?php endif; ?>
</div>

<?php if($hearing): ?>

<!-- MULTI-DAY HEARING SESSION DAYS TABS -->
<?php if($isMultiDay): ?>
<div class="card shadow-sm border-0 mb-3" style="border-radius: 14px; background: #ffffff;">
  <div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2.5">
      <div class="d-flex align-items-center gap-2">
        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
          <i class="bi bi-calendar3-range me-1"></i> Multi-Day Hearing (<?= count($sessionDays) ?> Days)
        </span>
        <span class="small text-muted">Each day has separate attendance:</span>
      </div>
      <div class="small text-muted">
        Currently Tracking: <strong class="text-dark font-monospace"><?= e($dayLabel) ?> · <?= e($dayFormattedDate) ?></strong>
      </div>
    </div>

    <div class="d-flex align-items-center gap-2 flex-wrap">
      <?php foreach($sessionDays as $sd): ?>
        <?php $isSelected = ($sd['date'] === $selectedDate); ?>
        <a href="?hearing_id=<?= $hearingId ?>&date=<?= urlencode($sd['date']) ?>"
           class="btn session-day-btn <?= $isSelected ? 'btn-primary shadow-sm border-2' : 'btn-outline-secondary' ?> text-decoration-none d-flex align-items-center gap-2 py-1.5 px-3"
           style="border-radius: 10px;">
          <div class="text-start">
            <div class="fw-bold <?= $isSelected ? 'text-white' : 'text-dark' ?>" style="font-size: 0.86rem;">
              <?= e($sd['day_label']) ?> · <?= e($sd['formatted_date']) ?>
            </div>
            <div class="small <?= $isSelected ? 'text-white-50' : 'text-muted' ?>" style="font-size: 0.72rem;">
              <?= e($sd['weekday']) ?>
            </div>
          </div>
          <span class="badge <?= $sd['status_badge_class'] ?> ms-1" style="font-size: 0.68rem;">
            <?php if($sd['status'] === 'closed'): ?>
              <i class="bi bi-lock-fill me-0.5"></i> Closed
            <?php elseif($sd['status'] === 'open'): ?>
              <i class="bi bi-broadcast me-0.5"></i> Open
            <?php else: ?>
              <i class="bi bi-clock me-0.5"></i> Upcoming
            <?php endif; ?>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- HEARING COMPLETED BANNER -->
<?php if($isHearingCompleted): ?>
<div class="alert alert-warning border-0 shadow-sm d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 py-3 px-4" style="border-radius: 12px; background: #fff3cd;">
  <div class="d-flex align-items-center gap-3">
    <div class="rounded-circle bg-warning text-dark d-flex align-items-center justify-content-center shadow-sm" style="width: 42px; height: 42px;">
      <i class="bi bi-check2-all fs-4"></i>
    </div>
    <div>
      <strong class="text-dark d-block" style="font-size: 0.98rem;">
        Hearing Completed · Attendance Tracking Concluded &amp; Hidden
      </strong>
      <span class="small text-muted">
        This hearing has concluded and is marked as <strong>Completed</strong>. Live attendance tracking is hidden. To resume attendance tracking, update the hearing status to <strong>Ongoing</strong>.
      </span>
    </div>
  </div>
  <div class="d-flex align-items-center gap-2">
    <a href="history.php?hearing_id=<?= (int)$hearing['id'] ?>" class="btn btn-sm btn-outline-dark">
      <i class="bi bi-clock-history me-1"></i> View Attendance History
    </a>
  </div>
</div>
<?php endif; ?>

<!-- SESSION DAY STATUS BANNER -->
<?php if($isDayClosed): ?>
<div class="alert alert-secondary border-0 shadow-sm d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 py-2.5 px-3" style="border-radius: 12px; background: #e2e8f0;">
  <div class="d-flex align-items-center gap-3">
    <div class="rounded-circle bg-dark text-white d-flex align-items-center justify-content-center shadow-sm" style="width: 40px; height: 40px;">
      <i class="bi bi-lock-fill fs-5"></i>
    </div>
    <div>
      <strong class="text-dark d-block" style="font-size: 0.95rem;">
        Attendance CLOSED · <?= e($dayLabel) ?> (<?= e($dayFormattedDate) ?>)
      </strong>
      <span class="small text-muted">
        <?= e($activeSessionDay['status_reason'] ?? 'Attendance is concluded and locked for this date to maintain official records.') ?>
      </span>
    </div>
  </div>
  <div class="d-flex align-items-center gap-2">
    <span class="badge bg-dark py-1.5 px-2.5"><i class="bi bi-lock-fill me-1"></i> Locked / Read-Only</span>
    <?php if($canManageAttendance): ?>
    <button type="button" class="btn btn-sm btn-outline-dark btn-toggle-day" data-mode="2" data-date="<?= e($selectedDate) ?>" title="Unlock attendance for this date (Administrative override)">
      <i class="bi bi-unlock me-1"></i> Reopen Session Day
    </button>
    <?php endif; ?>
  </div>
</div>
<?php elseif($dayStatus === 'upcoming'): ?>
<div class="alert alert-info border-0 shadow-sm d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 py-2.5 px-3" style="border-radius: 12px;">
  <div class="d-flex align-items-center gap-3">
    <div class="rounded-circle bg-info text-white d-flex align-items-center justify-content-center shadow-sm" style="width: 40px; height: 40px;">
      <i class="bi bi-calendar-event fs-5"></i>
    </div>
    <div>
      <strong class="text-dark d-block" style="font-size: 0.95rem;">
        Upcoming Session Day · <?= e($dayLabel) ?> (<?= e($dayFormattedDate) ?>)
      </strong>
      <span class="small text-muted">
        This session date has not arrived yet. Attendance will automatically open on <?= e($dayFormattedDate) ?>.
      </span>
    </div>
  </div>
  <div class="d-flex align-items-center gap-2">
    <?php if($canManageAttendance): ?>
    <button type="button" class="btn btn-sm btn-info text-dark fw-semibold btn-toggle-day" data-mode="2" data-date="<?= e($selectedDate) ?>" title="Open attendance early for pre-session check-in">
      <i class="bi bi-broadcast me-1"></i> Open Attendance Early
    </button>
    <?php else: ?>
    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle py-1.5 px-2.5"><i class="bi bi-clock me-1"></i> Scheduled</span>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>
<div class="alert alert-success border-0 shadow-sm d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 py-2.5 px-3" style="border-radius: 12px;">
  <div class="d-flex align-items-center gap-3">
    <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center shadow-sm" style="width: 40px; height: 40px;">
      <i class="bi bi-broadcast fs-5"></i>
    </div>
    <div>
      <strong class="text-success-emphasis d-block" style="font-size: 0.95rem;">
        Attendance ACTIVE &amp; OPEN · <?= e($dayLabel) ?> (<?= e($dayFormattedDate) ?>)
      </strong>
      <span class="small text-muted">Attendance is open for today's session. <?= $canManageAttendance ? 'Ready to scan QR passes or record Time In / Time Out.' : 'Live session records are being logged by administrators.' ?></span>
    </div>
  </div>
  <div class="d-flex align-items-center gap-2">
    <?php if($canManageAttendance): ?>
    <button type="button" class="btn btn-sm btn-outline-danger btn-toggle-day" data-mode="1" data-date="<?= e($selectedDate) ?>" title="End session day early and close attendance">
      <i class="bi bi-lock me-1"></i> Close Session Day Now
    </button>
    <?php else: ?>
    <span class="badge bg-success-subtle text-success border border-success-subtle py-1.5 px-2.5"><i class="bi bi-broadcast me-1"></i> Session Active</span>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if(!$canManageAttendance): ?>
<!-- READ-ONLY INFORMATIONAL BANNER FOR REGULAR USERS -->
<div class="alert alert-light border shadow-sm d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3 py-2 px-3" style="border-radius: 12px; background: #f8fafc; border-left: 4px solid #2563eb !important;">
  <div class="d-flex align-items-center gap-2.5">
    <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
      <i class="bi bi-shield-lock fs-5"></i>
    </div>
    <div>
      <strong class="text-dark d-block" style="font-size: 0.92rem;">Attendance Roster (View-Only Mode)</strong>
      <span class="small text-muted">You are viewing the official attendance records. Attendance recording (Time In / Time Out) is restricted to System Administrators.</span>
    </div>
  </div>
  <span class="badge bg-secondary-subtle text-secondary border px-2.5 py-1.5 fw-semibold"><i class="bi bi-eye me-1"></i> Read-Only View</span>
</div>
<?php endif; ?>

<!-- STATS FOR THE SELECTED SESSION DAY -->
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="lphx-stat">
      <i class="bi bi-people text-secondary"></i>
      <div><strong><?= count($rows) ?></strong><small>Approved Roster</small></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="lphx-stat">
      <i class="bi bi-box-arrow-in-right text-success"></i>
      <div><strong><?= $timedIn ?></strong><small>Timed In (<?= e($dayLabel) ?>)</small></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="lphx-stat">
      <i class="bi bi-box-arrow-right text-primary"></i>
      <div><strong><?= $timedOut ?></strong><small>Timed Out (<?= e($dayLabel) ?>)</small></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="lphx-stat">
      <i class="bi bi-person-x text-danger"></i>
      <div><strong><?= $absent + $excused ?></strong><small>Absent / Excused</small></div>
    </div>
  </div>
</div>

<?php if($canManageAttendance): ?>
<!-- QR CODE SCANNER BOX (ADMIN ONLY) -->
<div class="card shadow-sm border mb-3" style="border-radius: 14px; background: #ffffff;">
  <div class="card-body p-3.5">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
      <div class="d-flex align-items-center gap-2">
        <div class="rounded-circle d-flex align-items-center justify-content-center <?= $isDayClosed ? 'bg-secondary bg-opacity-10 text-secondary' : 'bg-primary bg-opacity-10 text-primary' ?>" style="width: 38px; height: 38px;">
          <i class="bi <?= $isDayClosed ? 'bi-lock-fill' : 'bi-qr-code-scan' ?> fs-5"></i>
        </div>
        <div>
          <h6 class="fw-bold mb-0 text-dark">QR Code Scanner (<?= e($dayLabel) ?> · <?= e($dayFormattedDate) ?>)</h6>
          <small class="text-muted">
            <?= $isDayClosed ? 'Attendance for this session date is concluded and locked.' : 'Scan stakeholder QR pass, invitation code, or type code for automatic Time In / Time Out' ?>
          </small>
        </div>
      </div>
      <div class="d-flex align-items-center gap-3 flex-wrap">
        <?php if(!$isDayClosed): ?>
        <button id="btnToggleCamera" class="btn btn-outline-primary btn-sm fw-semibold shadow-sm px-3">
          <i class="bi bi-camera-video me-1"></i> Use Laptop Camera
        </button>
        <div class="form-check form-switch m-0 text-dark">
          <input class="form-check-input" type="checkbox" id="autoScanToggle" checked>
          <label class="form-check-label small fw-semibold text-secondary" for="autoScanToggle">Smart Auto Time In/Out</label>
        </div>
        <?php else: ?>
        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle py-1.5 px-3 fw-semibold">
          <i class="bi bi-lock-fill me-1"></i> Scanner Locked (Past/Closed Date)
        </span>
        <?php endif; ?>
      </div>
    </div>

    <!-- LIVE LAPTOP CAMERA SCANNER VIEWPORT (If Open) -->
    <?php if(!$isDayClosed): ?>
    <div id="cameraViewportContainer" class="d-none my-3 p-3 bg-dark rounded-3 border">
      <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
          <span class="spinner-grow spinner-grow-sm text-danger" role="status"></span>
          <span class="fw-semibold small text-white">Live Laptop Camera Scanner</span>
          <span class="badge bg-success small">Active</span>
        </div>
        <div class="d-flex align-items-center gap-2">
          <div id="cameraSelectWrap" class="d-none">
            <select id="cameraSelect" class="form-select form-select-sm bg-secondary text-white border-secondary py-1" style="max-width: 220px; font-size: 0.8rem;"></select>
          </div>
          <button id="btnCloseCamera" class="btn btn-sm btn-outline-light py-0.5 px-2" style="font-size: 0.78rem;">
            <i class="bi bi-x-circle me-1"></i> Close Camera
          </button>
        </div>
      </div>
      <div class="position-relative d-flex justify-content-center align-items-center bg-black rounded overflow-hidden shadow-sm" style="min-height: 260px; max-width: 440px; margin: 0 auto; border: 2px solid rgba(255,255,255,0.2);">
        <div id="cameraReader" style="width: 100%;"></div>
      </div>
      <div class="text-center text-white-50 small mt-2">
        <i class="bi bi-lightbulb me-1"></i> Hold stakeholder QR pass in front of your laptop camera. Time In / Out triggers automatically upon detection.
      </div>
    </div>
    <?php endif; ?>

    <div class="row g-2 align-items-center mt-1">
      <div class="col-lg-6">
        <div class="input-group">
          <span class="input-group-text bg-light border-secondary-subtle"><i class="bi bi-upc-scan text-muted"></i></span>
          <input id="scanCode" class="form-control border-secondary-subtle py-2 font-monospace fw-bold"
                 <?= $isDayClosed ? 'disabled' : 'autofocus' ?>
                 autocomplete="off"
                 placeholder="<?= $isDayClosed ? '🔒 Attendance is closed for this session date' : 'Scan Stakeholder QR Pass (STK-...) or Registration Code' ?>">
        </div>
      </div>
      <div class="col-6 col-lg-2">
        <button id="btnFindCode" class="btn btn-outline-secondary w-100 fw-semibold" <?= $isDayClosed ? 'disabled' : '' ?>>
          <i class="bi bi-search me-1"></i> Find
        </button>
      </div>
      <div class="col-3 col-lg-2">
        <button id="btnScanCheckIn" class="btn btn-success w-100 fw-semibold shadow-sm" disabled>
          <i class="bi bi-box-arrow-in-right me-1"></i> Time In
        </button>
      </div>
      <div class="col-3 col-lg-2">
        <button id="btnScanCheckOut" class="btn btn-warning w-100 fw-semibold text-dark shadow-sm" disabled>
          <i class="bi bi-box-arrow-right me-1"></i> Time Out
        </button>
      </div>
    </div>

    <div id="scanResultBox" class="mt-2.5 p-2.5 rounded bg-light border border-light-subtle small d-flex align-items-center justify-content-between flex-wrap gap-2" style="min-height: 42px;">
      <div id="scanResultText" class="text-muted">
        <?php if($isDayClosed): ?>
          <span class="text-secondary fw-semibold"><i class="bi bi-lock-fill me-1 text-warning"></i> Attendance for this date (<?= e($dayFormattedDate) ?>) is closed. No new check-ins will be accepted.</span>
        <?php else: ?>
          <i class="bi bi-info-circle me-1 text-primary"></i> Ready for scan. Aim scanner at attendee's Stakeholder QR Pass or type their code and press Enter.
        <?php endif; ?>
      </div>
      <div id="scanBadges" class="d-flex align-items-center gap-2"></div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ATTENDANCE ROSTER TABLE -->
<div class="card lphx-card shadow-sm border-0">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <span class="fw-bold"><i class="bi bi-list-check me-1 text-primary"></i> <?= e($hearing['title']) ?></span>
      <span class="badge bg-success-subtle text-success border border-success-subtle ms-2"><i class="bi bi-envelope-check me-1"></i><?= count($rows) ?> approved attendee(s)</span>
      <span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-1 font-monospace"><?= e($dayLabel) ?> · <?= e($dayFormattedDate) ?></span>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
      <?php if($canManageAttendance && !$isDayClosed): ?>
      <div id="bulkRosterActions" class="d-none d-flex gap-1 align-items-center">
        <button type="button" class="btn btn-sm btn-success text-nowrap" onclick="executeBulkAttendance('Present')">
          <i class="bi bi-check-all me-1"></i> Mark Present (<span id="bulkSelectedCount">0</span>)
        </button>
        <button type="button" class="btn btn-sm btn-outline-danger text-nowrap" onclick="executeBulkAttendance('Absent')">
          <i class="bi bi-x-circle me-1"></i> Absent
        </button>
      </div>
      <?php endif; ?>
      <a href="export_excel.php?hearing_id=<?= $hearingId ?>&date=<?= urlencode($selectedDate) ?>" class="btn btn-sm btn-outline-success text-nowrap" title="Export this session roster to Excel">
        <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
      </a>
      <?php if($canManageAttendance): ?>
      <a href="../stakeholders/invitations.php?hearing_id=<?= (int)$hearingId ?>" class="btn btn-sm btn-outline-secondary text-nowrap">
        <i class="bi bi-envelope-paper me-1"></i> Invitations
      </a>
      <?php endif; ?>
      <a href="print.php?hearing_id=<?= $hearingId ?>&date=<?= urlencode($selectedDate) ?>" target="_blank" class="btn btn-sm btn-outline-primary text-nowrap">
        <i class="bi bi-printer me-1"></i> Print <?= e($dayLabel) ?> Roster
      </a>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" id="attendanceTable">
      <thead class="table-light">
        <tr>
          <?php if($canManageAttendance && !$isDayClosed): ?>
          <th style="width: 40px;" class="text-center">
            <input type="checkbox" class="form-check-input" id="checkAllRoster" onchange="toggleAllRosterCheckboxes(this)" title="Select all attendees for bulk check-in">
          </th>
          <?php endif; ?>
          <th style="min-width: 200px;">Stakeholder</th>
          <th style="min-width: 140px;">QR Pass / Reg Code</th>
          <th style="min-width: 130px;">Status (<?= e($dayLabel) ?>)</th>
          <th style="min-width: 130px;">Time In</th>
          <th style="min-width: 130px;">Time Out</th>
          <th class="text-end" style="min-width: <?= $canManageAttendance ? '200px' : '100px' ?>;"><?= $canManageAttendance ? 'Actions' : 'Pass' ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if(!$rows): ?>
        <tr>
          <td colspan="<?= ($canManageAttendance && !$isDayClosed) ? '7' : '6' ?>" class="text-center py-5">
            <div class="text-muted">
              <i class="bi bi-people display-6 d-block mb-2 text-secondary opacity-50"></i>
              <strong>No approved invitees found for this hearing session.</strong>
              <p class="small mb-3">Only stakeholders with an approved / accepted invitation are placed on the attendance roster.</p>
              <?php if($canManageAttendance): ?>
              <a href="../stakeholders/invitations.php?hearing_id=<?= (int)$hearingId ?>" class="btn btn-sm btn-primary">
                <i class="bi bi-envelope-paper me-1"></i> Review &amp; Approve Invitations
              </a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endif; ?>

        <?php foreach($rows as $r): ?>
        <tr id="roster-row-<?= (int)$r['registration_id'] ?>" data-id="<?= (int)$r['registration_id'] ?>" class="<?= !empty($r['checked_out_at']) ? 'table-light text-muted' : (!empty($r['checked_in_at']) ? 'bg-success bg-opacity-10' : '') ?>">
          <?php if($canManageAttendance && !$isDayClosed): ?>
          <td class="text-center">
            <input type="checkbox" class="form-check-input roster-checkbox" value="<?= (int)$r['stakeholder_id'] ?>" data-reg-id="<?= (int)$r['registration_id'] ?>" onchange="updateBulkAttendanceSelection()" <?= (!empty($r['checked_out_at'])) ? 'disabled' : '' ?>>
          </td>
          <?php endif; ?>
          <td>
            <strong class="text-dark d-block"><?= e($r['full_name']) ?></strong>
            <div class="small text-muted"><?= e($r['organization'] ?: $r['email']) ?></div>
          </td>
          <td>
            <?php if(!empty($r['code_value'])): ?>
              <a href="../stakeholders/qr.php?id=<?= (int)$r['stakeholder_id'] ?>" target="_blank" class="badge bg-light text-dark border text-decoration-none font-monospace d-inline-flex align-items-center gap-1" title="View & Print QR Pass">
                <i class="bi bi-qr-code text-primary"></i> <?= e($r['code_value']) ?>
              </a>
            <?php else: ?>
              <span class="badge bg-light text-muted border font-monospace"><?= e($r['registration_code']) ?></span>
            <?php endif; ?>
          </td>
          <td>
            <?php if(!empty($r['checked_out_at'])): ?>
              <span class="badge bg-primary-subtle text-primary border border-primary-subtle d-inline-flex align-items-center gap-1">
                <i class="bi bi-check2-all"></i> Completed
              </span>
            <?php elseif(!empty($r['checked_in_at'])): ?>
              <span class="badge bg-success-subtle text-success border border-success-subtle d-inline-flex align-items-center gap-1">
                <i class="bi bi-box-arrow-in-right"></i> In Session
              </span>
            <?php elseif($r['attendance_status']==='Absent'): ?>
              <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Absent</span>
            <?php elseif($r['attendance_status']==='Excused'): ?>
              <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Excused</span>
            <?php else: ?>
              <span class="badge bg-light text-muted border">Not Marked</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if(!empty($r['checked_in_at'])): ?>
              <span class="text-success fw-bold d-inline-flex align-items-center gap-1">
                <i class="bi bi-box-arrow-in-right"></i> <?= date('h:i A', strtotime($r['checked_in_at'])) ?>
              </span>
              <div class="small text-muted" style="font-size: 0.72rem;"><?= e($r['check_in_method'] ?: 'QR') ?></div>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if(!empty($r['checked_out_at'])): ?>
              <span class="text-primary fw-bold d-inline-flex align-items-center gap-1">
                <i class="bi bi-box-arrow-right"></i> <?= date('h:i A', strtotime($r['checked_out_at'])) ?>
              </span>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <?php if(!$canManageAttendance): ?>
              <?php if(!empty($r['stakeholder_id'])): ?>
                <a href="../stakeholders/qr.php?id=<?= (int)$r['stakeholder_id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="View &amp; Print QR Pass">
                  <i class="bi bi-qr-code me-1"></i> Pass
                </a>
              <?php else: ?>
                <span class="badge bg-light text-muted border">View Only</span>
              <?php endif; ?>
            <?php elseif($isDayClosed): ?>
              <span class="badge bg-light text-muted border py-1.5 px-2" title="Attendance is closed for this session date">
                <i class="bi bi-lock me-1"></i> Closed
              </span>
              <a href="../stakeholders/qr.php?id=<?= (int)$r['stakeholder_id'] ?>" target="_blank" class="btn btn-sm btn-outline-dark ms-1" title="View &amp; Print QR Pass">
                <i class="bi bi-qr-code"></i>
              </a>
            <?php elseif(!empty($r['checked_out_at'])): ?>
              <!-- Permanent lock once Timed Out -->
              <div class="d-inline-flex align-items-center gap-1">
                <span class="badge bg-secondary-subtle text-secondary border py-1.5 px-2" title="Attendance completed and permanently locked. Cannot be modified.">
                  <i class="bi bi-lock-fill me-1"></i> Completed &amp; Locked
                </span>
                <a href="../stakeholders/qr.php?id=<?= (int)$r['stakeholder_id'] ?>" target="_blank" class="btn btn-sm btn-outline-dark" title="View &amp; Print QR Pass">
                  <i class="bi bi-qr-code"></i>
                </a>
              </div>
            <?php elseif(!empty($r['checked_in_at'])): ?>
              <!-- Permanent lock on Time In (cannot be changed to Absent/Excused; only Time Out allowed) -->
              <div class="btn-group btn-group-sm">
                <button class="btn btn-success" disabled title="Time In is recorded and locked. Cannot be changed.">
                  <i class="bi bi-check2"></i> In
                </button>
                <button class="btn btn-outline-primary att-action"
                  data-id="<?= (int)$r['registration_id'] ?>" data-action="check_out" title="Record Time Out">
                  <i class="bi bi-box-arrow-right"></i> Out
                </button>
                <a href="../stakeholders/qr.php?id=<?= (int)$r['stakeholder_id'] ?>" target="_blank" class="btn btn-outline-dark" title="View &amp; Print QR Pass">
                  <i class="bi bi-qr-code"></i>
                </a>
              </div>
            <?php else: ?>
              <!-- Not yet timed in: Time In, Absent, and Excused are available -->
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-success att-action"
                  data-id="<?= (int)$r['registration_id'] ?>" data-action="check_in" title="Record Time In">
                  <i class="bi bi-box-arrow-in-right"></i> In
                </button>
                <button class="btn btn-outline-secondary" disabled title="Must record Time In before Time Out">
                  <i class="bi bi-box-arrow-right"></i> Out
                </button>
                <button class="btn <?= $r['attendance_status']==='Absent' ? 'btn-danger' : 'btn-outline-danger' ?> att-action"
                  data-id="<?= (int)$r['registration_id'] ?>" data-action="absent" title="Mark Absent">
                  <i class="bi bi-x"></i>
                </button>
                <button class="btn <?= $r['attendance_status']==='Excused' ? 'btn-secondary' : 'btn-outline-secondary' ?> att-action"
                  data-id="<?= (int)$r['registration_id'] ?>" data-action="excused" title="Mark Excused">
                  <i class="bi bi-dash"></i>
                </button>
                <a href="../stakeholders/qr.php?id=<?= (int)$r['stakeholder_id'] ?>" target="_blank" class="btn btn-outline-dark" title="View &amp; Print QR Pass">
                  <i class="bi bi-qr-code"></i>
                </a>
              </div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- FAST WALK-IN ENROLLMENT MODAL (ADMIN ONLY) -->
<?php if($canManageAttendance && !$isDayClosed && !empty($unregisteredStakeholders)): ?>
<div class="modal fade" id="enrollModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered" style="max-width: 540px;">
    <div class="modal-content border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
      <div class="modal-header py-3 px-4 bg-light border-bottom">
        <h6 class="modal-title fw-bold text-dark mb-0"><i class="bi bi-person-plus me-1 text-primary"></i> Enroll Stakeholder into Hearing</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-3">
        <p class="small text-muted mb-2">Select any existing stakeholder to add them to this hearing session roster:</p>
        <div class="list-group list-group-flush border rounded" style="max-height: 280px; overflow-y: auto;">
          <?php foreach($unregisteredStakeholders as $us): ?>
          <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
            <div>
              <strong class="d-block" style="font-size: 0.9rem;"><?= e($us['full_name']) ?></strong>
              <small class="text-muted"><?= e($us['organization'] ?: $us['email']) ?>
                <?php if(!empty($us['code_value'])): ?>
                  · <span class="font-monospace text-primary"><?= e($us['code_value']) ?></span>
                <?php endif; ?>
              </small>
            </div>
            <button class="btn btn-sm btn-primary btn-enroll-now" data-code="<?= e($us['code_value'] ?: '') ?>" data-sid="<?= (int)$us['id'] ?>">
              Enroll &amp; In
            </button>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php else: ?>

<!-- HEARINGS DIRECTORY: SEARCH, FILTER & VIEW CONTROLS -->
<div class="card shadow-sm border-0 mb-3" style="border-radius: 14px; background: #ffffff;">
  <div class="card-body p-3">
    <div class="row g-2 align-items-center justify-content-between">
      <div class="col-md-4">
        <div class="input-group input-group-sm">
          <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
          <input type="text" id="hearingSearchInput" class="form-control border-start-0" placeholder="Search hearing title, ref #, or venue...">
        </div>
      </div>
      <div class="col-md-5 d-flex gap-1.5 flex-wrap">
        <button type="button" class="btn btn-sm filter-btn active" data-filter="all">
          All (<?= count($allHearings) ?>)
        </button>
        <button type="button" class="btn btn-sm filter-btn btn-outline-success" data-filter="ongoing">
          <span class="status-pulse me-1"></span> Ongoing (<?= $ongoingCount ?>)
        </button>
        <button type="button" class="btn btn-sm filter-btn btn-outline-primary" data-filter="upcoming">
          Upcoming (<?= $upcomingCount ?>)
        </button>
        <button type="button" class="btn btn-sm filter-btn btn-outline-secondary" data-filter="completed">
          Completed (<?= $completedCount ?>)
        </button>
      </div>
      <div class="col-md-3 text-end d-flex justify-content-end align-items-center gap-2">
        <span class="small text-muted">View:</span>
        <div class="btn-group btn-group-sm" role="group">
          <button type="button" class="btn btn-outline-secondary view-btn active" id="btnViewCards" title="Grid Cards View">
            <i class="bi bi-grid-3x3-gap-fill me-1"></i> Cards
          </button>
          <button type="button" class="btn btn-outline-secondary view-btn" id="btnViewTable" title="Table View">
            <i class="bi bi-table me-1"></i> Table
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- CARDS VIEW (DEFAULT) -->
<div id="hearingsCardView" class="row g-3">
  <?php foreach($allHearings as $h): ?>
    <?php
      $daysCount = 1;
      if (!empty($h['end_date']) && !empty($h['hearing_date']) && $h['end_date'] > $h['hearing_date']) {
        $d1 = new DateTime($h['hearing_date']);
        $d2 = new DateTime($h['end_date']);
        $daysCount = $d1->diff($d2)->days + 1;
      }
      $dateLabel = formatDate($h['hearing_date']);
      if ($daysCount > 1) {
        $dateLabel .= ' – ' . formatDate($h['end_date']);
      }
      $timeLabel = !empty($h['hearing_time']) ? date('g:i A', strtotime($h['hearing_time'])) : '';
      $statusLower = strtolower($h['status']);
      $searchString = strtolower($h['reference_number'] . ' ' . $h['title'] . ' ' . ($h['venue'] ?? '') . ' ' . $h['status']);
    ?>
    <div class="col-md-6 col-xl-4 hearing-card-item" data-status="<?= e($statusLower) ?>" data-search="<?= e($searchString) ?>">
      <div class="hearing-card <?= e($statusLower) ?> p-3">
        <!-- Top header: Reference & Status Badge -->
        <div class="d-flex justify-content-between align-items-center mb-2">
          <span class="text-muted font-monospace fw-semibold" style="font-size: 0.78rem;">
            <?= e($h['reference_number'] ?: 'PHC-'.$h['id']) ?>
          </span>
          <span class="status-pill-badge status-<?= e($statusLower) ?>">
            <?php if($h['status'] === 'Ongoing'): ?>
              <span class="status-pulse me-0.5"></span>
            <?php elseif($h['status'] === 'Upcoming'): ?>
              <i class="bi bi-clock me-0.5"></i>
            <?php else: ?>
              <i class="bi bi-check2 me-0.5"></i>
            <?php endif; ?>
            <?= e($h['status']) ?>
          </span>
        </div>

        <!-- Hearing Title -->
        <h6 class="hearing-title-clamp mb-2" title="<?= e($h['title']) ?>">
          <a href="?hearing_id=<?= (int)$h['id'] ?>" class="text-decoration-none">
            <?= e($h['title']) ?>
          </a>
        </h6>

        <!-- Date, Time & Venue -->
        <div class="small text-muted mb-2.5 d-flex flex-column gap-1">
          <div class="d-flex align-items-center gap-1.5 flex-wrap">
            <i class="bi bi-calendar3 text-primary opacity-75" style="font-size: 0.8rem;"></i>
            <span class="fw-semibold text-dark"><?= e($dateLabel) ?></span>
            <?php if($daysCount > 1): ?>
              <span class="badge bg-light text-secondary border px-1.5 py-0.5" style="font-size: 0.65rem;">
                <?= $daysCount ?> Days
              </span>
            <?php endif; ?>
            <?php if($timeLabel): ?>
              <span class="text-secondary opacity-50">·</span>
              <span class="text-secondary"><?= e($timeLabel) ?></span>
            <?php endif; ?>
          </div>
          <div class="d-flex align-items-center gap-1.5 text-truncate">
            <i class="bi bi-geo-alt text-muted opacity-75" style="font-size: 0.8rem;"></i>
            <span class="text-truncate"><?= e($h['venue'] ?: 'Manila City Hall') ?></span>
          </div>
        </div>

        <!-- Sleek Attendance Stat Strip (Single clean line) -->
        <div class="attendance-stat-strip mb-2.5 mt-auto">
          <div class="attendance-stat-item" title="Registered Stakeholders">
            <i class="bi bi-people text-secondary"></i>
            <span>Roster: <strong class="text-dark"><?= (int)$h['approved_count'] ?></strong></span>
          </div>
          <span class="text-muted opacity-25">|</span>
          <div class="attendance-stat-item" title="Checked In">
            <i class="bi bi-box-arrow-in-right text-success"></i>
            <span>In: <strong class="text-success"><?= (int)$h['checked_in_count'] ?></strong></span>
          </div>
          <span class="text-muted opacity-25">|</span>
          <div class="attendance-stat-item" title="Checked Out">
            <i class="bi bi-box-arrow-right text-primary"></i>
            <span>Out: <strong class="text-primary"><?= (int)$h['checked_out_count'] ?></strong></span>
          </div>
        </div>

        <!-- Action Button -->
        <div class="pt-2 border-top">
          <a href="?hearing_id=<?= (int)$h['id'] ?>" class="btn btn-sm <?= $h['status'] === 'Ongoing' ? 'btn-success text-white' : ($h['status'] === 'Upcoming' ? 'btn-primary text-white' : 'btn-outline-secondary') ?> w-100 py-1.5 fw-semibold d-flex align-items-center justify-content-center gap-1.5 rounded-2" style="font-size: 0.82rem;">
            <span><?= $h['status'] === 'Ongoing' ? 'Open Scanner & Attendance' : ($h['status'] === 'Upcoming' ? 'Track Attendance' : 'View Attendance') ?></span>
            <i class="bi bi-arrow-right"></i>
          </a>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- TABLE VIEW (ALTERNATIVE) -->
<div id="hearingsTableView" class="d-none">
  <div class="card shadow-sm border" style="border-radius: 12px; border-color: #e9ecef !important; overflow: hidden; background: #ffffff;">
    <div class="table-responsive stakeholder-scroll-container">
      <table class="table table-hover lphx-table mb-0 align-middle">
        <thead class="bg-light">
          <tr>
            <th style="width: 140px;" class="text-secondary small fw-semibold">Reference #</th>
            <th class="text-secondary small fw-semibold">Hearing Title</th>
            <th style="width: 220px;" class="text-secondary small fw-semibold">Schedule &amp; Venue</th>
            <th class="text-center text-secondary small fw-semibold" style="width: 120px;">Status</th>
            <th class="text-center text-secondary small fw-semibold" style="width: 190px;">Attendance Summary</th>
            <th class="text-end text-secondary small fw-semibold" style="width: 130px;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($allHearings as $h): ?>
            <?php
              $daysCount = 1;
              if (!empty($h['end_date']) && !empty($h['hearing_date']) && $h['end_date'] > $h['hearing_date']) {
                $d1 = new DateTime($h['hearing_date']);
                $d2 = new DateTime($h['end_date']);
                $daysCount = $d1->diff($d2)->days + 1;
              }
              $dateLabel = formatDate($h['hearing_date']);
              if ($daysCount > 1) {
                $dateLabel .= ' – ' . formatDate($h['end_date']);
              }
              $timeLabel = !empty($h['hearing_time']) ? date('g:i A', strtotime($h['hearing_time'])) : '';
              $statusLower = strtolower($h['status']);
              $searchString = strtolower($h['reference_number'] . ' ' . $h['title'] . ' ' . ($h['venue'] ?? '') . ' ' . $h['status']);
            ?>
            <tr class="hearing-table-row" data-status="<?= e($statusLower) ?>" data-search="<?= e($searchString) ?>" onclick="window.location.href='?hearing_id=<?= (int)$h['id'] ?>'">
              <td>
                <span class="font-monospace text-muted small fw-semibold">
                  <?= e($h['reference_number'] ?: 'PHC-'.$h['id']) ?>
                </span>
              </td>
              <td>
                <div class="fw-semibold text-dark" style="font-size: 0.92rem;"><?= e($h['title']) ?></div>
                <?php if($daysCount > 1): ?>
                  <span class="badge bg-light text-secondary border px-1.5 py-0.5 mt-1" style="font-size: 0.65rem;">
                    <i class="bi bi-calendar3-range me-1"></i><?= $daysCount ?> Days Multi-Session
                  </span>
                <?php endif; ?>
              </td>
              <td>
                <div class="small fw-semibold text-dark"><?= e($dateLabel) ?></div>
                <div class="small text-muted text-truncate" style="max-width: 210px;">
                  <?php if($timeLabel): ?><?= e($timeLabel) ?> · <?php endif; ?><?= e($h['venue'] ?: 'Manila City Hall') ?>
                </div>
              </td>
              <td class="text-center">
                <span class="status-pill-badge status-<?= e($statusLower) ?>">
                  <?php if($h['status'] === 'Ongoing'): ?>
                    <span class="status-pulse me-0.5"></span>
                  <?php elseif($h['status'] === 'Upcoming'): ?>
                    <i class="bi bi-clock me-0.5"></i>
                  <?php else: ?>
                    <i class="bi bi-check2 me-0.5"></i>
                  <?php endif; ?>
                  <?= e($h['status']) ?>
                </span>
              </td>
              <td class="text-center">
                <div class="d-inline-flex align-items-center gap-2 small text-nowrap px-2 py-1 rounded bg-light border border-light-subtle">
                  <span class="text-secondary" title="Approved Roster"><i class="bi bi-people me-1"></i><?= (int)$h['approved_count'] ?></span>
                  <span class="text-muted opacity-25">|</span>
                  <span class="text-success fw-semibold" title="Checked In"><i class="bi bi-box-arrow-in-right me-1"></i><?= (int)$h['checked_in_count'] ?> In</span>
                  <span class="text-muted opacity-25">|</span>
                  <span class="text-primary fw-semibold" title="Checked Out"><i class="bi bi-box-arrow-right me-1"></i><?= (int)$h['checked_out_count'] ?> Out</span>
                </div>
              </td>
              <td class="text-end" onclick="event.stopPropagation();">
                <a href="?hearing_id=<?= (int)$h['id'] ?>" class="btn btn-sm <?= $h['status'] === 'Ongoing' ? 'btn-success text-white' : ($h['status'] === 'Upcoming' ? 'btn-outline-primary' : 'btn-outline-secondary') ?> px-2.5 py-1" style="font-size: 0.8rem; border-radius: 6px;">
                  <i class="bi bi-qr-code-scan me-1"></i> Track
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- EMPTY STATE WHEN NO SEARCH/FILTER MATCHES -->
<div id="hearingsNoMatches" class="d-none text-center py-5 bg-white rounded-3 border shadow-sm my-3">
  <i class="bi bi-search text-muted display-4 d-block mb-2"></i>
  <h5>Walang hearing na tumugma</h5>
  <p class="text-muted small">Subukang baguhin ang search term o ang napiling status filter.</p>
</div>

<?php endif; ?>

</div></div>

<?php if(!$hearing): ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
  const searchInput = document.getElementById('hearingSearchInput');
  const filterBtns = document.querySelectorAll('.filter-btn');
  const cardItems = document.querySelectorAll('.hearing-card-item');
  const tableRows = document.querySelectorAll('.hearing-table-row');
  const btnViewCards = document.getElementById('btnViewCards');
  const btnViewTable = document.getElementById('btnViewTable');
  const cardView = document.getElementById('hearingsCardView');
  const tableView = document.getElementById('hearingsTableView');
  const noMatches = document.getElementById('hearingsNoMatches');

  let activeFilter = 'all';

  function filterHearings() {
    const query = (searchInput?.value || '').toLowerCase().trim();
    let visibleCount = 0;

    cardItems.forEach(card => {
      const matchSearch = !query || (card.dataset.search || '').includes(query);
      const matchStatus = (activeFilter === 'all') || (card.dataset.status === activeFilter);
      if (matchSearch && matchStatus) {
        card.classList.remove('d-none');
        visibleCount++;
      } else {
        card.classList.add('d-none');
      }
    });

    tableRows.forEach(row => {
      const matchSearch = !query || (row.dataset.search || '').includes(query);
      const matchStatus = (activeFilter === 'all') || (row.dataset.status === activeFilter);
      if (matchSearch && matchStatus) {
        row.classList.remove('d-none');
      } else {
        row.classList.add('d-none');
      }
    });

    if (noMatches) {
      if (visibleCount === 0) {
        noMatches.classList.remove('d-none');
      } else {
        noMatches.classList.add('d-none');
      }
    }
  }

  if (searchInput) searchInput.addEventListener('input', filterHearings);

  filterBtns.forEach(btn => {
    btn.addEventListener('click', function(){
      filterBtns.forEach(b => {
        b.classList.remove('active');
        b.classList.remove('btn-primary');
        b.classList.remove('btn-success');
        b.classList.remove('btn-secondary');
        if (b.dataset.filter === 'ongoing') b.classList.add('btn-outline-success');
        else if (b.dataset.filter === 'upcoming') b.classList.add('btn-outline-primary');
        else if (b.dataset.filter === 'completed') b.classList.add('btn-outline-secondary');
        else b.classList.add('btn-outline-dark');
      });
      this.classList.add('active');
      activeFilter = this.dataset.filter;
      filterHearings();
    });
  });

  if (btnViewCards && btnViewTable && cardView && tableView) {
    btnViewCards.addEventListener('click', function(){
      btnViewCards.classList.add('active');
      btnViewCards.classList.remove('btn-outline-secondary');
      btnViewTable.classList.remove('active');
      btnViewTable.classList.add('btn-outline-secondary');
      cardView.classList.remove('d-none');
      tableView.classList.add('d-none');
      try { localStorage.setItem('lph_hearing_view', 'cards'); } catch(e){}
    });

    btnViewTable.addEventListener('click', function(){
      btnViewTable.classList.add('active');
      btnViewTable.classList.remove('btn-outline-secondary');
      btnViewCards.classList.remove('active');
      btnViewCards.classList.add('btn-outline-secondary');
      tableView.classList.remove('d-none');
      cardView.classList.add('d-none');
      try { localStorage.setItem('lph_hearing_view', 'table'); } catch(e){}
    });

    try {
      if (localStorage.getItem('lph_hearing_view') === 'table') {
        btnViewTable.click();
      }
    } catch(e){}
  }
});
</script>
<?php endif; ?>

<?php if($hearing && $canManageAttendance): ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
  const hearingId = <?= (int)$hearingId ?>;
  const selectedDate = '<?= e($selectedDate) ?>';
  const isDayClosed = <?= $isDayClosed ? 'true' : 'false' ?>;
  const csrf = '<?= e(csrfToken()) ?>';
  const scanInput = document.getElementById('scanCode');
  const btnFind = document.getElementById('btnFindCode');
  const btnCheckIn = document.getElementById('btnScanCheckIn');
  const btnCheckOut = document.getElementById('btnScanCheckOut');
  const resultText = document.getElementById('scanResultText');
  const resultBadges = document.getElementById('scanBadges');
  const autoScanSwitch = document.getElementById('autoScanToggle');

  let currentFound = null;

  // Web Audio chime for scanner feedback
  function playScanSound(type = 'in'){
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'sine';
      osc.connect(gain);
      gain.connect(ctx.destination);
      const now = ctx.currentTime;
      if (type === 'out') {
        osc.frequency.setValueAtTime(587.33, now); // D5
        osc.frequency.setValueAtTime(440.00, now + 0.1); // A4
        gain.gain.setValueAtTime(0.2, now);
        gain.gain.exponentialRampToValueAtTime(0.01, now + 0.25);
        osc.start(now);
        osc.stop(now + 0.25);
      } else {
        osc.frequency.setValueAtTime(523.25, now); // C5
        osc.frequency.setValueAtTime(659.25, now + 0.1); // E5
        gain.gain.setValueAtTime(0.2, now);
        gain.gain.exponentialRampToValueAtTime(0.01, now + 0.25);
        osc.start(now);
        osc.stop(now + 0.25);
      }
    } catch(e){}
  }

  // Attendance Action Handler (Time In, Time Out, Absent, Excused)
  async function performAction(registrationId, action, method = 'Manual'){
    if (!registrationId) return;
    if (isDayClosed) {
      Swal.fire('Attendance Closed', 'Attendance for this date (' + selectedDate + ') is closed because the session has concluded.', 'warning');
      return;
    }

    const fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('registration_id', registrationId);
    fd.append('action', action);
    fd.append('check_in_method', method);
    fd.append('session_date', selectedDate);

    try {
      const res = await fetch(APP_URL + '/modules/attendance/ajax_attendance_action.php', {
        method: 'POST',
        body: fd
      }).then(r => r.json());

      if (res.success) {
        playScanSound(action === 'check_out' ? 'out' : 'in');
        appToast('success', res.message);
        setTimeout(() => location.reload(), 450);
      } else {
        Swal.fire('Attendance Notice', res.message, 'warning');
      }
    } catch (err) {
      Swal.fire('Connection Error', 'Failed to communicate with server.', 'error');
    }
  }

  // Row button clicks
  document.querySelectorAll('.att-action').forEach(btn => {
    btn.onclick = () => performAction(btn.dataset.id, btn.dataset.action, 'Manual');
  });

  // Fast enroll buttons from modal
  document.querySelectorAll('.btn-enroll-now').forEach(btn => {
    btn.onclick = async () => {
      const code = btn.dataset.code;
      if (code) {
        if (scanInput) scanInput.value = code;
        const modalEl = document.getElementById('enrollModal');
        if (modalEl) {
          const bsModal = bootstrap.Modal.getInstance(modalEl);
          if (bsModal) bsModal.hide();
        }
        await lookupCode(true); // scan & auto time-in
      }
    };
  });

  // Toggle Day Closure (Close or Reopen)
  document.querySelectorAll('.btn-toggle-day').forEach(btn => {
    btn.onclick = async () => {
      const mode = parseInt(btn.dataset.mode || '1', 10);
      const targetDate = btn.dataset.date || selectedDate;
      const promptTitle = (mode === 1) ? 'Close Attendance for this Date?' : 'Reopen Attendance for this Date?';
      const promptText = (mode === 1) 
        ? 'Closing this session day (' + targetDate + ') will make attendance records read-only and prevent any new scans or Time In / Time Out entries.'
        : 'This will reopen attendance for date ' + targetDate + ' to allow new scans and attendance recording.';

      const result = await Swal.fire({
        title: promptTitle,
        text: promptText,
        icon: (mode === 1) ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonText: (mode === 1) ? 'Yes, Close Attendance' : 'Yes, Reopen Attendance',
        confirmButtonColor: (mode === 1) ? '#dc2626' : '#2563eb'
      });

      if (!result.isConfirmed) return;

      const fd = new FormData();
      fd.append('csrf_token', csrf);
      fd.append('hearing_id', hearingId);
      fd.append('session_date', targetDate);
      fd.append('mode', mode);

      try {
        const res = await fetch(APP_URL + '/modules/attendance/ajax_day_status.php', {
          method: 'POST',
          body: fd
        }).then(r => r.json());

        if (res.success) {
          appToast('success', res.message);
          setTimeout(() => location.reload(), 450);
        } else {
          Swal.fire('Error', res.message, 'error');
        }
      } catch (e) {
        Swal.fire('Error', 'Failed to update day status.', 'error');
      }
    };
  });

  // Code lookup logic
  async function lookupCode(isAuto = false){
    if (isDayClosed) {
      Swal.fire('Attendance Closed', 'Attendance for date ' + selectedDate + ' is closed.', 'warning');
      return;
    }

    if (!scanInput) return;
    const code = scanInput.value.trim();
    if (!code) {
      scanInput.focus();
      return;
    }

    if (resultText) resultText.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Looking up code <strong>' + code + '</strong> for ' + selectedDate + '...';
    if (resultBadges) resultBadges.innerHTML = '';
    if (btnCheckIn) btnCheckIn.disabled = true;
    if (btnCheckOut) btnCheckOut.disabled = true;

    try {
      const res = await appGet(APP_URL + '/modules/attendance/ajax_scan.php?hearing_id=' + hearingId + '&session_date=' + encodeURIComponent(selectedDate) + '&code=' + encodeURIComponent(code));

      if (res.success) {
        currentFound = res.registration;

        let statusText = '';
        let statusBadgeClass = 'bg-secondary';
        const isLockedOut = Boolean(currentFound.checked_out_at);
        const isLockedIn = Boolean(currentFound.checked_in_at);

        if (isLockedOut) {
          statusText = '<i class="bi bi-lock-fill me-1"></i> Completed & Locked (Timed Out: ' + currentFound.checked_out_at + ')';
          statusBadgeClass = 'bg-secondary text-white';
        } else if (isLockedIn) {
          statusText = '<i class="bi bi-check2 me-1"></i> Timed In (Locked) at ' + currentFound.checked_in_at;
          statusBadgeClass = 'bg-success text-white';
        } else {
          statusText = 'Ready for Time In';
          statusBadgeClass = 'bg-warning text-dark';
        }

        if (resultText) {
          resultText.innerHTML = '<strong class="text-dark fs-6">' + currentFound.full_name + '</strong>' +
            '<span class="text-muted ms-2">(' + (currentFound.organization || currentFound.email) + ')</span>' +
            '<span class="ms-2 font-monospace text-primary small">[' + (currentFound.code_value || currentFound.registration_code) + ']</span>' +
            '<span class="badge bg-secondary ms-2 small">' + (currentFound.day_label || selectedDate) + '</span>';
        }

        if (resultBadges) {
          resultBadges.innerHTML = '<span class="badge ' + statusBadgeClass + ' py-1 px-2">' + statusText + '</span>';
        }

        if (btnCheckIn) btnCheckIn.disabled = isLockedIn || isLockedOut;
        if (btnCheckOut) btnCheckOut.disabled = !isLockedIn || isLockedOut;

        // Smart auto-action when barcode/QR scanner hits Enter
        if (isAuto && autoScanSwitch && autoScanSwitch.checked && !isLockedOut) {
          const recommended = currentFound.recommended_action || 'check_in';
          if ((recommended === 'check_in' && !isLockedIn) || (recommended === 'check_out' && isLockedIn)) {
            performAction(currentFound.registration_id, recommended, 'QR');
          }
        }
      } else {
        currentFound = null;
        if (resultText) resultText.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i> ' + res.message + '</span>';
        if (resultBadges) resultBadges.innerHTML = '';
        if (btnCheckIn) btnCheckIn.disabled = true;
        if (btnCheckOut) btnCheckOut.disabled = true;
      }
    } catch (err) {
      if (resultText) resultText.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i> Error processing scan request.</span>';
    }
  }

  if (btnFind) btnFind.onclick = () => lookupCode(false);

  if (scanInput) {
    scanInput.addEventListener('keydown', function(e){
      if (e.key === 'Enter') {
        e.preventDefault();
        lookupCode(true);
      }
    });
  }

  if (btnCheckIn) {
    btnCheckIn.onclick = () => {
      if (currentFound) performAction(currentFound.registration_id, 'check_in', 'QR');
    };
  }

  if (btnCheckOut) {
    btnCheckOut.onclick = () => {
      if (currentFound) performAction(currentFound.registration_id, 'check_out', 'QR');
    };
  }

  /* ================= LAPTOP CAMERA SCANNER (html5-qrcode) ================= */
  let html5QrCode = null;
  let isCameraRunning = false;
  let lastCameraCode = null;
  let lastCameraTime = 0;

  const btnToggleCam = document.getElementById('btnToggleCamera');
  const btnCloseCam = document.getElementById('btnCloseCamera');
  const camContainer = document.getElementById('cameraViewportContainer');
  const camSelect = document.getElementById('cameraSelect');
  const camSelectWrap = document.getElementById('cameraSelectWrap');

  async function startCamera(preferredCameraId = null){
    if (isDayClosed) {
      Swal.fire('Attendance Closed', 'Scanning is disabled because attendance for this session date is closed.', 'warning');
      return;
    }

    if (typeof Html5Qrcode === 'undefined') {
      Swal.fire('Scanner Library Not Ready', 'The QR camera library is still loading. Please check connection or reload.', 'warning');
      return;
    }

    if (!camContainer) return;
    camContainer.classList.remove('d-none');
    if (btnToggleCam) {
      btnToggleCam.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Starting Camera...';
      btnToggleCam.disabled = true;
    }

    try {
      if (!html5QrCode) {
        html5QrCode = new Html5Qrcode("cameraReader");
      }

      const devices = await Html5Qrcode.getCameras();
      if (!devices || devices.length === 0) {
        camContainer.classList.add('d-none');
        if (btnToggleCam) {
          btnToggleCam.innerHTML = '<i class="bi bi-camera-video me-1"></i> Use Laptop Camera';
          btnToggleCam.disabled = false;
        }
        Swal.fire('No Camera Detected', 'No webcam or camera device was found on this laptop/computer.', 'warning');
        return;
      }

      if (devices.length > 1 && camSelect && camSelectWrap) {
        camSelect.innerHTML = devices.map((d, i) =>
          `<option value="${d.id}" ${d.id === preferredCameraId || (!preferredCameraId && i === 0) ? 'selected' : ''}>${d.label || 'Camera ' + (i+1)}</option>`
        ).join('');
        camSelectWrap.classList.remove('d-none');
      } else if (camSelectWrap) {
        camSelectWrap.classList.add('d-none');
      }

      const selectedCamId = preferredCameraId || devices[0].id;

      await html5QrCode.start(
        selectedCamId,
        {
          fps: 10,
          qrbox: { width: 250, height: 250 }
        },
        (decodedText) => {
          const now = Date.now();
          // Debounce same QR code read within 3.5 seconds
          if (decodedText === lastCameraCode && (now - lastCameraTime) < 3500) {
            return;
          }
          lastCameraCode = decodedText;
          lastCameraTime = now;

          if (scanInput) scanInput.value = decodedText;
          lookupCode(true); // Automatically performs Time In or Time Out!
        },
        () => {} // ignore frame noise
      );

      isCameraRunning = true;
      if (btnToggleCam) {
        btnToggleCam.innerHTML = '<i class="bi bi-camera-video-off me-1"></i> Stop Camera';
        btnToggleCam.classList.remove('btn-warning');
        btnToggleCam.classList.add('btn-danger');
        btnToggleCam.disabled = false;
      }

    } catch (err) {
      console.error('Camera startup error:', err);
      if (camContainer) camContainer.classList.add('d-none');
      if (btnToggleCam) {
        btnToggleCam.innerHTML = '<i class="bi bi-camera-video me-1"></i> Use Laptop Camera';
        btnToggleCam.classList.remove('btn-danger');
        btnToggleCam.classList.add('btn-warning');
        btnToggleCam.disabled = false;
      }
      Swal.fire({
        icon: 'warning',
        title: 'Camera Access Needed',
        html: '<p class="mb-2">Could not access laptop webcam.</p>' +
              '<p class="small text-muted mb-0">Please ensure camera permissions are allowed in your browser address bar (lock / camera icon next to URL).</p>'
      });
    }
  }

  async function stopCamera(){
    if (html5QrCode && isCameraRunning) {
      try {
        await html5QrCode.stop();
      } catch (e) {}
    }
    isCameraRunning = false;
    if (camContainer) camContainer.classList.add('d-none');
    if (btnToggleCam) {
      btnToggleCam.innerHTML = '<i class="bi bi-camera-video me-1"></i> Use Laptop Camera';
      btnToggleCam.classList.remove('btn-danger');
      btnToggleCam.classList.add('btn-warning');
      btnToggleCam.disabled = false;
    }
  }

  if (btnToggleCam) {
    btnToggleCam.onclick = async () => {
      if (isCameraRunning) {
        await stopCamera();
      } else {
        await startCamera();
      }
    };
  }

  if (btnCloseCam) {
    btnCloseCam.onclick = stopCamera;
  }

  if (camSelect) {
    camSelect.onchange = async () => {
      const newId = camSelect.value;
      if (isCameraRunning && html5QrCode) {
        await html5QrCode.stop();
        isCameraRunning = false;
        await startCamera(newId);
      }
    };
  }

  // Bulk Attendance Recording functions
  window.toggleAllRosterCheckboxes = function(master) {
    const boxes = document.querySelectorAll('.roster-checkbox:not(:disabled)');
    boxes.forEach(b => b.checked = master.checked);
    updateBulkAttendanceSelection();
  };

  window.updateBulkAttendanceSelection = function() {
    const checked = document.querySelectorAll('.roster-checkbox:checked');
    const actions = document.getElementById('bulkRosterActions');
    const countSpan = document.getElementById('bulkSelectedCount');
    if (countSpan) countSpan.textContent = checked.length;
    if (actions) {
      if (checked.length > 0) {
        actions.classList.remove('d-none');
      } else {
        actions.classList.add('d-none');
      }
    }
  };

  window.executeBulkAttendance = async function(status) {
    const checked = document.querySelectorAll('.roster-checkbox:checked');
    if (checked.length === 0) {
      Swal.fire('No Attendees Selected', 'Please check at least one attendee from the roster table.', 'info');
      return;
    }

    const stakeholderIds = Array.from(checked).map(c => c.value);
    const confirm = await Swal.fire({
      title: `Mark ${checked.length} Attendee(s) as ${status}?`,
      text: `This will record attendance for the selected session date (<?= e($dayFormattedDate) ?>).`,
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: `<i class="bi bi-check-lg me-1"></i> Yes, Mark as ${status}`,
      cancelButtonText: 'Cancel',
      confirmButtonColor: status === 'Present' ? '#198754' : '#dc3545'
    });

    if (!confirm.isConfirmed) return;

    Swal.fire({
      title: 'Recording Attendance...',
      html: 'Processing batch attendance updates. Please wait.',
      allowOutsideClick: false,
      didOpen: () => { Swal.showLoading(); }
    });

    try {
      const fd = new FormData();
      fd.append('hearing_id', '<?= (int)$hearingId ?>');
      fd.append('session_date', '<?= e($selectedDate) ?>');
      fd.append('status', status);
      fd.append('csrf_token', <?= json_encode(csrfToken()) ?>);
      stakeholderIds.forEach(id => fd.append('stakeholder_ids[]', id));

      const res = await fetch('ajax_bulk_checkin.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
      });
      const data = await res.json();
      Swal.close();

      if (data && data.success) {
        Swal.fire({
          icon: 'success',
          title: 'Attendance Recorded!',
          text: data.message || `Successfully marked ${checked.length} attendee(s) as ${status}.`,
          timer: 1500,
          showConfirmButton: false
        }).then(() => {
          window.location.reload();
        });
      } else {
        Swal.fire({
          icon: 'error',
          title: 'Recording Failed',
          text: data ? data.message : 'Failed to record bulk attendance.'
        });
      }
    } catch (e) {
      Swal.close();
      Swal.fire('Error', 'Network or server error during bulk attendance: ' + e.message, 'error');
    }
  };
});
</script>
<?php endif; ?>
<?php include __DIR__.'/../../layouts/footer.php'; ?>
