<?php
declare(strict_types=1);

/**
 * modules/stakeholders/portal.php
 * ------------------------------------------------------------------
 * Public Stakeholder Portal for Legislative Public Hearings & Consultations.
 * City Government of Manila · Sangguniang Panlungsod.
 *
 * Allows stakeholders to:
 * 1. Browse all upcoming & ongoing public hearings.
 * 2. Self-register / RSVP for hearings (choose role: Resource Person, Delegate, Observer).
 * 3. Lookup their assigned hearings, official invitations, and digital attendance QR badges.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';

$pdo = db();
lphEnsureMultiDayAttendanceSchema($pdo);

$pageTitle = 'Stakeholder Portal · Legislative Public Hearings';

// Fetch active upcoming and ongoing hearings
$hearingsStmt = $pdo->query("
    SELECT h.id, h.reference_number, h.title, h.description, h.venue,
           h.hearing_date, h.hearing_time, h.status, h.committee_id,
           c.name AS committee_name, ht.name AS hearing_type,
           (SELECT COUNT(*) FROM registrations r WHERE r.hearing_id = h.id AND r.registration_status IN ('Pending','Approved')) AS attendee_count
    FROM hearings h
    LEFT JOIN committees c ON c.id = h.committee_id
    LEFT JOIN hearing_types ht ON ht.id = h.hearing_type_id
    WHERE h.status IN ('Upcoming', 'Ongoing')
    ORDER BY h.hearing_date ASC, h.hearing_time ASC
");
$hearings = $hearingsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch hearing session days for multi-day hearings
$hearingSessionDays = [];
$sdStmt = $pdo->query("
    SELECT id, hearing_id, day_number, session_date, start_time, end_time, title, venue
    FROM hearing_session_days
    ORDER BY day_number ASC, session_date ASC
");
while ($sd = $sdStmt->fetch(PDO::FETCH_ASSOC)) {
    $hearingSessionDays[(int)$sd['hearing_id']][] = $sd;
}

// Fetch committees for filter
$committees = $pdo->query("SELECT id, name FROM committees WHERE status = 'Active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch stakeholder categories
$categories = $pdo->query("SELECT id, name FROM stakeholder_categories ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$manilaLogoUrl = 'https://raw.githubusercontent.com/junarlove05/public_hearing/main/assets/images/manila.png';
$liveAppUrl = (defined('APP_URL') && APP_URL && !str_contains(APP_URL, 'localhost'))
    ? rtrim(APP_URL, '/')
    : 'https://public-hearing-integrated-legislative-system.hostforgeplatforms.com';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> · City of Manila</title>
    <link rel="icon" type="image/png" href="<?= e($manilaLogoUrl) ?>">
    
    <!-- Bootstrap 5 CSS & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Google Fonts: Inter & Outfit -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        :root {
            --manila-navy: #0a2540;
            --manila-navy-dark: #06182a;
            --manila-gold: #c59b27;
            --manila-gold-light: #e5b842;
            --manila-gold-subtle: #fef9e7;
            --surface-bg: #f8fafc;
            --card-border: #e2e8f0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--surface-bg);
            color: #1e293b;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Top Government Header */
        .portal-top-bar {
            background-color: var(--manila-navy-dark);
            color: #94a3b8;
            font-size: 0.78rem;
            padding: 6px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        /* Main Hero Header */
        .portal-hero {
            background: linear-gradient(135deg, var(--manila-navy) 0%, #11385f 100%);
            color: #ffffff;
            padding: 38px 0 32px 0;
            border-bottom: 4px solid var(--manila-gold);
            position: relative;
            box-shadow: 0 4px 20px rgba(10, 37, 64, 0.15);
        }

        .portal-title {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            letter-spacing: -0.5px;
            color: #ffffff;
            margin-bottom: 6px;
        }

        .portal-tagline {
            color: #cbd5e1;
            font-size: 0.95rem;
            max-width: 650px;
        }

        /* Navigation Tabs */
        .portal-nav-pills {
            background: #ffffff;
            padding: 6px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--card-border);
            display: inline-flex;
            gap: 4px;
        }

        .portal-nav-pills .nav-link {
            color: #475569;
            font-weight: 600;
            font-size: 0.88rem;
            padding: 10px 22px;
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .portal-nav-pills .nav-link:hover {
            color: var(--manila-navy);
            background-color: #f1f5f9;
        }

        .portal-nav-pills .nav-link.active {
            background: var(--manila-navy);
            color: #ffffff;
            box-shadow: 0 2px 8px rgba(10, 37, 64, 0.25);
        }

        /* Hearing Cards */
        .hearing-card {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: 14px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .hearing-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
            border-color: #cbd5e1;
        }

        .hearing-card-header {
            padding: 16px 20px;
            border-bottom: 1px solid #f1f5f9;
            background: #fafcff;
        }

        .hearing-card-body {
            padding: 20px;
            flex-grow: 1;
        }

        .hearing-card-footer {
            padding: 14px 20px;
            background: #ffffff;
            border-top: 1px solid #f1f5f9;
        }

        .hearing-title-link {
            color: var(--manila-navy);
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            font-size: 1.15rem;
            line-height: 1.35;
            text-decoration: none;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .hearing-title-link:hover {
            color: #1d4ed8;
        }

        .info-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.8rem;
            color: #475569;
        }

        .btn-rsvp {
            background: linear-gradient(135deg, var(--manila-gold) 0%, #b88a1b 100%);
            color: #ffffff;
            font-weight: 700;
            border: none;
            box-shadow: 0 2px 6px rgba(197, 155, 39, 0.3);
            transition: all 0.2s ease;
        }

        .btn-rsvp:hover {
            background: linear-gradient(135deg, #d4a72d 0%, var(--manila-gold) 100%);
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(197, 155, 39, 0.4);
        }

        /* Pass & QR Styles */
        .qr-badge-card {
            background: #ffffff;
            border: 2px dashed var(--manila-gold);
            border-radius: 12px;
            padding: 18px;
            text-align: center;
        }

        /* Footer */
        .portal-footer {
            margin-top: auto;
            background: #0f172a;
            color: #94a3b8;
            font-size: 0.82rem;
            padding: 32px 0 24px 0;
            border-top: 3px solid var(--manila-gold);
        }
    </style>
</head>
<body>

    <!-- Top Gov Bar -->
    <div class="portal-top-bar">
        <div class="container d-flex justify-content-between align-items-center">
            <div>
                <i class="bi bi-shield-check me-1 text-warning"></i>
                Official Portal of the City of Manila · Sangguniang Panlungsod
            </div>
            <div>
                <a href="<?= e(APP_URL . '/login.php') ?>" class="text-white text-decoration-none small">
                    <i class="bi bi-person-lock me-1"></i> Secretariat Staff Login
                </a>
            </div>
        </div>
    </div>

    <!-- Hero Header -->
    <header class="portal-hero">
        <div class="container">
            <div class="d-flex align-items-center gap-3">
                <img src="<?= e($manilaLogoUrl) ?>" alt="City of Manila Seal" width="75" height="75" class="d-none d-sm-block flex-shrink-0">
                <div>
                    <div class="text-uppercase fw-bold" style="color: var(--manila-gold-light); font-size: 0.78rem; letter-spacing: 1.5px;">
                        Office of the City Council &amp; Committee Secretariat
                    </div>
                    <h1 class="portal-title fs-2 fs-md-1">Legislative Public Hearings &amp; Consultations</h1>
                    <p class="portal-tagline mb-0">
                        Stakeholder &amp; Citizen Self-Service Portal: Browse legislative public hearing schedules, submit your intent to attend (RSVP), and access your official digital attendance QR passes.
                    </p>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content Container -->
    <main class="container my-4 my-md-5">

        <!-- Navigation Tabs -->
        <div class="d-flex justify-content-center mb-4">
            <ul class="nav portal-nav-pills" id="portalTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="hearings-tab" data-bs-toggle="tab" data-bs-target="#tab-hearings" type="button" role="tab">
                        <i class="bi bi-calendar-event-fill me-1.5 text-warning"></i> Public Hearings Schedule
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="my-hearings-tab" data-bs-toggle="tab" data-bs-target="#tab-my-hearings" type="button" role="tab">
                        <i class="bi bi-qr-code-scan me-1.5 text-info"></i> My Hearings &amp; QR Pass
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="register-tab" data-bs-toggle="tab" data-bs-target="#tab-register" type="button" role="tab">
                        <i class="bi bi-person-vcard-fill me-1.5 text-success"></i> Stakeholder Accreditation
                    </button>
                </li>
            </ul>
        </div>

        <div class="tab-content" id="portalTabContent">

            <!-- ============================================================== -->
            <!-- TAB 1: HEARINGS CATALOG & RSVP                                  -->
            <!-- ============================================================== -->
            <div class="tab-pane fade show active" id="tab-hearings" role="tabpanel">

                <!-- Search & Filter Controls -->
                <div class="card border-0 shadow-sm rounded-3 mb-4">
                    <div class="card-body p-3 p-md-4">
                        <div class="row g-2 align-items-center">
                            <div class="col-lg-6 col-md-5">
                                <div class="input-group">
                                    <span class="input-group-text bg-white text-muted border-end-0"><i class="bi bi-search"></i></span>
                                    <input type="text" id="hearingSearchInput" class="form-control border-start-0" placeholder="Search by hearing title, measure, agenda, or venue...">
                                </div>
                            </div>
                            <div class="col-lg-3 col-md-4">
                                <select id="committeeFilterSelect" class="form-select">
                                    <option value="">All Legislative Committees</option>
                                    <?php foreach ($committees as $c): ?>
                                        <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-lg-3 col-md-3">
                                <select id="statusFilterSelect" class="form-select">
                                    <option value="all">All Statuses</option>
                                    <option value="Upcoming" selected>Upcoming Hearings</option>
                                    <option value="Ongoing">Ongoing Sessions</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Hearings Grid -->
                <?php if (empty($hearings)): ?>
                    <div class="text-center py-5 bg-white rounded-3 border">
                        <i class="bi bi-calendar-x text-muted" style="font-size: 3rem;"></i>
                        <h5 class="fw-bold mt-3 mb-1">No Upcoming Hearings Scheduled</h5>
                        <p class="text-muted small mb-0">The Secretariat will publish newly scheduled legislative hearings and consultations soon.</p>
                    </div>
                <?php else: ?>
                    <div class="row g-4" id="hearingsGrid">
                        <?php foreach ($hearings as $h): 
                            $hId = (int)$h['id'];
                            $title = $h['title'];
                            $ref = $h['reference_number'] ?: ('HRG-' . date('Y') . '-' . str_pad((string)$hId, 4, '0', STR_PAD_LEFT));
                            $dateStr = !empty($h['hearing_date']) ? date('l, F j, Y', strtotime($h['hearing_date'])) : 'Date to be Announced';
                            $timeStr = !empty($h['hearing_time']) ? date('g:i A', strtotime($h['hearing_time'])) : 'Scheduled Time';
                            $venue = $h['venue'] ?: 'Session Hall, 2nd Floor, Manila City Hall';
                            $committeeName = $h['committee_name'] ?: 'City Council Committee';
                            $sessions = $hearingSessionDays[$hId] ?? [];
                            $isMultiDay = count($sessions) > 1;
                        ?>
                            <div class="col-lg-6 hearing-grid-item" 
                                 data-title="<?= e(strtolower($title)) ?>" 
                                 data-ref="<?= e(strtolower($ref)) ?>" 
                                 data-committee="<?= (int)$h['committee_id'] ?>" 
                                 data-status="<?= e($h['status']) ?>">
                                
                                <div class="hearing-card">
                                    <div class="hearing-card-header d-flex justify-content-between align-items-center">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge bg-dark-subtle text-dark border font-monospace px-2.5 py-1.5">
                                                <i class="bi bi-hash me-0.5"></i><?= e($ref) ?>
                                            </span>
                                            <?php if ($h['status'] === 'Ongoing'): ?>
                                                <span class="badge bg-danger animate-pulse px-2 py-1"><i class="bi bi-broadcast me-1"></i>Ongoing Session</span>
                                            <?php else: ?>
                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">Upcoming</span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="badge bg-light text-secondary border">
                                            <i class="bi bi-people me-1"></i><?= (int)$h['attendee_count'] ?> Registered
                                        </span>
                                    </div>

                                    <div class="hearing-card-body">
                                        <div class="text-uppercase small fw-bold mb-1" style="color: var(--manila-gold); font-size: 0.75rem;">
                                            <i class="bi bi-diagram-3-fill me-1"></i><?= e($committeeName) ?>
                                        </div>
                                        <h5 class="mb-2">
                                            <a href="javascript:void(0)" onclick="viewHearingModal(<?= $hId ?>)" class="hearing-title-link">
                                                <?= e($title) ?>
                                            </a>
                                        </h5>

                                        <p class="text-muted small mb-3 text-truncate-2">
                                            <?= e($h['description'] ?: 'Official Legislative Public Hearing and consultation meeting convened by the Sangguniang Panlungsod.') ?>
                                        </p>

                                        <!-- Hearing Session Details -->
                                        <div class="d-flex flex-column gap-2 mb-3">
                                            <div class="info-pill">
                                                <i class="bi bi-calendar3 text-primary"></i>
                                                <span><strong><?= e($dateStr) ?></strong> · <?= e($timeStr) ?></span>
                                            </div>
                                            <div class="info-pill text-truncate">
                                                <i class="bi bi-geo-alt text-danger"></i>
                                                <span class="text-truncate"><?= e($venue) ?></span>
                                            </div>
                                            <?php if ($isMultiDay): ?>
                                                <div class="info-pill bg-warning-subtle text-dark border-warning">
                                                    <i class="bi bi-layers-fill text-warning"></i>
                                                    <span>Multi-Day Session (<?= count($sessions) ?> Session Days Scheduled)</span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="hearing-card-footer d-flex gap-2">
                                        <button type="button" class="btn btn-rsvp flex-grow-1 py-2" onclick="openRsvpModal(<?= $hId ?>, '<?= e(addslashes($title)) ?>', '<?= e($dateStr) ?>', '<?= e($timeStr) ?>', '<?= e(addslashes($venue)) ?>')">
                                            <i class="bi bi-person-check-fill me-1"></i> Mag-register / Attend (RSVP)
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary px-3" onclick="viewHearingModal(<?= $hId ?>)" title="View Details">
                                            <i class="bi bi-info-circle"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </div>

            <!-- ============================================================== -->
            <!-- TAB 2: MY HEARINGS & QR PASS LOOKUP                            -->
            <!-- ============================================================== -->
            <div class="tab-pane fade" id="tab-my-hearings" role="tabpanel">
                <div class="row justify-content-center">
                    <div class="col-lg-8">
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-body p-4 p-md-5">
                                <div class="text-center mb-4">
                                    <div class="d-inline-flex align-items-center justify-content-center bg-primary-subtle text-primary rounded-circle mb-3" style="width: 64px; height: 64px;">
                                        <i class="bi bi-search fs-2"></i>
                                    </div>
                                    <h4 class="fw-bold mb-1 text-dark">Lookup My Hearings &amp; Digital Passes</h4>
                                    <p class="text-muted small">Enter your registered email address or invitation reference code to instantly access your official invitations, attendance QR badges, and executive certificates.</p>
                                </div>

                                <form id="lookupForm" onsubmit="handleLookup(event)">
                                    <div class="input-group input-group-lg shadow-sm mb-3">
                                        <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-envelope-at"></i></span>
                                        <input type="text" id="lookupInput" class="form-control border-start-0" placeholder="e.g. lumagbasemariel@gmail.com or INV-2026-..." required>
                                        <button class="btn btn-primary px-4 fw-bold" type="submit" id="btnLookup">
                                            <i class="bi bi-arrow-right-circle me-1"></i> Search
                                        </button>
                                    </div>
                                    <div class="text-center">
                                        <small class="text-muted">
                                            <i class="bi bi-shield-lock me-1"></i> Access is secured and displays only your official notices and attendance passes.
                                        </small>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Results Container -->
                        <div id="lookupResults" style="display: none;">
                            <!-- Populated via JavaScript -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================================== -->
            <!-- TAB 3: STAKEHOLDER ACCREDITATION                               -->
            <!-- ============================================================== -->
            <div class="tab-pane fade" id="tab-register" role="tabpanel">
                <div class="row justify-content-center">
                    <div class="col-lg-7">
                        <div class="card border-0 shadow-sm rounded-4">
                            <div class="card-body p-4 p-md-5 text-center">
                                <div class="d-inline-flex align-items-center justify-content-center bg-warning-subtle text-warning-emphasis rounded-circle mb-3" style="width: 70px; height: 70px;">
                                    <i class="bi bi-award fs-1"></i>
                                </div>
                                <h4 class="fw-bold mb-2">Stakeholder Accreditation &amp; Directory Registration</h4>
                                <p class="text-muted mb-4">
                                    Represent an organization, association, government agency, professional group, or civic sector in the City of Manila? Register your official profile with the Committee Secretariat to automatically receive invitations to relevant public hearings.
                                </p>
                                <a href="<?= e(APP_URL . '/modules/stakeholders/register.php') ?>" class="btn btn-primary btn-lg px-5 py-2.5 fw-bold shadow-sm">
                                    <i class="bi bi-pencil-square me-2"></i> Open Accreditation Registration Form
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </main>

    <!-- ================================================================== -->
    <!-- MODAL: RSVP / SELF-REGISTRATION FOR HEARING                       -->
    <!-- ================================================================== -->
    <div class="modal fade" id="rsvpModal" tabindex="-1" aria-labelledby="rsvpModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                <div class="modal-header bg-dark text-white p-3 p-md-4" style="background: linear-gradient(135deg, var(--manila-navy) 0%, #153c65 100%) !important;">
                    <div class="d-flex align-items-center gap-2.5">
                        <div class="bg-warning text-dark rounded-circle d-flex align-items-center justify-content-center" style="width: 42px; height: 42px;">
                            <i class="bi bi-calendar-check-fill fs-5"></i>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold mb-0 text-white" id="rsvpModalLabel">Register for Legislative Hearing</h5>
                            <small class="text-warning-emphasis" style="color: #fef08a !important;">City Council Committee Secretariat · Official RSVP</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <form id="rsvpForm" onsubmit="submitRsvp(event)">
                    <input type="hidden" name="hearing_id" id="rsvp_hearing_id">
                    <input type="hidden" name="session_day_id" id="rsvp_session_day_id" value="0">
                    <input type="hidden" name="session_date" id="rsvp_session_date" value="">

                    <div class="modal-body p-3 p-md-4">
                        <!-- Hearing Banner Info -->
                        <div class="alert alert-light border p-3 rounded-3 mb-3 bg-light">
                            <h6 class="fw-bold text-dark mb-1" id="rsvp_hearing_title">Hearing Title</h6>
                            <div class="small text-muted d-flex flex-wrap gap-3 mt-1">
                                <span><i class="bi bi-calendar-event me-1 text-primary"></i><span id="rsvp_hearing_date">Date</span></span>
                                <span><i class="bi bi-clock me-1 text-primary"></i><span id="rsvp_hearing_time">Time</span></span>
                                <span><i class="bi bi-geo-alt me-1 text-danger"></i><span id="rsvp_hearing_venue">Venue</span></span>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-uppercase text-secondary">Full Name <span class="text-danger">*</span></label>
                                <input type="text" name="full_name" class="form-control" placeholder="e.g. Juan Dela Cruz" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-uppercase text-secondary">Email Address <span class="text-danger">*</span></label>
                                <input type="email" name="email" class="form-control" placeholder="e.g. juan.delacruz@gmail.com" required>
                                <div class="form-text small">Your digital attendance QR badge will be linked to this email.</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-uppercase text-secondary">Mobile / Contact Number</label>
                                <input type="text" name="phone" class="form-control" placeholder="e.g. 0917-123-4567">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-uppercase text-secondary">Organization / Company / Affiliation</label>
                                <input type="text" name="organization" class="form-control" placeholder="e.g. Tondo Vendors Association / DOH">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-uppercase text-secondary">Stakeholder Sector</label>
                                <select name="sector" class="form-select">
                                    <option value="Civic Organization">Civic Organization / NGO</option>
                                    <option value="Business & Commerce">Business &amp; Commerce</option>
                                    <option value="Government Agency">National / Local Government Agency</option>
                                    <option value="Academe & Research">Academe &amp; Education</option>
                                    <option value="Youth Sector">Youth &amp; Student Sector</option>
                                    <option value="Senior Citizens & PWD">Senior Citizens &amp; PWD</option>
                                    <option value="Community Resident">Community Resident / Citizen</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-uppercase text-secondary">Role / Attendance Type <span class="text-danger">*</span></label>
                                <select name="attendance_type" class="form-select" required>
                                    <option value="Official Delegate" selected>Official Organization Delegate</option>
                                    <option value="Resource Person">Resource Person / Technical Expert</option>
                                    <option value="Public Observer">Public Observer / Citizen Participant</option>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-bold small text-uppercase text-secondary">Statement / Position Summary (Optional)</label>
                                <textarea name="position_summary" class="form-control" rows="2" placeholder="Brief manifestation or key insights your organization wishes to share during the consultation..."></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer bg-light p-3">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" id="btnSubmitRsvp" class="btn btn-rsvp px-4 py-2">
                            <i class="bi bi-send-check me-1"></i> Submit Registration &amp; RSVP
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="portal-footer">
        <div class="container text-center">
            <div class="d-flex align-items-center justify-content-center gap-2 mb-2">
                <img src="<?= e($manilaLogoUrl) ?>" width="32" height="32" alt="City Seal">
                <strong class="text-white">Sangguniang Panlungsod · City Council of Manila</strong>
            </div>
            <p class="mb-1 text-muted small">Legislative Public Hearing &amp; Consultation Management System (LPH-CMS)</p>
            <p class="mb-0 text-muted" style="font-size: 0.75rem;">Manila City Hall, Padre Burgos Ave., Ermita, Manila · Republic of the Philippines</p>
        </div>
    </footer>

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        const APP_URL = <?= json_encode(APP_URL) ?>;

        // Search & Filter
        const searchInput = document.getElementById('hearingSearchInput');
        const commFilter = document.getElementById('committeeFilterSelect');
        const statusFilter = document.getElementById('statusFilterSelect');
        const hearingItems = document.querySelectorAll('.hearing-grid-item');

        function filterHearings() {
            const query = (searchInput.value || '').trim().toLowerCase();
            const commVal = commFilter.value;
            const statusVal = statusFilter.value;

            hearingItems.forEach(item => {
                const title = item.dataset.title || '';
                const ref = item.dataset.ref || '';
                const comm = item.dataset.committee || '';
                const status = item.dataset.status || '';

                const matchesQuery = !query || title.includes(query) || ref.includes(query);
                const matchesComm = !commVal || comm === commVal;
                const matchesStatus = (statusVal === 'all') || (status === statusVal);

                if (matchesQuery && matchesComm && matchesStatus) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        if (searchInput) searchInput.addEventListener('input', filterHearings);
        if (commFilter) commFilter.addEventListener('change', filterHearings);
        if (statusFilter) statusFilter.addEventListener('change', filterHearings);

        // Open RSVP Modal
        function openRsvpModal(hearingId, title, date, time, venue) {
            document.getElementById('rsvp_hearing_id').value = hearingId;
            document.getElementById('rsvp_hearing_title').textContent = title;
            document.getElementById('rsvp_hearing_date').textContent = date;
            document.getElementById('rsvp_hearing_time').textContent = time;
            document.getElementById('rsvp_hearing_venue').textContent = venue;

            const modal = new bootstrap.Modal(document.getElementById('rsvpModal'));
            modal.show();
        }

        // Submit RSVP
        async function submitRsvp(e) {
            e.preventDefault();
            const form = document.getElementById('rsvpForm');
            const btn = document.getElementById('btnSubmitRsvp');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Recording RSVP...';

            const fd = new FormData(form);

            try {
                const resp = await fetch(APP_URL + '/modules/stakeholders/ajax_portal_rsvp.php', {
                    method: 'POST',
                    body: fd
                });
                const res = await resp.json();

                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-send-check me-1"></i> Submit Registration &amp; RSVP';

                if (res.success) {
                    bootstrap.Modal.getInstance(document.getElementById('rsvpModal')).hide();
                    form.reset();

                    const qrUrl = res.qr_code 
                        ? `https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=${encodeURIComponent(res.qr_code)}&margin=4`
                        : '';

                    Swal.fire({
                        icon: 'success',
                        title: 'RSVP Submitted Successfully!',
                        html: `
                            <div class="text-center">
                                <p class="mb-2">Your registration for <strong>${res.hearing_title}</strong> has been logged with the Secretariat.</p>
                                <div class="badge bg-primary fs-6 font-monospace mb-3">Ref: ${res.registration_code}</div>
                                ${qrUrl ? `
                                    <div class="p-3 bg-light rounded-3 border d-inline-block mb-2">
                                        <img src="${qrUrl}" width="160" height="160" alt="Attendance QR Pass">
                                        <div class="small text-muted mt-1 font-monospace">${res.qr_code}</div>
                                    </div>
                                ` : ''}
                                <p class="small text-muted mt-2">You can look up your registration and QR pass anytime under the <strong>My Hearings &amp; QR Pass</strong> tab.</p>
                            </div>
                        `,
                        confirmButtonText: 'Done',
                        confirmButtonColor: '#0a2540'
                    });
                } else {
                    Swal.fire('Registration Error', res.message || 'Could not record RSVP.', 'error');
                }
            } catch (err) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-send-check me-1"></i> Submit Registration &amp; RSVP';
                Swal.fire('Connection Error', err.message || 'Failed to connect to server.', 'error');
            }
        }

        // Handle My Hearings Lookup
        async function handleLookup(e) {
            e.preventDefault();
            const query = (document.getElementById('lookupInput').value || '').trim();
            const btn = document.getElementById('btnLookup');
            const resultsDiv = document.getElementById('lookupResults');

            if (!query) return;

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Searching...';

            try {
                const resp = await fetch(APP_URL + `/modules/stakeholders/ajax_portal_lookup.php?query=${encodeURIComponent(query)}`);
                const res = await resp.json();

                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-arrow-right-circle me-1"></i> Search';

                if (!res.success) {
                    resultsDiv.style.display = 'block';
                    resultsDiv.innerHTML = `
                        <div class="alert alert-warning border-warning p-4 rounded-4 shadow-sm text-center">
                            <i class="bi bi-exclamation-circle fs-2 d-block mb-2 text-warning"></i>
                            <h5 class="fw-bold mb-1">No Stakeholder Record Found</h5>
                            <p class="small text-muted mb-0">${res.message}</p>
                        </div>
                    `;
                    return;
                }

                const stk = res.stakeholder;
                const invs = res.invitations || [];
                const regs = res.registrations || [];

                let html = `
                    <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
                        <div class="card-header bg-dark text-white p-3 d-flex justify-content-between align-items-center" style="background: var(--manila-navy) !important;">
                            <div>
                                <h5 class="fw-bold mb-0 text-white">${stk.full_name}</h5>
                                <small class="text-warning" style="color: #fef08a !important;">${stk.organization || 'Recognized Stakeholder'} · ${stk.email}</small>
                            </div>
                            <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1.5">${stk.status}</span>
                        </div>
                        <div class="card-body p-4">
                            <h6 class="fw-bold text-uppercase text-secondary small mb-3">
                                <i class="bi bi-envelope-paper-fill me-1 text-primary"></i> Official Secretariat Invitations (${invs.length})
                            </h6>
                `;

                if (invs.length === 0) {
                    html += `<p class="text-muted small mb-4 fst-italic">No official direct invitations issued yet.</p>`;
                } else {
                    html += `<div class="d-flex flex-column gap-3 mb-4">`;
                    invs.forEach(inv => {
                        html += `
                            <div class="border rounded-3 p-3 bg-light">
                                <div class="row align-items-center">
                                    <div class="col-md-8">
                                        <div class="badge bg-secondary-subtle text-dark border font-monospace mb-1">${inv.invitation_code}</div>
                                        <h6 class="fw-bold text-dark mb-1">${inv.hearing_title}</h6>
                                        <div class="small text-muted d-flex flex-wrap gap-2 mb-2">
                                            <span><i class="bi bi-calendar-event me-1"></i>${inv.effective_date}</span>
                                            <span><i class="bi bi-clock me-1"></i>${inv.hearing_time || 'Scheduled Time'}</span>
                                            <span><i class="bi bi-geo-alt me-1"></i>${inv.venue}</span>
                                        </div>
                                        <a href="${inv.certificate_url}" target="_blank" class="btn btn-sm btn-outline-dark fw-semibold">
                                            <i class="bi bi-file-earmark-pdf me-1 text-danger"></i> View &amp; Print Certificate
                                        </a>
                                    </div>
                                    <div class="col-md-4 text-center mt-3 mt-md-0">
                                        <div class="qr-badge-card d-inline-block">
                                            <img src="${inv.qr_url}" width="110" height="110" alt="Attendance Pass">
                                            <div class="small font-monospace fw-bold text-dark mt-1" style="font-size:0.75rem;">ATTENDANCE PASS</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    html += `</div>`;
                }

                html += `
                            <h6 class="fw-bold text-uppercase text-secondary small mb-3">
                                <i class="bi bi-card-checklist me-1 text-success"></i> My Self-Registered Hearings / RSVPs (${regs.length})
                            </h6>
                `;

                if (regs.length === 0) {
                    html += `<p class="text-muted small mb-0 fst-italic">No self-registration records found.</p>`;
                } else {
                    html += `<div class="d-flex flex-column gap-2">`;
                    regs.forEach(reg => {
                        const stBadge = reg.registration_status === 'Approved' 
                            ? '<span class="badge bg-success">Approved</span>' 
                            : '<span class="badge bg-warning text-dark">Pending Review</span>';
                        html += `
                            <div class="border rounded-3 p-3 bg-white d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="badge bg-light text-dark border font-monospace">${reg.registration_code}</span>
                                        ${stBadge}
                                        <span class="badge bg-light text-secondary border">${reg.attendance_type}</span>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-0">${reg.hearing_title}</h6>
                                    <small class="text-muted">${reg.effective_date} · ${reg.venue}</small>
                                </div>
                                <div class="text-end">
                                    <img src="${reg.qr_url}" width="65" height="65" class="rounded border p-1" alt="Pass">
                                </div>
                            </div>
                        `;
                    });
                    html += `</div>`;
                }

                html += `
                        </div>
                    </div>
                `;

                resultsDiv.style.display = 'block';
                resultsDiv.innerHTML = html;

            } catch (err) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-arrow-right-circle me-1"></i> Search';
                Swal.fire('Error', err.message || 'Lookup failed.', 'error');
            }
        }
    </script>
</body>
</html>
