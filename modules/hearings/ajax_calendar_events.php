<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

requireLogin();

$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

if ($year < 2000 || $year > 2200) {
    $year = (int)date('Y');
}

if ($month < 1 || $month > 12) {
    $month = (int)date('n');
}

$monthStart = sprintf('%04d-%02d-01', $year, $month);
$monthEnd = date('Y-m-t', strtotime($monthStart));

$stmt = db()->prepare(
    'SELECT
        h.id,
        h.reference_number,
        h.title,
        h.hearing_date,
        h.end_date,
        h.hearing_time,
        h.end_time,
        h.status,
        h.venue,
        h.meeting_link,
        h.visibility,
        c.name AS committee_name,
        ht.name AS type_name
     FROM hearings h
     LEFT JOIN committees c ON c.id = h.committee_id
     LEFT JOIN hearing_types ht ON ht.id = h.hearing_type_id
     WHERE h.hearing_date <= :month_end
       AND COALESCE(h.end_date, h.hearing_date) >= :month_start
     ORDER BY h.hearing_date, h.hearing_time, h.id'
);
$stmt->execute([
    ':month_start' => $monthStart,
    ':month_end' => $monthEnd,
]);

jsonResponse(true, '', ['events' => $stmt->fetchAll()]);
