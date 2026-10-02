<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';
requireLogin();

$pdo = db();
$hid = (int)($_GET['hearing_id'] ?? 0);
$h = $pdo->prepare('SELECT * FROM hearings WHERE id=:id');
$h->execute([':id' => $hid]);
$hearing = $h->fetch();
if (!$hearing) exit('Hearing not found.');

$sessionDays = lphGetHearingSessionDays($hearing, $pdo);
$date = clean($_GET['date'] ?? '');

$activeDay = null;
if ($date !== '') {
    foreach ($sessionDays as $sd) {
        if ($sd['date'] === $date) {
            $activeDay = $sd;
            break;
        }
    }
}
if (!$activeDay && !empty($sessionDays)) {
    $activeDay = $sessionDays[0];
    $date = $activeDay['date'];
}

$dateFilter = $activeDay ? $activeDay['date'] : $hearing['hearing_date'];
$dayTitle = $activeDay ? ($activeDay['day_label'] . ' · ' . $activeDay['formatted_date']) : formatDate($dateFilter);

$stmt = $pdo->prepare(
 "SELECT s.full_name, s.organization, 
         COALESCE(r.registration_code, i.invitation_code) AS registration_code,
         COALESCE(r.attendance_type, 'Invited') AS attendance_type,
         a.status, a.checked_in_at, a.checked_out_at, a.check_in_method, a.remarks
  FROM invitations i
  JOIN stakeholders s ON s.id = i.stakeholder_id
  LEFT JOIN registrations r ON (r.hearing_id = i.hearing_id AND r.stakeholder_id = s.id AND (r.session_date = i.session_date OR r.session_date IS NULL OR i.session_date IS NULL))
  LEFT JOIN attendance a ON (a.hearing_id = i.hearing_id AND a.stakeholder_id = s.id AND a.attendance_date = :adate1)
  WHERE i.hearing_id = :hid 
    AND i.status IN ('Accepted', 'Approved')
    AND (i.session_date = :adate2 OR i.session_date IS NULL)
  GROUP BY s.id
  ORDER BY s.full_name"
);
$stmt->execute([':hid' => $hid, ':adate1' => $dateFilter, ':adate2' => $dateFilter]);
$rows = $stmt->fetchAll();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Attendance Sheet · <?= e($hearing['title']) ?> (<?= e($dayTitle) ?>)</title>
<style>
body { font: 12px Arial, sans-serif; margin: 30px; color: #111827; }
h2 { margin: 4px 0; }
.head { text-align: center; border-bottom: 3px solid #0f2137; padding-bottom: 12px; margin-bottom: 15px; }
.subhead { color: #4b5563; font-size: 13px; margin-top: 4px; }
table { width: 100%; border-collapse: collapse; margin-top: 10px; }
th, td { border: 1px solid #cbd5e1; padding: 7px 10px; text-align: left; }
th { background: #f1f5f9; font-weight: 600; text-transform: uppercase; font-size: 11px; }
.text-center { text-align: center; }
.badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: 600; }
.badge-present { background: #dcfce7; color: #15803d; }
.badge-absent { background: #fee2e2; color: #b91c1c; }
.badge-excused { background: #f3f4f6; color: #4b5563; }
</style>
</head>
<body onload="window.print()">
<div class="head">
  <strong><?= e(APP_NAME) ?></strong>
  <h2>Official Session Attendance Sheet</h2>
  <div class="subhead">
    <strong><?= e($hearing['title']) ?></strong> &nbsp;|&nbsp; 
    <span>Session Day: <strong><?= e($dayTitle) ?></strong></span>
    <?php if (!empty($hearing['venue'])): ?> &nbsp;|&nbsp; Venue: <?= e($hearing['venue']) ?><?php endif; ?>
  </div>
</div>
<table>
  <thead>
    <tr>
      <th style="width: 25%;">Participant / Organization</th>
      <th style="width: 15%;">Reg Code</th>
      <th style="width: 12%;">Type</th>
      <th style="width: 12%;" class="text-center">Status</th>
      <th style="width: 12%;">Time In</th>
      <th style="width: 12%;">Time Out</th>
      <th style="width: 12%;">Method</th>
    </tr>
  </thead>
  <tbody>
    <?php if (!$rows): ?>
    <tr><td colspan="7" class="text-center" style="padding: 20px;">No registered attendees found for this session.</td></tr>
    <?php endif; ?>
    <?php foreach($rows as $r): ?>
    <tr>
      <td>
        <strong><?= e($r['full_name']) ?></strong>
        <?php if (!empty($r['organization'])): ?><br><small style="color:#64748b;"><?= e($r['organization']) ?></small><?php endif; ?>
      </td>
      <td><code><?= e($r['registration_code']) ?></code></td>
      <td><?= e($r['attendance_type']) ?></td>
      <td class="text-center">
        <?php
          $st = $r['status'] ?: 'Not Marked';
          $cls = match($st) {
            'Present' => 'badge-present',
            'Absent' => 'badge-absent',
            'Excused' => 'badge-excused',
            default => ''
          };
        ?>
        <span class="badge <?= $cls ?>"><?= e($st) ?></span>
      </td>
      <td><?= $r['checked_in_at'] ? date('h:i A', strtotime($r['checked_in_at'])) : '—' ?></td>
      <td><?= $r['checked_out_at'] ? date('h:i A', strtotime($r['checked_out_at'])) : '—' ?></td>
      <td><?= e($r['check_in_method'] ?: '—') ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</body>
</html>
