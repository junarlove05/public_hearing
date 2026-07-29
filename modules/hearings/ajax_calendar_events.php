<?php
/**
 * modules/hearings/ajax_calendar_events.php
 * ------------------------------------------------------------------
 * Returns hearings for a given year/month as JSON events, consumed
 * by the lightweight custom calendar on calendar.php.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$year  = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

if ($month < 1 || $month > 12) $month = (int)date('n');

$stmt = db()->prepare(
    "SELECT h.id, h.title, h.hearing_date, h.hearing_time, h.status, h.venue, c.name AS committee_name
     FROM hearings h
     LEFT JOIN committees c ON c.id = h.committee_id
     WHERE YEAR(h.hearing_date) = :year AND MONTH(h.hearing_date) = :month
     ORDER BY h.hearing_date, h.hearing_time"
);
$stmt->execute([':year' => $year, ':month' => $month]);
$events = $stmt->fetchAll();

jsonResponse(true, '', ['events' => $events]);
