<?php
/**
 * layouts/header.php
 * ------------------------------------------------------------------
 * Shared <head> + top navigation bar. Included at the top of every
 * protected page after requireLogin()/requireRole() has run.
 * Expects $pageTitle to optionally be set by the including page.
 * ------------------------------------------------------------------
 */

$pageTitle = $pageTitle ?? 'Dashboard';
$user = currentUser();

/**
 * Build a role-appropriate notification list for the bell icon.
 */
$notifItems = [];
try {
    $pdo = db();

    if (currentRole() === ROLE_STAKEHOLDER) {
        $stStmt = $pdo->prepare('SELECT id FROM stakeholders WHERE email = :email LIMIT 1');
        $stStmt->execute([':email' => $user['email']]);
        $stakeholderRow = $stStmt->fetch();

        if ($stakeholderRow) {
            $sid = $stakeholderRow['id'];

            $upcoming = $pdo->prepare(
                "SELECT DISTINCT h.id, h.title, h.hearing_date FROM hearings h
                 WHERE h.status IN ('Upcoming','Ongoing') AND h.hearing_date >= CURDATE()
                 AND (h.id IN (SELECT hearing_id FROM registrations WHERE stakeholder_id = :sid1)
                      OR h.id IN (SELECT hearing_id FROM invitations WHERE stakeholder_id = :sid2))
                 ORDER BY h.hearing_date LIMIT 5"
            );
            $upcoming->execute([':sid1' => $sid, ':sid2' => $sid]);
            foreach ($upcoming->fetchAll() as $h) {
                $notifItems[] = [
                    'text' => 'Upcoming: ' . $h['title'] . ' on ' . formatDate($h['hearing_date']),
                    'url' => APP_URL . '/modules/hearings/view.php?id=' . $h['id'],
                ];
            }

            $pending = $pdo->prepare(
                "SELECT h.id, h.title FROM registrations r
                 JOIN hearings h ON h.id = r.hearing_id
                 WHERE r.stakeholder_id = :sid AND h.status IN ('Upcoming','Ongoing')
                 AND h.id NOT IN (SELECT hearing_id FROM attendance WHERE stakeholder_id = :sid2)
                 LIMIT 5"
            );
            $pending->execute([':sid' => $sid, ':sid2' => $sid]);
            foreach ($pending->fetchAll() as $h) {
                $notifItems[] = [
                    'text' => 'Attendance pending for: ' . $h['title'],
                    'url' => APP_URL . '/modules/hearings/view.php?id=' . $h['id'],
                ];
            }

            $invStmt = $pdo->prepare("SELECT COUNT(*) FROM invitations WHERE stakeholder_id = :sid AND status = 'Pending'");
            $invStmt->execute([':sid' => $sid]);
            $pendingInvites = (int)$invStmt->fetchColumn();
            if ($pendingInvites > 0) {
                $notifItems[] = [
                    'text' => $pendingInvites . ' pending invitation(s) awaiting your response',
                    'url' => APP_URL . '/modules/hearings/index.php',
                ];
            }
        } else {
            $notifItems[] = [
                'text' => 'No stakeholder record found for your account email yet — contact staff if you were expecting an invitation.',
                'url' => APP_URL . '/modules/feedback/index.php',
            ];
        }
    } elseif (currentRole() === ROLE_PUBLIC) {
        $upcoming = $pdo->query(
            "SELECT id, title, hearing_date FROM hearings WHERE status = 'Upcoming' AND hearing_date >= CURDATE()
             ORDER BY hearing_date LIMIT 5"
        )->fetchAll();
        foreach ($upcoming as $h) {
            $notifItems[] = [
                'text' => 'Upcoming: ' . $h['title'] . ' on ' . formatDate($h['hearing_date']),
                'url' => APP_URL . '/modules/hearings/view.php?id=' . $h['id'],
            ];
        }
    } else {
        $curUid = currentUserId();
        // 1. Personal Assigned Issues (High Priority Notification)
        if ($curUid) {
            $myIssuesStmt = $pdo->prepare(
                "SELECT id, reference_number, title, priority, status
                 FROM hearing_issues
                 WHERE assigned_user_id = :uid AND status NOT IN ('Closed', 'Resolved')
                 ORDER BY updated_at DESC LIMIT 5"
            );
            $myIssuesStmt->execute([':uid' => $curUid]);
            foreach ($myIssuesStmt->fetchAll() as $mi) {
                $notifItems[] = [
                    'text' => 'Assigned to you: ' . $mi['reference_number'] . ' – ' . $mi['title'],
                    'url'  => APP_URL . '/modules/issues/view.php?id=' . $mi['id'],
                    'type' => 'issue',
                ];
            }

            // 2. Personal DB Notifications
            $userNotifsStmt = $pdo->prepare(
                "SELECT id, title, target_url FROM notifications
                 WHERE user_id = :uid AND is_read = 0
                 ORDER BY created_at DESC LIMIT 5"
            );
            $userNotifsStmt->execute([':uid' => $curUid]);
            foreach ($userNotifsStmt->fetchAll() as $un) {
                $notifItems[] = [
                    'text' => $un['title'],
                    'url'  => $un['target_url'] ?: (APP_URL . '/dashboard.php'),
                    'type' => 'notification',
                ];
            }
        }

        $upcomingCount = (int)$pdo->query("SELECT COUNT(*) FROM hearings WHERE status = 'Upcoming' AND hearing_date >= CURDATE() AND hearing_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
        $newFeedbackCount = (int)$pdo->query("SELECT COUNT(*) FROM feedback WHERE status = 'New'")->fetchColumn();
        $openIssuesCount = (int)$pdo->query("SELECT COUNT(*) FROM hearing_issues WHERE status = 'Open'")->fetchColumn();
        $dueActionsCount = (int)$pdo->query("SELECT COUNT(*) FROM hearing_actions WHERE status = 'Pending' AND deadline IS NOT NULL AND deadline <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)")->fetchColumn();

        if ($upcomingCount > 0) $notifItems[] = ['text' => $upcomingCount . ' hearing(s) upcoming this week', 'url' => APP_URL . '/modules/hearings/index.php'];
        if ($newFeedbackCount > 0) $notifItems[] = ['text' => $newFeedbackCount . ' new feedback awaiting review', 'url' => APP_URL . '/modules/feedback/index.php'];
        if ($openIssuesCount > 0) $notifItems[] = ['text' => $openIssuesCount . ' open issue(s) to triage', 'url' => APP_URL . '/modules/issues/index.php'];
        if ($dueActionsCount > 0) $notifItems[] = ['text' => $dueActionsCount . ' action(s) nearing deadline', 'url' => APP_URL . '/modules/actions/index.php'];
    }
} catch (Throwable $e) {
    error_log('Notification bell error: ' . $e->getMessage());
    $notifItems = [];
}

$notifCount = count($notifItems);

$fullName = trim((string)($user['full_name'] ?? 'Administrator'));
$userInitial = strtoupper(substr($fullName !== '' ? $fullName : 'A', 0, 1));
$roleLabel = trim((string)($user['role_name'] ?? 'Administrator'));

$portalBase = rtrim(dirname(APP_URL), '/\\');
if ($portalBase === '.' || $portalBase === '') {
    $portalBase = APP_URL;
}

$subsystems = [
    ['short' => 'ORLMS', 'name' => 'Ordinance & Resolution Life Cycle', 'url' => $portalBase . '/ORLMS/', 'icon' => 'bi-file-earmark-text', 'active' => false],
    ['short' => 'SLMMS', 'name' => 'Session & Legislative Meeting', 'url' => $portalBase . '/SLMMS/dashboard.php', 'icon' => 'bi-calendar-event', 'active' => false],
    ['short' => 'LACMS', 'name' => 'Legislative Agenda & Calendar', 'url' => $portalBase . '/LACMS/', 'icon' => 'bi-calendar3', 'active' => false],
    ['short' => 'CMAS', 'name' => 'Committee Management & Assignment', 'url' => $portalBase . '/CMAS/dashboard.php', 'icon' => 'bi-diagram-3', 'active' => false],
    ['short' => 'VQDSS', 'name' => 'Voting, Quorum & Decisions', 'url' => $portalBase . '/vqdss/', 'icon' => 'bi-check2-square', 'active' => false],
    ['short' => 'LRDMS', 'name' => 'Records & Document Management', 'url' => $portalBase . '/LRDMS/dashboard.php', 'icon' => 'bi-folder-check', 'active' => false],
    ['short' => 'LPH', 'name' => 'Public Hearing & Consultation', 'url' => APP_URL . '/dashboard.php', 'icon' => 'bi-people', 'active' => true],
    ['short' => 'LAHRS', 'name' => 'Archives & Historical Repository', 'url' => $portalBase . '/LAHRS/dashboard.php', 'icon' => 'bi-archive', 'active' => false],
    ['short' => 'LRPAIES', 'name' => 'Research, Policy & Impact Evaluation', 'url' => $portalBase . '/LRPAIES/dashboard.php', 'icon' => 'bi-graph-up-arrow', 'active' => false],
    ['short' => 'CEPFMS', 'name' => 'Citizen Engagement & Feedback', 'url' => $portalBase . '/CEPFMS/', 'icon' => 'bi-chat-square-heart', 'active' => false],
    ['short' => 'PORTAL', 'name' => 'Citizen Public Portal', 'url' => $portalBase . '/citizen_portal/', 'icon' => 'bi-globe2', 'active' => false],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> | <?= e(APP_SHORT_NAME) ?></title>

<link rel="icon" type="image/png" href="<?= e(APP_URL) ?>/assets/images/logo.png">
<link rel="shortcut icon" type="image/png" href="<?= e(APP_URL) ?>/assets/images/logo.png">


<link href="<?= e(vendorAsset('bootstrap/bootstrap.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css')) ?>" rel="stylesheet">
<link href="<?= e(vendorAsset('bootstrap-icons/bootstrap-icons.css', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css')) ?>" rel="stylesheet">

<!-- Google Fonts matching Landing Page -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700;800;900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<link href="<?= e(vendorAsset('datatables/dataTables.bootstrap5.min.css', 'https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css')) ?>" rel="stylesheet">
<link href="<?= e(APP_URL) ?>/assets/css/style.css?v=<?= time() ?>" rel="stylesheet">
<link href="<?= e(APP_URL) ?>/assets/css/orlms-shell.css?v=<?= time() ?>" rel="stylesheet">
<link href="<?= e(APP_URL) ?>/assets/css/lph-mobile-responsive.css?v=<?= time() ?>" rel="stylesheet">
<?php if (!empty($extraCss)) foreach ($extraCss as $css): ?>
<link href="<?= e($css) ?>" rel="stylesheet">
<?php endforeach; ?>
<script>
(function() {
    try {
        if (localStorage.getItem('lph_sidebar_collapsed') === '1') {
            document.documentElement.classList.add('sidebar-collapsed');
            document.addEventListener('DOMContentLoaded', function() {
                if (document.body) document.body.classList.add('sidebar-collapsed');
            });
        }
    } catch(e) {}
})();
</script>

<style>
/* ============================================================
   HEADER - Coastal Blue Theme (No Toggle Buttons)
   Colors: Midnight Blue, White, Gold Accents
   ============================================================ */
:root {
    --header-midnight-dark: #071426;
    --header-midnight: #0f2137;
    --header-midnight-blue: #1a3a5c;
    --header-midnight-soft: #2C5282;
    --header-midnight-pale: #4A7EB5;
    --header-midnight-lighter: #6B9BC7;
    --header-white: #ffffff;
    --header-gold: #a97900;
    --header-gold-light: #8a6200;
    --header-shadow: 0 4px 25px rgba(7, 20, 38, 0.2);
}

.topnav {
    background: linear-gradient(180deg, #0A1628 0%, #0F2137 40%, #1A3A5C 100%);
    box-shadow: var(--header-shadow);
    padding: 0.5rem 1.5rem;
    height: 72px;
    z-index: 1050;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
   
}

/* ===== BRAND / LOGO ===== */
.navbar-brand {
    color: var(--header-white) !important;
    font-weight: 700;
    font-size: 1.1rem;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 0.85rem;
    text-decoration: none;
    padding: 0;
}

.navbar-brand:hover {
    transform: scale(1.02);
    color: var(--header-white) !important;
}

/* Logo Container */
.navbar-brand .logo-wrapper {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.10);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    padding: 6px;
    border: 2px solid rgba(245, 200, 66, 0.2);
    box-shadow: 0 4px 20px rgba(245, 200, 66, 0.08);
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.navbar-brand .logo-wrapper img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    border-radius: 8px;
    display: block;
}

.navbar-brand .logo-wrapper:hover {
    transform: rotate(-6deg) scale(1.08);
    border-color: #F7D95A;
    box-shadow: 0 4px 30px rgba(245, 200, 66, 0.2);
}

/* Brand Text */
.navbar-brand .brand-text {
    display: flex;
    flex-direction: column;
    line-height: 1.15;
}

.navbar-brand .brand-text .brand-title {
    font-weight: 800;
    font-size: 1.15rem;
    color: var(--header-white);
    letter-spacing: 0.5px;
    transition: color 0.3s ease;
}

.navbar-brand .brand-text .brand-title .accent {
    color: #a97900;
    transition: color 0.3s ease;
    text-shadow: 0 0 30px rgba(245, 200, 66, 0.15);
}

.navbar-brand .brand-text .brand-subtitle {
    font-size: 0.55rem;
    color: rgba(255, 255, 255, 0.4);
    font-weight: 400;
    letter-spacing: 0.8px;
    text-transform: uppercase;
    margin-top: 1px;
}

.navbar-brand:hover .brand-text .brand-title {
    color: #F7D95A;
}

.navbar-brand:hover .brand-text .brand-title .accent {
    color: var(--header-white);
}

/* ===== NAV ICONS ===== */
.topnav .text-white {
    color: rgba(255, 255, 255, 0.9) !important;
    transition: all 0.3s ease;
    text-decoration: none;
}

.topnav .text-white:hover {
    color: #a97900 !important;
}

/* Notification Bell */
.topnav .bi-bell {
    font-size: 1.3rem;
    transition: all 0.3s ease;
    color: rgba(255, 255, 255, 0.8);
}

.topnav .bi-bell:hover {
    transform: scale(1.1) rotate(-10deg);
    color: #a97900;
}

/* Notification Badge */
.topnav .badge.bg-danger {
    background: #EF4444 !important;
    font-size: 0.6rem;
    padding: 0.15rem 0.45rem;
    border: 2px solid var(--header-white);
    font-weight: 700;
    box-shadow: 0 2px 10px rgba(239, 68, 68, 0.4);
    animation: pulse-badge 2s ease-in-out infinite;
}

@keyframes pulse-badge {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.15); }
}

