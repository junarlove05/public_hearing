<?php
declare(strict_types=1);

$pdo = db();

$search = clean($_GET['search'] ?? '');
$statusFil = clean($_GET['status'] ?? '');
$typeFil = (int)($_GET['hearing_type_id'] ?? 0);
$committeeFil = (int)($_GET['committee_id'] ?? 0);
$legislativeTypeFil = clean($_GET['legislative_type'] ?? '');
$dateFrom = clean($_GET['date_from'] ?? '');
$dateTo = clean($_GET['date_to'] ?? '');

$sortableColumns = [
    'reference_number',
    'title',
    'hearing_date',
    'hearing_time',
    'status',
    'venue',
];

$sortBy = in_array($_GET['sort'] ?? '', $sortableColumns, true)
    ? $_GET['sort']
    : 'hearing_date';

$sortDir = strtolower($_GET['dir'] ?? 'asc') === 'desc'
    ? 'DESC'
    : 'ASC';

$where = [];
$params = [];

if ($search !== '') {
    $where[] =
        '(h.reference_number LIKE :search_ref
          OR h.title LIKE :search_title
          OR h.venue LIKE :search_venue
          OR h.description LIKE :search_description
          OR li.reference_number LIKE :search_leg_ref
          OR li.title LIKE :search_leg_title)';

    $like = '%' . $search . '%';

    $params[':search_ref'] = $like;
    $params[':search_title'] = $like;
    $params[':search_venue'] = $like;
    $params[':search_description'] = $like;
    $params[':search_leg_ref'] = $like;
    $params[':search_leg_title'] = $like;
}

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

$whereSql = $where
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

$countStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM hearings h
     LEFT JOIN legislative_items li ON li.id = h.legislative_item_id
     LEFT JOIN legislative_item_types lit ON lit.id = li.item_type_id
     {$whereSql}"
);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$pageInfo = paginate($totalRows);

$sql = "
    SELECT
        h.*,
        ht.name AS type_name,
        c.name AS committee_name,
        li.reference_number AS legislative_reference,
        li.title AS legislative_title,
        lit.name AS legislative_type_name,
        lit.code AS legislative_type_code,
        (
            SELECT COUNT(*)
            FROM registrations r
            WHERE r.hearing_id = h.id
        ) AS registration_count,
        (
            SELECT COUNT(*)
            FROM hearing_documents d
            WHERE d.hearing_id = h.id
        ) AS document_count
    FROM hearings h
    LEFT JOIN hearing_types ht ON ht.id = h.hearing_type_id
    LEFT JOIN committees c ON c.id = h.committee_id
    LEFT JOIN legislative_items li ON li.id = h.legislative_item_id
    LEFT JOIN legislative_item_types lit ON lit.id = li.item_type_id
    {$whereSql}
    ORDER BY h.{$sortBy} {$sortDir}, h.hearing_time {$sortDir}
    LIMIT {$pageInfo['perPage']}
    OFFSET {$pageInfo['offset']}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$statusClasses = [
    'Upcoming' => 'primary',
    'Ongoing' => 'warning',
    'Completed' => 'success',
    'Cancelled' => 'danger',
];

