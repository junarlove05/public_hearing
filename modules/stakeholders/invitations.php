<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';
requireLogin();
if (!canManage()) redirect(APP_URL.'/dashboard.php');

$pdo = db();
lphEnsureMultiDayAttendanceSchema($pdo);
$pageTitle = 'Hearing Invitations';
$activeMenu = 'stakeholders';

$hearings = $pdo->query(
    "SELECT id, reference_number, title, hearing_date, status 
     FROM hearings 
     WHERE status IN ('Upcoming','Ongoing') 
     ORDER BY hearing_date ASC"
)->fetchAll();

$filterHearings = $pdo->query(
    "SELECT DISTINCT h.id, h.reference_number, h.title, h.hearing_date, h.status,
            (SELECT COUNT(*) FROM invitations i WHERE i.hearing_id = h.id) AS invite_count
     FROM hearings h
     WHERE h.status IN ('Upcoming','Ongoing')
        OR h.id IN (SELECT DISTINCT hearing_id FROM invitations WHERE hearing_id IS NOT NULL)
     ORDER BY h.hearing_date DESC, h.title ASC"
)->fetchAll();

$filterKey = trim((string)($_GET['filter_key'] ?? ''));
$hearingFilter = filter_input(INPUT_GET, 'hearing_id', FILTER_VALIDATE_INT);
$hearingFilter = ($hearingFilter !== false && $hearingFilter !== null && $hearingFilter > 0) ? $hearingFilter : 0;
$sessionDateFilter = trim((string)($_GET['session_date'] ?? ''));

if ($filterKey !== '' && $filterKey !== '0') {
    $parts = explode('_', $filterKey);
    if (isset($parts[0]) && is_numeric($parts[0])) $hearingFilter = (int)$parts[0];
    if (isset($parts[2]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $parts[2])) $sessionDateFilter = $parts[2];
}

/**
 * Builds a direct Gmail Web Compose URL with Manila letterhead, official seal, and QR code pass.
 */
function lphBuildGmailComposeUrl(array $r, array $group): string {
    $email = trim((string)($r['email'] ?? ''));
    $name = trim((string)($r['full_name'] ?? 'Stakeholder'));
    $code = trim((string)($r['invitation_code'] ?? 'LPH-SECURE'));
    $title = trim((string)($group['hearing_title'] ?? 'Legislative Public Hearing & Consultation'));
    $date = !empty($r['session_date']) ? date('F j, Y', strtotime($r['session_date'])) : (!empty($group['hearing_date']) ? date('F j, Y', strtotime($group['hearing_date'])) : 'Scheduled Date');
    $time = !empty($group['hearing_time']) ? date('g:i A', strtotime($group['hearing_time'])) : 'Scheduled Time';
    $venue = !empty($group['venue']) ? $group['venue'] : 'City Hall Session Hall, City of Manila';
    $id = (int)($r['id'] ?? 0);

    $baseUrl = (defined('APP_URL') && APP_URL) ? rtrim(APP_URL, '/') : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/legislative/lph');
    $printUrl = $baseUrl . '/modules/stakeholders/invitation_print.php?id=' . $id;
    $qrImageUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($code) . '&margin=6';
    $manilaLogoUrl = 'https://raw.githubusercontent.com/junarlove05/public_hearing/main/assets/images/manila.png';

    $subject = "Official Invitation: {$title} [Pass Code: {$code}] · City of Manila";
    $body = "REPUBLIC OF THE PHILIPPINES\n"
          . "CITY OF MANILA · SANGGUNIANG PANLUNGSOD\n"
          . "Office of the City Council & Committee Secretariat\n"
          . "Legislative Public Hearing & Consultation Management System\n\n"
          . "🏛️ OFFICIAL CITY SEAL / LOGO:\n{$manilaLogoUrl}\n\n"
          . "Dear {$name},\n\n"
          . "Warm greetings from the Office of the City Council of Manila!\n\n"
          . "You are officially invited to attend and participate as an official stakeholder in the upcoming Legislative Public Hearing & Consultation:\n\n"
          . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
          . "LEGISLATIVE PUBLIC HEARING DETAILS\n"
          . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
          . "• Agenda / Title : {$title}\n"
          . "• Scheduled Date : {$date}\n"
          . "• Session Time   : {$time}\n"
          . "• Session Venue  : {$venue}\n"
          . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n"
          . "🎫 YOUR OFFICIAL ATTENDANCE PASS & QR CODE:\n"
          . "• Stakeholder Name : {$name}\n"
          . "• Official Pass Code: {$code}\n\n"
          . "📱 SCAN / VIEW YOUR QR CODE BADGE:\n{$qrImageUrl}\n\n"
          . "📜 VIEW & PRINT OFFICIAL EXECUTIVE CERTIFICATE:\n{$printUrl}\n\n"
          . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
          . "IMPORTANT INSTRUCTIONS FOR ATTENDEES:\n"
          . "1. Please present this Official Invitation Code or your digital QR Code upon arrival at the secretariat registration desk.\n"
          . "2. For on-site attendees, registration desk opens 30 minutes before the session starts.\n"
          . "3. Keep this email and QR pass accessible on your mobile phone or print a hard copy.\n"
          . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n"
          . "Respectfully yours,\n\n"
          . "OFFICE OF THE CITY COUNCIL & COMMITTEE SECRETARIAT\n"
          . "Sangguniang Panlungsod, City of Manila\n"
          . "City Hall, Padre Burgos Ave, Ermita, Manila, Philippines";

    return 'https://mail.google.com/mail/?view=cm&fs=1&to=' . rawurlencode($email) . '&su=' . rawurlencode($subject) . '&body=' . rawurlencode($body);
}

// Generate hearing session options for dropdowns (multi-day hearing sessions listed separately)
$hearingSessionOptions = lphGetHearingSessionDropdownOptions($pdo, $hearings);
$filterHearingSessionOptions = lphGetHearingSessionDropdownOptions($pdo, $filterHearings);

$registeredStakeholders = $pdo->query(
    "SELECT r.id AS registration_id, r.hearing_id, r.session_day_id, r.session_date, r.stakeholder_id, r.registration_status, r.attendance_type, r.registered_at,
            s.full_name, s.email, s.organization, s.status, sc.name AS category_name,
            (SELECT code_value FROM qr_codes q WHERE q.stakeholder_id=s.id LIMIT 1) AS code_value
     FROM registrations r
     JOIN stakeholders s ON s.id = r.stakeholder_id
     LEFT JOIN stakeholder_categories sc ON sc.id = s.category_id
     ORDER BY s.full_name ASC"
)->fetchAll();

// Map registered stakeholder count per hearing session
$sessionRegCounts = [];
foreach ($registeredStakeholders as $rs) {
    $hid = (int)$rs['hearing_id'];
    $sdid = (int)($rs['session_day_id'] ?? 0);
    $sdate = (string)($rs['session_date'] ?? '');
    
    $fullKey = "{$hid}_{$sdid}_{$sdate}";
    $sessionRegCounts[$fullKey] = ($sessionRegCounts[$fullKey] ?? 0) + 1;
    if ($sdate !== '') {
        $sessionRegCounts["{$hid}_{$sdate}"] = ($sessionRegCounts["{$hid}_{$sdate}"] ?? 0) + 1;
    }
    $sessionRegCounts[$hid] = ($sessionRegCounts[$hid] ?? 0) + 1;
}

// Map existing active invitations: [sessionKey => [stakeholder_id => status]]
$existingInvites = $pdo->query(
    "SELECT hearing_id, session_day_id, session_date, stakeholder_id, status FROM invitations WHERE status IN ('Pending','Sent','Accepted')"
)->fetchAll();
$hearingInvitedMap = [];
foreach ($existingInvites as $ei) {
    $hid = (int)$ei['hearing_id'];
    $sdid = (int)($ei['session_day_id'] ?? 0);
    $sdate = (string)($ei['session_date'] ?? '');
    $sid = (int)$ei['stakeholder_id'];
    $st = $ei['status'];

    $fullKey = "{$hid}_{$sdid}_{$sdate}";
    if (!isset($hearingInvitedMap[$fullKey])) $hearingInvitedMap[$fullKey] = [];
    $hearingInvitedMap[$fullKey][$sid] = $st;

    if ($sdate !== '') {
        $dateKey = "{$hid}_{$sdate}";
        if (!isset($hearingInvitedMap[$dateKey])) $hearingInvitedMap[$dateKey] = [];
        $hearingInvitedMap[$dateKey][$sid] = $st;
    }

    if (!isset($hearingInvitedMap[$hid])) $hearingInvitedMap[$hid] = [];
    $hearingInvitedMap[$hid][$sid] = $st;
}

$invitationSql =
    "SELECT i.*, s.full_name, s.email, s.organization,
            h.id AS hearing_id, h.title AS hearing_title, h.reference_number, h.hearing_date, h.status AS hearing_status,
            hsd.day_number, hsd.start_time AS session_start_time, hsd.end_time AS session_end_time,
            COALESCE(i.session_date, hsd.session_date, h.hearing_date) AS effective_date
     FROM invitations i 
     JOIN stakeholders s ON s.id = i.stakeholder_id
     LEFT JOIN hearings h ON h.id = i.hearing_id
     LEFT JOIN hearing_session_days hsd ON (hsd.id = i.session_day_id)";

$where = [];
$params = [];
if ($hearingFilter > 0) {
    $where[] = "i.hearing_id = :hearing_id";
    $params[':hearing_id'] = $hearingFilter;
}
if ($sessionDateFilter !== '') {
    $where[] = "(i.session_date = :sdate1 OR (i.session_date IS NULL AND h.hearing_date = :sdate2))";
    $params[':sdate1'] = $sessionDateFilter;
    $params[':sdate2'] = $sessionDateFilter;
}
if ($where) {
    $invitationSql .= " WHERE " . implode(' AND ', $where);
}
$invitationSql .= " ORDER BY 
    CASE WHEN h.id IS NULL THEN 1 ELSE 0 END,
    effective_date DESC,
    h.id DESC,
    i.session_day_id ASC,
    i.created_at DESC,
    i.id DESC";

$invitationStmt = $pdo->prepare($invitationSql);
$invitationStmt->execute($params);
$rows = $invitationStmt->fetchAll();

// Group invitations strictly by Hearing ID (so same hearing title is never split)
$invitationsGrouped = [];
foreach ($rows as $r) {
    $hid = (int)($r['hearing_id'] ?? 0);
    $effDate = (string)($r['effective_date'] ?? $r['hearing_date'] ?? '');
    $groupKey = 'hearing_' . $hid;

    if (!isset($invitationsGrouped[$groupKey])) {
        $invitationsGrouped[$groupKey] = [
            'group_key'        => $groupKey,
            'hearing_id'       => $hid,
            'session_date'     => $effDate,
            'hearing_title'    => $r['hearing_title'] ?: ($hid === 0 ? 'General / Unassigned Invitations' : 'Hearing #' . $hid),
            'reference_number' => $r['reference_number'] ?: '',
            'hearing_date'     => $r['hearing_date'] ?? $effDate,
            'hearing_status'   => $r['hearing_status'] ?: '',
            'rows'             => []
        ];
    }
    $invitationsGrouped[$groupKey]['rows'][] = $r;
}