/* User Avatar */
.topnav .bi-person-circle {
    font-size: 1.6rem;
    color: #a97900;
    transition: all 0.3s ease;
    filter: drop-shadow(0 2px 8px rgba(245, 200, 66, 0.15));
}

.topnav .bi-person-circle:hover {
    transform: scale(1.1);
    filter: drop-shadow(0 4px 15px rgba(245, 200, 66, 0.3));
}

/* User Name */
.topnav .small.user-name {
    font-weight: 500;
    color: rgba(255, 255, 255, 0.85);
    transition: color 0.3s ease;
}

.topnav .small.user-name:hover {
    color: #a97900;
}

/* Dropdown Caret */
.topnav .bi-caret-down-fill {
    font-size: 0.6rem;
    color: rgba(255, 255, 255, 0.3);
    transition: transform 0.3s ease;
}

.topnav .dropdown-toggle[aria-expanded="true"] .bi-caret-down-fill {
    transform: rotate(180deg);
}

/* ===== DROPDOWN MENUS ===== */
.topnav .dropdown-menu {
    border: none;
    border-radius: 16px;
    box-shadow: 0 15px 50px rgba(10, 22, 40, 0.15);
    padding: 0.5rem;
    margin-top: 0.75rem;
    border-top: 4px solid #a97900;
    min-width: 220px;
    background: var(--header-white);
    animation: dropdownFade 0.25s ease;
}

