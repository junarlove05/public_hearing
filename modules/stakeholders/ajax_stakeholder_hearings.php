<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

$sid = (int)($_GET['stakeholder_id'] ?? $_POST['stakeholder_id'] ?? 0);
if ($sid <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid stakeholder ID.']);
    exit;
}

$pdo = db();

// Fetch stakeholder details
$stkStmt = $pdo->prepare(
    "SELECT s.id, s.full_name, s.email, s.phone, s.organization, s.sector, s.status, s.address,
            sc.name AS category_name,
            (SELECT q.code_value FROM qr_codes q WHERE q.stakeholder_id = s.id LIMIT 1) AS code_value
     FROM stakeholders s
     LEFT JOIN stakeholder_categories sc ON sc.id = s.category_id
     WHERE s.id = :sid"
);
$stkStmt->execute([':sid' => $sid]);
$stakeholder = $stkStmt->fetch(PDO::FETCH_ASSOC);

if (!$stakeholder) {
    echo json_encode(['success' => false, 'message' => 'Stakeholder not found.']);
    exit;
}

// Fetch all hearings assigned/registered to this stakeholder
$hStmt = $pdo->prepare(
    "SELECT r.id AS registration_id, r.registration_code, r.registration_status, r.attendance_type,
            r.registered_at, r.approved_at,
            COALESCE(r.session_date, hsd.session_date, h.hearing_date) AS effective_date,
            h.id AS hearing_id, h.reference_number, h.title AS hearing_title, h.description,
            h.hearing_date, h.hearing_time, h.venue, h.status AS hearing_status,
            c.name AS committee_name, ht.name AS hearing_type,
            hsd.day_number, hsd.start_time AS session_start_time, hsd.end_time AS session_end_time,
            (SELECT COUNT(*) FROM attendance a WHERE a.hearing_id = h.id AND a.stakeholder_id = :sid_att) AS attendance_count,
            (SELECT i.status FROM invitations i WHERE i.hearing_id = h.id AND i.stakeholder_id = :sid_inv LIMIT 1) AS invitation_status
     FROM registrations r
     JOIN hearings h ON h.id = r.hearing_id
     LEFT JOIN committees c ON c.id = h.committee_id
     LEFT JOIN hearing_types ht ON ht.id = h.hearing_type_id
     LEFT JOIN hearing_session_days hsd ON hsd.id = r.session_day_id
     WHERE r.stakeholder_id = :sid
     ORDER BY effective_date DESC, h.id DESC"
);
$hStmt->execute([':sid' => $sid, ':sid_att' => $sid, ':sid_inv' => $sid]);
$hearings = $hStmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success'     => true,
    'stakeholder' => $stakeholder,
    'hearings'    => $hearings,
    'count'       => count($hearings)
]);
