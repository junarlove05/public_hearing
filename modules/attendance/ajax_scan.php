<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

requireLogin();

$code=trim((string)($_GET['code']??''));
if($code==='') jsonResponse(false,'Enter or scan a QR/registration code.');

$stmt=db()->prepare(
 'SELECT r.id registration_id,r.registration_code,r.registration_status,r.attendance_type,
         s.full_name,s.email,h.title hearing_title,h.reference_number,h.hearing_date,
         q.code_value,q.status qr_status,q.expires_at
  FROM registrations r
  JOIN stakeholders s ON s.id=r.stakeholder_id
  JOIN hearings h ON h.id=r.hearing_id
  LEFT JOIN qr_codes q ON q.registration_id=r.id
  WHERE r.registration_code=:code1 OR q.code_value=:code2
  LIMIT 1'
);
$stmt->execute([':code1'=>$code,':code2'=>$code]);
$row=$stmt->fetch();
if(!$row) jsonResponse(false,'No registration matches that code.');
if($row['registration_status']!=='Approved') jsonResponse(false,'This registration is not approved.');
if(!empty($row['expires_at']) && time()>strtotime($row['expires_at'])) jsonResponse(false,'This QR credential has expired.');

jsonResponse(true,'Registration found.',['registration'=>$row]);