@keyframes dropdownFade {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.topnav .dropdown-menu .dropdown-header {
    color: #0A1628;
    font-weight: 700;
    padding: 0.5rem 1rem;
    font-size: 0.8rem;
    letter-spacing: 0.3px;
}

.topnav .dropdown-menu .dropdown-item {
    border-radius: 10px;
    padding: 0.6rem 1rem;
    transition: all 0.25s ease;
    color: #64748b;
    font-size: 0.875rem;
    display: flex;
    align-items: center;
    gap: 0.65rem;
    font-weight: 500;
}

.topnav .dropdown-menu .dropdown-item:hover {
    background: #F0F4F8;
    color: #0A1628;
    transform: translateX(4px);
}

.topnav .dropdown-menu .dropdown-item i {
    color: #1A3A5C;
    font-size: 1.1rem;
    width: 1.5rem;
    text-align: center;
    transition: color 0.3s ease;
}

.topnav .dropdown-menu .dropdown-item:hover i {
    color: #a97900;
}

.topnav .dropdown-menu .dropdown-divider {
    border-color: #E2E8F0;
    margin: 0.25rem 0;
}

.topnav .dropdown-menu .dropdown-item-text.text-muted {
    color: #94A3B8 !important;
    font-size: 0.8rem;
    padding: 0.5rem 1rem;
}

/* Notification Items */
.topnav .dropdown-item.small {
    font-size: 0.8rem;
    white-space: normal;
    word-wrap: break-word;
    padding: 0.5rem 0.75rem;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 992px) {
    .topnav {
        padding: 0.4rem 1rem;
        height: 64px;
    }

    .navbar-brand .brand-text .brand-title {
        font-size: 1rem;
    }

    .navbar-brand .brand-text .brand-subtitle {
        font-size: 0.5rem;
    }

    .navbar-brand .logo-wrapper {
        width: 40px;
        height: 40px;
        padding: 5px;
    }

    .topnav .bi-person-circle {
        font-size: 1.4rem;
    }

    .topnav .bi-bell {
        font-size: 1.2rem;
    }

    .topnav .small.user-name {
        display: none !important;
    }
}

@media (max-width: 576px) {
    .topnav {
        padding: 0.3rem 0.6rem;
        height: 56px;
        border-bottom-width: 3px;
    }

    .navbar-brand .brand-text .brand-title {
        font-size: 0.85rem;
    }

    .navbar-brand .brand-text .brand-subtitle {
        display: none;
    }

    .navbar-brand .logo-wrapper {
        width: 34px;
        height: 34px;
        padding: 4px;
        border-radius: 8px;
    }

    .topnav .gap-3 {
        gap: 0.5rem !important;
    }

    .topnav .bi-person-circle {
        font-size: 1.2rem;
    }

    .topnav .bi-bell {
        font-size: 1rem;
    }

    .topnav .dropdown-menu {
        min-width: 280px;
        margin-right: -0.5rem;
    }
}

/* Hide default dropdown caret */
.topnav .dropdown-toggle::after {
    display: none;
}

/* ===== HEADER LAYOUT ===== */
.topnav .container-fluid {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0;
}

/* Left section with brand only */
.topnav .nav-left {
    display: flex;
    align-items: center;
}

/* Right section with notifications and profile */
.topnav .nav-right {
    display: flex;
    align-items: center;
    gap: 1rem;
}

/* ===== ADDITIONAL COASTAL BLUE ACCENTS ===== */
/* Smooth transitions for all interactive elements */
.topnav * {
    transition: all 0.3s ease;
}

/* Custom scrollbar for dropdown */
.topnav .dropdown-menu::-webkit-scrollbar {
    width: 4px;
}

.topnav .dropdown-menu::-webkit-scrollbar-track {
    background: #F0F4F8;
    border-radius: 10px;
}

.topnav .dropdown-menu::-webkit-scrollbar-thumb {
    background: #4A7EB5;
    border-radius: 10px;
}

/* ============================================================
   GLOBAL SYSTEMWIDE COLOR OVERRIDE: DARK GOLD (#a97900)
   ============================================================ */
:root {
    --gold: #a97900 !important;
    --hear-gold: #a97900 !important;
    --header-gold: #a97900 !important;
    --primary-yellow: #a97900 !important;
    --primary-yellow-light: #8a6200 !important;
    --footer-gold: #a97900 !important;
    --ai-gold: #a97900 !important;
    --og-gold: #a97900 !important;
}

.lphwf-eyebrow,
.lphx-eyebrow,
.lpha-eyebrow,
.text-warning,
.text-gold,
.text-yellow,
.bi-grid-3x3-gap-fill.text-warning,
.bi-exclamation-triangle.text-warning,
.bi-geo-alt.text-warning,
.bi-people.text-warning,
.hearing-eyebrow {
    color: #a97900 !important;
}

.lphwf-head,
.lphx-head,
.lpha-head,
.hearing-page-head,
.lphx-scan-box,
.breadcrumb-bar {
    border-left-color: #a97900 !important;
}

.lphwf-card > .card-header,
.lphx-card > .card-header,
.lpha-card > .card-header,
.lphwf-stat,
.lphx-stat,
.lpha-stat,
.lphwf-funnel a,
.lphwf-table thead th,
.lphx-table thead th,
.lpha-table thead th,
.table thead th {
    border-bottom-color: #a97900 !important;
}

.btn-warning,
.btn-outline-warning:hover,
.btn-outline-warning:focus,
.btn-outline-warning:active,
.badge.bg-warning,
.badge.text-bg-warning,
.text-bg-warning {
    background-color: #a97900 !important;
    border-color: #a97900 !important;
    color: #ffffff !important;
}

.btn-outline-warning {
    color: #a97900 !important;
    border-color: #a97900 !important;
}

.orlms-sidebar-link.active,
.orlms-sidebar-link:hover,
.sidebar-navigation a.active,
.sidebar-navigation a:hover {
    color: #a97900 !important;
}

body.sidebar-collapsed .orlms-sidebar-link.active > i,
body.sidebar-collapsed .orlms-sidebar-link:hover > i,
body.sidebar-collapsed .sidebar-navigation a.active > i,
body.sidebar-collapsed .sidebar-navigation a:hover > i {
    color: #a97900 !important;
}

.pagination .page-item.active .page-link {
    border-color: #a97900 !important;
}

.pagination .page-link:hover {
    background-color: #a97900 !important;
    border-color: #a97900 !important;
    color: #ffffff !important;
}

/* REMOVE BACKGROUND COLOR FOR CARD HEADERS (e.g. Hearings Trend & All System Card Headers) */
.card-header,
.lphwf-card > .card-header,
.lphx-card > .card-header,
.lpha-card > .card-header,
.hearing-detail-card .card-header {
    background: transparent !important;
    background-color: transparent !important;
    background-image: none !important;
    color: #0f172a !important;
    border-bottom: 2px solid #e2e8f0 !important;
}
/* ============================================================
   CLEAN & SPACIOUS MODAL DESIGN SYSTEM (Subsystem 7 - LPH)
   Ensures modals are wider, beautifully proportioned, comfortable to view,
   and follow a simple, clean, elegant, professional modern design.
   ============================================================ */
.modal-dialog {
    max-width: 820px !important;
    margin: 1.75rem auto !important;
}
.modal-dialog.modal-sm,
.modal-sm {
    max-width: 480px !important;
}
.modal-dialog.modal-lg,
.modal-lg {
    max-width: 960px !important;
}
.modal-dialog.modal-xl,
.modal-xl {
    max-width: 1140px !important;
}
.modal-dialog-centered {
    display: flex !important;
    align-items: center !important;
    min-height: calc(100% - 3.5rem) !important;
}
.modal-content {
    border-radius: 12px !important;
    border: 1px solid #e2e8f0 !important;
    box-shadow: 0 16px 36px -6px rgba(15, 23, 42, 0.16), 0 4px 12px -2px rgba(0, 0, 0, 0.05) !important;
    overflow: hidden !important;
    background: #ffffff !important;
}
.modal-header {
    background: #f8fafc !important;
    color: #0f172a !important;
    padding: 1rem 1.5rem !important;
    border-bottom: 1px solid #e2e8f0 !important;
}
.modal-header .modal-title,
.modal-header h5,
.modal-header h6 {
    font-size: 1.05rem !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    letter-spacing: -0.2px;
    margin: 0 !important;
}
.modal-header small,
.modal-header .text-muted,
.modal-header p,
.modal-header .text-white-50 {
    color: #64748b !important;
    font-size: 0.8rem !important;
    margin: 0 !important;
}
.modal-header .btn-close,
.modal-header .btn-close-white {
    filter: none !important;
    opacity: 0.55 !important;
    padding: 0.5rem !important;
}
.modal-header .btn-close:hover,
.modal-header .btn-close-white:hover {
    opacity: 1 !important;
}
.modal-header .rounded-circle {
    background-color: rgba(30, 74, 122, 0.1) !important;
    color: #1e4a7a !important;
}
.modal-header .rounded-circle i {
    color: #1e4a7a !important;
}
.modal-body {
    padding: 1.5rem 1.75rem !important;
    max-height: calc(85vh - 120px) !important;
    overflow-y: auto !important;
    background: #ffffff !important;
}
.modal-footer {
    padding: 0.85rem 1.75rem !important;
    background-color: #f8fafc !important;
    border-top: 1px solid #e2e8f0 !important;
}
.modal .form-label {
    font-size: 0.82rem !important;
    font-weight: 600 !important;
    color: #334155 !important;
    margin-bottom: 0.35rem !important;
}
.modal .form-control,
.modal .form-select {
    font-size: 0.875rem !important;
    padding: 0.45rem 0.75rem !important;
    border-radius: 7px !important;
    border: 1px solid #cbd5e1 !important;
    color: #0f172a !important;
}
.modal .form-control:focus,
.modal .form-select:focus {
    border-color: #1e4a7a !important;
    box-shadow: 0 0 0 3px rgba(30, 74, 122, 0.12) !important;
}
.modal textarea.form-control {
    min-height: 75px !important;
    max-height: 200px !important;
}
.modal .form-text,
.modal small.text-muted {
    font-size: 0.78rem !important;
    color: #64748b !important;
}
@media (max-width: 768px) {
    .modal-dialog,
    .modal-dialog.modal-lg,
    .modal-dialog.modal-xl,
    .modal-lg,
    .modal-xl {
        max-width: 95% !important;
        margin: 0.75rem auto !important;
    }
}
</style>
</head>
<?php if (!empty($hideTopnav) || !empty($hideHeader)): ?>
<style>
  .topnav { display: none !important; }
  .sidebar, .orlms-sidebar { top: 0 !important; padding-top: 0 !important; }
  .main-content, .orlms-main-content { padding-top: 1.5rem !important; }
</style>
<?php else: ?>
<nav class="navbar navbar-expand-lg topnav shadow-sm fixed-top">
  <div class="container-fluid">
    <!-- Left Section: Brand & Subsystems Navigation -->
    <div class="nav-left d-flex align-items-center gap-3">
      <!-- Brand with Manila Logo -->
      <a class="navbar-brand fw-bold text-white d-flex align-items-center gap-2" href="<?= e(APP_URL) ?>/dashboard.php">
        <span class="logo-wrapper">
          <img src="<?= e(APP_URL) ?>/assets/images/logo.png" alt="<?= e(APP_SHORT_NAME) ?> Logo">
        </span>
        <span class="brand-text">
          <span class="brand-title">
            City of<span class="accent"> MANILA</span>
          </span>
          <span class="brand-subtitle">Government Portal</span>
        </span>
      </a>

      <!-- Subsystems Navigation Dropdown -->
      <div class="dropdown ms-2">
        <button class="orlms-system-switcher dropdown-toggle text-white" type="button" data-bs-toggle="dropdown" aria-expanded="false">
          <span>Subsystems</span>
        </button>
        <div class="dropdown-menu dropdown-menu-end orlms-subsystem-menu shadow-lg" style="max-height: 85vh; overflow-y: auto; z-index: 1060;">
          <a href="http://localhost/legislative/index.php" class="orlms-subsystem-item orlms-subsystem-item-portal">
            <div><strong>PORTAL</strong><small>Main Landing Page</small></div>
          </a>
          <?php foreach ($subsystems as $system): ?>
          <a href="<?= e($system['url']) ?>" class="orlms-subsystem-item <?= $system['active'] ? 'active' : '' ?>">
            <div><strong><?= e($system['short']) ?></strong><small><?= e($system['name']) ?></small></div>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Right Section: Notifications + Profile -->
    <div class="nav-right">
      <!-- Notifications -->
      <div class="dropdown">
        <a href="#" class="text-white position-relative" data-bs-toggle="dropdown" aria-expanded="false">
          <i class="bi bi-bell fs-5"></i>
          <?php if ($notifCount > 0): ?>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
              <?= $notifCount > 99 ? '99+' : $notifCount ?>
            </span>
          <?php endif; ?>
        </a>
        <div class="dropdown-menu dropdown-menu-end p-2" style="min-width:320px;max-height:380px;overflow-y:auto;">
          <h6 class="dropdown-header">Notifications</h6>
          <?php if (empty($notifItems)): ?>
            <span class="dropdown-item-text small text-muted">You're all caught up — nothing new right now.</span>
          <?php endif; ?>
          <?php foreach ($notifItems as $item): ?>
            <a class="dropdown-item small" href="<?= e($item['url']) ?>"><?= e($item['text']) ?></a>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- User Profile -->
      <div class="dropdown">
        <button class="orlms-user-button dropdown-toggle text-white" type="button" data-bs-toggle="dropdown" aria-expanded="false">
          <span class="orlms-avatar"><?= e(strtoupper(substr($user['full_name'] ?? 'A', 0, 1))) ?></span>
          <span class="orlms-user-copy d-none d-md-flex">
            <strong class="text-white"><?= e($user['full_name']) ?></strong>
            <small class="text-white-50"><?= e($user['role_name']) ?></small>
          </span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 mt-2" style="font-size: 0.85rem; z-index: 1060; border-radius: 12px; min-width: 210px;">
          <li><span class="dropdown-item-text small text-muted"><?= e($user['role_name']) ?></span></li>
          <li><hr class="dropdown-divider my-1"></li>
          <li><a class="dropdown-item py-2" href="<?= e(APP_URL) ?>/dashboard.php"><i class="bi bi-speedometer2 me-2 text-warning"></i>Dashboard</a></li>
          <li><a class="dropdown-item py-2" href="<?= e(APP_URL) ?>/pages/profile.php"><i class="bi bi-person me-2 text-warning"></i>My Profile</a></li>
          <li><hr class="dropdown-divider my-1"></li>
          <li><a class="dropdown-item py-2 text-danger" href="<?= e(APP_URL) ?>/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Sign Out</a></li>
        </ul>
      </div>
    </div>
  </div>
</nav>
<?php endif; ?>