// If specific hearing filtered but has 0 invitations, display its empty card
if ($hearingFilter > 0 && empty($invitationsGrouped)) {
    $hStmt = $pdo->prepare("SELECT id, title, reference_number, hearing_date, status FROM hearings WHERE id = :id");
    $hStmt->execute([':id' => $hearingFilter]);
    $hInfo = $hStmt->fetch();
    if ($hInfo) {
        $effDate = $sessionDateFilter ?: ($hInfo['hearing_date'] ?? null);
        $invitationsGrouped['filtered_empty'] = [
            'group_key'        => 'filtered_empty',
            'hearing_id'       => (int)$hInfo['id'],
            'session_day_id'   => 0,
            'session_date'     => $effDate,
            'day_number'       => 0,
            'day_label'        => '',
            'hearing_title'    => $hInfo['title'],
            'reference_number' => $hInfo['reference_number'] ?: '',
            'hearing_date'     => $effDate,
            'hearing_status'   => $hInfo['status'] ?: '',
            'rows'             => []
        ];
    }
}

// Helper functions for stylish stakeholder presentation
if (!function_exists('lphInitials')) {
    function lphInitials(string $name): string {
        $parts = preg_split('/\s+/', trim($name));
        if (count($parts) >= 2) {
            return strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts)-1], 0, 1));
        }
        return strtoupper(substr($name, 0, 2));
    }
}

if (!function_exists('lphAvatarStyle')) {
    function lphAvatarStyle(string $name): string {
        $palettes = [
            ['#e0f2fe', '#0369a1'],
            ['#fef3c7', '#b45309'],
            ['#dcfce7', '#15803d'],
            ['#f3e8ff', '#7e22ce'],
            ['#ffe4e6', '#be123c'],
            ['#e2e8f0', '#334155'],
            ['#ffedd5', '#c2410c'],
            ['#ccfbf1', '#0f766e'],
        ];
        $idx = abs(crc32($name)) % count($palettes);
        return "background-color: {$palettes[$idx][0]}; color: {$palettes[$idx][1]};";
    }
}

include __DIR__ . '/../../layouts/header.php';
?>
<link rel="stylesheet" href="<?= e(APP_URL.'/assets/css/lph-complete-modules.css') ?>">
<style>
.stk-avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.85rem;
    flex-shrink: 0;
}
.stakeholder-row {
    cursor: pointer;
    transition: background-color 0.15s ease;
}
.stakeholder-row:hover {
    background-color: #f8fafc !important;
}
.stakeholder-row.row-selected {
    background-color: #f0f7ff !important;
}
.stakeholder-row.row-already-invited {
    opacity: 0.72;
}
.sticky-table-head th {
    position: sticky;
    top: 0;
    background-color: #0f2137 !important;
    color: #ffffff !important;
    border-bottom: 2px solid #a97900 !important;
    z-index: 5;
    font-size: 0.75rem;
    letter-spacing: 0.04em;
}
.custom-checkbox-cell {
    width: 44px;
    text-align: center;
}
.custom-checkbox-cell .form-check-input {
    cursor: pointer;
    width: 1.15rem;
    height: 1.15rem;
}
.badge-subtle-success {
    background-color: #d1fae5;
    color: #065f46;
    border: 1px solid #a7f3d0;
}
.badge-subtle-warning {
    background-color: #fef3c7;
    color: #92400e;
    border: 1px solid #fde68a;
}
.badge-subtle-info {
    background-color: #e0f2fe;
    color: #075985;
    border: 1px solid #bae6fd;
}
.badge-subtle-secondary {
    background-color: #f1f5f9;
    color: #475569;
    border: 1px solid #e2e8f0;
}
.table-scroll-container {
    max-height: 420px;
    overflow-y: auto;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
}
.table-scroll-container::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}
.table-scroll-container::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}
.table-highlight-flash {
    animation: hearingTableFlash 1.6s ease-out;
}
@keyframes hearingTableFlash {
    0% { box-shadow: 0 0 0 4px rgba(169, 121, 0, 0.7); transform: scale(1.002); }
    50% { box-shadow: 0 0 0 6px rgba(169, 121, 0, 0.3); }
    100% { box-shadow: 0 4px 16px rgba(10, 22, 40, 0.04); transform: scale(1); }
}
.hearing-jump-link {
    transition: all 0.2s ease;
}
.hearing-jump-link:hover {
    background: #fff7d6 !important;
    border-color: #f6df8a !important;
    color: #a97900 !important;
    transform: translateY(-1px);
}
.hearing-header-card {
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    border-bottom: 2px solid #e2e8f0;
}
.lphx-hearing-avatar {
    width: 40px;
    height: 40px;
    border-radius: 9px;
    background: #fff7d6;
    border: 1px solid #f6df8a;
    color: #a97900;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    flex-shrink: 0;
}
</style>

<div class="app-wrapper"><?php include __DIR__.'/../../layouts/sidebar.php'; ?><div class="main-content">
<div class="lphx-head d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
    <div>
        <div class="lphx-eyebrow">Step 3 · Invitations</div>
        <h1 class="mb-1">Hearing Invitations</h1>
        <p class="mb-0">Create single or bulk invitations, issue invitation codes, track delivery-ready state and responses.</p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap" style="white-space: nowrap;">
        <a class="btn btn-outline-success btn-sm text-nowrap" href="export_csv.php?type=invitations<?= $hearingFilter > 0 ? '&hearing_id='.$hearingFilter : '' ?>" title="Export current invitations to CSV">
            <i class="bi bi-filetype-csv me-1"></i> Export CSV
        </a>
        <button type="button" class="btn btn-outline-primary btn-sm text-nowrap" onclick="sendHearingReminders()" title="Send email reminder notices to all invited stakeholders">
            <i class="bi bi-bell-fill me-1"></i> Send Reminders
        </button>
        <button type="button" class="btn btn-primary btn-sm text-nowrap" id="btnSmtpSetup">
            <i class="bi bi-envelope-gear-fill me-1"></i> Gmail / Email Setup
        </button>
        <a class="btn btn-outline-secondary btn-sm text-nowrap" href="index.php">
            <i class="bi bi-arrow-left me-1"></i> Stakeholders
        </a>
    </div>
</div>

<!-- ============================================================
     CREATE INVITATIONS CARD WITH INTERACTIVE STAKEHOLDER TABLE
     ============================================================ -->
