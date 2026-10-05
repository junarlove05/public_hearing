<?php
declare(strict_types=1);

/**
 * ajax_portal_lookup.php
 * ------------------------------------------------------------------
 * Lookup endpoint for Stakeholders to view all hearings assigned to them,
 * official invitations received, and attendance QR passes.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';

header('Content-Type: application/json; charset=utf-8');

$query = trim((string)($_GET['query'] ?? ($_POST['query'] ?? '')));

if ($query === '') {
    echo json_encode(['success' => false, 'message' => 'Please enter your registered email address or invitation code.']);
    exit;
}

$pdo = db();

try {
    // 1. Locate Stakeholder by email, phone, code, or invitation code
    $stk = null;

    if (filter_var($query, FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare("SELECT id, full_name, email, organization, sector, status FROM stakeholders WHERE LOWER(email) = :q LIMIT 1");
        $stmt->execute([':q' => strtolower($query)]);
        $stk = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        // Try QR code or invitation code
        $stmt = $pdo->prepare("
            SELECT s.id, s.full_name, s.email, s.organization, s.sector, s.status 
            FROM stakeholders s
            LEFT JOIN qr_codes q ON q.stakeholder_id = s.id
            LEFT JOIN invitations i ON i.stakeholder_id = s.id
            WHERE q.code_value = :q1 
               OR i.invitation_code = :q2 
               OR s.email LIKE :q3 
               OR s.full_name LIKE :q4
            LIMIT 1
        ");
        $stmt->execute([
            ':q1' => $query,
            ':q2' => $query,
            ':q3' => '%' . $query . '%',
            ':q4' => '%' . $query . '%'
        ]);
        $stk = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$stk) {
        echo json_encode([
            'success' => false, 
            'message' => "No stakeholder record found for '{$query}'. Please ensure your email is registered or submit an RSVP to upcoming hearings."
        ]);
        exit;
    }

    $sid = (int)$stk['id'];

    // 2. Stakeholder's QR Code
    $qrStmt = $pdo->prepare("SELECT code_value FROM qr_codes WHERE stakeholder_id = :sid LIMIT 1");
    $qrStmt->execute([':sid' => $sid]);
    $stkQrCode = (string)($qrStmt->fetchColumn() ?: '');

    // 3. Fetch Official Invitations
    $invStmt = $pdo->prepare("
        SELECT i.id, i.invitation_code, i.status AS invitation_status, i.sent_at, i.remarks,
               COALESCE(i.session_date, hsd.session_date, h.hearing_date) AS effective_date,
               h.id AS hearing_id, h.title AS hearing_title, h.reference_number AS hearing_ref,
               h.venue, h.hearing_time, h.status AS hearing_status,
               c.name AS committee_name,
               hsd.day_number, hsd.start_time AS session_start_time
        FROM invitations i
        JOIN hearings h ON h.id = i.hearing_id
        LEFT JOIN hearing_session_days hsd ON hsd.id = i.session_day_id
        LEFT JOIN committees c ON c.id = h.committee_id
        WHERE i.stakeholder_id = :sid
        ORDER BY effective_date DESC, i.id DESC
    ");
    $invStmt->execute([':sid' => $sid]);
    $invitations = $invStmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Fetch Registrations / Self-RSVPs
    $regStmt = $pdo->prepare("
        SELECT r.id, r.registration_code, r.registration_status, r.attendance_type, r.registered_at,
               COALESCE(r.session_date, hsd.session_date, h.hearing_date) AS effective_date,
               h.id AS hearing_id, h.title AS hearing_title, h.reference_number AS hearing_ref,
               h.venue, h.hearing_time, h.status AS hearing_status,
               c.name AS committee_name
        FROM registrations r
        JOIN hearings h ON h.id = r.hearing_id
        LEFT JOIN hearing_session_days hsd ON hsd.id = r.session_day_id
        LEFT JOIN committees c ON c.id = h.committee_id
        WHERE r.stakeholder_id = :sid
        ORDER BY effective_date DESC, r.id DESC
    ");
    $regStmt->execute([':sid' => $sid]);
    $registrations = $regStmt->fetchAll(PDO::FETCH_ASSOC);

    $liveBaseUrl = (defined('APP_URL') && APP_URL && !str_contains(APP_URL, 'localhost'))
        ? rtrim(APP_URL, '/')
        : 'https://public-hearing-integrated-legislative-system.hostforgeplatforms.com';

    // Format invitations with links and QR
    foreach ($invitations as &$inv) {
        $code = $inv['invitation_code'] ?: ($stkQrCode ?: 'INV-PASS');
        $inv['qr_url'] = "https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=" . urlencode($code) . "&margin=4";
        $inv['certificate_url'] = $liveBaseUrl . "/modules/stakeholders/invitation_print.php?id=" . $inv['id'] . "&code=" . urlencode($code);
    }
    unset($inv);

    foreach ($registrations as &$reg) {
        $code = $reg['registration_code'] ?: ($stkQrCode ?: 'REG-PASS');
        $reg['qr_url'] = "https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=" . urlencode($code) . "&margin=4";
    }
    unset($reg);

    echo json_encode([
        'success' => true,
        'stakeholder' => [
            'id' => $sid,
            'full_name' => $stk['full_name'],
            'email' => $stk['email'],
            'organization' => $stk['organization'],
            'sector' => $stk['sector'],
            'qr_code' => $stkQrCode,
            'qr_url' => $stkQrCode ? "https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=" . urlencode($stkQrCode) . "&margin=4" : null
        ],
        'invitations_count' => count($invitations),
        'invitations' => $invitations,
        'registrations_count' => count($registrations),
        'registrations' => $registrations
    ]);

} catch (Throwable $e) {
    error_log("[ajax_portal_lookup] " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
}
