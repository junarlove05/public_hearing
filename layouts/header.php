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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> | <?= e(APP_SHORT_NAME) ?></title>

<link href="<?= e(vendorAsset('bootstrap/bootstrap.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css')) ?>" rel="stylesheet">
<link href="<?= e(vendorAsset('bootstrap-icons/bootstrap-icons.css', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css')) ?>" rel="stylesheet">
<link href="<?= e(vendorAsset('datatables/dataTables.bootstrap5.min.css', 'https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css')) ?>" rel="stylesheet">
<link href="<?= e(APP_URL) ?>/assets/css/style.css" rel="stylesheet">
<?php if (!empty($extraCss)) foreach ($extraCss as $css): ?>
<link href="<?= e($css) ?>" rel="stylesheet">
<?php endforeach; ?>

<style>
/* ============================================================
   HEADER - Coastal Blue Theme (No Toggle Buttons)
   Colors: Midnight Blue, White, Gold Accents
   ============================================================ */
:root {
    --header-midnight-dark: #0A1628;
    --header-midnight: #0F2137;
    --header-midnight-blue: #1A3A5C;
    --header-midnight-soft: #2C5282;
    --header-midnight-pale: #4A7EB5;
    --header-midnight-lighter: #6B9BC7;
    --header-white: #ffffff;
    --header-gold: #F5C842;
    --header-gold-light: #F7D95A;
    --header-shadow: 0 4px 25px rgba(10, 22, 40, 0.2);
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
    color: #F5C842;
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
    color: #F5C842 !important;
}

/* Notification Bell */
.topnav .bi-bell {
    font-size: 1.3rem;
    transition: all 0.3s ease;
    color: rgba(255, 255, 255, 0.8);
}

.topnav .bi-bell:hover {
    transform: scale(1.1) rotate(-10deg);
    color: #F5C842;
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
    color: #F5C842;
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
    color: #F5C842;
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
    border-top: 4px solid #F5C842;
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
    color: #F5C842;
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
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg topnav shadow-sm fixed-top">
  <div class="container-fluid">
    <!-- Left Section: Brand Only -->
    <div class="nav-left">
      <!-- Brand with Manila Logo -->
      <a class="navbar-brand fw-bold text-white d-flex align-items-center gap-2" href="<?= e(APP_URL) ?>/dashboard.php">
        <span class="logo-wrapper">
          <img src="<?= e(APP_URL) ?>/assets/images/manila.png" alt="<?= e(APP_SHORT_NAME) ?> Logo">
        </span>
        <span class="brand-text">
          <span class="brand-title">
            City of<span class="accent"> MANILA</span>
          </span>
          <span class="brand-subtitle">Government Portal</span>
        </span>
      </a>
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
        <a href="#" class="d-flex align-items-center text-white text-decoration-none gap-2" data-bs-toggle="dropdown" aria-expanded="false">
          <i class="bi bi-person-circle fs-5"></i>
          <span class="d-none d-md-inline small user-name"><?= e($user['full_name']) ?></span>
          <i class="bi bi-caret-down-fill small"></i>
        </a>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><span class="dropdown-item-text small text-muted"><?= e($user['role_name']) ?></span></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item" href="<?= e(APP_URL) ?>/pages/profile.php"><i class="bi bi-person"></i> Profile</a></li>
          <li><a class="dropdown-item" href="<?= e(APP_URL) ?>/logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
        </ul>
      </div>
    </div>
  </div>
</nav>