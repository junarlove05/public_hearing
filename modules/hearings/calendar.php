<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

requireLogin();

$pageTitle = 'Hearing Calendar';
$activeMenu = 'hearings';

include __DIR__ . '/../../layouts/header.php';
?>
<link rel="stylesheet" href="<?= e(APP_URL . '/assets/css/hearings-complete.css') ?>">

<div class="app-wrapper">
<?php include __DIR__ . '/../../layouts/sidebar.php'; ?>

<div class="main-content hearing-module">
    <div class="hearing-page-head">
        <div>
            <a href="index.php" class="small text-decoration-none">
                <i class="bi bi-arrow-left"></i> Back to Hearing Scheduling
            </a>

            <div class="hearing-eyebrow mt-2">
                <i class="bi bi-calendar3"></i> Calendar Integration
            </div>

            <h1>Hearing Calendar</h1>
            <p>Month view of public hearings and consultations.</p>
        </div>

        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-outline-secondary btn-sm" id="prevMonth">
                <i class="bi bi-chevron-left"></i>
            </button>

            <strong id="calMonthLabel" class="hearing-calendar-label"></strong>

            <button class="btn btn-outline-secondary btn-sm" id="nextMonth">
                <i class="bi bi-chevron-right"></i>
            </button>
        </div>
    </div>

    <div class="card hearing-calendar-card">
        <div class="card-body">
            <div class="hearing-calendar-grid" id="calendarGrid">
                <div class="text-center text-muted py-5">Loading calendar...</div>
            </div>
        </div>
    </div>

    <div class="hearing-calendar-legend">
        <span><b class="upcoming"></b> Upcoming</span>
        <span><b class="ongoing"></b> Ongoing</span>
        <span><b class="completed"></b> Completed</span>
        <span><b class="cancelled"></b> Cancelled</span>
    </div>
</div>
</div>

<?php
$extraJs = [APP_URL . '/assets/js/hearings-calendar.js'];
include __DIR__ . '/../../layouts/footer.php';
?>
