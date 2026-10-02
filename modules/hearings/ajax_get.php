<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

requireLogin();

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    jsonResponse(false, 'Invalid hearing id.');
}

$stmt = db()->prepare(
    'SELECT
        h.*,
        li.reference_number AS legislative_reference,
        li.title AS legislative_title,
        lit.name AS legislative_type
     FROM hearings h
     LEFT JOIN legislative_items li ON li.id = h.legislative_item_id
     LEFT JOIN legislative_item_types lit ON lit.id = li.item_type_id
     WHERE h.id = :id'
);
$stmt->execute([':id' => $id]);
$hearing = $stmt->fetch();

if (!$hearing) {
    jsonResponse(false, 'Hearing not found.');
}

if (!empty($hearing['hearing_time'])) {
    $hearing['hearing_time'] = substr((string)$hearing['hearing_time'], 0, 5);
}

if (!empty($hearing['end_time'])) {
    $hearing['end_time'] = substr((string)$hearing['end_time'], 0, 5);
}

if (!empty($hearing['registration_deadline'])) {
    $hearing['registration_deadline'] =
        date('Y-m-d\TH:i', strtotime($hearing['registration_deadline']));
}

$sessions = [];
try {
    $sessStmt = db()->prepare(
        'SELECT id, session_date, day_number, start_time, end_time, is_closed, notes
         FROM hearing_session_days
         WHERE hearing_id = :id
         ORDER BY session_date ASC, day_number ASC'
    );
    $sessStmt->execute([':id' => $id]);
    $rawSessions = $sessStmt->fetchAll();

    foreach ($rawSessions as $rs) {
        $sessions[] = [
            'id' => (int)$rs['id'],
            'session_date' => $rs['session_date'],
            'day_number' => (int)$rs['day_number'],
            'start_time' => !empty($rs['start_time']) ? substr((string)$rs['start_time'], 0, 5) : $hearing['hearing_time'],
            'end_time' => !empty($rs['end_time']) ? substr((string)$rs['end_time'], 0, 5) : $hearing['end_time'],
            'is_closed' => (int)($rs['is_closed'] ?? 0),
            'notes' => (string)($rs['notes'] ?? ''),
        ];
    }
} catch (Throwable $e) {
    $sessions = [];
}

// Fallback to single session if hearing_session_days has no rows yet
if (empty($sessions) && !empty($hearing['hearing_date'])) {
    $sessions[] = [
        'id' => 0,
        'session_date' => $hearing['hearing_date'],
        'day_number' => 1,
        'start_time' => $hearing['hearing_time'],
        'end_time' => $hearing['end_time'],
        'is_closed' => 0,
        'notes' => '',
    ];
}

$hearing['sessions'] = $sessions;

jsonResponse(true, '', ['hearing' => $hearing]);

