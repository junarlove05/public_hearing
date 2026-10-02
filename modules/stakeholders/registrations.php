<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';

requireLogin();

$pdo = db();
lphEnsureMultiDayAttendanceSchema($pdo);
$pageTitle = 'Stakeholder Registrations';
$activeMenu = 'stakeholders';

$filterKey = trim((string)($_GET['filter_key'] ?? ''));
$hearingFilter = (int)($_GET['hearing_id'] ?? 0);
$sessionDateFilter = trim((string)($_GET['session_date'] ?? ''));

if ($filterKey !== '' && $filterKey !== '0') {
    $parts = explode('_', $filterKey);
    if (isset($parts[0]) && is_numeric($parts[0])) $hearingFilter = (int)$parts[0];
    if (isset($parts[2]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $parts[2])) $sessionDateFilter = $parts[2];
}

// Active hearings for staff quick-enroll form
$activeHearings = $pdo->query(
    "SELECT h.id, h.reference_number, h.title, h.hearing_date, h.status, h.committee_id,
            c.name AS committee_name
     FROM hearings h
     LEFT JOIN committees c ON c.id = h.committee_id
     WHERE h.status IN ('Upcoming','Ongoing') 
     ORDER BY h.hearing_date DESC, h.title ASC"
)->fetchAll();
if (empty($activeHearings)) {
    $activeHearings = $pdo->query(
        "SELECT h.id, h.reference_number, h.title, h.hearing_date, h.status, h.committee_id,
                c.name AS committee_name
         FROM hearings h
         LEFT JOIN committees c ON c.id = h.committee_id
         ORDER BY h.hearing_date DESC, h.title ASC LIMIT 30"
    )->fetchAll();
}

// All hearings available for filtering
$filterHearings = $pdo->query(
    "SELECT DISTINCT h.id, h.reference_number, h.title, h.hearing_date, h.status,
            (SELECT COUNT(*) FROM registrations r WHERE r.hearing_id = h.id) AS reg_count
     FROM hearings h
     WHERE h.status IN ('Upcoming','Ongoing') 
        OR h.id IN (SELECT DISTINCT hearing_id FROM registrations WHERE hearing_id IS NOT NULL)
     ORDER BY h.hearing_date DESC, h.title ASC"
)->fetchAll();

// Generate hearing session options for dropdowns (multi-day hearing sessions listed separately)
$activeSessionOptions = lphGetHearingSessionDropdownOptions($pdo, $activeHearings);
$filterSessionOptions = lphGetHearingSessionDropdownOptions($pdo, $filterHearings);

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

// Stakeholders for manual registration (VERIFIED ONLY)
$stakeholders = $pdo->query(
    "SELECT s.id, s.full_name, s.email, s.phone, s.organization, s.sector, s.status, s.category_id,
            sc.name AS category_name,
            (SELECT q.code_value FROM qr_codes q WHERE q.stakeholder_id = s.id LIMIT 1) AS code_value
     FROM stakeholders s
     LEFT JOIN stakeholder_categories sc ON sc.id = s.category_id
     WHERE s.status = 'Verified'
     ORDER BY s.full_name ASC"
)->fetchAll();

// Stakeholder categories for category filtering
$stakeholderCategories = $pdo->query(
    "SELECT sc.id, sc.name, COUNT(s.id) AS verified_count
     FROM stakeholder_categories sc
     LEFT JOIN stakeholders s ON s.category_id = sc.id AND s.status = 'Verified'
     GROUP BY sc.id, sc.name
     ORDER BY sc.id ASC"
)->fetchAll();

// Map existing active registrations by session:
// Supports full key "{hid}_{sdid}_{date}", date key "{hid}_{date}", and base "{hid}"
$existingHearingRegistrations = [];
$activeRegStmt = $pdo->query(
    "SELECT hearing_id, session_day_id, session_date, stakeholder_id, registration_status 
     FROM registrations 
     WHERE registration_status IN ('Pending','Approved')"
);
while ($ar = $activeRegStmt->fetch()) {
    $hid = (int)$ar['hearing_id'];
    $sdid = (int)($ar['session_day_id'] ?? 0);
    $sdate = (string)($ar['session_date'] ?? '');
    $sid = (int)$ar['stakeholder_id'];
    $status = $ar['registration_status'];

    $fullKey = "{$hid}_{$sdid}_{$sdate}";
    if (!isset($existingHearingRegistrations[$fullKey])) {
        $existingHearingRegistrations[$fullKey] = [];
    }
    $existingHearingRegistrations[$fullKey][$sid] = $status;

    if ($sdate !== '') {
        $dateKey = "{$hid}_{$sdate}";
        if (!isset($existingHearingRegistrations[$dateKey])) {
            $existingHearingRegistrations[$dateKey] = [];
        }
        $existingHearingRegistrations[$dateKey][$sid] = $status;
    }

    if (!isset($existingHearingRegistrations[$hid])) {
        $existingHearingRegistrations[$hid] = [];
    }
    $existingHearingRegistrations[$hid][$sid] = $status;
}

// Fetch registrations with associated hearing, session day, and QR credentials
$sql = "SELECT r.*, s.full_name, s.email, s.organization,
               h.id AS hearing_id, h.title AS hearing_title, h.reference_number, h.hearing_date, h.status AS hearing_status,
               hsd.day_number, hsd.start_time AS session_start_time, hsd.end_time AS session_end_time,
               COALESCE(r.session_date, hsd.session_date, h.hearing_date) AS effective_date,
               COALESCE(q.code_value, q_stk.code_value) AS code_value,
               COALESCE(q.status, q_stk.status) AS qr_status
        FROM registrations r
        JOIN stakeholders s ON s.id = r.stakeholder_id
        LEFT JOIN hearings h ON h.id = r.hearing_id
        LEFT JOIN hearing_session_days hsd ON (hsd.id = r.session_day_id)
        LEFT JOIN qr_codes q ON q.registration_id = r.id
        LEFT JOIN qr_codes q_stk ON (q_stk.stakeholder_id = s.id AND q_stk.registration_id IS NULL)";

$where = [];
$params = [];
if ($hearingFilter > 0) {
    $where[] = "r.hearing_id = :hid";
    $params[':hid'] = $hearingFilter;
}
if ($sessionDateFilter !== '') {
    $where[] = "(r.session_date = :sdate1 OR (r.session_date IS NULL AND h.hearing_date = :sdate2))";
    $params[':sdate1'] = $sessionDateFilter;
    $params[':sdate2'] = $sessionDateFilter;
}
if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}
$sql .= " ORDER BY 
           CASE WHEN h.id IS NULL THEN 1 ELSE 0 END,
           effective_date DESC,
           h.id DESC,
           r.session_day_id ASC,
           r.registered_at DESC,
           r.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Group registrations by Hearing Session Day
$hearingsGrouped = [];
foreach ($rows as $r) {
    $hid = (int)($r['hearing_id'] ?? 0);
    $effDate = (string)($r['effective_date'] ?? $r['hearing_date'] ?? '');
    $groupKey = 'hearing_' . $hid;

    if (!isset($hearingsGrouped[$groupKey])) {
        $hearingsGrouped[$groupKey] = [
            'group_key'        => $groupKey,
            'hearing_id'       => $hid,
            'session_date'     => $effDate,
            'hearing_title'    => $r['hearing_title'] ?: ($hid === 0 ? 'General / Unassigned Registrations' : 'Hearing #' . $hid),
            'reference_number' => $r['reference_number'] ?: '',
            'hearing_date'     => $r['hearing_date'] ?? $effDate,
            'hearing_status'   => $r['hearing_status'] ?: '',
            'rows'             => []
        ];
    }
    $hearingsGrouped[$groupKey]['rows'][] = $r;
}

