<?php
declare(strict_types=1);

/**
 * ajax_portal_rsvp.php
 * ------------------------------------------------------------------
 * Public Stakeholder Self-Registration / RSVP endpoint for Hearings.
 * Allows stakeholders to express intent to attend, choose their role,
 * and submit position papers/manifestations.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';
require_once __DIR__ . '/../../includes/lph_security.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$pdo = db();
lphEnsureMultiDayAttendanceSchema($pdo);

$hearingId = (int)($_POST['hearing_id'] ?? 0);
$sessionDayId = (int)($_POST['session_day_id'] ?? 0) ?: null;
$sessionDate = clean($_POST['session_date'] ?? '');
$fullName = clean($_POST['full_name'] ?? '');
$email = strtolower(trim((string)clean($_POST['email'] ?? '')));
$phone = clean($_POST['phone'] ?? '');
$organization = clean($_POST['organization'] ?? '');
$sector = clean($_POST['sector'] ?? '');
$attendanceType = clean($_POST['attendance_type'] ?? 'Official Delegate');
$positionSummary = clean($_POST['position_summary'] ?? '');

if ($hearingId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Please select a valid hearing.']);
    exit;
}
if ($fullName === '') {
    echo json_encode(['success' => false, 'message' => 'Full Name is required.']);
    exit;
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'A valid Email address is required to receive your attendance pass.']);
    exit;
}

try {
    // 1. Verify Hearing exists and is open
    $hStmt = $pdo->prepare("SELECT id, title, hearing_date, status FROM hearings WHERE id = :id LIMIT 1");
    $hStmt->execute([':id' => $hearingId]);
    $hearing = $hStmt->fetch(PDO::FETCH_ASSOC);
    if (!$hearing) {
        echo json_encode(['success' => false, 'message' => 'Hearing not found.']);
        exit;
    }
    if (in_array($hearing['status'], ['Completed', 'Cancelled'], true)) {
        echo json_encode(['success' => false, 'message' => 'Registration is closed for this hearing.']);
        exit;
    }

    if ($sessionDate === '') {
        $sessionDate = $hearing['hearing_date'] ?? '';
    }

    $pdo->beginTransaction();

    // 2. Find or create Stakeholder record
    $sStmt = $pdo->prepare("SELECT id, full_name, email, organization, status FROM stakeholders WHERE LOWER(email) = :email LIMIT 1");
    $sStmt->execute([':email' => $email]);
    $stakeholder = $sStmt->fetch(PDO::FETCH_ASSOC);

    if (!$stakeholder) {
        // Create new stakeholder in directory with status 'Pending' (for admin verification)
        $insStk = $pdo->prepare("
            INSERT INTO stakeholders (full_name, email, phone, organization, sector, status, created_at, updated_at)
            VALUES (:name, :email, :phone, :org, :sector, 'Pending', NOW(), NOW())
        ");
        $insStk->execute([
            ':name' => $fullName,
            ':email' => $email,
            ':phone' => $phone,
            ':org' => $organization ?: 'Independent Stakeholder',
            ':sector' => $sector ?: 'General Public'
        ]);
        $stakeholderId = (int)$pdo->lastInsertId();
    } else {
        $stakeholderId = (int)$stakeholder['id'];
        // Update contact details if provided
        $updStk = $pdo->prepare("
            UPDATE stakeholders 
            SET phone = COALESCE(NULLIF(:phone, ''), phone),
                organization = COALESCE(NULLIF(:org, ''), organization),
                updated_at = NOW()
            WHERE id = :id
        ");
        $updStk->execute([
            ':phone' => $phone,
            ':org' => $organization,
            ':id' => $stakeholderId
        ]);
    }

    // Ensure QR Code exists for stakeholder
    $qrStmt = $pdo->prepare("SELECT code_value FROM qr_codes WHERE stakeholder_id = :sid LIMIT 1");
    $qrStmt->execute([':sid' => $stakeholderId]);
    $qrCode = $qrStmt->fetchColumn();

    if (!$qrCode) {
        $qrCode = 'STK-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $pdo->prepare("INSERT INTO qr_codes (stakeholder_id, code_value, status, created_at) VALUES (:sid, :code, 'Active', NOW())")
            ->execute([':sid' => $stakeholderId, ':code' => $qrCode]);
    }

    // 3. Check for existing registration
    $chkReg = $pdo->prepare("
        SELECT id, registration_code, registration_status 
        FROM registrations 
        WHERE stakeholder_id = :sid AND hearing_id = :hid
        LIMIT 1
    ");
    $chkReg->execute([':sid' => $stakeholderId, ':hid' => $hearingId]);
    $existing = $chkReg->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $pdo->commit();
        echo json_encode([
            'success' => true,
            'already_registered' => true,
            'message' => "You have already submitted an RSVP for this hearing.",
            'registration_code' => $existing['registration_code'],
            'registration_status' => $existing['registration_status'],
            'qr_code' => $qrCode,
            'hearing_title' => $hearing['title']
        ]);
        exit;
    }

    // 4. Create new Registration record
    $regCode = lphUniqueCode($pdo, 'registrations', 'registration_code', 'REG');
    
    $insReg = $pdo->prepare("
        INSERT INTO registrations 
        (stakeholder_id, hearing_id, session_day_id, session_date, registration_code, 
         registration_status, attendance_type, registered_at)
        VALUES 
        (:sid, :hid, :sdid, :sdate, :code, 'Pending', :att_type, NOW())
    ");

    $insReg->execute([
        ':sid' => $stakeholderId,
        ':hid' => $hearingId,
        ':sdid' => $sessionDayId,
        ':sdate' => $sessionDate ?: null,
        ':code' => $regCode,
        ':att_type' => $attendanceType
    ]);

    $regId = (int)$pdo->lastInsertId();

    // History log
    lphHistory($pdo, 'registration', $regId, 'Self-RSVP', null, 'Pending',
        "Stakeholder {$fullName} ({$email}) self-registered online for hearing: {$hearing['title']} as {$attendanceType}.");

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "Your RSVP has been recorded successfully! The Secretariat will review and confirm your attendance.",
        'registration_code' => $regCode,
        'registration_status' => 'Pending',
        'qr_code' => $qrCode,
        'hearing_title' => $hearing['title'],
        'attendance_type' => $attendanceType
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("[ajax_portal_rsvp] " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A system error occurred while recording your RSVP: ' . $e->getMessage()]);
}
