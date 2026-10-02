<?php
declare(strict_types=1);

/**
 * modules/stakeholders/export_csv.php
 * ------------------------------------------------------------------
 * Exports Hearing Stakeholder Invitations or Registrations as CSV/Excel.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';
requireLogin();
if (!canManage()) redirect(APP_URL . '/dashboard.php');

$type = clean($_GET['type'] ?? 'invitations');
$hearingId = (int)($_GET['hearing_id'] ?? 0);
$pdo = db();

if ($type === 'registrations') {
    $sql = "SELECT r.id, r.hearing_id, r.session_date, r.registration_status, r.attendance_type, r.registered_at,
                   s.full_name, s.email, s.organization, s.phone,
                   h.title AS hearing_title, h.reference_number
            FROM registrations r
            JOIN stakeholders s ON s.id = r.stakeholder_id
            LEFT JOIN hearings h ON h.id = r.hearing_id";
    $params = [];
    if ($hearingId > 0) {
        $sql .= " WHERE r.hearing_id = :hid";
        $params[':hid'] = $hearingId;
    }
    $sql .= " ORDER BY r.registered_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $filename = 'registrations' . ($hearingId > 0 ? "-hearing-{$hearingId}" : '') . '-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    fputcsv($output, ['ID', 'Hearing Ref', 'Hearing Title', 'Session Date', 'Stakeholder Name', 'Email', 'Organization', 'Phone', 'Attendance Type', 'Status', 'Registered At']);

    foreach ($rows as $r) {
        fputcsv($output, [
            $r['id'],
            $r['reference_number'] ?: '-',
            $r['hearing_title'] ?: '-',
            $r['session_date'] ?: '-',
            $r['full_name'],
            $r['email'],
            $r['organization'] ?: '-',
            $r['phone'] ?: '-',
            $r['attendance_type'] ?: 'In-Person',
            $r['registration_status'] ?: 'Pending',
            $r['registered_at'] ?: '-'
        ]);
    }
    fclose($output);
    exit;
}

// Default: Invitations export
$sql = "SELECT i.id, i.hearing_id, i.session_date, i.status, i.sent_at, i.created_at,
               s.full_name, s.email, s.organization, s.phone,
               h.title AS hearing_title, h.reference_number, h.hearing_date
        FROM invitations i
        JOIN stakeholders s ON s.id = i.stakeholder_id
        LEFT JOIN hearings h ON h.id = i.hearing_id";
$params = [];
if ($hearingId > 0) {
    $sql .= " WHERE i.hearing_id = :hid";
    $params[':hid'] = $hearingId;
}
$sql .= " ORDER BY i.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$filename = 'invitations' . ($hearingId > 0 ? "-hearing-{$hearingId}" : '') . '-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
fputcsv($output, ['Invitation ID', 'Hearing Ref', 'Hearing Title', 'Session Date', 'Stakeholder Name', 'Email', 'Organization', 'Phone', 'Invitation Status', 'Sent Date', 'Created Date']);

foreach ($rows as $r) {
    fputcsv($output, [
        $r['id'],
        $r['reference_number'] ?: '-',
        $r['hearing_title'] ?: '-',
        $r['session_date'] ?: ($r['hearing_date'] ?: '-'),
        $r['full_name'],
        $r['email'],
        $r['organization'] ?: '-',
        $r['phone'] ?: '-',
        $r['status'] ?: 'Pending',
        $r['sent_at'] ?: '-',
        $r['created_at'] ?: '-'
    ]);
}
fclose($output);
exit;