// If specific hearing session filtered but has 0 registrations, display empty card
if ($hearingFilter > 0 && empty($hearingsGrouped)) {
    $hStmt = $pdo->prepare("SELECT id, title, reference_number, hearing_date, status FROM hearings WHERE id = :id");
    $hStmt->execute([':id' => $hearingFilter]);
    $hInfo = $hStmt->fetch();
    if ($hInfo) {
        $effDate = $sessionDateFilter ?: ($hInfo['hearing_date'] ?? null);
        $hearingsGrouped['filtered_empty'] = [
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

// Calculate summary stats
$totalRegistrations = count($rows);
$totalApproved = 0;
$totalPending = 0;
$totalRejected = 0;
foreach ($rows as $r) {
    if ($r['registration_status'] === 'Approved') $totalApproved++;
    elseif ($r['registration_status'] === 'Pending') $totalPending++;
    elseif (in_array($r['registration_status'], ['Rejected', 'Declined'], true)) $totalRejected++;
}

include __DIR__ . '/../../layouts/header.php';
?>
<link rel="stylesheet" href="<?= e(APP_URL . '/assets/css/lph-complete-modules.css') ?>">
<style>
/* ==========================================================
   ASSIGNED HEARINGS BUTTON (PREMIUM EXECUTIVE CIVIC DESIGN)
   ========================================================== */
.btn-assigned-hearings {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.42rem;
    height: 31px;
    padding: 0 0.85rem;
    font-size: 0.76rem;
    font-weight: 600;
    line-height: 1;
    letter-spacing: 0.015em;
    color: #ffffff !important;
    background: linear-gradient(135deg, #1e3a8a 0%, #0284c7 100%);
    border: 1px solid rgba(255, 255, 255, 0.25);
    border-radius: 20px;
    box-shadow: 0 2px 5px rgba(2, 132, 199, 0.28), inset 0 1px 0 rgba(255, 255, 255, 0.25);
    transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
    white-space: nowrap;
    cursor: pointer;
    user-select: none;
    text-decoration: none;
    position: relative;
    overflow: hidden;
}
.btn-assigned-hearings::before {
    content: '';
    position: absolute;
    top: 0;
    left: -80%;
    width: 50%;
    height: 100%;
    background: linear-gradient(120deg, rgba(255,255,255,0) 0%, rgba(255,255,255,0.28) 50%, rgba(255,255,255,0) 100%);
    transform: skewX(-20deg);
    transition: left 0.6s ease;
}
.btn-assigned-hearings:hover::before {
    left: 140%;
}
.btn-assigned-hearings:hover {
    color: #ffffff !important;
    background: linear-gradient(135deg, #172554 0%, #0369a1 100%);
    border-color: rgba(255, 255, 255, 0.45);
    box-shadow: 0 4px 14px rgba(2, 132, 199, 0.45), 0 1px 3px rgba(0, 0, 0, 0.12);
    transform: translateY(-1.5px);
}
.btn-assigned-hearings:active {
    transform: translateY(0);
    box-shadow: 0 1px 3px rgba(2, 132, 199, 0.25);
}
.btn-assigned-hearings i {
    font-size: 0.86rem;
    color: #fde047 !important;
    filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.25));
    transition: transform 0.2s ease;
}
.btn-assigned-hearings:hover i {
    transform: scale(1.15);
}

.reg-page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.reg-page-title h1 {
    font-size: 1.45rem;
    font-weight: 700;
    color: #0f2137;
    margin-bottom: 0.2rem;
}
.reg-page-title p {
    color: #64748b;
    font-size: 0.85rem;
    margin-bottom: 0;
}
.reg-eyebrow {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #005a9c;
    margin-bottom: 0.2rem;
}

/* Stat Cards */
.reg-stat-card {
    background: #ffffff;
    border-radius: 12px;
    padding: 1.1rem 1.25rem;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.reg-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.06);
}
.reg-stat-icon {
    width: 46px;
    height: 46px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    flex-shrink: 0;
}

/* Hearing Table Cards */
.hearing-reg-card {
    background: #ffffff;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    overflow: hidden;
    margin-bottom: 1.5rem;
}
.hearing-reg-header {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 1rem 1.25rem;
}
.hearing-reg-avatar {
    width: 38px;
    height: 38px;
    border-radius: 8px;
    background: #eef5fb;
    border: 1px solid #bad9fc;
    color: #005a9c;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    flex-shrink: 0;
}

/* Registration Table */
.reg-table {
    margin-bottom: 0;
}
.reg-table thead th {
    background: #f8fafc;
    color: #475569;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    border-bottom: 1px solid #e2e8f0;
    padding: 0.8rem 1rem;
    white-space: nowrap;
}
.reg-table tbody td {
    padding: 0.85rem 1rem;
    vertical-align: middle;
    font-size: 0.85rem;
    border-bottom: 1px solid #f1f5f9;
}
.reg-table tbody tr:hover {
    background-color: #f8fafc;
}

/* Avatar */
.stk-avatar {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.8rem;
    flex-shrink: 0;
}

/* Modal Stakeholder Directory */
.stk-picker-container {
    max-height: 340px;
    overflow-y: auto;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
}
.stk-picker-container::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}
.stk-picker-container::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 3px;
}
.stk-picker-table thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: #f8fafc;
    color: #475569;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    border-bottom: 2px solid #e2e8f0;
    padding: 0.65rem 0.85rem;
}
.stk-picker-row {
    cursor: pointer;
    transition: background 0.15s ease;
}
.stk-picker-row:hover {
    background-color: #f1f7fc !important;
}
.stk-picker-row.selected-row {
    background-color: #e6f3ff !important;
}
.stk-picker-row.already-registered-row {
    background-color: #fafafa !important;
    opacity: 0.75;
}
</style>

