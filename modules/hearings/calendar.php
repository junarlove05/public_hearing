<?php
/**
 * modules/hearings/calendar.php
 * ------------------------------------------------------------------
 * Month-grid calendar view of hearings, built with plain HTML/CSS/JS
 * (no external calendar library) and populated via AJAX from
 * ajax_calendar_events.php.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pageTitle  = 'Hearing Calendar';
$activeMenu = 'hearings';

include __DIR__ . '/../../layouts/header.php';
?>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../layouts/sidebar.php'; ?>

  <div class="main-content">
    <div class="breadcrumb-bar d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <a href="index.php" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> Back to Hearing Schedule</a>
        <h5 class="mb-0 mt-1"><i class="bi bi-calendar3 text-primary"></i> Hearing Calendar</h5>
      </div>
      <div class="d-flex align-items-center gap-2">
        <button class="btn btn-outline-secondary btn-sm" id="prevMonth"><i class="bi bi-chevron-left"></i></button>
        <span class="fw-semibold" id="calMonthLabel" style="min-width:160px;text-align:center;"></span>
        <button class="btn btn-outline-secondary btn-sm" id="nextMonth"><i class="bi bi-chevron-right"></i></button>
      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <div class="calendar-grid" id="calendarGrid">
          <div class="text-center text-muted py-5">Loading calendar...</div>
        </div>
      </div>
    </div>

    <div class="d-flex gap-3 mt-3 small text-muted flex-wrap">
      <span><span class="badge bg-primary">&nbsp;</span> Upcoming</span>
      <span><span class="badge bg-warning">&nbsp;</span> Ongoing</span>
      <span><span class="badge bg-success">&nbsp;</span> Completed</span>
      <span><span class="badge bg-danger">&nbsp;</span> Cancelled</span>
    </div>
  </div>
</div>

<style>
.calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
.calendar-grid .cal-head { font-weight: 600; font-size: 12px; text-transform: uppercase; color: #6c757d; padding: 6px 4px; text-align: center; }
.calendar-grid .cal-day { min-height: 90px; border: 1px solid #e1e5ea; border-radius: 6px; padding: 6px; background: #fff; }
.calendar-grid .cal-day.empty { background: #f4f6f9; border-color: transparent; }
.calendar-grid .cal-day .day-num { font-size: 12px; color: #6c757d; margin-bottom: 4px; }
.calendar-grid .cal-day.today { border-color: #0b3d6e; box-shadow: inset 0 0 0 1px #0b3d6e; }
.cal-event { font-size: 11px; padding: 2px 5px; border-radius: 4px; margin-bottom: 2px; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: pointer; }
</style>

<?php $extraJs = [APP_URL . '/assets/js/hearings-calendar.js']; ?>
<?php include __DIR__ . '/../../layouts/footer.php'; ?>