<div class="card lphx-card mb-4 shadow-sm">
    <div class="card-header bg-white py-3 px-3 px-md-4 d-flex justify-content-between align-items-center flex-wrap gap-2 border-bottom">
        <div class="d-flex align-items-center gap-2">
            <div class="rounded-3 p-2 d-inline-flex align-items-center justify-content-center" style="background:#eef5fb;color:#0b3d6e;width:38px;height:38px;">
                <i class="bi bi-envelope-plus-fill fs-5"></i>
            </div>
            <div>
                <h5 class="mb-0 fw-bold text-dark" style="font-size:1.05rem;">Create Invitations</h5>
                <small class="text-muted">Select an upcoming hearing and pick stakeholders to issue official invitations</small>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge rounded-pill bg-light text-dark border px-3 py-2">
                <i class="bi bi-people-fill text-primary me-1"></i> <span id="totalStakeholderCount">0</span> Registered for this Hearing
            </span>
        </div>
    </div>

    <div class="card-body p-3 p-md-4">
        <form id="inviteForm" novalidate>
            <?= csrfField() ?>
            
            <!-- UPPER FORM: Hearing & Top Action -->
            <div class="row g-3 mb-4 align-items-end">
                <div class="col-lg-8 col-md-7">
                    <label class="form-label fw-bold small text-uppercase text-secondary mb-1">
                        Hearing <span class="text-danger">*</span>
                    </label>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-muted"><i class="bi bi-calendar-event"></i></span>
                        <select name="hearing_session_key" id="hearingSelect" class="form-select" required>
                            <option value="" data-hearing-id="0" data-session-day-id="0" data-session-date="">Choose a target hearing session day...</option>
                            <?php foreach ($hearingSessionOptions as $opt): 
                                $cnt = $sessionRegCounts[$opt['key']] ?? ($opt['session_date'] ? ($sessionRegCounts[$opt['hearing_id'].'_'.$opt['session_date']] ?? 0) : 0);
                            ?>
                                <option value="<?= e($opt['key']) ?>"
                                        data-hearing-id="<?= (int)$opt['hearing_id'] ?>"
                                        data-session-day-id="<?= (int)$opt['session_day_id'] ?>"
                                        data-session-date="<?= e($opt['session_date']) ?>"
                                        data-day-number="<?= (int)$opt['day_number'] ?>"
                                        <?= ($filterKey === $opt['key'] || (!$filterKey && $hearingFilter === (int)$opt['hearing_id'])) ? 'selected' : '' ?>>
                                    <?= e($opt['dropdown_label']) ?> (<?= $cnt ?> registered)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="hearing_id" id="hiddenHearingId" value="">
                        <input type="hidden" name="session_day_id" id="hiddenSessionDayId" value="0">
                        <input type="hidden" name="session_date" id="hiddenSessionDate" value="">
                    </div>
                    <div class="form-text small text-muted">
                        <i class="bi bi-info-circle me-1"></i> Only stakeholders registered for the selected hearing session day are displayed.
                    </div>
                </div>

                <div class="col-lg-4 col-md-5">
                    <button type="submit" id="btnSubmitTop" class="btn btn-primary w-100 py-2 fw-semibold shadow-sm">
                        <i class="bi bi-send-fill me-1"></i> Create Invitation(s)
                    </button>
                    <div class="text-center mt-1">
                        <small class="text-muted" id="selectedSummaryTop">0 stakeholder(s) selected</small>
                    </div>
                </div>
            </div>

            <!-- STAKEHOLDER SELECTION TABLE SECTION -->
            <div class="stakeholder-section mb-3">
                <!-- Toolbar: Title, Filter Pills, Search Bar, Select All Buttons -->
                <div class="p-3 bg-light rounded-top border border-bottom-0 d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <label class="form-label fw-bold text-dark small text-uppercase mb-0 me-1">
                            Stakeholders <span class="text-danger">*</span>
                        </label>
                        <span class="badge bg-primary rounded-pill px-2.5 py-1.5" id="selectionCountBadge">
                            <i class="bi bi-check2-circle me-1"></i>0 selected
                        </span>

                        <!-- Status Filter Buttons -->
                        <div class="btn-group btn-group-sm ms-md-2" role="group" aria-label="Filter status">
                            <button type="button" class="btn btn-outline-secondary active btn-filter-status" data-filter="all">All</button>
                            <button type="button" class="btn btn-outline-secondary btn-filter-status" data-filter="Verified">Verified</button>
                            <button type="button" class="btn btn-outline-secondary btn-filter-status" data-filter="Pending">Pending</button>
                        </div>
                    </div>

                    <div class="d-flex align-items-center gap-2 flex-wrap ms-auto">
                        <!-- Live Search Input -->
                        <div class="input-group input-group-sm" style="min-width: 230px;">
                            <span class="input-group-text bg-white text-muted border-end-0"><i class="bi bi-search"></i></span>
                            <input type="text" id="stakeholderSearch" class="form-control border-start-0" placeholder="Filter by name, email, org...">
                            <button class="btn btn-outline-secondary d-none" type="button" id="btnClearSearch"><i class="bi bi-x"></i></button>
                        </div>

                        <!-- Bulk Actions -->
                        <button type="button" class="btn btn-sm btn-outline-primary" id="btnSelectAll">
                            <i class="bi bi-check-all"></i> Select All
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnSelectVerified">
                            <i class="bi bi-patch-check"></i> Verified Only
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnDeselectAll">
                            <i class="bi bi-x-square"></i> Clear
                        </button>
                    </div>
                </div>

                <!-- Scrollable Table of Stakeholders -->
                <div class="table-scroll-container rounded-0 border">
                    <table class="table table-hover align-middle mb-0" id="stakeholderSelectTable">
                        <thead class="sticky-table-head">
                            <tr>
                                <th class="custom-checkbox-cell py-2.5">
                                    <input type="checkbox" id="masterCheckbox" class="form-check-input" title="Select / Deselect all visible">
                                </th>
                                <th class="py-2.5">Registered Stakeholder</th>
                                <th class="py-2.5">Organization / Sector</th>
                                <th class="py-2.5">Email Contact</th>
                                <th class="py-2.5">Registration Info</th>
                                <th class="py-2.5 text-center" style="width: 150px;">Hearing Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Initial prompt state before hearing is chosen -->
                            <tr id="promptSelectHearingRow">
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="bi bi-calendar2-event text-primary fs-1 d-block mb-2"></i>
                                    <h6 class="fw-bold text-dark mb-1">Select a Hearing First</h6>
                                    <p class="small text-muted mb-0">Please choose a target hearing above to view its registered stakeholders.</p>
                                </td>
                            </tr>

                            <!-- Empty state if selected hearing has 0 registrations -->
                            <tr id="noRegisteredRow" class="d-none">
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="bi bi-person-x fs-1 d-block mb-2 text-secondary"></i>
                                    <h6 class="fw-bold text-dark mb-1">No Stakeholders Assigned for this Hearing</h6>
                                    <p class="small text-muted mb-3">Stakeholders must first be assigned to this hearing before an invitation can be issued.</p>
                                    <a id="btnGoToRegister" href="registrations.php" class="btn btn-sm btn-primary">
                                        <i class="bi bi-person-plus-fill me-1"></i> Assign Stakeholders on this Hearing
                                    </a>
                                </td>
                            </tr>

                            <?php foreach ($registeredStakeholders as $s): 
                                $sId = (int)$s['stakeholder_id'];
                                $hId = (int)$s['hearing_id'];
                                $fullName = $s['full_name'] ?? '';
                                $email = $s['email'] ?? '';
                                $org = $s['organization'] ?? '';
                                $category = $s['category_name'] ?? '';
                                $status = $s['status'] ?? 'Pending';
                                $attendanceType = $s['attendance_type'] ?: 'On-site';
                                $regStatus = $s['registration_status'] ?: 'Registered';
                                $initials = lphInitials($fullName);
                                $avatarStyle = lphAvatarStyle($fullName);
                            ?>
                                <?php
                                    $rowSdid = (int)($s['session_day_id'] ?? 0);
                                    $rowSdate = (string)($s['session_date'] ?? '');
                                    $rowKey = "{$hId}_{$rowSdid}_{$rowSdate}";
                                ?>
                                <tr class="stakeholder-row d-none" 
                                    data-sid="<?= $sId ?>" 
                                    data-hearing-id="<?= $hId ?>"
                                    data-session-day-id="<?= $rowSdid ?>"
                                    data-session-date="<?= e($rowSdate) ?>"
                                    data-session-key="<?= e($rowKey) ?>"
                                    data-status="<?= e($status) ?>"
                                    data-name="<?= e(strtolower($fullName)) ?>"
                                    data-email="<?= e(strtolower($email)) ?>"
                                    data-org="<?= e(strtolower($org . ' ' . $category)) ?>">
                                    
                                    <!-- Checkbox -->
                                    <td class="custom-checkbox-cell" onclick="event.stopPropagation()">
                                        <input type="checkbox" 
                                               name="stakeholder_ids[]" 
                                               value="<?= $sId ?>" 
                                               class="form-check-input stakeholder-cb" 
                                               id="cb_stk_<?= $hId ?>_<?= $rowSdid ?>_<?= $sId ?>">
                                    </td>

                                    <!-- Stakeholder Name + Avatar -->
                                    <td>
                                        <div class="d-flex align-items-center gap-2.5">
                                            <div class="stk-avatar" style="<?= $avatarStyle ?>">
                                                <?= e($initials) ?>
                                            </div>
                                            <div>
                                                <label for="cb_stk_<?= $hId ?>_<?= $sId ?>" class="fw-bold text-dark mb-0 d-block cursor-pointer">
                                                    <?= e($fullName) ?>
                                                </label>
                                                <?php if (!empty($category)): ?>
                                                    <span class="badge bg-light text-secondary border px-1.5 py-0.5" style="font-size:0.65rem;">
                                                        <?= e($category) ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if (!empty($s['code_value'])): ?>
                                                    <span class="badge bg-light text-muted border font-monospace px-1.5 py-0.5" style="font-size:0.65rem;" title="QR ID">
                                                        <i class="bi bi-qr-code text-primary"></i> <?= e($s['code_value']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Organization -->
                                    <td>
                                        <?php if (!empty($org)): ?>
                                            <div class="text-dark small"><i class="bi bi-building me-1 text-muted"></i><?= e($org) ?></div>
                                        <?php else: ?>
                                            <span class="text-muted small fst-italic">Individual Citizen</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Email -->
                                    <td>
                                        <div class="small text-dark font-monospace">
                                            <i class="bi bi-envelope me-1 text-secondary"></i><?= e($email) ?>
                                        </div>
                                    </td>

                                    <!-- Registration Info: Attendance Type & Status -->
                                    <td>
                                        <div class="d-flex align-items-center gap-1 flex-wrap">
                                            <span class="badge <?= $attendanceType === 'Online' ? 'bg-info-subtle text-info border' : 'bg-primary-subtle text-primary border' ?> rounded-pill px-2 py-0.5" style="font-size:0.68rem;">
                                                <i class="bi <?= $attendanceType === 'Online' ? 'bi-camera-video' : 'bi-geo-alt-fill' ?> me-1"></i><?= e($attendanceType) ?>
                                            </span>
                                            <span class="badge bg-success-subtle text-success-emphasis border rounded-pill px-2 py-0.5" style="font-size:0.68rem;">
                                                <i class="bi bi-patch-check-fill me-1"></i><?= e($regStatus) ?>
                                            </span>
                                        </div>
                                    </td>

                                    <!-- Live Hearing Eligibility / Invitation Status -->
                                    <td class="text-center hearing-status-cell">
                                        <span class="badge badge-subtle-secondary rounded-pill hearing-eligibility-badge" style="font-size:0.72rem;">
                                            <i class="bi bi-arrow-right-circle me-1"></i>Select Hearing
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- No Match Message -->
                    <div id="noMatchMessage" class="p-4 text-center text-muted d-none">
                        <i class="bi bi-search fs-3 d-block mb-1 text-secondary"></i>
                        No registered stakeholders found matching your filter criteria.
                    </div>
                </div>

                <!-- Table Bottom Bar -->
                <div class="p-2.5 px-3 bg-light rounded-bottom border border-top-0 d-flex justify-content-between align-items-center flex-wrap gap-2 text-muted small">
                    <div>
                        <i class="bi bi-info-circle me-1 text-primary"></i> Only stakeholders registered for the selected hearing are displayed.
                    </div>
                    <div>
                        Showing <span id="visibleRowCount" class="fw-semibold text-dark">0</span> of <span id="totalHearingRegCount" class="fw-semibold text-dark">0</span> registered stakeholder(s)
                    </div>
                </div>
            </div>

            <!-- Remarks Field -->
            <div class="mb-4">
                <label class="form-label fw-bold small text-uppercase text-secondary mb-1">
                    Remarks / Instructions <span class="text-muted fw-normal">(Optional)</span>
                </label>
                <textarea class="form-control" name="remarks" rows="2" placeholder="Add optional remarks, special agenda notes, or arrival instructions for the invited stakeholders..."></textarea>
            </div>

            <!-- Bottom Dispatch Summary & Action Bar -->
            <div class="d-flex align-items-center justify-content-between p-3 rounded-3 border bg-light flex-wrap gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle p-2 bg-primary bg-opacity-10 text-primary d-inline-flex align-items-center justify-content-center" style="width: 42px; height: 42px;">
                        <i class="bi bi-send-check fs-5"></i>
                    </div>
                    <div>
                        <div class="fw-bold text-dark" id="bottomSummaryTitle">
                            Ready to dispatch: <span class="text-primary" id="bottomSelectedCount">0</span> stakeholder invitation(s)
                        </div>
                        <div class="small text-muted" id="bottomSummarySub">
                            Select an upcoming hearing and pick stakeholders to continue.
                        </div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-outline-secondary px-3" id="btnResetForm">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Clear Selection
                    </button>
                    <button type="submit" class="btn btn-primary px-4 py-2 fw-semibold shadow-sm" id="btnSubmitBottom">
                        <i class="bi bi-send-fill me-2"></i> Create Invitation(s)
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     INVITATION REGISTER: SEPARATE TABLES PER HEARING
     ============================================================ -->
<div class="card lphx-card mb-4">
    <div class="card-header bg-white py-3 px-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2.5">
            <div class="rounded-3 p-2 d-inline-flex align-items-center justify-content-center" style="background:#eef5fb;color:#0b3d6e;width:38px;height:38px;">
                <i class="bi bi-envelope-paper-fill fs-5"></i>
            </div>
            <div>
                <h5 class="mb-0 fw-bold text-dark" style="font-size:1.05rem;">Invitation Register</h5>
                <small class="text-muted">Separate invitation tables organized by legislative hearing</small>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-secondary-subtle text-secondary px-3 py-2 border">
                <i class="bi bi-calendar-week me-1"></i> <?= count($invitationsGrouped) ?> Hearing<?= count($invitationsGrouped) === 1 ? '' : 's' ?>
            </span>
            <span class="badge bg-primary-subtle text-primary px-3 py-2 border">
                <i class="bi bi-envelope-check me-1"></i> <?= count($rows) ?> Total Logged
            </span>
        </div>
    </div>
    
    <div class="card-body py-3">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <!-- Hearing Filter Form -->
            <form method="get" class="d-flex align-items-center gap-2 flex-wrap">
                <label for="hearingFilterSelect" class="form-label mb-0 fw-bold small text-dark">
                    <i class="bi bi-funnel text-primary"></i> Filter Register:
                </label>
                <select id="hearingFilterSelect" name="filter_key" class="form-select form-select-sm" style="min-width: 280px;" onchange="this.form.submit()">
                    <option value="0">All Hearings &amp; Dates (Show Separate Tables)</option>
                    <?php foreach ($filterHearingSessionOptions as $fso): ?>
                        <option value="<?= e($fso['key']) ?>" <?= ($filterKey === $fso['key'] || (!$filterKey && $hearingFilter === $fso['hearing_id'])) ? 'selected' : '' ?>>
                            <?= e($fso['dropdown_label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($hearingFilter > 0 || $filterKey !== ''): ?>
                    <a href="invitations.php" class="btn btn-sm btn-outline-secondary text-nowrap">
                        <i class="bi bi-x-circle"></i> Show All Tables
                    </a>
                <?php endif; ?>
            </form>

            <!-- Instant Search across all tables -->
            <div class="ms-auto" style="min-width: 260px; max-width: 360px; width: 100%;">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" id="invitationSearchInput" class="form-control" placeholder="Search code, stakeholder, email...">
                </div>
            </div>
        </div>

        <!-- Jump pills for quick navigation when multiple tables exist -->
        <?php if ($hearingFilter === 0 && count($invitationsGrouped) > 1): ?>
            <div class="d-flex align-items-center gap-1 flex-wrap pt-2 mt-2 border-top">
                <span class="small text-muted me-2"><i class="bi bi-arrow-down-circle"></i> Jump to Session:</span>
                <?php foreach ($invitationsGrouped as $gKey => $grp): ?>
                    <a href="#inv-hearing-section-<?= e($gKey) ?>" class="badge rounded-pill bg-light text-dark border text-decoration-none py-1 px-2.5 hearing-jump-link">
                        <?= e($grp['hearing_title']) ?><?= !empty($grp['day_label']) ? ' · ' . e($grp['day_label']) : '' ?> <span class="text-primary fw-bold ms-1">(<?= count($grp['rows']) ?>)</span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Separate Tables Depending on Hearing -->
<?php if (empty($invitationsGrouped)): ?>
    <div class="card lphx-card mb-4">
        <div class="card-body py-5 text-center text-muted">
            <i class="bi bi-envelope fs-1 text-warning d-block mb-2"></i>
            <h5 class="fw-bold">No Invitations Found</h5>
            <p class="mb-0 text-secondary"><?= $hearingFilter > 0 ? 'No invitations found for this hearing.' : 'No invitations logged yet.' ?></p>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($invitationsGrouped as $gKey => $group): ?>
        <?php
            $invCount = count($group['rows']);
            $sentCount = 0;
            $acceptedCount = 0;
            $declinedCount = 0;
            foreach ($group['rows'] as $ir) {
                if ($ir['status'] === 'Sent') $sentCount++;
                elseif ($ir['status'] === 'Accepted') $acceptedCount++;
                elseif ($ir['status'] === 'Declined') $declinedCount++;
            }
            $hid = (int)($group['hearing_id'] ?? 0);
        ?>
        <div class="card lphx-card mb-4 hearing-table-container" id="inv-hearing-section-<?= e($gKey) ?>" data-group-key="<?= e($gKey) ?>" data-hearing-id="<?= $hid ?>">
            <!-- Hearing Table Header -->
            <div class="card-header py-3 hearing-header-card d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-3">
                    <div class="lphx-hearing-avatar">
                        <i class="bi bi-calendar-event"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <h5 class="mb-0 fw-bold text-dark" style="font-size: 1.05rem;">
                                <?= e($group['hearing_title']) ?>
                            </h5>
                            <?php if (!empty($group['day_label'])): ?>
                                <span class="badge bg-primary text-white fw-bold"><i class="bi bi-calendar2-day me-1"></i><?= e($group['day_label']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($group['reference_number'])): ?>
                                <span class="badge bg-light text-secondary border font-monospace"><?= e($group['reference_number']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($group['hearing_status'])): ?>
                                <?php
                                    $stClass = match($group['hearing_status']) {
                                        'Upcoming' => 'primary',
                                        'Ongoing' => 'warning text-dark',
                                        'Completed' => 'success',
                                        'Cancelled' => 'danger',
                                        default => 'secondary'
                                    };
                                ?>
                                <span class="badge bg-<?= $stClass ?>"><?= e($group['hearing_status']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="small text-muted mt-1 d-flex align-items-center gap-3 flex-wrap">
                            <span>
                                <i class="bi bi-calendar3 me-1 text-secondary"></i>
                                <?= !empty($group['hearing_date']) ? formatDate($group['hearing_date']) : 'Date to be determined' ?>
                            </span>
                            <?php if ($sentCount > 0): ?>
                                <span class="text-primary fw-medium">
                                    <i class="bi bi-send-check me-1"></i><?= $sentCount ?> Sent
                                </span>
                            <?php endif; ?>
                            <?php if ($acceptedCount > 0): ?>
                                <span class="text-success fw-medium">
                                    <i class="bi bi-check-circle me-1"></i><?= $acceptedCount ?> Accepted
                                </span>
                            <?php endif; ?>
                            <?php if ($declinedCount > 0): ?>
                                <span class="text-danger fw-medium">
                                    <i class="bi bi-x-circle me-1"></i><?= $declinedCount ?> Declined
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge rounded-pill bg-dark px-3 py-2 hearing-count-badge" data-original-count="<?= $invCount ?>">
                        <i class="bi bi-envelope-paper-fill me-1"></i> <?= $invCount ?> <?= $invCount === 1 ? 'Invitation' : 'Invitations' ?>
                    </span>
                    <?php if ($hid > 0): ?>
                        <?php 
                            $regLink = "registrations.php?hearing_id=" . $hid;
                            if (!empty($group['session_date'])) {
                                $regLink .= "&session_date=" . urlencode($group['session_date']);
                            }
                        ?>
                        <a href="<?= $regLink ?>" class="btn btn-sm btn-outline-secondary" title="View Registrations for this hearing session">
                            <i class="bi bi-person-check me-1"></i> Registrations
                        </a>
                        <?php if (($group['hearing_status'] ?? '') !== 'Completed' && ($group['hearing_status'] ?? '') !== 'Cancelled'): ?>
                            <?php 
                                $attLink = APP_URL . "/modules/attendance/index.php?hearing_id=" . $hid;
                                if (!empty($group['session_date'])) {
                                    $attLink .= "&date=" . urlencode($group['session_date']);
                                }
                            ?>
                            <a href="<?= $attLink ?>" class="btn btn-sm btn-outline-primary" title="Open QR Attendance Tracker for this hearing session">
                                <i class="bi bi-qr-code-scan me-1"></i> Attendance
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Table for this specific Hearing -->
            <div class="table-responsive">
                <table class="table table-hover lphx-table mb-0">
                    <thead>
                        <tr>
                            <th style="width: 170px;"><i class="bi bi-hash"></i> Code</th>
                            <th><i class="bi bi-person"></i> Stakeholder</th>
                            <th style="width: 210px;"><i class="bi bi-clock-history"></i> Created / Sent</th>
                            <th style="width: 130px;"><i class="bi bi-shield-check"></i> Status</th>
                            <th class="text-end" style="width: 260px;"><i class="bi bi-sliders"></i> Update / Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($group['rows'])): ?>
                            <tr>
                                <td colspan="5">
                                    <div class="lphx-empty py-4">
                                        <i class="bi bi-envelope"></i>
                                        No invitations recorded for this hearing.
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($group['rows'] as $r): ?>
                                <tr class="inv-row" data-id="<?= (int)$r['id'] ?>" id="inv-row-<?= (int)$r['id'] ?>">
                                    <td>
                                        <span class="lphx-code font-monospace fw-semibold"><?= e($r['invitation_code'] ?: '—') ?></span>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2.5">
                                            <div class="stk-avatar" style="<?= lphAvatarStyle($r['full_name']) ?> width:34px;height:34px;font-size:0.8rem;">
                                                <?= e(lphInitials($r['full_name'])) ?>
                                            </div>
                                            <div>
                                                <strong class="text-dark d-block"><?= e($r['full_name']) ?></strong>
                                                <div class="small text-muted font-monospace"><i class="bi bi-envelope me-1 text-secondary"></i><?= e($r['email']) ?></div>
                                                <div class="d-flex align-items-center gap-1.5 flex-wrap mt-0.5">
                                                    <?php if (!empty($r['organization'])): ?>
                                                        <span class="small text-muted"><i class="bi bi-building me-1"></i><?= e($r['organization']) ?></span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($r['day_number'])): ?>
                                                        <span class="badge bg-primary-subtle text-primary border px-1.5 py-0.5" style="font-size:0.68rem;" title="Scheduled Session Day">
                                                            <i class="bi bi-calendar2-day me-1"></i>Day <?= (int)$r['day_number'] ?>
                                                        </span>
                                                    <?php elseif (!empty($r['session_date'])): ?>
                                                        <span class="badge bg-light text-secondary border px-1.5 py-0.5" style="font-size:0.68rem;">
                                                            <i class="bi bi-calendar3 me-1"></i><?= formatDate($r['session_date']) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div><i class="bi bi-calendar-plus me-1 text-muted"></i><?= formatDateTime($r['created_at']) ?></div>
                                        <div class="small mt-0.5">
                                            <?php if (!empty($r['sent_at'])): ?>
                                                <span class="text-success"><i class="bi bi-send-check me-1"></i>Sent <?= formatDateTime($r['sent_at']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted"><i class="bi bi-dash me-1"></i>Not marked sent</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php 
                                        $st = $r['status'];
                                        $badgeClass = match($st) {
                                            'Accepted' => 'bg-success-subtle text-success border border-success-subtle',
                                            'Sent' => 'bg-primary-subtle text-primary border border-primary-subtle',
                                            'Declined' => 'bg-danger-subtle text-danger border border-danger-subtle',
                                            'Cancelled' => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
                                            default => 'bg-warning-subtle text-warning border border-warning-subtle'
                                        };
                                        ?>
                                        <span class="badge <?= $badgeClass ?> px-2.5 py-1.5"><?= e($st) ?></span>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <div class="d-inline-flex align-items-center gap-1.5">
                                            <?php if ($r['status'] !== 'Accepted'): ?>
                                                <button type="button" 
                                                        class="btn btn-sm btn-success btn-invite-status fw-semibold" 
                                                        data-id="<?= (int)$r['id'] ?>" 
                                                        data-sid="<?= (int)$r['stakeholder_id'] ?>"
                                                        data-status="Accepted"
                                                        data-name="<?= e($r['full_name']) ?>"
                                                        data-email="<?= e($r['email'] ?? '') ?>"
                                                        data-code="<?= e($r['invitation_code']) ?>"
                                                        data-title="<?= e($group['hearing_title'] ?? '') ?>"
                                                        data-date="<?= e(!empty($r['session_date']) ? formatDate($r['session_date']) : formatDate($group['hearing_date'] ?? '')) ?>"
                                                        data-time="<?= e($group['hearing_time'] ?? '') ?>"
                                                        data-venue="<?= e($group['venue'] ?? '') ?>"
                                                        data-gmail-url="<?= e(lphBuildGmailComposeUrl($r, $group)) ?>"
                                                        title="Approve Stakeholder & Send Invitation via Gmail">
                                                    <i class="bi bi-check-circle-fill me-1"></i>Approve &amp; Send
                                                </button>
                                                <a href="<?= e(lphBuildGmailComposeUrl($r, $group)) ?>" 
                                                   target="_blank" 
                                                   rel="noopener noreferrer"
                                                   class="btn btn-sm btn-outline-danger fw-semibold" 
                                                   title="Open & Send official invitation letter in Gmail">
                                                    <i class="bi bi-google me-1"></i>Gmail
                                                </a>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" 
                                                            class="btn btn-outline-danger btn-invite-status <?= $r['status']==='Declined'?'active fw-bold':'' ?>" 
                                                            data-id="<?= (int)$r['id'] ?>" 
                                                            data-status="Declined"
                                                            title="Mark as Declined">
                                                        <i class="bi bi-x-circle me-1"></i>Decline
                                                    </button>
                                                    <button type="button" 
                                                            class="btn btn-outline-secondary btn-invite-status <?= $r['status']==='Cancelled'?'active fw-bold':'' ?>" 
                                                            data-id="<?= (int)$r['id'] ?>" 
                                                            data-status="Cancelled"
                                                            title="Mark as Cancelled">
                                                        <i class="bi bi-slash-circle me-1"></i>Cancel
                                                    </button>
                                                </div>
                                            <?php else: ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 me-1">
                                                    <i class="bi bi-check-circle-fill me-1"></i>Approved &amp; Sent
                                                </span>
                                                <a href="<?= e(lphBuildGmailComposeUrl($r, $group)) ?>" 
                                                   target="_blank" 
                                                   rel="noopener noreferrer"
                                                   class="btn btn-sm btn-danger text-white fw-semibold shadow-sm" 
                                                   title="Open and send official invitation letter directly from your Gmail">
                                                    <i class="bi bi-google me-1"></i>Send via Gmail
                                                </a>
                                                <a href="<?= e(lphBuildGmailComposeUrl($r, $group)) ?>" 
                                                   target="_blank" 
                                                   rel="noopener noreferrer"
                                                   class="btn btn-sm btn-outline-primary fw-semibold" 
                                                   title="Resend official invitation letter via Gmail">
                                                    <i class="bi bi-send me-1"></i>Resend
                                                </a>
                                                <button type="button" 
                                                        class="btn btn-sm btn-outline-secondary btn-invite-status" 
                                                        data-id="<?= (int)$r['id'] ?>" 
                                                        data-status="Cancelled"
                                                        title="Mark as Cancelled">
                                                    <i class="bi bi-slash-circle"></i>
                                                </button>
                                            <?php endif; ?>
                                            <a href="invitation_print.php?id=<?= (int)$r['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Print Official Invitation">
                                                <i class="bi bi-printer"></i>
                                            </a>
                                            <a href="invitation_download.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Download Invitation HTML">
                                                <i class="bi bi-download"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="inv-empty-search text-center py-3 text-muted" style="display: none;">
                                <td colspan="5" class="py-3">
                                    <i class="bi bi-search me-1"></i>No matching invitations found in this hearing.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

</div></div>

<!-- ============================================================
     GMAIL / SMTP & RESEND HTTPS EMAIL SETUP MODAL
     ============================================================ -->
<div class="modal fade" id="smtpSetupModal" tabindex="-1" aria-labelledby="smtpSetupModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width: 580px;">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
      <div class="modal-header text-white justify-content-between py-3 px-4" style="background: #0F2137; border-bottom: 4px solid #c89523;">
        <h5 class="modal-title fw-bold fs-5 mb-0" id="smtpSetupModalLabel">
          <i class="bi bi-envelope-gear-fill me-2 text-warning"></i>Email Dispatcher &amp; Delivery Setup
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4 bg-light">

        <!-- Informational Banner -->
        <div class="alert alert-info py-2.5 px-3 small border-0 mb-3 shadow-sm d-flex align-items-start gap-2" style="background:#eff6ff;border-left:4px solid #3b82f6 !important;border-radius:8px;">
          <i class="bi bi-shield-check text-primary fs-5 flex-shrink-0 mt-0.5"></i>
          <div>
            <strong class="text-dark d-block">100% Secure &amp; No Passwords Required:</strong>
            <span class="text-secondary" style="font-size:0.82rem;line-height:1.4;">
              <strong>No passwords are ever requested or stored from anyone.</strong> The system utilizes official <strong>API Keys</strong> to dispatch invitations securely to any email address.
            </span>
          </div>
        </div>

        <form id="smtpSettingsForm">
          <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
          <input type="hidden" name="action" value="save">

          <!-- PROVIDER SELECTION -->
          <div class="mb-3 p-3 bg-white rounded-3 border shadow-sm">
            <label class="form-label small fw-bold text-dark d-block mb-2">
              <i class="bi bi-gear-wide-connected me-1 text-primary"></i> Select Email Service (API Key Only, No Password):
            </label>
            <div class="d-flex flex-column gap-2">
              <!-- BREVO API OPTION -->
              <div class="form-check p-2.5 rounded border" style="background:#f0fdf4; border-color:#86efac !important;">
                <input class="form-check-input ms-0 me-2" type="radio" name="email_provider" id="providerBrevo" value="brevo" checked>
                <label class="form-check-label fw-bold text-dark small" for="providerBrevo" style="cursor:pointer;">
                  <i class="bi bi-send-check-fill text-success me-1"></i> Brevo API (Sendinblue)
                  <span class="badge bg-success text-white ms-1 fw-bold" style="font-size:0.68rem;">Delivers to Any Email</span>
                  <div class="text-muted fw-normal mt-1" style="font-size:0.75rem;">
                    Free 300 emails/day. <strong>Can send to ANY GMAIL, YAHOO, OR STAKEHOLDER ADDRESS</strong> without domain lock or recipient password!
                  </div>
                </label>
              </div>

              <!-- RESEND API OPTION -->
              <div class="form-check p-2.5 rounded border" style="background:#f8fafc;">
                <input class="form-check-input ms-0 me-2" type="radio" name="email_provider" id="providerResend" value="resend">
                <label class="form-check-label fw-bold text-dark small" for="providerResend" style="cursor:pointer;">
                  <i class="bi bi-lightning-charge-fill text-warning me-1"></i> Resend Cloud API
                  <div class="text-muted fw-normal mt-1" style="font-size:0.75rem;">
                    High-speed HTTPS via Port 443. Limited to verified domain or account owner email.
                  </div>
                </label>
              </div>
            </div>
          </div>

          <!-- BREVO API KEY CARD -->
          <div id="sectionBrevo" class="mb-3 p-3 bg-white rounded-3 border shadow-sm">
            <div class="d-flex align-items-center justify-content-between mb-1">
              <label class="form-label small fw-bold text-dark mb-0">
                <i class="bi bi-key-fill text-success me-1"></i> Brevo API Key:
              </label>
              <a href="https://app.brevo.com/settings/keys/api" target="_blank" class="small text-decoration-none fw-bold text-success" style="font-size:0.75rem;">
                <i class="bi bi-box-arrow-up-right me-1"></i>app.brevo.com (Free API Key)
              </a>
            </div>
            <input type="password" class="form-control form-control-sm font-monospace" id="brevoApiKey" name="brevo_api_key" placeholder="xkeysib-xxxxxxxxxxxxxxxxxxxxxxxx">
            <div class="form-text small mt-1 text-muted" style="font-size:0.73rem;">
              Free account at Brevo.com (no credit card required). Once configured, <strong>all recipient Gmail addresses</strong> will receive invitations directly.
            </div>
          </div>

          <!-- RESEND API KEY CARD -->
          <div id="sectionResend" class="mb-3 p-3 bg-white rounded-3 border shadow-sm">
            <div class="d-flex align-items-center justify-content-between mb-1">
              <label class="form-label small fw-bold text-dark mb-0">
                <i class="bi bi-key-fill text-warning me-1"></i> Resend API Key:
              </label>
              <a href="https://resend.com/api-keys" target="_blank" class="small text-decoration-none" style="font-size:0.75rem;">
                <i class="bi bi-box-arrow-up-right me-1"></i>resend.com/api-keys
              </a>
            </div>
            <input type="password" class="form-control form-control-sm font-monospace" id="resendApiKey" name="resend_api_key" placeholder="re_xxxxxxxxxxxxxxxxxxxx">
            <div class="form-text small mt-1 text-muted" style="font-size:0.73rem;">
              Saved and active API key for account owner delivery.
            </div>
          </div>

          <!-- SENDER COMMON FIELDS -->
          <div class="mb-3 p-3 bg-white rounded-3 border shadow-sm">
            <div class="mb-2">
              <label class="form-label small fw-bold text-dark mb-1">
                <i class="bi bi-person-badge me-1 text-primary"></i> Sender Display Name:
              </label>
              <input type="text" class="form-control form-control-sm" id="smtpFromName" name="smtp_from_name" value="City Council - Legislative Public Hearing">
            </div>

            <div>
              <label class="form-label small fw-bold text-dark mb-1">
                <i class="bi bi-at me-1 text-primary"></i> Official Sender Email Address:
              </label>
              <input type="email" class="form-control form-control-sm" id="smtpUser" name="smtp_user" placeholder="junarlove05@gmail.com">
              <div class="form-text small" style="font-size: 0.72rem;">The official email address shown as the sender of invitations.</div>
            </div>
          </div>

          <div class="d-flex justify-content-between align-items-center pt-3 border-top gap-2 flex-wrap">
            <button type="submit" class="btn btn-primary px-3 fw-semibold shadow-sm" id="btnSaveSmtp">
              <i class="bi bi-check2-circle me-1"></i> Save Email Settings
            </button>
            <button type="button" class="btn btn-outline-success px-3 fw-semibold" id="btnTestSmtp">
              <i class="bi bi-send-check me-1"></i> Send Test Email
            </button>
          </div>
        </form>
      </div>
      <div class="modal-footer bg-white py-2 px-3 border-top justify-content-end">
        <button type="button" class="btn btn-sm btn-secondary rounded-pill px-3" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ============================================================
     CLIENT-SIDE INTERACTION JAVASCRIPT
     ============================================================ -->
<script>
const APP_URL = <?= json_encode(rtrim(APP_URL, '/')) ?>;
const APP_CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;
// Pre-loaded hearing invitation map: { hearingId: { stakeholderId: status } }
const HEARING_INVITED_MAP = <?= json_encode($hearingInvitedMap, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
window.escapeHtml = escapeHtml;

window.buildClientGmailUrl = function(email, name, code, title, date, time, venue, id) {
    name = name || 'Valued Stakeholder';
    title = title || 'Legislative Public Hearing & Consultation';
    const passCodeStr = code ? ` [Pass Code: ${code}]` : '';
    date = date || 'Scheduled Session Date';
    time = time ? ` at ${time}` : '';
    venue = venue || 'City Hall Session Hall, City of Manila';

    const origin = window.location.origin;
    const pathname = window.location.pathname;
    const printUrl = id 
        ? (origin + pathname.replace('invitations.php', 'invitation_print.php?id=' + encodeURIComponent(id))) 
        : (origin + pathname.replace('invitations.php', 'index.php'));
    const qrImageUrl = code 
        ? `https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${encodeURIComponent(code)}&margin=6` 
        : '';
    const manilaLogoUrl = 'https://raw.githubusercontent.com/junarlove05/public_hearing/main/assets/images/manila.png';

    const subject = `Official Invitation: ${title}${passCodeStr} · City of Manila`;
    const body = `REPUBLIC OF THE PHILIPPINES
CITY OF MANILA · SANGGUNIANG PANLUNGSOD
Office of the City Council & Committee Secretariat
Legislative Public Hearing & Consultation Management System

🏛️ OFFICIAL CITY SEAL / LOGO:
${manilaLogoUrl}

Dear ${name},

Warm greetings from the Office of the City Council of Manila!

You are officially invited to attend and participate as an official stakeholder in the upcoming Legislative Public Hearing & Consultation:

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
LEGISLATIVE PUBLIC HEARING DETAILS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
• Agenda / Title : ${title}
• Scheduled Date : ${date}
• Session Time   : ${time}
• Session Venue  : ${venue}
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

🎫 YOUR OFFICIAL ATTENDANCE PASS & QR CODE:
• Stakeholder Name : ${name}
• Official Pass Code: ${code || 'LPH-SECURE'}

📱 SCAN / VIEW YOUR QR CODE BADGE:
${qrImageUrl}

📜 VIEW & PRINT OFFICIAL EXECUTIVE CERTIFICATE:
${printUrl}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
IMPORTANT INSTRUCTIONS FOR ATTENDEES:
1. Please present this Official Invitation Code or your digital QR Code upon arrival at the secretariat registration desk.
2. For on-site attendees, registration desk opens 30 minutes before the session starts.
3. Keep this email and QR pass accessible on your mobile phone or print a hard copy.
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Respectfully yours,

OFFICE OF THE CITY COUNCIL & COMMITTEE SECRETARIAT
Sangguniang Panlungsod, City of Manila
City Hall, Padre Burgos Ave, Ermita, Manila, Philippines`;

    return `https://mail.google.com/mail/?view=cm&fs=1&to=${encodeURIComponent(email)}&su=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
};

window.openGmailDirect = function(email, name, code, title, date, time, venue, id) {
    if (!email) {
        Swal.fire('No Email Address', 'No email address registered for this stakeholder.', 'warning');
        return;
    }
    const gmailUrl = window.buildClientGmailUrl(email, name, code, title, date, time, venue, id);
    const win = window.open(gmailUrl, '_blank');

    if (!win || win.closed || typeof win.closed === 'undefined') {
        Swal.fire({
            icon: 'info',
            title: 'Open Gmail Compose',
            html: `
                <p>Your browser blocked the automatic pop-up window. Click below to open Gmail:</p>
                <a href="${gmailUrl}" target="_blank" rel="noopener noreferrer" class="btn btn-danger btn-lg w-100 fw-bold shadow-sm mt-2" onclick="Swal.close()">
                    <i class="bi bi-google me-2"></i> Open in Gmail
                </a>
            `,
            showConfirmButton: false,
            showCloseButton: true
        });
    } else {
        if (typeof appToast === 'function') {
            appToast('success', `Opened Gmail compose for ${email}!`);
        }
    }

    if (id) {
        const fd = new FormData();
        const token = document.querySelector('[name=csrf_token]')?.value || (typeof APP_CSRF_TOKEN !== 'undefined' ? APP_CSRF_TOKEN : '');
        fd.append('csrf_token', token);
        fd.append('id', id);
        fetch(APP_URL + '/modules/stakeholders/ajax_invitation_send.php', { method: 'POST', body: fd }).catch(() => {});
    }
};

window.openGmailInvite = function(btn, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    if (!btn) return;
    const email = btn.dataset.email || '';
    const name = btn.dataset.name || '';
    const code = btn.dataset.code || '';
    const title = btn.dataset.title || '';
    const date = btn.dataset.date || '';
    const time = btn.dataset.time || '';
    const venue = btn.dataset.venue || '';
    const id = btn.dataset.id || '';
    openGmailDirect(email, name, code, title, date, time, venue, id);
};

window.handleResendInvitation = function(btn, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    if (!btn) return;

    const id = btn.dataset.id;
    const name = btn.dataset.name || 'Stakeholder';
    const email = btn.dataset.email || '';
    const code = btn.dataset.code || '';
    const title = btn.dataset.title || '';
    const date = btn.dataset.date || '';
    const time = btn.dataset.time || '';
    const venue = btn.dataset.venue || '';
    const gmailUrl = btn.dataset.gmailUrl || window.buildClientGmailUrl(email, name, code, title, date, time, venue, id);

    const win = window.open(gmailUrl, '_blank');
    if (!win || win.closed || typeof win.closed === 'undefined') {
        Swal.fire({
            icon: 'info',
            title: 'Resend via Gmail',
            html: `
                <p>Click below to open Gmail and resend the official invitation with Manila seal and QR pass:</p>
                <a href="${gmailUrl}" target="_blank" rel="noopener noreferrer" class="btn btn-danger btn-lg w-100 fw-bold shadow-sm mt-2" onclick="Swal.close()">
                    <i class="bi bi-google me-2"></i> Open in Gmail
                </a>
            `,
            showConfirmButton: false,
            showCloseButton: true
        });
    }
};

document.addEventListener('DOMContentLoaded', function() {
    const inviteForm = document.getElementById('inviteForm');
    const hearingSelect = document.getElementById('hearingSelect');
    const masterCheckbox = document.getElementById('masterCheckbox');
    const stakeholderRows = document.querySelectorAll('.stakeholder-row');
    const checkboxes = document.querySelectorAll('.stakeholder-cb');
    const searchInput = document.getElementById('stakeholderSearch');
    const btnClearSearch = document.getElementById('btnClearSearch');
    const btnSelectAll = document.getElementById('btnSelectAll');
    const btnSelectVerified = document.getElementById('btnSelectVerified');
    const btnDeselectAll = document.getElementById('btnDeselectAll');
    const btnResetForm = document.getElementById('btnResetForm');
    const selectionCountBadge = document.getElementById('selectionCountBadge');
    const selectedSummaryTop = document.getElementById('selectedSummaryTop');
    const bottomSelectedCount = document.getElementById('bottomSelectedCount');
    const visibleRowCountEl = document.getElementById('visibleRowCount');
    const totalHearingRegCountEl = document.getElementById('totalHearingRegCount');
    const totalStakeholderCountEl = document.getElementById('totalStakeholderCount');
    const noMatchMessage = document.getElementById('noMatchMessage');
    const promptSelectHearingRow = document.getElementById('promptSelectHearingRow');
    const noRegisteredRow = document.getElementById('noRegisteredRow');
    const btnGoToRegister = document.getElementById('btnGoToRegister');

    let currentStatusFilter = 'all';

    // 1. Update Selection Counters & Active Row State
    function updateSelectionState() {
        let checkedCount = 0;
        let visibleCount = 0;
        let visibleCheckedCount = 0;

        checkboxes.forEach(cb => {
            const row = cb.closest('tr');
            const isVisible = !row.classList.contains('d-none');
            
            if (cb.checked) {
                checkedCount++;
                row.classList.add('row-selected');
            } else {
                row.classList.remove('row-selected');
            }

            if (isVisible) {
                visibleCount++;
                if (cb.checked) visibleCheckedCount++;
            }
        });

        // Update badge and text labels
        if (selectionCountBadge) {
            selectionCountBadge.innerHTML = `<i class="bi bi-check2-circle me-1"></i>${checkedCount} selected`;
        }
        if (selectedSummaryTop) {
            selectedSummaryTop.textContent = `${checkedCount} stakeholder(s) selected`;
        }
        if (bottomSelectedCount) {
            bottomSelectedCount.textContent = checkedCount;
        }

        // Update master checkbox
        if (masterCheckbox) {
            if (visibleCount === 0) {
                masterCheckbox.checked = false;
                masterCheckbox.indeterminate = false;
            } else if (visibleCheckedCount === visibleCount) {
                masterCheckbox.checked = true;
                masterCheckbox.indeterminate = false;
            } else if (visibleCheckedCount > 0) {
                masterCheckbox.checked = false;
                masterCheckbox.indeterminate = true;
            } else {
                masterCheckbox.checked = false;
                masterCheckbox.indeterminate = false;
            }
        }
    }

    // 2. Row Click toggles Checkbox
    stakeholderRows.forEach(row => {
        row.addEventListener('click', function(e) {
            if (e.target.tagName === 'A' || e.target.tagName === 'BUTTON' || e.target.closest('a') || e.target.closest('button')) {
                return;
            }
            const cb = this.querySelector('.stakeholder-cb');
            if (cb && e.target !== cb) {
                cb.checked = !cb.checked;
                updateSelectionState();
            }
        });
    });

    // Individual checkbox change
    checkboxes.forEach(cb => {
        cb.addEventListener('change', updateSelectionState);
    });

    // 3. Master Checkbox Click
    if (masterCheckbox) {
        masterCheckbox.addEventListener('change', function() {
            const isChecked = this.checked;
            checkboxes.forEach(cb => {
                const row = cb.closest('tr');
                if (!row.classList.contains('d-none')) {
                    cb.checked = isChecked;
                }
            });
            updateSelectionState();
        });
    }

    // 4. Bulk Buttons
    if (btnSelectAll) {
        btnSelectAll.addEventListener('click', function() {
            checkboxes.forEach(cb => {
                const row = cb.closest('tr');
                if (!row.classList.contains('d-none')) {
                    cb.checked = true;
                }
            });
            updateSelectionState();
        });
    }

    if (btnSelectVerified) {
        btnSelectVerified.addEventListener('click', function() {
            checkboxes.forEach(cb => {
                const row = cb.closest('tr');
                const isVerified = row.dataset.status === 'Verified';
                if (!row.classList.contains('d-none')) {
                    cb.checked = isVerified;
                }
            });
            updateSelectionState();
        });
    }

    if (btnDeselectAll) {
        btnDeselectAll.addEventListener('click', function() {
            checkboxes.forEach(cb => { cb.checked = false; });
            updateSelectionState();
        });
    }

    if (btnResetForm) {
        btnResetForm.addEventListener('click', function() {
            checkboxes.forEach(cb => { cb.checked = false; });
            if (searchInput) searchInput.value = '';
            currentStatusFilter = 'all';
            document.querySelectorAll('.btn-filter-status').forEach(b => b.classList.toggle('active', b.dataset.filter === 'all'));
            filterRows();
        });
    }

    // 5. Live Check of Existing Invitations when Hearing Session is Selected
    function updateHearingEligibility() {
        const selectedOption = hearingSelect && hearingSelect.selectedIndex >= 0 ? hearingSelect.options[hearingSelect.selectedIndex] : null;
        const sessionKey = hearingSelect ? (hearingSelect.value || '') : '';
        const hearingId = selectedOption ? parseInt(selectedOption.dataset.hearingId || '0', 10) : parseInt(sessionKey, 10);
        const sessionDate = selectedOption ? (selectedOption.dataset.sessionDate || '') : '';

        let invitedMap = {};
        if (sessionKey && HEARING_INVITED_MAP[sessionKey]) {
            invitedMap = HEARING_INVITED_MAP[sessionKey];
        } else if (hearingId > 0 && sessionDayId > 0 && HEARING_INVITED_MAP[hearingId + '_' + sessionDayId]) {
            invitedMap = HEARING_INVITED_MAP[hearingId + '_' + sessionDayId];
        } else if (hearingId > 0 && sessionDate && HEARING_INVITED_MAP[hearingId + '_' + sessionDate]) {
            invitedMap = HEARING_INVITED_MAP[hearingId + '_' + sessionDate];
        }

        stakeholderRows.forEach(row => {
            if (row.classList.contains('d-none')) return;
            const sid = parseInt(row.dataset.sid, 10);
            const statusCell = row.querySelector('.hearing-status-cell');
            if (!statusCell) return;

            if (invitedMap[sid]) {
                const inviteStatus = invitedMap[sid];
                row.classList.add('row-already-invited');
                statusCell.innerHTML = `<span class="badge bg-secondary-subtle text-secondary border rounded-pill" style="font-size:0.72rem;" title="Already invited for this session date">
                    <i class="bi bi-check-circle-fill text-secondary me-1"></i>Invited (${inviteStatus})
                </span>`;
            } else {
                row.classList.remove('row-already-invited');
                statusCell.innerHTML = `<span class="badge badge-subtle-success rounded-pill" style="font-size:0.72rem;">
                    <i class="bi bi-check2 me-1"></i>Eligible
                </span>`;
            }
        });
    }

    // 6. Live Search & Filter (Only among registered stakeholders of the selected session day)
    function filterRows() {
        const selectedOption = hearingSelect && hearingSelect.selectedIndex >= 0 ? hearingSelect.options[hearingSelect.selectedIndex] : null;
        const sessionKey = hearingSelect ? (hearingSelect.value || '') : '';
        const hearingId = selectedOption ? parseInt(selectedOption.dataset.hearingId || '0', 10) : parseInt(sessionKey, 10);
        const sessionDayId = selectedOption ? parseInt(selectedOption.dataset.sessionDayId || '0', 10) : 0;
        const sessionDate = selectedOption ? (selectedOption.dataset.sessionDate || '') : '';

        // Update hidden inputs for backend submission
        const hiddenHearingId = document.getElementById('hiddenHearingId');
        const hiddenSessionDayId = document.getElementById('hiddenSessionDayId');
        const hiddenSessionDate = document.getElementById('hiddenSessionDate');
        if (hiddenHearingId) hiddenHearingId.value = hearingId > 0 ? hearingId : '';
        if (hiddenSessionDayId) hiddenSessionDayId.value = sessionDayId;
        if (hiddenSessionDate) hiddenSessionDate.value = sessionDate;

        const query = (searchInput ? searchInput.value : '').trim().toLowerCase();

        if (btnClearSearch) {
            btnClearSearch.classList.toggle('d-none', query === '');
        }

        if (btnGoToRegister) {
            let regLink = 'registrations.php';
            if (hearingId > 0) {
                regLink += `?hearing_id=${hearingId}`;
                if (sessionDate) regLink += `&session_date=${encodeURIComponent(sessionDate)}`;
            }
            btnGoToRegister.href = regLink;
        }

        // Case 1: No hearing session selected
        if (!sessionKey || hearingId === 0) {
            if (promptSelectHearingRow) promptSelectHearingRow.classList.remove('d-none');
            if (noRegisteredRow) noRegisteredRow.classList.add('d-none');
            if (noMatchMessage) noMatchMessage.classList.add('d-none');
            stakeholderRows.forEach(row => {
                row.classList.add('d-none');
                const cb = row.querySelector('.stakeholder-cb');
                if (cb) cb.checked = false;
            });
            if (visibleRowCountEl) visibleRowCountEl.textContent = '0';
            if (totalHearingRegCountEl) totalHearingRegCountEl.textContent = '0';
            if (totalStakeholderCountEl) totalStakeholderCountEl.textContent = '0';
            updateSelectionState();
            return;
        }

        // Case 2: Hearing session is selected
        if (promptSelectHearingRow) promptSelectHearingRow.classList.add('d-none');

        let hearingRegCount = 0;
        let visibleCount = 0;

        stakeholderRows.forEach(row => {
            const rowHearingId = parseInt(row.dataset.hearingId, 10) || 0;
            const rowSessionDayId = parseInt(row.dataset.sessionDayId, 10) || 0;
            const rowSessionDate = row.dataset.sessionDate || '';

            // Match exact session day
            let matchesSession = false;
            if (rowHearingId === hearingId) {
                if (sessionDayId > 0 && rowSessionDayId > 0) {
                    matchesSession = (rowSessionDayId === sessionDayId);
                } else if (sessionDate && rowSessionDate) {
                    matchesSession = (rowSessionDate === sessionDate);
                } else {
                    matchesSession = true;
                }
            }

            if (!matchesSession) {
                row.classList.add('d-none');
                const cb = row.querySelector('.stakeholder-cb');
                if (cb) cb.checked = false;
                return;
            }

            hearingRegCount++;

            const name = row.dataset.name || '';
            const email = row.dataset.email || '';
            const org = row.dataset.org || '';
            const status = row.dataset.status || '';

            const matchesQuery = query === '' || name.includes(query) || email.includes(query) || org.includes(query);
            const matchesStatus = currentStatusFilter === 'all' || status === currentStatusFilter;

            if (matchesQuery && matchesStatus) {
                row.classList.remove('d-none');
                visibleCount++;
            } else {
                row.classList.add('d-none');
                const cb = row.querySelector('.stakeholder-cb');
                if (cb && !matchesStatus) cb.checked = false;
            }
        });

        if (totalStakeholderCountEl) totalStakeholderCountEl.textContent = hearingRegCount;
        if (totalHearingRegCountEl) totalHearingRegCountEl.textContent = hearingRegCount;
        if (visibleRowCountEl) visibleRowCountEl.textContent = visibleCount;

        if (hearingRegCount === 0) {
            if (noRegisteredRow) noRegisteredRow.classList.remove('d-none');
            if (noMatchMessage) noMatchMessage.classList.add('d-none');
        } else {
            if (noRegisteredRow) noRegisteredRow.classList.add('d-none');
            if (noMatchMessage) noMatchMessage.classList.toggle('d-none', visibleCount > 0);
        }

        updateHearingEligibility();
        updateSelectionState();
    }

    if (searchInput) {
        searchInput.addEventListener('input', filterRows);
    }
    if (btnClearSearch) {
        btnClearSearch.addEventListener('click', () => {
            searchInput.value = '';
            filterRows();
            searchInput.focus();
        });
    }

    // Status filter tabs
    document.querySelectorAll('.btn-filter-status').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.btn-filter-status').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            currentStatusFilter = this.dataset.filter || 'all';
            filterRows();
        });
    });

    if (hearingSelect) {
        hearingSelect.addEventListener('change', function() {
            checkboxes.forEach(cb => { cb.checked = false; });
            filterRows();
            const hearingText = this.options[this.selectedIndex]?.text || '';
            const titleEl = document.getElementById('bottomSummarySub');
            if (titleEl) {
                if (this.value) {
                    titleEl.textContent = `Target: ${hearingText}`;
                } else {
                    titleEl.textContent = `Select an upcoming hearing and pick registered stakeholders to continue.`;
                }
            }
        });
    }

    // Initial check and filter
    filterRows();

    const getCsrfToken = () => document.querySelector('[name=csrf_token]')?.value || (typeof APP_CSRF_TOKEN !== 'undefined' ? APP_CSRF_TOKEN : '');

    // 8. Form Submit via AJAX
    inviteForm.onsubmit = async function(e) {
        e.preventDefault();

        // Validation
        const hearingId = parseInt(hearingSelect.value, 10);
        if (!hearingId) {
            Swal.fire({
                icon: 'warning',
                title: 'Hearing Required',
                text: 'Please select a target hearing for the invitation(s).',
                confirmButtonColor: '#0b3d6e'
            });
            hearingSelect.focus();
            return;
        }

        const checkedCbs = document.querySelectorAll('.stakeholder-cb:checked');
        if (checkedCbs.length === 0) {
            Swal.fire({
                icon: 'warning',
                title: 'No Stakeholders Selected',
                text: 'Please check at least one stakeholder from the list to invite.',
                confirmButtonColor: '#0b3d6e'
            });
            return;
        }

        const submitBtn = document.getElementById('btnSubmitBottom');
        const submitTopBtn = document.getElementById('btnSubmitTop');
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitTopBtn.disabled = true;
        submitBtn.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>Creating ${checkedCbs.length} Invitation(s)...`;

        try {
            const r = await appPostForm(APP_URL + '/modules/stakeholders/ajax_invite.php', inviteForm);
            if (r.success) {
                if (r.emails_delivered > 0) {
                    await Swal.fire({
                        icon: 'success',
                        title: 'Invitations & Emails Sent!',
                        html: `${escapeHtml(r.message)}`,
                        confirmButtonColor: '#198754'
                    });
                } else if (r.email_notice) {
                    const askSetup = await Swal.fire({
                        icon: 'warning',
                        title: 'Invitations Created (Email Notice)',
                        html: `<div>${escapeHtml(r.message)}</div><div class="mt-2 small text-muted">A configured <strong>Email Dispatcher / API Key</strong> is required to dispatch invitations directly to inboxes. Would you like to open the setup now?</div>`,
                        showCancelButton: true,
                        confirmButtonText: '<i class="bi bi-envelope-gear me-1"></i> Setup Email Dispatcher',
                        cancelButtonText: 'Later',
                        confirmButtonColor: '#0b3d6e'
                    });
                    if (askSetup.isConfirmed) {
                        document.getElementById('btnSmtpSetup')?.click();
                        return;
                    }
                } else {
                    if (typeof appToast === 'function') {
                        appToast('success', r.message);
                    }
                }
                setTimeout(() => location.reload(), 600);
            } else if (!r.session_expired) {
                Swal.fire({
                    icon: 'error',
                    title: 'Invitation Error',
                    text: r.message || 'Unable to create invitations.',
                    confirmButtonColor: '#0b3d6e'
                });
            }
        } catch (err) {
            Swal.fire({
                icon: 'error',
                title: 'Network Error',
                text: 'An unexpected error occurred while communicating with the server.',
                confirmButtonColor: '#0b3d6e'
            });
        } finally {
            submitBtn.disabled = false;
            submitTopBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    };

    // 9. Status change buttons in Invitation Register (Event Delegation)
    document.addEventListener('click', async function(e) {
        const b = e.target.closest('.btn-invite-status');
        if (!b) return;
        e.preventDefault();

        const status = b.dataset.status;
        const id = b.dataset.id;
        const email = b.dataset.email || '';
        const name = b.dataset.name || 'Stakeholder';
        const code = b.dataset.code || '';
        const title = b.dataset.title || '';
        const date = b.dataset.date || '';
        const time = b.dataset.time || '';
        const venue = b.dataset.venue || '';

        let targetEmail = email;
        const sid = b.dataset.sid || '';

        if (status === 'Accepted') {
            const confirm = await Swal.fire({
                title: 'Approve & Send via Gmail?',
                html: `Approve <strong>${escapeHtml(name)}</strong> and dispatch official invitation notice &amp; QR pass to:<br><span class="badge bg-danger fs-6 mt-2"><i class="bi bi-google me-1"></i>${escapeHtml(email)}</span>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '<i class="bi bi-google me-1"></i> Approve &amp; Open Gmail',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#ea4335'
            });
            if (!confirm.isConfirmed) return;

            Swal.fire({
                title: 'Approving Stakeholder...',
                html: `Processing approval and preparing official invitation for <strong>${escapeHtml(email)}</strong>...`,
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
        } else if (status === 'Sent') {
            const confirm = await Swal.fire({
                title: 'Send Invitation via Gmail?',
                html: `Send official invitation notice to:<br><strong class="text-danger font-monospace"><i class="bi bi-google me-1"></i>${escapeHtml(email)}</strong>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '<i class="bi bi-google me-1"></i> Open in Gmail',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#ea4335'
            });
            if (!confirm.isConfirmed) return;

            openGmailDirect(email, name, code, title, date, time, venue, id);
            return;
        }

        const fd = new FormData();
        fd.append('csrf_token', getCsrfToken());
        fd.append('id', id);
        fd.append('status', status);
        
        try {
            const resp = await fetch(APP_URL + '/modules/stakeholders/ajax_invitation_status.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: fd
            });

            const rawText = await resp.text();
            let r;
            try {
                r = JSON.parse(rawText);
            } catch(pe) {
                throw new Error(rawText || 'Server error: Invalid response');
            }

            // Immediately dismiss loading spinner!
            Swal.close();

            if (r.csrf_failed || r.session_expired) {
                await Swal.fire({
                    icon: 'warning',
                    title: 'Session Notice',
                    text: 'Your security session has expired or refreshed. Click OK to refresh the page.',
                    confirmButtonColor: '#0b3d6e'
                });
                location.reload();
                return;
            }

            if (r.success) {
                if (status === 'Accepted') {
                    const gmailUrl = b.dataset.gmailUrl || window.buildClientGmailUrl(email, name, code, title, date, time, venue, id);
                    window.open(gmailUrl, '_blank');

                    await Swal.fire({
                        icon: 'success',
                        title: 'Stakeholder Approved!',
                        html: `
                            <div class="text-center py-2">
                                <p class="mb-2">Stakeholder <strong>${escapeHtml(name)}</strong> has been approved.</p>
                                <p class="small text-muted mb-3">Target Email: <strong class="text-danger font-monospace">${escapeHtml(email)}</strong></p>
                                <a href="${gmailUrl}" target="_blank" rel="noopener noreferrer" class="btn btn-danger btn-lg w-100 fw-bold shadow-sm mb-2" onclick="Swal.close(); setTimeout(()=>location.reload(), 500);">
                                    <i class="bi bi-google me-2"></i> Open in Gmail &amp; Send Invitation
                                </a>
                            </div>
                        `,
                        showConfirmButton: true,
                        confirmButtonText: '<i class="bi bi-arrow-clockwise me-1"></i> Done &amp; Refresh Page',
                        confirmButtonColor: '#0b3d6e',
                        allowOutsideClick: false
                    });
                    location.reload();
                    return;
                }

                if (typeof appToast === 'function') {
                    appToast('success', r.message);
                }
                setTimeout(() => location.reload(), 400);
            } else {
                Swal.fire('Update Failed', r.message || 'Unable to update invitation.', 'error');
            }
        } catch (err) {
            Swal.fire({
                icon: 'error',
                title: 'Server Notice',
                html: `<div class="text-start small p-2 bg-light rounded text-danger">${escapeHtml(err.message || 'An error occurred.')}</div>`,
                confirmButtonColor: '#0b3d6e'
            });
        }
    });

    // 10. Real-time client-side search across all hearing tables
    const invSearchInput = document.getElementById('invitationSearchInput');
    if (invSearchInput) {
        invSearchInput.addEventListener('input', function() {
            const q = this.value.toLowerCase().trim();
            document.querySelectorAll('.hearing-table-container').forEach(container => {
                let visibleCount = 0;
                container.querySelectorAll('tbody tr.inv-row').forEach(row => {
                    const text = row.textContent.toLowerCase();
                    if (!q || text.includes(q)) {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });

                const emptySearch = container.querySelector('.inv-empty-search');
                if (emptySearch) {
                    emptySearch.style.display = (visibleCount === 0 && q) ? '' : 'none';
                }

                const badge = container.querySelector('.hearing-count-badge');
                if (badge) {
                    if (q) {
                        badge.innerHTML = '<i class="bi bi-funnel me-1"></i> ' + visibleCount + ' match' + (visibleCount === 1 ? '' : 'es');
                    } else {
                        const orig = badge.dataset.originalCount;
                        badge.innerHTML = '<i class="bi bi-envelope-paper-fill me-1"></i> ' + orig + ' ' + (orig === '1' ? 'Invitation' : 'Invitations');
                    }
                }
            });
        });
    }

    // 11. Smooth scroll and visual flash for jump-to pills
    document.querySelectorAll('.hearing-jump-link').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const targetId = this.getAttribute('href');
            const target = document.querySelector(targetId);
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                target.classList.add('table-highlight-flash');
                setTimeout(() => target.classList.remove('table-highlight-flash'), 1700);
            }
        });
    });

    // 12. Email Dispatcher Setup Modal Handler
    const smtpModalEl = document.getElementById('smtpSetupModal');
    const smtpModal = smtpModalEl ? new bootstrap.Modal(smtpModalEl) : null;
    const btnSmtpSetup = document.getElementById('btnSmtpSetup');
    const smtpForm = document.getElementById('smtpSettingsForm');
    const btnTestSmtp = document.getElementById('btnTestSmtp');

    // Toggle Provider Sections in Modal (Zero Passwords - API Key Only)
    function updateProviderSections() {
        const prov = document.querySelector('input[name="email_provider"]:checked')?.value || 'resend';
        const secBrevo = document.getElementById('sectionBrevo');
        const secResend = document.getElementById('sectionResend');
        if (secBrevo) secBrevo.style.display = (prov === 'brevo') ? '' : 'none';
        if (secResend) secResend.style.display = (prov === 'resend') ? '' : 'none';
    }

    document.querySelectorAll('input[name="email_provider"]').forEach(r => {
        r.addEventListener('change', updateProviderSections);
    });

    if (btnSmtpSetup && smtpModal) {
        btnSmtpSetup.addEventListener('click', async function() {
            try {
                const res = await fetch(APP_URL + '/modules/stakeholders/ajax_smtp_settings.php?action=get').then(r => r.json());
                const d = res.data || res;
                if (d) {
                    if (document.getElementById('smtpUser')) document.getElementById('smtpUser').value = d.smtp_user || '';
                    if (document.getElementById('smtpFromName')) document.getElementById('smtpFromName').value = d.smtp_from_name || 'City Council - Legislative Public Hearing';
                    if (d.has_resend_key && document.getElementById('resendApiKey')) {
                        document.getElementById('resendApiKey').placeholder = '●●●●●●●●●●●●●●●● (Resend Key saved. Leave blank to keep)';
                    }
                    if (d.has_brevo_key && document.getElementById('brevoApiKey')) {
                        document.getElementById('brevoApiKey').placeholder = '●●●●●●●●●●●●●●●● (Brevo Key saved. Leave blank to keep)';
                    }
                    if (d.email_provider === 'brevo') {
                        const rBrevo = document.getElementById('providerBrevo');
                        if (rBrevo) rBrevo.checked = true;
                    } else {
                        const rResend = document.getElementById('providerResend');
                        if (rResend) rResend.checked = true;
                    }
                    updateProviderSections();
                }
            } catch(e) {}
            smtpModal.show();
        });
    }

    const btnToggleSmtpPass = document.getElementById('btnToggleSmtpPass');
    if (btnToggleSmtpPass) {
        btnToggleSmtpPass.addEventListener('click', function() {
            const inp = document.getElementById('smtpPass');
            if (!inp) return;
            const isPass = inp.type === 'password';
            inp.type = isPass ? 'text' : 'password';
            this.innerHTML = isPass ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
        });
    }

    if (smtpForm) {
        smtpForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = document.getElementById('btnSaveSmtp');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

            const fd = new FormData(this);
            try {
                const resp = await fetch(APP_URL + '/modules/stakeholders/ajax_smtp_settings.php', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: fd
                });
                const rawText = await resp.text();
                let r;
                try { r = JSON.parse(rawText); } catch(pe) { throw new Error(rawText || 'Invalid response'); }

                if (r.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Settings Saved',
                        text: r.message || 'Email settings have been updated successfully!',
                        confirmButtonColor: '#0b3d6e'
                    });
                    smtpModal.hide();
                } else {
                    Swal.fire('Save Failed', r.message || 'Unable to save settings.', 'error');
                }
            } catch(err) {
                Swal.fire('Error', 'An error occurred while saving email settings: ' + (err.message || ''), 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Save Email Settings';
            }
        });
    }

    if (btnTestSmtp) {
        btnTestSmtp.addEventListener('click', async function() {
            const curUser = document.getElementById('smtpUser')?.value || '';
            const { value: testEmail } = await Swal.fire({
                title: 'Send Test Email',
                html: 'Enter the email address to receive a live test message and verify delivery speed:',
                input: 'email',
                inputValue: curUser,
                inputPlaceholder: 'example@gmail.com',
                showCancelButton: true,
                confirmButtonText: '<i class="bi bi-send-fill me-1"></i> Send Test Now',
                confirmButtonColor: '#198754'
            });

            const curProvider = document.querySelector('input[name="email_provider"]:checked')?.value || 'brevo';
            const provName = curProvider === 'brevo' ? 'Brevo API' : 'Resend API';

            Swal.fire({
                title: 'Sending Test Email...',
                html: `Connecting to <strong>${provName}</strong> and sending test email to <strong>${escapeHtml(testEmail)}</strong>...`,
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            const fd = new FormData(smtpForm || undefined);
            fd.append('csrf_token', getCsrfToken());
            fd.append('action', 'test');
            fd.append('test_email', testEmail);

            try {
                const resp = await fetch(APP_URL + '/modules/stakeholders/ajax_smtp_settings.php', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: fd
                });
                const rawText = await resp.text();
                let res;
                try { res = JSON.parse(rawText); } catch(pe) { throw new Error(rawText || 'Invalid response'); }

                Swal.close();

                if (res.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Test Email Successful!',
                        html: `Test email was successfully delivered to <strong>${escapeHtml(testEmail)}</strong>!<br><small class="text-muted">Please check your Inbox or Spam folder.</small>`,
                        confirmButtonColor: '#198754'
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Delivery Notice',
                        html: `<div class="text-start small p-2 bg-light rounded text-danger mb-2">${escapeHtml(res.message)}</div>`,
                        confirmButtonColor: '#dc3545'
                    });
                }
            } catch(e) {
                Swal.close();
                Swal.fire('Error', 'Connection failed or network error occurred during test send: ' + (e.message || ''), 'error');
            }
        });
    }

    // 13. Direct Send Email button for resending (Event Delegation fallback)
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-direct-send-email');
        if (btn) {
            window.handleResendInvitation(btn, e);
        }
    });

    // 14. Send Hearing Reminders to all invited stakeholders
    window.sendHearingReminders = async function() {
        const hearingSelect = document.getElementById('hearingSelect');
        const selectedOpt = hearingSelect ? hearingSelect.options[hearingSelect.selectedIndex] : null;
        const hearingId = selectedOpt ? (selectedOpt.getAttribute('data-hearing-id') || 0) : 0;
        const sessionDate = selectedOpt ? (selectedOpt.getAttribute('data-session-date') || '') : '';
        const hearingTitle = selectedOpt ? selectedOpt.textContent.trim() : '';

        if (!hearingId || hearingId == '0') {
            Swal.fire('Select a Hearing', 'Please select a specific hearing session from the dropdown above before sending reminders.', 'info');
            return;
        }

        const confirm = await Swal.fire({
            title: 'Send Hearing Reminders?',
            html: `Dispatch automated reminder emails to all active/accepted stakeholders for:<br><br><strong>${escapeHtml(hearingTitle)}</strong>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-send-fill me-1"></i> Send Reminders Now',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#0F2137'
        });

        if (!confirm.isConfirmed) return;

        Swal.fire({
            title: 'Sending Reminders...',
            html: 'Please wait while hearing reminder notices are dispatched to stakeholders.',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); }
        });

        try {
            const fd = new FormData();
            fd.append('hearing_id', hearingId);
            fd.append('session_date', sessionDate);
            fd.append('csrf_token', <?= json_encode(csrfToken()) ?>);

            const res = await fetch(APP_URL + '/modules/stakeholders/ajax_bulk_remind.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            });
            const data = await res.json();
            Swal.close();

            if (data && data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Reminders Sent!',
                    text: data.message || 'Hearing reminders have been successfully dispatched.',
                    confirmButtonColor: '#198754'
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Reminder Notice',
                    text: data ? data.message : 'Failed to send reminders.',
                    confirmButtonColor: '#dc3545'
                });
            }
        } catch (e) {
            Swal.close();
            Swal.fire('Error', 'A network error occurred while dispatching reminders: ' + e.message, 'error');
        }
    };
});
</script>
<?php include __DIR__ . '/../../layouts/footer.php'; ?>