<div class="app-wrapper">
    <?php include __DIR__ . '/../../layouts/sidebar.php'; ?>
    <div class="main-content">
        <?php if (file_exists(__DIR__ . '/../../layouts/top_controls.php')): ?>
            <?php include __DIR__ . '/../../layouts/top_controls.php'; ?>
        <?php endif; ?>

        <!-- Clean Page Header -->
        <div class="reg-page-header">
            <div class="reg-page-title">
                <div class="reg-eyebrow"><i class="bi bi-clipboard-check"></i> Step 3 · Registration</div>
                <h1>Stakeholder Registrations</h1>
                <p>Review participant registrations per hearing session, approve or reject applications, and issue QR attendance credentials.</p>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap no-print">
                <?php if (canManage()): ?>
                    <button type="button" class="btn btn-primary d-inline-flex align-items-center gap-2 fw-semibold shadow-sm" data-bs-toggle="modal" data-bs-target="#registerModal">
                        <i class="bi bi-person-plus-fill"></i> Assign Stakeholders on Hearing
                    </button>
                <?php endif; ?>
                <a href="index.php" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1.5">
                    <i class="bi bi-people"></i> Stakeholders
                </a>
                <a href="invitations.php" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1.5">
                    <i class="bi bi-envelope-paper"></i> Invitations
                </a>
                <a href="<?= e(APP_URL . '/modules/attendance/index.php') ?>" class="btn btn-outline-primary d-inline-flex align-items-center gap-1.5">
                    <i class="bi bi-qr-code-scan"></i> Attendance
                </a>
            </div>
        </div>

        <!-- Clean Summary Stats -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <div class="reg-stat-card">
                    <div class="reg-stat-icon bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-people-fill"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-dark lh-1 mb-1"><?= $totalRegistrations ?></div>
                        <div class="small text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.04em;">Total Registrations</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="reg-stat-card">
                    <div class="reg-stat-icon bg-secondary bg-opacity-10 text-secondary">
                        <i class="bi bi-calendar-event-fill"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-dark lh-1 mb-1"><?= count($hearingsGrouped) ?></div>
                        <div class="small text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.04em;">Hearings Listed</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="reg-stat-card">
                    <div class="reg-stat-icon bg-success bg-opacity-10 text-success">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-success lh-1 mb-1"><?= $totalApproved ?></div>
                        <div class="small text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.04em;">Approved Attendees</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="reg-stat-card">
                    <div class="reg-stat-icon bg-warning bg-opacity-10 text-warning">
                        <i class="bi bi-hourglass-split"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-warning lh-1 mb-1"><?= $totalPending ?></div>
                        <div class="small text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.04em;">Pending Review</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Clean Toolbar: Hearing Filter & Search -->
        <div class="card border-0 shadow-sm rounded-3 mb-4 bg-white">
            <div class="card-body p-3">
                <div class="row g-2 align-items-center">
                    <div class="col-md-5 col-lg-4">
                        <form method="get" class="m-0">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light text-muted border-end-0"><i class="bi bi-funnel"></i></span>
                                <select id="hearingFilterSelect" name="filter_key" class="form-select form-select-sm border-start-0" onchange="this.form.submit()">
                                    <option value="0">All Hearings &amp; Dates (Show All Tables)</option>
                                    <?php foreach ($filterSessionOptions as $fso): ?>
                                        <option value="<?= e($fso['key']) ?>" <?= ($filterKey === $fso['key'] || (!$filterKey && $hearingFilter === $fso['hearing_id'])) ? 'selected' : '' ?>>
                                            <?= e($fso['dropdown_label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </form>
                    </div>

                    <div class="col-md-5 col-lg-5">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light text-muted border-end-0"><i class="bi bi-search"></i></span>
                            <input type="text" id="stakeholderSearchInput" class="form-control form-control-sm border-start-0" placeholder="Search participant name, organization, email, or code...">
                        </div>
                    </div>

                    <div class="col-md-2 col-lg-3 text-md-end">
                        <?php if ($hearingFilter > 0): ?>
                            <a href="registrations.php" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-x-circle me-1"></i> Reset Filter
                            </a>
                        <?php else: ?>
                            <span class="small text-muted">
                                <i class="bi bi-info-circle me-1"></i> Showing <?= count($rows) ?> registration(s)
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Clean Hearing Registrations Tables -->
        <?php if (empty($hearingsGrouped)): ?>
            <div class="card border-0 shadow-sm rounded-3 bg-white py-5 text-center text-muted">
                <div class="card-body">
                    <i class="bi bi-person-check fs-1 text-warning d-block mb-2"></i>
                    <h5 class="fw-bold text-dark">No Registrations Found</h5>
                    <p class="mb-3 text-secondary">There are currently no stakeholder registrations matching your selected criteria.</p>
                    <?php if (canManage()): ?>
                        <button type="button" class="btn btn-primary btn-sm px-3" data-bs-toggle="modal" data-bs-target="#registerModal">
                            <i class="bi bi-person-plus-fill me-1"></i> Assign Stakeholders on Hearing Now
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($hearingsGrouped as $gKey => $group): ?>
                <?php
                    $hCount = count($group['rows']);
                    $hApproved = 0;
                    foreach ($group['rows'] as $gr) {
                        if ($gr['registration_status'] === 'Approved') $hApproved++;
                    }
                    $hid = (int)($group['hearing_id'] ?? 0);
                ?>
                <div class="hearing-reg-card hearing-table-container mb-4" id="hearing-section-<?= e($gKey) ?>" data-hearing-id="<?= $hid ?>" data-group-key="<?= e($gKey) ?>">
                    <!-- Hearing Header -->
                    <div class="hearing-reg-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-3">
                            <div class="hearing-reg-avatar">
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
                                    <span class="text-success fw-medium">
                                        <i class="bi bi-check-circle me-1"></i><?= $hApproved ?> Approved
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-white text-dark border px-2.5 py-1.5 rounded-pill hearing-count-badge" data-original-count="<?= $hCount ?>">
                                <i class="bi bi-people-fill text-primary me-1"></i> <?= $hCount ?> <?= $hCount === 1 ? 'Participant' : 'Participants' ?>
                            </span>
                            <?php if ($hid > 0 && ($group['hearing_status'] ?? '') !== 'Completed' && ($group['hearing_status'] ?? '') !== 'Cancelled'): ?>
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
                        </div>
                    </div>

                    <!-- Table for this specific Hearing -->
                    <div class="table-responsive">
                        <table class="table table-hover reg-table align-middle">
                            <thead>
                                <tr>
                                    <th style="width: 150px;"><i class="bi bi-hash"></i> Code</th>
                                    <th><i class="bi bi-person"></i> Participant</th>
                                    <th style="width: 140px;"><i class="bi bi-geo-alt"></i> Attendance</th>
                                    <th style="width: 130px;"><i class="bi bi-shield-check"></i> Status</th>
                                    <th style="width: 180px;"><i class="bi bi-qr-code"></i> QR Credential</th>
                                    <th class="text-end no-print" style="width: 200px;"><i class="bi bi-gear me-1"></i> Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($group['rows'])): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-muted">
                                            <i class="bi bi-person-x d-block fs-3 mb-1 text-secondary"></i>
                                            No registrations recorded for this hearing.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($group['rows'] as $r): ?>
                                        <tr class="reg-row" data-id="<?= (int)$r['id'] ?>" id="reg-row-<?= (int)$r['id'] ?>">
                                            <td>
                                                <span class="badge bg-light text-dark border font-monospace"><?= e($r['registration_code'] ?: '—') ?></span>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2.5">
                                                    <div class="stk-avatar" style="<?= lphAvatarStyle($r['full_name']) ?>">
                                                        <?= e(lphInitials($r['full_name'])) ?>
                                                    </div>
                                                    <div>
                                                        <strong class="text-dark d-block"><?= e($r['full_name']) ?></strong>
                                                        <div class="small text-muted font-monospace"><?= e($r['email']) ?></div>
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
                                                <?php
                                                    $att = $r['attendance_type'] ?: 'On-site';
                                                    $attIcon = match($att) {
                                                        'On-site' => 'bi-geo-alt-fill text-danger',
                                                        'Online' => 'bi-camera-video-fill text-primary',
                                                        'Hybrid' => 'bi-broadcast text-info',
                                                        'Invited' => 'bi-envelope-check-fill text-secondary',
                                                        default => 'bi-person-check'
                                                    };
                                                ?>
                                                <span class="badge bg-light text-dark border">
                                                    <i class="bi <?= $attIcon ?> me-1"></i><?= e($att) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge text-bg-<?= $r['registration_status'] === 'Approved' ? 'success' : (in_array($r['registration_status'], ['Rejected','Declined'], true) ? 'danger' : ($r['registration_status'] === 'Pending' ? 'warning' : 'secondary')) ?>">
                                                    <?= e($r['registration_status']) ?>
                                                </span>
                                                <?php if (!empty($r['rejection_reason'])): ?>
                                                    <div class="small text-danger mt-1">
                                                        <i class="bi bi-exclamation-circle me-1"></i><?= e($r['rejection_reason']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($r['code_value'])): ?>
                                                    <span class="badge bg-light text-primary border font-monospace">
                                                        <i class="bi bi-qr-code me-1"></i><?= e($r['code_value']) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted small">
                                                        <i class="bi bi-clock-history me-1"></i>Issued after approval
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end no-print">
                                                <div class="d-inline-flex align-items-center justify-content-end gap-1.5 flex-nowrap">
                                                    <button type="button" class="btn-assigned-hearings btn-view-assigned-hearings" 
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#stakeholderHearingsModal"
                                                            data-stakeholder-id="<?= (int)$r['stakeholder_id'] ?>" 
                                                            data-name="<?= e($r['full_name']) ?>"
                                                            data-avatar-style="<?= e(lphAvatarStyle($r['full_name'])) ?>"
                                                            data-initials="<?= e(lphInitials($r['full_name'])) ?>"
                                                            data-email="<?= e($r['email'] ?? '') ?>"
                                                            data-org="<?= e($r['organization'] ?? '') ?>"
                                                            title="View assigned hearings for <?= e($r['full_name']) ?>">
                                                        <i class="bi bi-calendar2-check-fill"></i>
                                                        <span>Hearings</span>
                                                    </button>
                                                    <?php if (canManage()): ?>
                                                        <div class="btn-group btn-group-sm">
                                                            <?php if ($r['registration_status'] !== 'Approved'): ?>
                                                                <button type="button" class="btn btn-outline-success btn-reg-status" data-id="<?= (int)$r['id'] ?>" data-status="Approved" title="Approve Registration">
                                                                    <i class="bi bi-check-lg"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                            <?php if (!in_array($r['registration_status'], ['Rejected', 'Declined'], true)): ?>
                                                                <button type="button" class="btn btn-outline-danger btn-reg-status" data-id="<?= (int)$r['id'] ?>" data-status="Rejected" title="Reject Registration">
                                                                    <i class="bi bi-x-lg"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                            <button type="button" class="btn btn-outline-secondary btn-reg-status" data-id="<?= (int)$r['id'] ?>" data-status="Cancelled" title="Cancel Registration">
                                                                <i class="bi bi-slash-circle"></i>
                                                            </button>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="reg-empty-search text-center py-3 text-muted" style="display: none;">
                                        <td colspan="6" class="py-3">
                                            <i class="bi bi-search me-1"></i>No matching participants found in this hearing.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>
</div>

<!-- ==========================================================
     MODAL: REGISTER STAKEHOLDERS (CLEAN & SIMPLE)
     ========================================================== -->
<?php if (canManage()): ?>
<div class="modal fade" id="registerModal" tabindex="-1" aria-labelledby="registerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-3 px-4 bg-light">
                <div>
                    <h5 class="modal-title fw-bold text-dark" id="registerModalLabel">
                        <i class="bi bi-person-check-fill text-primary me-2"></i>Assign Stakeholder on the Hearing
                    </h5>
                    <small class="text-muted">Select target hearing, filter verified stakeholders, and assign them on the hearing with credentials.</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-4">
                <form id="registrationForm" novalidate>
                    <?= csrfField() ?>
                    <div id="selectedStakeholderInputs"></div>

                    <!-- Step 1: Target Hearing & Attendance Mode -->
                    <div class="card border border-light-subtle bg-light rounded-3 p-3 mb-3">
                        <div class="row g-3">
                            <div class="col-lg-7">
                                <label class="form-label fw-bold small text-uppercase text-secondary mb-1">
                                    Target Hearing <span class="text-danger">*</span>
                                </label>
                                <select name="hearing_session_key" id="hearingSelect" class="form-select bg-white" required>
                                    <option value="" data-hearing-id="0" data-session-day-id="0" data-session-date="" data-committee="">Select target hearing session day...</option>
                                    <?php foreach ($activeSessionOptions as $opt): ?>
                                        <option value="<?= e($opt['key']) ?>" 
                                                data-hearing-id="<?= (int)$opt['hearing_id'] ?>"
                                                data-session-day-id="<?= (int)$opt['session_day_id'] ?>"
                                                data-session-date="<?= e($opt['session_date']) ?>"
                                                data-day-number="<?= (int)$opt['day_number'] ?>"
                                                data-title="<?= e($opt['hearing_title']) ?>"
                                                <?= ($filterKey === $opt['key'] || (!$filterKey && $hearingFilter === (int)$opt['hearing_id'])) ? 'selected' : '' ?>>
                                            <?= e($opt['dropdown_label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="hearing_id" id="hiddenHearingId" value="">
                                <input type="hidden" name="session_day_id" id="hiddenSessionDayId" value="0">
                                <input type="hidden" name="session_date" id="hiddenSessionDate" value="">
                            </div>

                            <div class="col-lg-5">
                                <label class="form-label fw-bold small text-uppercase text-secondary mb-1">
                                    Attendance Mode <span class="text-danger">*</span>
                                </label>
                                <select name="attendance_type" id="attendanceTypeSelect" class="form-select bg-white">
                                    <option value="On-site">On-site (Physical Presence)</option>
                                    <option value="Online">Online (Virtual / Teleconference)</option>
                                    <option value="Hybrid">Hybrid (Combined)</option>
                                </select>
                                <div class="form-text small text-muted">
                                    Determines QR check-in & access rules.
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Selected Stakeholders Chips Preview -->
                    <div id="selectedStakeholderCard" class="card mb-3 border-primary shadow-sm" style="display: none; background: #f0f7ff;">
                        <div class="card-body py-2 px-3">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 border-bottom pb-2 mb-2">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="small text-uppercase fw-bold text-primary" style="font-size:0.7rem;">Selected:</span>
                                    <span class="badge bg-primary rounded-pill px-2.5 py-1" id="previewCountBadge">
                                        <i class="bi bi-people-fill me-1"></i><span id="selectedCountText">0</span> Stakeholders
                                    </span>
                                </div>
                                <button type="button" id="btnCancelSelection" class="btn btn-xs btn-outline-secondary">
                                    <i class="bi bi-x-circle me-1"></i> Clear Selection
                                </button>
                            </div>
                            <div class="d-flex flex-wrap gap-1.5 align-items-center" id="previewChipsContainer" style="max-height: 80px; overflow-y: auto;"></div>
                        </div>
                    </div>

                    <!-- Step 2: Directory of Verified Stakeholders -->
                    <div class="border rounded-3 overflow-hidden">
                        <!-- Toolbar -->
                        <div class="p-3 bg-light border-bottom d-flex flex-wrap align-items-center justify-content-between gap-2">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="fw-bold text-dark small text-uppercase">
                                    <i class="bi bi-patch-check-fill text-success me-1"></i> Verified Directory:
                                </span>
                                <span class="badge bg-white text-secondary border font-monospace" id="stkResultCount">
                                    <?= count($stakeholders) ?> available
                                </span>
                                <div class="btn-group btn-group-sm ms-2">
                                    <button type="button" id="btnSelectAllVisible" class="btn btn-outline-primary" title="Select all visible">
                                        <i class="bi bi-check-all me-1"></i>Select All
                                    </button>
                                    <button type="button" id="btnDeselectAll" class="btn btn-outline-secondary" title="Clear">
                                        <i class="bi bi-x-lg me-1"></i>Clear
                                    </button>
                                </div>
                            </div>

                            <div class="d-flex align-items-center gap-2 flex-wrap flex-grow-1 justify-content-end" style="max-width: 580px;">
                                <!-- Category Dropdown -->
                                <div class="input-group input-group-sm" style="max-width: 250px;">
                                    <span class="input-group-text bg-white text-muted"><i class="bi bi-tag"></i></span>
                                    <select id="stkCategoryFilter" class="form-select form-select-sm bg-white" title="Filter by Category">
                                        <option value="">All Categories (<?= count($stakeholders) ?>)</option>
                                        <?php foreach ($stakeholderCategories as $cat): ?>
                                            <option value="<?= (int)$cat['id'] ?>">
                                                <?= e($cat['name']) ?> (<?= (int)$cat['verified_count'] ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Live Search Input -->
                                <div class="input-group input-group-sm" style="max-width: 250px;">
                                    <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
                                    <input type="search" id="stkSearchInput" class="form-control form-control-sm bg-white" placeholder="Search name, org, category...">
                                </div>

                                <button type="button" id="btnResetStkFilter" class="btn btn-sm btn-outline-secondary" title="Clear Filter">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Table -->
                        <div class="stk-picker-container bg-white">
                            <table class="table table-hover align-middle mb-0 stk-picker-table" id="stakeholderPickerTable">
                                <thead>
                                    <tr>
                                        <th style="width: 44px; text-align: center;">
                                            <input type="checkbox" id="selectAllStkCb" class="form-check-input cursor-pointer" title="Select all visible">
                                        </th>
                                        <th style="width: 32%;">Stakeholder Name</th>
                                        <th style="width: 28%;">Organization / Sector</th>
                                        <th style="width: 26%;">Category & Contact</th>
                                        <th style="width: 14%; text-align: right;">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="stakeholderPickerBody">
                                    <?php if (empty($stakeholders)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-4 text-muted">
                                                <i class="bi bi-person-x fs-3 d-block mb-1"></i>
                                                No verified stakeholders found.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($stakeholders as $s): 
                                            $sId = (int)$s['id'];
                                            $sName = $s['full_name'];
                                            $sEmail = $s['email'] ?? '';
                                            $sPhone = $s['phone'] ?? '';
                                            $sOrg = $s['organization'] ?? '';
                                            $sSector = $s['sector'] ?? '';
                                            $sCatId = (int)($s['category_id'] ?? 0);
                                            $sCatName = $s['category_name'] ?? '';
                                            $sInitials = lphInitials($sName);
                                            $sAvatarStyle = lphAvatarStyle($sName);
                                            $sCatShort = preg_replace('/^[A-Z]\.\s*/', '', $sCatName);

                                            $catKeywords = '';
                                            if (stripos($sCatName, 'Barangay') !== false) {
                                                $catKeywords = 'barangay brgy community captain kagawad tanod liga sk federation';
                                            } elseif (stripos($sCatName, 'Emergency') !== false || stripos($sCatName, 'Safety') !== false) {
                                                $catKeywords = 'emergency safety police pnp bfp fire rescue disaster cdrrmo hospital medical responders peace order';
                                            } elseif (stripos($sCatName, 'City Government') !== false || stripos($sCatName, 'Internal') !== false) {
                                                $catKeywords = 'city government internal mayor council legal budget administrator planning personnel gso sanggunian city hall';
                                            } elseif (stripos($sCatName, 'National') !== false) {
                                                $catKeywords = 'national government dilg doh dswd denr dpwh dotr mpd secretary police regional';
                                            } elseif (stripos($sCatName, 'Public Institutions') !== false) {
                                                $catKeywords = 'public institutions school education library parks hospital health center university recreation';
                                            } elseif (stripos($sCatName, 'Private Sector') !== false) {
                                                $catKeywords = 'private business commerce trade enterprise chamber industry merchant store';
                                            } elseif (stripos($sCatName, 'Civil Society') !== false) {
                                                $catKeywords = 'civil society ngo advocacy non-profit organization community association';
                                            }

                                            $searchMeta = strtolower(implode(' ', array_filter([
                                                $sName, 
                                                $sEmail, 
                                                $sPhone, 
                                                $sOrg, 
                                                $sSector, 
                                                $sCatName, 
                                                $sCatShort, 
                                                'category: ' . $sCatName, 
                                                'category ' . $sCatShort,
                                                'cat: ' . $sCatShort,
                                                'cat ' . $sCatShort,
                                                $catKeywords
                                            ])));
                                        ?>
                                            <tr class="stk-picker-row" 
                                                id="stk_row_<?= $sId ?>"
                                                data-id="<?= $sId ?>"
                                                data-name="<?= e($sName) ?>"
                                                data-org="<?= e($sOrg) ?>"
                                                data-sector="<?= e($sSector) ?>"
                                                data-email="<?= e($sEmail) ?>"
                                                data-phone="<?= e($sPhone) ?>"
                                                data-category-id="<?= $sCatId ?>"
                                                data-category-name="<?= e($sCatName) ?>"
                                                data-category-short="<?= e($sCatShort) ?>"
                                                data-initials="<?= e($sInitials) ?>"
                                                data-avatar-style="<?= e($sAvatarStyle) ?>"
                                                data-search="<?= e($searchMeta) ?>">
                                                
                                                <td style="text-align: center;" onclick="event.stopPropagation()">
                                                    <input type="checkbox" class="form-check-input stk-cb cursor-pointer" value="<?= $sId ?>" id="stk_cb_<?= $sId ?>" title="Select <?= e($sName) ?>">
                                                </td>

                                                <td>
                                                    <div class="d-flex align-items-center gap-2.5">
                                                        <div class="stk-avatar" style="<?= $sAvatarStyle ?>">
                                                            <?= e($sInitials) ?>
                                                        </div>
                                                        <div>
                                                            <div class="fw-bold text-dark stk-name-label">
                                                                <?= e($sName) ?>
                                                            </div>
                                                            <div class="d-flex align-items-center gap-1 flex-wrap mt-0.5">
                                                                <span class="badge bg-secondary-subtle text-secondary border already-reg-badge" style="display: none; font-size: 0.65rem;">
                                                                    <i class="bi bi-check-all me-1"></i>Already Registered
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>

                                                <td>
                                                    <div class="text-dark fw-medium small">
                                                        <?= !empty($sOrg) ? e($sOrg) : '<span class="text-muted fst-italic">Individual / Unspecified</span>' ?>
                                                    </div>
                                                    <?php if (!empty($sSector)): ?>
                                                        <small class="text-muted d-block"><?= e($sSector) ?></small>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <?php if (!empty($sCatName)): ?>
                                                        <span class="badge bg-light text-secondary border px-1.5 py-0.5 mb-1 d-inline-block" style="font-size:0.67rem;">
                                                            <i class="bi bi-tag text-primary me-0.5"></i><?= e($sCatName) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <div class="small text-truncate" style="max-width: 220px;" title="<?= e($sEmail) ?>">
                                                        <i class="bi bi-envelope text-muted me-1"></i><?= e($sEmail) ?>
                                                    </div>
                                                </td>

                                                <td class="text-end">
                                                    <button type="button" 
                                                            class="btn btn-sm btn-outline-primary btn-select-stk" 
                                                            id="btn_select_stk_<?= $sId ?>"
                                                            data-id="<?= $sId ?>"
                                                            title="Select <?= e($sName) ?>">
                                                        <i class="bi bi-check2 me-1"></i>Select
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    
                                    <tr id="stkEmptyFilterRow" style="display: none;">
                                        <td colspan="5" class="text-center py-4 text-muted">
                                            <i class="bi bi-search fs-4 d-block mb-1 text-secondary"></i>
                                            No verified stakeholders found matching the selected filter.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </form>
            </div>

            <div class="modal-footer py-2.5 px-4 bg-light border-top d-flex justify-content-between align-items-center">
                <div>
                    <span class="badge bg-primary text-white px-2.5 py-1.5 rounded-pill">
                        <i class="bi bi-check2-circle me-1"></i> <span id="modalSelectedCountPreview">0</span> Selected
                    </span>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-light border px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="btnSubmitRegistrationModal" class="btn btn-primary px-4 fw-semibold shadow-sm" onclick="document.getElementById('registrationForm').dispatchEvent(new Event('submit', {cancelable:true, bubbles:true}))">
                        <i class="bi bi-person-check-fill me-1"></i> <span id="submitBtnText">Assign Selected</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ==========================================================
     MODAL: VIEW ASSIGNED HEARINGS FOR STAKEHOLDER
     ========================================================== -->
<div class="modal fade" id="stakeholderHearingsModal" tabindex="-1" aria-labelledby="stakeholderHearingsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-3">
            <div class="modal-header border-bottom py-3 px-4 bg-light">
                <div class="d-flex align-items-center gap-3">
                    <div id="shModalAvatar" class="stk-avatar shadow-sm" style="width: 46px; height: 46px; font-size: 1.05rem; flex-shrink: 0;">--</div>
                    <div>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <h5 class="modal-title fw-bold text-dark mb-0" id="shModalName">Assigned Hearings</h5>
                            <span id="shModalBadgeCount" class="badge bg-primary text-white rounded-pill px-2.5 py-1">0 Hearings</span>
                        </div>
                        <div class="small text-muted mt-0.5" id="shModalSubtitle">Viewing assigned hearing schedules for this participant</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="shModalBody">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                    <div class="small text-muted mt-2">Loading assigned hearings...</div>
                </div>
            </div>
            <div class="modal-footer py-2.5 px-4 bg-light border-top d-flex justify-content-between align-items-center">
                <span class="small text-muted" id="shModalFooterInfo"></span>
                <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function() {
    const APP_URL = <?= json_encode(rtrim(APP_URL, '/')) ?>;
    // Existing hearing registrations map: { hearing_id: { stakeholder_id: status } }
    const existingHearingRegs = <?= json_encode($existingHearingRegistrations) ?>;

    // Multi-select state: Map of sid (int) -> stakeholder object
    const selectedStakeholders = new Map();

    const selectedInputsContainer = document.getElementById('selectedStakeholderInputs');
    const selectedPreviewCard = document.getElementById('selectedStakeholderCard');
    const previewChipsContainer = document.getElementById('previewChipsContainer');
    const selectedCountText = document.getElementById('selectedCountText');
    const submitBtnText = document.getElementById('submitBtnText');
    const btnCancelSelection = document.getElementById('btnCancelSelection');
    const btnSelectAllVisible = document.getElementById('btnSelectAllVisible');
    const btnDeselectAll = document.getElementById('btnDeselectAll');
    const selectAllStkCb = document.getElementById('selectAllStkCb');
    const hearingSelect = document.getElementById('hearingSelect');

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    function renderSelectedUI() {
        const count = selectedStakeholders.size;

        // 1. Update row styles, checkboxes, and action buttons
        document.querySelectorAll('.stk-picker-row').forEach(row => {
            const sid = parseInt(row.dataset.id, 10);
            const isSelected = selectedStakeholders.has(sid);
            const cb = row.querySelector('.stk-cb');
            const btn = row.querySelector('.btn-select-stk');

            if (cb) { cb.checked = isSelected; cb.disabled = false; }
            row.classList.toggle('selected-row', isSelected);
            if (btn) {
                btn.disabled = false;
                if (isSelected) {
                    btn.className = 'btn btn-sm btn-primary btn-select-stk active';
                    btn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Selected';
                } else {
                    btn.className = 'btn btn-sm btn-outline-primary btn-select-stk';
                    btn.innerHTML = '<i class="bi bi-check2-circle me-1"></i>Select';
                }
            }
        });

        // 2. Update Header Select All Checkbox
        if (selectAllStkCb) {
            const visibleRows = Array.from(document.querySelectorAll('.stk-picker-row')).filter(r => r.style.display !== 'none' && !r.classList.contains('already-registered-row'));
            if (visibleRows.length > 0) {
                const allSelected = visibleRows.every(r => selectedStakeholders.has(parseInt(r.dataset.id, 10)));
                const someSelected = visibleRows.some(r => selectedStakeholders.has(parseInt(r.dataset.id, 10)));
                selectAllStkCb.checked = allSelected;
                selectAllStkCb.indeterminate = !allSelected && someSelected;
            } else {
                selectAllStkCb.checked = false;
                selectAllStkCb.indeterminate = false;
            }
        }

        // 3. Update hidden form inputs
        if (selectedInputsContainer) {
            selectedInputsContainer.innerHTML = '';
            selectedStakeholders.forEach((s, sid) => {
                const inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'stakeholder_ids[]';
                inp.value = sid;
                selectedInputsContainer.appendChild(inp);
            });
        }

        // 4. Update preview card banner & chips
        const modalCountPreview = document.getElementById('modalSelectedCountPreview');
        if (modalCountPreview) modalCountPreview.textContent = count;

        if (count === 0) {
            if (selectedPreviewCard) selectedPreviewCard.style.display = 'none';
            if (submitBtnText) submitBtnText.textContent = 'Register Selected';
        } else {
            if (selectedPreviewCard) selectedPreviewCard.style.display = 'block';
            if (selectedCountText) selectedCountText.textContent = count;
            if (submitBtnText) {
                submitBtnText.textContent = 'Register ' + count + ' Stakeholder' + (count === 1 ? '' : 's');
            }

            if (previewChipsContainer) {
                previewChipsContainer.innerHTML = '';
                selectedStakeholders.forEach((s, sid) => {
                    const chip = document.createElement('div');
                    chip.className = 'badge bg-white text-dark border d-inline-flex align-items-center gap-1.5 py-1 px-2.5 rounded-pill shadow-xs';
                    chip.innerHTML = `
                        <span class="stk-avatar-xs" style="width:20px;height:20px;font-size:0.6rem;font-weight:700;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;${s.avatarStyle}">${escapeHtml(s.initials)}</span>
                        <span class="fw-bold text-dark" style="font-size:0.75rem;">${escapeHtml(s.name)}</span>
                        ${s.category ? `<span class="badge bg-light text-secondary border px-1" style="font-size:0.62rem;">${escapeHtml(s.category)}</span>` : ''}
                        <button type="button" class="btn-close btn-close-chip ms-1" style="font-size:0.55rem;width:8px;height:8px;" data-sid="${sid}" title="Remove"></button>
                    `;
                    chip.querySelector('.btn-close-chip').addEventListener('click', (e) => {
                        e.stopPropagation();
                        selectedStakeholders.delete(sid);
                        renderSelectedUI();
                    });
                    previewChipsContainer.appendChild(chip);
                });
            }
        }
    }

    function toggleStakeholder(row, forceState = null) {
        if (!row) return;
        const sid = parseInt(row.dataset.id, 10);
        if (!sid) return;

        const willSelect = forceState !== null ? forceState : !selectedStakeholders.has(sid);

        if (willSelect) {
            selectedStakeholders.set(sid, {
                id: sid,
                name: row.dataset.name || '',
                category: row.dataset.categoryName || '',
                org: row.dataset.org || '',
                sector: row.dataset.sector || '',
                email: row.dataset.email || '',
                phone: row.dataset.phone || '',
                initials: row.dataset.initials || '--',
                avatarStyle: row.dataset.avatarStyle || ''
            });
        } else {
            selectedStakeholders.delete(sid);
        }

        renderSelectedUI();
    }

    function updateHearingRegistrationStatus() {
        const selectedOption = hearingSelect && hearingSelect.selectedIndex >= 0 ? hearingSelect.options[hearingSelect.selectedIndex] : null;
        const sessionKey = hearingSelect ? (hearingSelect.value || '') : '';
        const hid = selectedOption ? parseInt(selectedOption.dataset.hearingId || '0', 10) : parseInt(sessionKey, 10);
        const sessionDayId = selectedOption ? parseInt(selectedOption.dataset.sessionDayId || '0', 10) : 0;
        const sessionDate = selectedOption ? (selectedOption.dataset.sessionDate || '') : '';

        // Update hidden inputs for backend submission
        const hiddenHearingId = document.getElementById('hiddenHearingId');
        const hiddenSessionDayId = document.getElementById('hiddenSessionDayId');
        const hiddenSessionDate = document.getElementById('hiddenSessionDate');
        if (hiddenHearingId) hiddenHearingId.value = hid > 0 ? hid : '';
        if (hiddenSessionDayId) hiddenSessionDayId.value = sessionDayId;
        if (hiddenSessionDate) hiddenSessionDate.value = sessionDate;

        // Look up registered stakeholders for this specific hearing session day only
        let registeredMap = {};
        if (sessionKey && existingHearingRegs[sessionKey]) {
            registeredMap = existingHearingRegs[sessionKey];
        } else if (hid > 0 && sessionDayId > 0 && existingHearingRegs[hid + '_' + sessionDayId]) {
            registeredMap = existingHearingRegs[hid + '_' + sessionDayId];
        } else if (hid > 0 && sessionDate && existingHearingRegs[hid + '_' + sessionDate]) {
            registeredMap = existingHearingRegs[hid + '_' + sessionDate];
        }

        // Check if registered on ANY other day of this hearing
        const anyHearingRegMap = (hid > 0 && existingHearingRegs[hid]) ? existingHearingRegs[hid] : {};

        document.querySelectorAll('.stk-picker-row').forEach(row => {
            const sid = parseInt(row.dataset.id, 10);
            const isRegThisDay = !!registeredMap[sid];
            const isRegOtherDay = !isRegThisDay && !!anyHearingRegMap[sid];
            const regBadge = row.querySelector('.already-reg-badge');
            let otherDayBadge = row.querySelector('.other-day-reg-badge');

            if (!otherDayBadge) {
                otherDayBadge = document.createElement('span');
                otherDayBadge.className = 'badge bg-info-subtle text-info border other-day-reg-badge';
                otherDayBadge.style.cssText = 'font-size: 0.65rem; display: none;';
                otherDayBadge.innerHTML = '<i class="bi bi-calendar2-check me-1"></i>Assigned (Other Day)';
                const badgeContainer = row.querySelector('.stk-name-label')?.parentElement?.querySelector('.d-flex');
                if (badgeContainer) badgeContainer.appendChild(otherDayBadge);
            }

            if (isRegThisDay) {
                if (regBadge) {
                    regBadge.style.display = 'inline-block';
                    regBadge.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Assigned (This Day)';
                }
                if (otherDayBadge) otherDayBadge.style.display = 'none';
            } else if (isRegOtherDay) {
                if (regBadge) regBadge.style.display = 'none';
                if (otherDayBadge) otherDayBadge.style.display = 'inline-block';
            } else {
                if (regBadge) regBadge.style.display = 'none';
                if (otherDayBadge) otherDayBadge.style.display = 'none';
            }

            // Keep all rows enabled so stakeholders can be assigned to Day 1, Day 2, or any day!
            row.classList.remove('already-registered-row');
        });

        renderSelectedUI();
    }

    if (hearingSelect) {
        hearingSelect.addEventListener('change', updateHearingRegistrationStatus);
        updateHearingRegistrationStatus();
    }

    // Row click handler (clicking row toggles selection)
    document.querySelectorAll('.stk-picker-row').forEach(row => {
        row.addEventListener('click', function(e) {
            if (e.target.closest('.btn-select-stk') || e.target.closest('.stk-cb')) return;
            toggleStakeholder(this);
        });
    });

    // Checkbox change handler
    document.querySelectorAll('.stk-cb').forEach(cb => {
        cb.addEventListener('change', function(e) {
            const row = this.closest('.stk-picker-row');
            if (row) toggleStakeholder(row, this.checked);
        });
    });

    // Select button click handler
    document.querySelectorAll('.btn-select-stk').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            const row = this.closest('.stk-picker-row');
            if (row) toggleStakeholder(row);
        });
    });

    // Select all visible / Deselect all handlers
    function selectAllVisibleStakeholders() {
        document.querySelectorAll('.stk-picker-row').forEach(row => {
            if (row.style.display !== 'none' && !row.classList.contains('already-registered-row')) {
                const sid = parseInt(row.dataset.id, 10);
                if (sid) {
                    selectedStakeholders.set(sid, {
                        id: sid,
                        name: row.dataset.name || '',
                        category: row.dataset.categoryName || '',
                        org: row.dataset.org || '',
                        sector: row.dataset.sector || '',
                        email: row.dataset.email || '',
                        phone: row.dataset.phone || '',
                        initials: row.dataset.initials || '--',
                        avatarStyle: row.dataset.avatarStyle || ''
                    });
                }
            }
        });
        renderSelectedUI();
    }

    function clearAllSelectedStakeholders() {
        selectedStakeholders.clear();
        renderSelectedUI();
    }

    if (btnSelectAllVisible) {
        btnSelectAllVisible.addEventListener('click', selectAllVisibleStakeholders);
    }

    if (btnDeselectAll) {
        btnDeselectAll.addEventListener('click', clearAllSelectedStakeholders);
    }

    if (btnCancelSelection) {
        btnCancelSelection.addEventListener('click', clearAllSelectedStakeholders);
    }

    if (selectAllStkCb) {
        selectAllStkCb.addEventListener('change', function() {
            if (this.checked) {
                selectAllVisibleStakeholders();
            } else {
                clearAllSelectedStakeholders();
            }
        });
    }

    // Category Filter & Live Search Logic
    const catFilter = document.getElementById('stkCategoryFilter');
    const stkSearchInput = document.getElementById('stkSearchInput');
    const btnResetFilter = document.getElementById('btnResetStkFilter');
    const stkResultCount = document.getElementById('stkResultCount');
    const stkEmptyFilterRow = document.getElementById('stkEmptyFilterRow');

    function syncCategoryPills(catId) {
        document.querySelectorAll('.category-pill').forEach(pill => {
            const pId = pill.dataset.catId || '';
            pill.classList.toggle('active', pId === catId);
        });
    }

    function filterStakeholders() {
        const catId = catFilter ? catFilter.value.trim() : '';
        let rawQuery = stkSearchInput ? stkSearchInput.value.toLowerCase().trim() : '';
        
        // Strip common prefix keywords if typed (e.g. "category: ", "cat: ")
        rawQuery = rawQuery.replace(/^category:\s*/i, '').replace(/^cat:\s*/i, '');
        const queryTokens = rawQuery.length > 0 ? rawQuery.split(/\s+/).filter(t => t.length > 0) : [];
        let visible = 0;

        document.querySelectorAll('.stk-picker-row').forEach(row => {
            const rowCatId = (row.dataset.categoryId || '').trim();
            const rowSearch = (row.dataset.search || '').toLowerCase();

            // Category match logic
            let matchesCat = true;
            if (catId !== '') {
                matchesCat = (rowCatId === catId);
            }

            // Search query token matching
            const matchesQuery = queryTokens.length === 0 || queryTokens.every(token => rowSearch.includes(token));

            if (matchesCat && matchesQuery) {
                row.style.display = '';
                visible++;
            } else {
                row.style.display = 'none';
            }
        });

        if (stkResultCount) {
            stkResultCount.textContent = visible + ' available';
        }
        if (stkEmptyFilterRow) {
            stkEmptyFilterRow.style.display = (visible === 0) ? '' : 'none';
        }

        renderSelectedUI();
    }

    // Category dropdown listener
    if (catFilter) {
        catFilter.addEventListener('change', function() {
            syncCategoryPills(this.value.trim());
            filterStakeholders();
        });
        catFilter.addEventListener('input', function() {
            syncCategoryPills(this.value.trim());
            filterStakeholders();
        });
    }

    // Category pills listener
    document.querySelectorAll('.category-pill').forEach(pill => {
        pill.addEventListener('click', function() {
            const catId = this.dataset.catId || '';
            if (catFilter) catFilter.value = catId;
            syncCategoryPills(catId);
            filterStakeholders();
        });
    });

    // Clickable category badge inside table rows
    document.querySelectorAll('.category-badge-clickable').forEach(badge => {
        badge.addEventListener('click', function(e) {
            e.stopPropagation();
            const catId = this.dataset.catId || '';
            if (catFilter) catFilter.value = catId;
            syncCategoryPills(catId);
            filterStakeholders();
        });
    });

    // Real-time live search input listener
    if (stkSearchInput) {
        stkSearchInput.addEventListener('input', filterStakeholders);
        stkSearchInput.addEventListener('search', filterStakeholders);
    }

    // Reset filters
    if (btnResetFilter) {
        btnResetFilter.addEventListener('click', function() {
            if (catFilter) catFilter.value = '';
            if (stkSearchInput) stkSearchInput.value = '';
            syncCategoryPills('');
            filterStakeholders();
        });
    }

    // Initial filter pass
    filterStakeholders();

    // Registration Form submit handler
    const form = document.getElementById('registrationForm');
    if (form) {
        form.onsubmit = async function(e) {
            e.preventDefault();
            
            const hearingId = hearingSelect ? parseInt(hearingSelect.value || '0', 10) : 0;
            if (!hearingId || hearingId <= 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Target Hearing Required',
                    text: 'Please select a target hearing above before submitting.'
                });
                if (hearingSelect) hearingSelect.focus();
                return;
            }

            if (selectedStakeholders.size === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'No Stakeholders Selected',
                    text: 'Please select one or more verified stakeholders from the directory below.'
                });
                return;
            }

            const r = await appPostForm(APP_URL + '/modules/stakeholders/ajax_registration_create.php', form);
            if (r.success) {
                appToast('success', r.message);
                setTimeout(() => location.reload(), 450);
            } else if (!r.session_expired) {
                Swal.fire('Registration Error', r.message, 'error');
            }
        };
    }

    // Status change buttons (Approve, Reject with reason modal, Cancel)
    document.querySelectorAll('.btn-reg-status').forEach(btn => {
        btn.onclick = async function() {
            let reason = '';
            if (this.dataset.status === 'Rejected') {
                const p = await Swal.fire({
                    title: 'Rejection reason',
                    input: 'textarea',
                    placeholder: 'Enter reason for rejecting this registration...',
                    showCancelButton: true,
                    inputValidator: v => !v ? 'Reason is required' : undefined
                });
                if (!p.isConfirmed) return;
                reason = p.value;
            }

            const fd = new FormData();
            fd.append('csrf_token', document.querySelector('[name=csrf_token]')?.value || '');
            fd.append('id', this.dataset.id);
            fd.append('status', this.dataset.status);
            fd.append('rejection_reason', reason);

            const r = await fetch(APP_URL + '/modules/stakeholders/ajax_registration_status.php', {
                method: 'POST',
                body: fd
            }).then(x => x.json());

            if (r.success) {
                if (window.lphSaveActivity) window.lphSaveActivity(this);
                location.reload();
            } else {
                Swal.fire('Update Failed', r.message, 'error');
            }
        };
    });

    // Real-time client-side search across all hearing tables
    const searchInput = document.getElementById('stakeholderSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const q = this.value.toLowerCase().trim();
            document.querySelectorAll('.hearing-table-container').forEach(container => {
                let visibleCount = 0;
                container.querySelectorAll('tbody tr.reg-row').forEach(row => {
                    const text = row.textContent.toLowerCase();
                    if (!q || text.includes(q)) {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });

                const emptySearch = container.querySelector('.reg-empty-search');
                if (emptySearch) {
                    emptySearch.style.display = (visibleCount === 0 && q) ? '' : 'none';
                }

                const badge = container.querySelector('.hearing-count-badge');
                if (badge) {
                    if (q) {
                        badge.innerHTML = '<i class="bi bi-funnel me-1"></i> ' + visibleCount + ' match' + (visibleCount === 1 ? '' : 'es');
                    } else {
                        const orig = badge.dataset.originalCount;
                        badge.innerHTML = '<i class="bi bi-people-fill me-1"></i> ' + orig + ' ' + (orig === '1' ? 'Participant' : 'Participants');
                    }
                }
            });
        });
    }

    // Smooth scroll and visual flash for jump-to pills
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

    // ==========================================================
    // VIEW ASSIGNED HEARINGS MODAL HANDLER
    // ==========================================================
    function escapeHtml(text) {
        if (!text) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.toString().replace(/[&<>"']/g, m => map[m]);
    }

    async function loadAssignedHearings(btn) {
        if (!btn) return;
        const sid = btn.dataset.stakeholderId;
        const name = btn.dataset.name || 'Stakeholder';
        const initials = btn.dataset.initials || 'SH';
        const avatarStyle = btn.dataset.avatarStyle || '';
        const email = btn.dataset.email || '';
        const org = btn.dataset.org || '';

        const shModalEl = document.getElementById('stakeholderHearingsModal');
        const modalAvatar = document.getElementById('shModalAvatar');
        const modalName = document.getElementById('shModalName');
        const modalSubtitle = document.getElementById('shModalSubtitle');
        const modalBadgeCount = document.getElementById('shModalBadgeCount');
        const modalBody = document.getElementById('shModalBody');
        const modalFooterInfo = document.getElementById('shModalFooterInfo');

        if (modalAvatar) {
            modalAvatar.textContent = initials;
            modalAvatar.style.cssText = 'width: 46px; height: 46px; font-size: 1.05rem; flex-shrink: 0; ' + avatarStyle;
        }
        if (modalName) modalName.textContent = name;
        if (modalSubtitle) modalSubtitle.textContent = [org, email].filter(Boolean).join(' • ') || 'Viewing assigned hearings';
        if (modalBadgeCount) modalBadgeCount.textContent = 'Loading...';
        if (modalFooterInfo) modalFooterInfo.textContent = '';
        if (modalBody) {
            modalBody.innerHTML = `
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                    <div class="small text-muted mt-2">Loading assigned hearings for ${escapeHtml(name)}...</div>
                </div>
            `;
        }

        // Ensure modal is visible
        if (shModalEl) {
            try {
                if (window.bootstrap && bootstrap.Modal) {
                    const inst = bootstrap.Modal.getOrCreateInstance(shModalEl);
                    inst.show();
                } else if (window.jQuery && typeof window.jQuery(shModalEl).modal === 'function') {
                    window.jQuery(shModalEl).modal('show');
                } else {
                    shModalEl.classList.add('show');
                    shModalEl.style.display = 'block';
                    document.body.classList.add('modal-open');
                }
            } catch (err) {
                console.warn('Modal open notice:', err);
            }
        }

        try {
            const fetchUrl = 'ajax_stakeholder_hearings.php?stakeholder_id=' + encodeURIComponent(sid);
            const resp = await fetch(fetchUrl);
            const data = await resp.json();

            if (!data.success) {
                if (modalBody) {
                    modalBody.innerHTML = `
                        <div class="alert alert-danger py-3 px-4 mb-0">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i> ${escapeHtml(data.message || 'Error loading hearings.')}
                        </div>
                    `;
                }
                return;
            }

            const stk = data.stakeholder || {};
            const hearings = data.hearings || [];

            if (modalSubtitle) {
                const subParts = [];
                if (stk.organization) subParts.push(stk.organization);
                if (stk.category_name) subParts.push(stk.category_name);
                if (stk.email) subParts.push(stk.email);
                modalSubtitle.textContent = subParts.join(' • ') || 'Verified Stakeholder';
            }

            if (modalBadgeCount) {
                modalBadgeCount.textContent = `${hearings.length} ${hearings.length === 1 ? 'Hearing' : 'Hearings'} Assigned`;
            }

            if (modalFooterInfo) {
                modalFooterInfo.innerHTML = stk.code_value 
                    ? `<i class="bi bi-qr-code me-1 text-primary"></i>QR Credential: <strong class="font-monospace text-dark">${escapeHtml(stk.code_value)}</strong>`
                    : '';
            }

            if (hearings.length === 0) {
                modalBody.innerHTML = `
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-calendar-x fs-1 text-secondary d-block mb-2"></i>
                        <h6 class="fw-bold text-dark mb-1">No Hearings Assigned</h6>
                        <p class="small text-muted mb-0">This stakeholder has not been assigned to any public hearings yet.</p>
                    </div>
                `;
                return;
            }

            let cardsHtml = `
                <div class="small fw-bold text-uppercase text-secondary mb-3 tracking-wide d-flex align-items-center justify-content-between">
                    <span><i class="bi bi-calendar2-check-fill text-primary me-1"></i> Assigned Hearing Sessions (${hearings.length})</span>
                    <span class="badge bg-light text-dark border">Stakeholder ID #${stk.id}</span>
                </div>
                <div class="d-flex flex-column gap-3">
            `;

            hearings.forEach(h => {
                const statusBadge = h.hearing_status === 'Upcoming' ? 'success' : (h.hearing_status === 'Ongoing' ? 'warning' : 'secondary');
                const regStatusBadge = h.registration_status === 'Approved' ? 'success' : (h.registration_status === 'Pending' ? 'warning' : 'secondary');
                const formattedDate = h.effective_date ? new Date(h.effective_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'Date TBA';
                const formattedTime = h.hearing_time ? h.hearing_time.substring(0, 5) : '';
                const hearingViewUrl = (typeof APP_URL !== 'undefined' && APP_URL ? APP_URL : '') + '/modules/hearings/view.php?id=' + encodeURIComponent(h.hearing_id);

                cardsHtml += `
                    <div class="card border rounded-3 shadow-sm overflow-hidden">
                        <div class="card-header bg-light py-2 px-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-primary-subtle text-primary border font-monospace fw-bold">${escapeHtml(h.reference_number || 'PH-REF')}</span>
                                <span class="badge text-bg-${statusBadge}">${escapeHtml(h.hearing_status || 'Upcoming')}</span>
                                ${h.day_number ? `<span class="badge bg-white text-dark border"><i class="bi bi-calendar-day me-1"></i>Day ${h.day_number}</span>` : ''}
                            </div>
                            <span class="badge bg-white text-dark border font-monospace small" title="Registration Code">
                                <i class="bi bi-hash text-muted"></i>${escapeHtml(h.registration_code || '—')}
                            </span>
                        </div>
                        <div class="card-body p-3">
                            <h6 class="fw-bold text-dark mb-1">${escapeHtml(h.hearing_title || 'Public Hearing')}</h6>
                            ${h.committee_name ? `<div class="small text-muted mb-2"><i class="bi bi-diagram-3 me-1 text-primary"></i>${escapeHtml(h.committee_name)}</div>` : ''}
                            
                            <div class="row g-2 small text-secondary bg-light p-2.5 rounded-2 mt-1">
                                <div class="col-sm-6">
                                    <i class="bi bi-calendar3 me-1 text-primary"></i><strong>Date:</strong> ${formattedDate}
                                </div>
                                <div class="col-sm-6">
                                    <i class="bi bi-clock me-1 text-primary"></i><strong>Time:</strong> ${formattedTime || 'Schedule TBA'}
                                </div>
                                <div class="col-sm-6">
                                    <i class="bi bi-geo-alt me-1 text-primary"></i><strong>Venue:</strong> ${escapeHtml(h.venue || 'Session Hall, Manila City Hall')}
                                </div>
                                <div class="col-sm-6">
                                    <i class="bi bi-person-badge me-1 text-primary"></i><strong>Attendance:</strong> ${escapeHtml(h.attendance_type || 'On-site')}
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-white py-2 px-3 border-top d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <span class="small text-muted">
                                <i class="bi bi-shield-check text-${regStatusBadge} me-1"></i>Registration: <strong class="text-dark">${escapeHtml(h.registration_status || 'Approved')}</strong>
                            </span>
                            <a href="${hearingViewUrl}" target="_blank" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1">
                                <span>View Hearing</span>
                                <i class="bi bi-box-arrow-up-right small"></i>
                            </a>
                        </div>
                    </div>
                `;
            });

            cardsHtml += `</div>`;
            modalBody.innerHTML = cardsHtml;

        } catch (err) {
            if (modalBody) {
                modalBody.innerHTML = `
                    <div class="alert alert-danger py-3 px-4 mb-0">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i> Failed to fetch assigned hearings: ${escapeHtml(err.message)}
                    </div>
                `;
            }
        }
    }

    // Direct event delegation without blocking default bootstrap modal action
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-view-assigned-hearings');
        if (!btn) return;
        loadAssignedHearings(btn);
    });

    // Modal dismiss fallback handler
    document.querySelectorAll('#stakeholderHearingsModal [data-bs-dismiss="modal"]').forEach(b => {
        b.addEventListener('click', function() {
            const m = document.getElementById('stakeholderHearingsModal');
            if (m) {
                m.classList.remove('show');
                m.style.display = 'none';
                document.body.classList.remove('modal-open');
                const backdrop = document.querySelector('.modal-backdrop');
                if (backdrop) backdrop.remove();
            }
        });
    });
});
</script>

<?php include __DIR__ . '/../../layouts/footer.php'; ?>
