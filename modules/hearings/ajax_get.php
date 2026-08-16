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

jsonResponse(true, '', ['hearing' => $hearing]);