$filterQuery = $_GET;
unset($filterQuery['page']);
?>
<div class="table-responsive">
<table class="table table-hover align-middle mb-0 hearing-schedule-table">
    <thead>
        <tr>
            <th><button type="button" class="sort-link btn btn-link p-0" data-sort="reference_number">Reference</button></th>
            <th><button type="button" class="sort-link btn btn-link p-0" data-sort="title">Hearing</button></th>
            <th>Legislative Item</th>
            <th>Committee</th>
            <th><button type="button" class="sort-link btn btn-link p-0" data-sort="hearing_date">Schedule</button></th>
            <th><button type="button" class="sort-link btn btn-link p-0" data-sort="venue">Venue / Link</button></th>
            <th>Registration</th>
            <th><button type="button" class="sort-link btn btn-link p-0" data-sort="status">Status</button></th>
            <th class="text-end no-print">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
        <tr>
            <td colspan="9">
                <div class="hearing-empty-state">
                    <i class="bi bi-calendar-x"></i>
                    <strong>No hearings found</strong>
                    <span>Try adjusting your search or filters.</span>
                </div>
            </td>
        </tr>
    <?php endif; ?>

    <?php foreach ($rows as $row): ?>
        <?php
        $registrationCount = (int)$row['registration_count'];
        $maxParticipants = (int)($row['maximum_participants'] ?? 0);
        $registrationState = hearingRegistrationState($row, $registrationCount);
        $statusClass = $statusClasses[$row['status']] ?? 'secondary';
        $scheduleEnd = '';

        if (!empty($row['end_time'])) {
            $scheduleEnd = ' - ' . formatTime($row['end_time']);
        }

        if (!empty($row['end_date']) && $row['end_date'] !== $row['hearing_date']) {
            $scheduleEnd .= ' · ends ' . formatDate($row['end_date']);
        }
        ?>
        <tr data-id="<?= (int)$row['id'] ?>" id="hearing-row-<?= (int)$row['id'] ?>">
            <td>
                <span class="hearing-ref"><?= e($row['reference_number'] ?: ('H-' . $row['id'])) ?></span>
                <div class="small text-muted"><?= e($row['type_name'] ?: 'Unspecified type') ?></div>
            </td>

            <td>
                <a
                    href="<?= e(APP_URL) ?>/modules/hearings/view.php?id=<?= (int)$row['id'] ?>"
                    class="fw-semibold text-decoration-none"
                >
                    <?= e($row['title']) ?>
                </a>

                <div class="small text-muted">
                    <?= e($row['visibility'] ?: 'Public') ?>
                    · <?= (int)$row['document_count'] ?> document(s)
                </div>
            </td>

            <td>
                <?php if (!empty($row['legislative_reference'])): ?>
                    <span class="badge text-bg-light"><?= e($row['legislative_type_name'] ?: 'Legislative Item') ?></span>
                    <div class="small fw-semibold mt-1"><?= e($row['legislative_reference']) ?></div>
                    <div class="small text-muted hearing-clamp"><?= e($row['legislative_title']) ?></div>
                <?php else: ?>
                    <span class="text-muted small">Not linked</span>
                <?php endif; ?>
            </td>

            <td><?= e($row['committee_name'] ?: '—') ?></td>

            <td>
                <div class="fw-semibold"><?= formatDate($row['hearing_date']) ?></div>
                <div class="small text-muted">
                    <?= formatTime($row['hearing_time']) ?><?= e($scheduleEnd) ?>
                </div>
            </td>

            <td>
                <div>
                    <i class="bi bi-geo-alt text-warning"></i>
                    <?= e($row['venue'] ?: 'No physical venue') ?>
                </div>

                <?php if (!empty($row['meeting_link'])): ?>
                    <a href="<?= e($row['meeting_link']) ?>" target="_blank" rel="noopener" class="small">
                        <i class="bi bi-camera-video"></i> Online meeting
                    </a>
                <?php endif; ?>
            </td>

            <td>
                <div class="fw-semibold">
                    <?= $registrationCount ?>
                    <?php if ($maxParticipants > 0): ?> / <?= $maxParticipants ?><?php endif; ?>
                </div>

                <span class="badge <?= $registrationState['open'] ? 'text-bg-success' : 'text-bg-secondary' ?>">
                    <?= e($registrationState['label']) ?>
                </span>
            </td>

            <td>
                <span class="badge text-bg-<?= e($statusClass) ?>"><?= e($row['status']) ?></span>

                <?php if ($row['status'] === 'Cancelled' && !empty($row['cancellation_reason'])): ?>
                    <div class="small text-danger mt-1 hearing-clamp"><?= e($row['cancellation_reason']) ?></div>
                <?php endif; ?>
            </td>

            <td class="text-end no-print">
                <div class="btn-group btn-group-sm">
                    <a href="<?= e(APP_URL) ?>/modules/hearings/view.php?id=<?= (int)$row['id'] ?>" class="btn btn-outline-secondary" title="View Details">
                        <i class="bi bi-eye"></i>
                    </a>

                    <?php if ($row['status'] !== 'Completed' && $row['status'] !== 'Cancelled'): ?>
                        <a href="<?= e(APP_URL) ?>/modules/attendance/index.php?hearing_id=<?= (int)$row['id'] ?>" class="btn btn-outline-primary" title="Attendance Tracking & QR Scanner">
                            <i class="bi bi-qr-code-scan"></i>
                        </a>
                    <?php endif; ?>

                    <?php if (canManage() && $row['status'] !== 'Completed'): ?>
                        <button type="button" class="btn btn-outline-primary btn-edit-hearing" data-id="<?= (int)$row['id'] ?>" title="Edit">
                            <i class="bi bi-pencil-square"></i>
                        </button>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<div class="hearing-table-footer">
    <div class="small text-muted">
        Showing
        <strong><?= $totalRows === 0 ? 0 : ($pageInfo['offset'] + 1) ?></strong>
        to
        <strong><?= min($totalRows, $pageInfo['offset'] + $pageInfo['perPage']) ?></strong>
        of
        <strong><?= $totalRows ?></strong>
        hearing(s)
    </div>

    <?= renderPagination(
    $pageInfo,
    APP_URL . '/modules/hearings/index.php'
) ?>
</div>
