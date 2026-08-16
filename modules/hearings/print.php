<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

requireLogin();

$pdo = db();
$id = (int)($_GET['id'] ?? 0);

if ($id > 0) {
    $stmt = $pdo->prepare(
        'SELECT
            h.*,
            ht.name AS type_name,
            c.name AS committee_name,
            li.reference_number AS legislative_reference,
            li.title AS legislative_title,
            lit.name AS legislative_type
         FROM hearings h
         LEFT JOIN hearing_types ht ON ht.id = h.hearing_type_id
         LEFT JOIN committees c ON c.id = h.committee_id
         LEFT JOIN legislative_items li ON li.id = h.legislative_item_id
         LEFT JOIN legislative_item_types lit ON lit.id = li.item_type_id
         WHERE h.id = :id'
    );
    $stmt->execute([':id' => $id]);
    $hearing = $stmt->fetch();

    if (!$hearing) {
        http_response_code(404);
        exit('Hearing not found.');
    }

    $regStmt = $pdo->prepare('SELECT COUNT(*) FROM registrations WHERE hearing_id = :id');
    $regStmt->execute([':id' => $id]);
    $registrationCount = (int)$regStmt->fetchColumn();

    $docStmt = $pdo->prepare(
        'SELECT file_name, document_type, visibility, uploaded_at
         FROM hearing_documents
         WHERE hearing_id = :id
         ORDER BY uploaded_at'
    );
    $docStmt->execute([':id' => $id]);
    $documents = $docStmt->fetchAll();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title><?= e($hearing['reference_number'] ?: 'Hearing') ?> - Print</title>
        <style>
            body{font-family:Arial,sans-serif;color:#111827;margin:32px;font-size:13px}
            h1{font-size:20px;margin:0 0 6px}.muted{color:#6b7280}
            .head{text-align:center;border-bottom:3px solid #0f2137;padding-bottom:14px;margin-bottom:18px}
            .grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:18px}
            .item{border:1px solid #d1d5db;padding:10px;border-radius:6px}
            .item small{display:block;color:#6b7280;text-transform:uppercase;font-size:10px;margin-bottom:4px}
            table{width:100%;border-collapse:collapse;margin-top:10px}
            th,td{border:1px solid #d1d5db;padding:7px;text-align:left}
            th{background:#f3f4f6}
        </style>
    </head>
    <body onload="window.print()">
        <div class="head">
            <div><?= e(APP_NAME) ?></div>
            <h1><?= e($hearing['title']) ?></h1>
            <div class="muted"><?= e($hearing['reference_number'] ?: '') ?></div>
        </div>

        <div class="grid">
            <div class="item"><small>Type</small><?= e($hearing['type_name'] ?: '—') ?></div>
            <div class="item"><small>Committee</small><?= e($hearing['committee_name'] ?: '—') ?></div>
            <div class="item"><small>Start</small><?= formatDate($hearing['hearing_date']) ?> <?= formatTime($hearing['hearing_time']) ?></div>
            <div class="item"><small>End</small><?= formatDate($hearing['end_date'] ?: $hearing['hearing_date']) ?> <?= formatTime($hearing['end_time']) ?></div>
            <div class="item"><small>Venue</small><?= e($hearing['venue'] ?: '—') ?></div>
            <div class="item"><small>Status</small><?= e($hearing['status']) ?></div>
            <div class="item"><small>Registration Deadline</small><?= !empty($hearing['registration_deadline']) ? formatDateTime($hearing['registration_deadline']) : 'No deadline' ?></div>
            <div class="item"><small>Capacity</small><?= !empty($hearing['maximum_participants']) ? ((int)$hearing['maximum_participants']) : 'No limit' ?> · <?= $registrationCount ?> registered</div>
            <div class="item"><small>Visibility</small><?= e($hearing['visibility'] ?: 'Public') ?></div>
            <div class="item"><small>Meeting Link</small><?= e($hearing['meeting_link'] ?: '—') ?></div>
        </div>

        <div class="item">
            <small>Related Legislative Item</small>
            <?php if (!empty($hearing['legislative_reference'])): ?>
                <?= e($hearing['legislative_type'] ?: 'Legislative Item') ?>:
                <?= e($hearing['legislative_reference']) ?> -
                <?= e($hearing['legislative_title']) ?>
            <?php else: ?>
                Not linked
            <?php endif; ?>
        </div>

        <div class="item" style="margin-top:10px">
            <small>Description</small>
            <?= nl2br(e($hearing['description'] ?: '—')) ?>
        </div>

        <?php if ($hearing['status'] === 'Cancelled'): ?>
            <div class="item" style="margin-top:10px">
                <small>Cancellation Reason</small>
                <?= nl2br(e($hearing['cancellation_reason'] ?: '—')) ?>
            </div>
        <?php endif; ?>

        <h3>Documents</h3>

        <table>
            <thead>
                <tr>
                    <th>File</th>
                    <th>Type</th>
                    <th>Visibility</th>
                    <th>Uploaded</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$documents): ?><tr><td colspan="4">No documents</td></tr><?php endif; ?>
                <?php foreach ($documents as $document): ?>
                    <tr>
                        <td><?= e($document['file_name']) ?></td>
                        <td><?= e($document['document_type']) ?></td>
                        <td><?= e($document['visibility']) ?></td>
                        <td><?= formatDateTime($document['uploaded_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p class="muted">Generated <?= date('F j, Y g:i A') ?></p>
    </body>
    </html>
    <?php
    exit;
}

$statusFil = clean($_GET['status'] ?? '');
$typeFil = (int)($_GET['hearing_type_id'] ?? 0);
$committeeFil = (int)($_GET['committee_id'] ?? 0);
$legislativeTypeFil = clean($_GET['legislative_type'] ?? '');
$dateFrom = clean($_GET['date_from'] ?? '');
$dateTo = clean($_GET['date_to'] ?? '');

$where = [];
$params = [];

if ($statusFil !== '') {
    $where[] = 'h.status = :status';
    $params[':status'] = $statusFil;
}

if ($typeFil > 0) {
    $where[] = 'h.hearing_type_id = :type_id';
    $params[':type_id'] = $typeFil;
}

if ($committeeFil > 0) {
    $where[] = 'h.committee_id = :committee_id';
    $params[':committee_id'] = $committeeFil;
}

if ($legislativeTypeFil !== '') {
    $where[] = 'LOWER(lit.code) = :legislative_type';
    $params[':legislative_type'] = strtolower($legislativeTypeFil);
}

if ($dateFrom !== '') {
    $where[] = 'h.hearing_date >= :date_from';
    $params[':date_from'] = $dateFrom;
}

if ($dateTo !== '') {
    $where[] = 'h.hearing_date <= :date_to';
    $params[':date_to'] = $dateTo;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare(
    "SELECT
        h.*,
        ht.name AS type_name,
        c.name AS committee_name,
        li.reference_number AS legislative_reference,
        lit.name AS legislative_type
     FROM hearings h
     LEFT JOIN hearing_types ht ON ht.id = h.hearing_type_id
     LEFT JOIN committees c ON c.id = h.committee_id
     LEFT JOIN legislative_items li ON li.id = h.legislative_item_id
     LEFT JOIN legislative_item_types lit ON lit.id = li.item_type_id
     {$whereSql}
     ORDER BY h.hearing_date, h.hearing_time"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Hearing Schedule - Print</title>
<style>
body{font-family:Arial,sans-serif;margin:30px;color:#111827;font-size:12px}
.head{text-align:center;border-bottom:3px solid #0f2137;padding-bottom:12px;margin-bottom:16px}
table{width:100%;border-collapse:collapse}
th,td{border:1px solid #d1d5db;padding:6px;vertical-align:top}
th{background:#f3f4f6}.muted{color:#6b7280}
</style>
</head>
<body onload="window.print()">
<div class="head">
    <strong><?= e(APP_NAME) ?></strong>
    <h2>Hearing Schedule</h2>
    <div class="muted">Generated <?= date('F j, Y g:i A') ?></div>
</div>

<table>
<thead>
<tr>
    <th>Reference</th>
    <th>Title / Legislative Item</th>
    <th>Type / Committee</th>
    <th>Schedule</th>
    <th>Venue</th>
    <th>Registration</th>
    <th>Status</th>
</tr>
</thead>
<tbody>
<?php if (!$rows): ?><tr><td colspan="7">No hearings match the selected filters.</td></tr><?php endif; ?>

<?php foreach ($rows as $row): ?>
<?php
$regStmt = $pdo->prepare('SELECT COUNT(*) FROM registrations WHERE hearing_id = :id');
$regStmt->execute([':id' => $row['id']]);
$regCount = (int)$regStmt->fetchColumn();
?>
<tr>
    <td><?= e($row['reference_number'] ?: ('H-' . $row['id'])) ?></td>
    <td>
        <strong><?= e($row['title']) ?></strong>
        <?php if (!empty($row['legislative_reference'])): ?>
            <div class="muted">
                <?= e($row['legislative_type']) ?>:
                <?= e($row['legislative_reference']) ?>
            </div>
        <?php endif; ?>
    </td>
    <td>
        <?= e($row['type_name'] ?: '—') ?>
        <div class="muted"><?= e($row['committee_name'] ?: '—') ?></div>
    </td>
    <td>
        <?= formatDate($row['hearing_date']) ?>
        <?= formatTime($row['hearing_time']) ?>
        <?php if (!empty($row['end_time'])): ?> - <?= formatTime($row['end_time']) ?><?php endif; ?>
    </td>
    <td><?= e($row['venue'] ?: '—') ?></td>
    <td>
        <?= $regCount ?>
        <?php if (!empty($row['maximum_participants'])): ?> / <?= (int)$row['maximum_participants'] ?><?php endif; ?>
    </td>
    <td><?= e($row['status']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<p class="muted">Total: <?= count($rows) ?> hearing(s)</p>
</body>
</html>
