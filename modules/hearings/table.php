<?php
/**
 * modules/hearings/table.php
 * ------------------------------------------------------------------
 * Builds the filtered/sorted/paginated hearings query and renders the
 * <tbody> rows + pagination nav. Included by both index.php (initial
 * page load) and ajax_search.php (live AJAX search/filter/sort/page).
 * Expects includes/auth.php to already be loaded by the caller.
 * ------------------------------------------------------------------
 */

$pdo = db();

/* ---- Read filters from GET ---- */
$search      = clean($_GET['search'] ?? '');
$statusFil   = clean($_GET['status'] ?? '');
$typeFil     = (int)($_GET['hearing_type_id'] ?? 0);
$committeeFil = (int)($_GET['committee_id'] ?? 0);
$dateFrom    = clean($_GET['date_from'] ?? '');
$dateTo      = clean($_GET['date_to'] ?? '');

$sortableColumns = ['title', 'hearing_date', 'hearing_time', 'status', 'venue'];
$sortBy  = in_array($_GET['sort'] ?? '', $sortableColumns, true) ? $_GET['sort'] : 'hearing_date';
$sortDir = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

$where  = [];
$params = [];

if ($search !== '') {
    $where[] = '(h.title LIKE :search1 OR h.venue LIKE :search2 OR h.description LIKE :search3)';
    $params[':search1'] = $params[':search2'] = $params[':search3'] = '%' . $search . '%';
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
if ($dateFrom !== '') {
    $where[] = 'h.hearing_date >= :date_from';
    $params[':date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'h.hearing_date <= :date_to';
    $params[':date_to'] = $dateTo;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ---- Count total for pagination ---- */
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM hearings h $whereSql");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$pageInfo = paginate($totalRows);

/* ---- Fetch page of rows ---- */
$sql = "SELECT h.*, ht.name AS type_name, c.name AS committee_name,
               (SELECT COUNT(*) FROM registrations r WHERE r.hearing_id = h.id) AS registration_count,
               (SELECT COUNT(*) FROM hearing_documents d WHERE d.hearing_id = h.id) AS document_count
        FROM hearings h
        LEFT JOIN hearing_types ht ON ht.id = h.hearing_type_id
        LEFT JOIN committees c ON c.id = h.committee_id
        $whereSql
        ORDER BY h.$sortBy $sortDir
        LIMIT {$pageInfo['perPage']} OFFSET {$pageInfo['offset']}";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Status colors for badges
$statusColors = [
    'Upcoming' => 'upcoming',
    'Ongoing' => 'ongoing',
    'Completed' => 'completed',
    'Cancelled' => 'cancelled',
];

$statusIcons = [
    'Upcoming' => 'bi-calendar-event',
    'Ongoing' => 'bi-play-circle',
    'Completed' => 'bi-check-circle',
    'Cancelled' => 'bi-x-circle',
];
?>
<style>
    /* ============================================================
       HEARINGS TABLE - Coastal Blue Professional
       ============================================================ */
    .hearings-table-wrap {
        background: #ffffff;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 2px 20px rgba(10, 22, 40, 0.05);
        border: 1px solid rgba(10, 22, 40, 0.04);
    }

    .hearings-table-wrap .table {
        margin-bottom: 0;
    }

    .hearings-table-wrap .table thead th {
        background: linear-gradient(135deg, #0A1628 0%, #1A3A5C 100%);
        color: #ffffff;
        border-bottom: 3px solid #F5C842;
        font-weight: 600;
        padding: 0.85rem 1.25rem;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        border-color: transparent;
        position: sticky;
        top: 0;
        z-index: 10;
    }

    .hearings-table-wrap .table thead th i {
        color: #F5C842;
        margin-right: 0.4rem;
        font-size: 0.85rem;
    }

    .hearings-table-wrap .table thead th a.sort-link {
        color: #ffffff !important;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        transition: color 0.3s ease;
    }

    .hearings-table-wrap .table thead th a.sort-link:hover {
        color: #F5C842 !important;
    }

    .hearings-table-wrap .table thead th .sort-icon {
        font-size: 0.6rem;
        opacity: 0.6;
    }

    .hearings-table-wrap .table tbody td {
        padding: 0.85rem 1.25rem;
        vertical-align: middle;
        border-bottom: 1px solid #E2E8F0;
        font-size: 0.9rem;
        color: #0F2137;
        transition: background 0.2s ease;
    }

    .hearings-table-wrap .table tbody tr {
        transition: all 0.2s ease;
    }

    .hearings-table-wrap .table tbody tr:hover {
        background: #F8F5FF;
    }

    .hearings-table-wrap .table tbody tr:last-child td {
        border-bottom: none;
    }

    /* Hearing Title */
    .hearings-table-wrap .hearing-title {
        font-weight: 600;
        color: #0F2137;
        font-size: 0.9rem;
        text-decoration: none;
        transition: color 0.3s ease;
    }

    .hearings-table-wrap .hearing-title:hover {
        color: #F5C842;
    }

    .hearings-table-wrap .hearing-title i {
        color: #F5C842;
        margin-right: 0.3rem;
        font-size: 0.75rem;
    }

    .hearings-table-wrap .registration-count {
        color: #94A3B8;
        font-size: 0.7rem;
    }

    .hearings-table-wrap .registration-count i {
        color: #F5C842;
        margin-right: 0.2rem;
    }

    /* Type & Committee */
    .hearings-table-wrap .type-badge {
        display: inline-block;
        background: #EDE9FE;
        color: #5B21B6;
        padding: 0.2rem 0.6rem;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 500;
    }

    .hearings-table-wrap .committee-badge {
        display: inline-block;
        background: #F1F5F9;
        color: #475569;
        padding: 0.2rem 0.6rem;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 500;
    }

    .hearings-table-wrap .committee-badge i {
        color: #F5C842;
        margin-right: 0.2rem;
        font-size: 0.6rem;
    }

    /* Date & Time */
    .hearings-table-wrap .hearing-date {
        font-weight: 500;
        color: #0F2137;
        font-size: 0.85rem;
    }

    .hearings-table-wrap .hearing-date i {
        color: #F5C842;
        margin-right: 0.3rem;
        font-size: 0.7rem;
    }

    .hearings-table-wrap .hearing-time {
        color: #94A3B8;
        font-size: 0.7rem;
    }

    .hearings-table-wrap .hearing-time i {
        color: #F5C842;
        margin-right: 0.2rem;
        font-size: 0.6rem;
    }

    /* Venue */
    .hearings-table-wrap .venue-text {
        color: #475569;
        font-size: 0.85rem;
    }

    .hearings-table-wrap .venue-text i {
        color: #F5C842;
        margin-right: 0.3rem;
        font-size: 0.7rem;
    }

    .hearings-table-wrap .venue-text .no-venue {
        color: #94A3B8;
        font-style: italic;
        font-size: 0.8rem;
    }

    /* Status Badges */
    .hearings-table-wrap .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.3rem 0.8rem;
        border-radius: 20px;
        font-weight: 600;
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        border: none;
        transition: all 0.3s ease;
    }

    .hearings-table-wrap .status-badge i {
        font-size: 0.7rem;
    }

    .status-badge.upcoming { background: #DBEAFE; color: #1E40AF; }
    .status-badge.upcoming i { color: #3B82F6; }

    .status-badge.ongoing { background: #D1FAE5; color: #065F46; }
    .status-badge.ongoing i { color: #10B981; }

    .status-badge.completed { background: #F1F5F9; color: #475569; }
    .status-badge.completed i { color: #94A3B8; }

    .status-badge.cancelled { background: #FEE2E2; color: #991B1B; }
    .status-badge.cancelled i { color: #EF4444; }

    /* Document Count */
    .hearings-table-wrap .doc-count {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 32px;
        height: 32px;
        background: #F1F5F9;
        border-radius: 8px;
        font-weight: 700;
        font-size: 0.85rem;
        color: #0F2137;
        border: 1px solid #E2E8F0;
        transition: all 0.3s ease;
    }

    .hearings-table-wrap .doc-count:hover {
        background: #F5C842;
        border-color: #F5C842;
        color: #0A1628;
        transform: scale(1.05);
    }

    .hearings-table-wrap .doc-count i {
        font-size: 0.7rem;
        color: #F5C842;
        margin-right: 0.2rem;
    }

    .hearings-table-wrap .doc-count:hover i {
        color: #0A1628;
    }

    /* Action Buttons */
    .hearings-table-wrap .btn-action {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        border: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s ease;
        font-size: 0.85rem;
        color: #64748B;
        background: transparent;
        border: 1px solid #E2E8F0;
    }

    .hearings-table-wrap .btn-action:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    }

    .hearings-table-wrap .btn-action.view:hover {
        background: #2C5282;
        color: white;
        border-color: #2C5282;
    }

    .hearings-table-wrap .btn-action.edit:hover {
        background: #F5C842;
        color: #0A1628;
        border-color: #F5C842;
    }

    .hearings-table-wrap .btn-action.delete:hover {
        background: #F43F5E;
        color: white;
        border-color: #F43F5E;
    }

    .hearings-table-wrap .btn-group {
        gap: 0.25rem;
    }

    /* Table Footer */
    .hearings-table-wrap .table-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 1.25rem;
        border-top: 1px solid #E2E8F0;
        background: #F8FAFC;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .hearings-table-wrap .table-footer .info-text {
        color: #64748B;
        font-size: 0.8rem;
    }

    .hearings-table-wrap .table-footer .info-text strong {
        color: #0F2137;
    }

    .hearings-table-wrap .pagination {
        margin: 0;
        gap: 0.2rem;
    }

    .hearings-table-wrap .pagination .page-link {
        color: #0F2137;
        border: 1px solid #E2E8F0;
        border-radius: 8px;
        padding: 0.35rem 0.75rem;
        font-size: 0.8rem;
        font-weight: 500;
        transition: all 0.3s ease;
        background: transparent;
    }

    .hearings-table-wrap .pagination .page-link:hover {
        background: #F5C842;
        color: #0A1628;
        border-color: #F5C842;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(245, 200, 66, 0.2);
    }

    .hearings-table-wrap .pagination .page-item.active .page-link {
        background: linear-gradient(135deg, #0A1628 0%, #1A3A5C 100%);
        border-color: #F5C842;
        color: #ffffff;
        box-shadow: 0 4px 15px rgba(10, 22, 40, 0.15);
    }

    .hearings-table-wrap .pagination .page-item.disabled .page-link {
        color: #94A3B8;
        cursor: not-allowed;
        opacity: 0.5;
    }

    /* Empty State */
    .hearings-table-wrap .empty-state {
        text-align: center;
        padding: 3rem 1.5rem;
    }

    .hearings-table-wrap .empty-state i {
        font-size: 3rem;
        color: #F5C842;
        opacity: 0.3;
        display: block;
        margin-bottom: 0.75rem;
    }

    .hearings-table-wrap .empty-state h6 {
        color: #0F2137;
        font-weight: 600;
        margin-bottom: 0.25rem;
    }

    .hearings-table-wrap .empty-state p {
        color: #94A3B8;
        font-size: 0.85rem;
        margin: 0;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .hearings-table-wrap .table thead th,
        .hearings-table-wrap .table tbody td {
            padding: 0.6rem 0.8rem;
            font-size: 0.8rem;
        }

        .hearings-table-wrap .hearing-title {
            font-size: 0.8rem;
        }

        .hearings-table-wrap .btn-action {
            width: 28px;
            height: 28px;
            font-size: 0.7rem;
        }

        .hearings-table-wrap .table-footer {
            flex-direction: column;
            align-items: flex-start;
            padding: 0.6rem 0.8rem;
        }

        .hearings-table-wrap .table-footer .pagination {
            width: 100%;
            justify-content: center;
        }

        .hearings-table-wrap .status-badge {
            font-size: 0.6rem;
            padding: 0.2rem 0.5rem;
        }

        .hearings-table-wrap .type-badge,
        .hearings-table-wrap .committee-badge {
            font-size: 0.6rem;
            padding: 0.15rem 0.4rem;
        }

        .hearings-table-wrap .doc-count {
            min-width: 28px;
            height: 28px;
            font-size: 0.7rem;
        }

        .hearings-table-wrap .hearing-date {
            font-size: 0.75rem;
        }

        .hearings-table-wrap .venue-text {
            font-size: 0.75rem;
        }
    }

    @media (max-width: 576px) {
        .hearings-table-wrap .table thead th,
        .hearings-table-wrap .table tbody td {
            padding: 0.4rem 0.5rem;
            font-size: 0.7rem;
        }

        .hearings-table-wrap .hearing-title {
            font-size: 0.7rem;
        }

        .hearings-table-wrap .registration-count {
            font-size: 0.6rem;
        }

        .hearings-table-wrap .btn-action {
            width: 24px;
            height: 24px;
            font-size: 0.6rem;
        }

        .hearings-table-wrap .status-badge {
            font-size: 0.5rem;
            padding: 0.15rem 0.4rem;
            gap: 0.2rem;
        }

        .hearings-table-wrap .status-badge i {
            font-size: 0.5rem;
        }

        .hearings-table-wrap .type-badge,
        .hearings-table-wrap .committee-badge {
            font-size: 0.55rem;
            padding: 0.1rem 0.3rem;
        }

        .hearings-table-wrap .doc-count {
            min-width: 24px;
            height: 24px;
            font-size: 0.6rem;
        }

        .hearings-table-wrap .doc-count i {
            font-size: 0.5rem;
        }

        .hearings-table-wrap .hearing-date {
            font-size: 0.65rem;
        }

        .hearings-table-wrap .hearing-time {
            font-size: 0.55rem;
        }

        .hearings-table-wrap .venue-text {
            font-size: 0.65rem;
        }

        .hearings-table-wrap .table-footer .info-text {
            font-size: 0.65rem;
        }

        .hearings-table-wrap .pagination .page-link {
            font-size: 0.6rem;
            padding: 0.15rem 0.4rem;
        }
    }
</style>

<div class="hearings-table-wrap">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>
                        <a class="sort-link" data-sort="title">
                            <i class="bi bi-megaphone"></i> Title
                            <?php if ($sortBy === 'title'): ?>
                                <span class="sort-icon"><?= $sortDir === 'ASC' ? '↑' : '↓' ?></span>
                            <?php endif; ?>
                        </a>
                    </th>
                    <th><i class="bi bi-tag"></i> Type</th>
                    <th><i class="bi bi-people"></i> Committee</th>
                    <th>
                        <a class="sort-link" data-sort="hearing_date">
                            <i class="bi bi-calendar3"></i> Date / Time
                            <?php if ($sortBy === 'hearing_date'): ?>
                                <span class="sort-icon"><?= $sortDir === 'ASC' ? '↑' : '↓' ?></span>
                            <?php endif; ?>
                        </a>
                    </th>
                    <th>
                        <a class="sort-link" data-sort="venue">
                            <i class="bi bi-geo-alt"></i> Venue
                            <?php if ($sortBy === 'venue'): ?>
                                <span class="sort-icon"><?= $sortDir === 'ASC' ? '↑' : '↓' ?></span>
                            <?php endif; ?>
                        </a>
                    </th>
                    <th>
                        <a class="sort-link" data-sort="status">
                            <i class="bi bi-circle"></i> Status
                            <?php if ($sortBy === 'status'): ?>
                                <span class="sort-icon"><?= $sortDir === 'ASC' ? '↑' : '↓' ?></span>
                            <?php endif; ?>
                        </a>
                    </th>
                    <th class="text-center"><i class="bi bi-file-earmark"></i> Docs</th>
                    <th class="text-end no-print"><i class="bi bi-tools"></i> Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                <i class="bi bi-calendar-event"></i>
                                <h6>No Hearings Found</h6>
                                <p>Try adjusting your filters or search criteria.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): 
                    $statusClass = $statusColors[$row['status']] ?? 'secondary';
                    $statusIcon = $statusIcons[$row['status']] ?? 'bi-circle';
                ?>
                    <tr>
                        <td>
                            <a href="<?= e(APP_URL) ?>/modules/hearings/view.php?id=<?= (int)$row['id'] ?>" class="hearing-title">
                                <i class="bi bi-megaphone"></i> <?= e($row['title']) ?>
                            </a>
                            <div class="registration-count">
                                <i class="bi bi-people"></i> <?= (int)$row['registration_count'] ?> registered
                            </div>
                        </td>
                        <td>
                            <?php if ($row['type_name']): ?>
                                <span class="type-badge"><?= e($row['type_name']) ?></span>
                            <?php else: ?>
                                <span class="text-muted" style="font-size: 0.8rem;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['committee_name']): ?>
                                <span class="committee-badge"><i class="bi bi-building"></i> <?= e($row['committee_name']) ?></span>
                            <?php else: ?>
                                <span class="text-muted" style="font-size: 0.8rem;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="hearing-date">
                                <i class="bi bi-calendar3"></i> <?= formatDate($row['hearing_date']) ?>
                            </div>
                            <div class="hearing-time">
                                <i class="bi bi-clock"></i> <?= formatTime($row['hearing_time']) ?>
                            </div>
                        </td>
                        <td>
                            <span class="venue-text">
                                <?php if ($row['venue']): ?>
                                    <i class="bi bi-geo-alt"></i> <?= e($row['venue']) ?>
                                <?php else: ?>
                                    <span class="no-venue">—</span>
                                <?php endif; ?>
                            </span>
                        </td>
                        <td>
                            <span class="status-badge <?= e($statusClass) ?>">
                                <i class="bi <?= e($statusIcon) ?>"></i>
                                <?= e($row['status']) ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <span class="doc-count">
                                <i class="bi bi-file-earmark"></i> <?= (int)$row['document_count'] ?>
                            </span>
                        </td>
                        <td class="text-end no-print">
                            <div class="btn-group">
                                <a href="<?= e(APP_URL) ?>/modules/hearings/view.php?id=<?= (int)$row['id'] ?>"
                                   class="btn-action view" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php if (canManage()): ?>
                                    <button type="button" class="btn-action edit btn-edit-hearing"
                                            data-id="<?= (int)$row['id'] ?>" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn-action delete" 
                                            title="Delete"
                                            data-confirm-delete="hearing &quot;<?= e($row['title']) ?>&quot;"
                                            data-delete-url="<?= e(APP_URL) ?>/modules/hearings/ajax_delete.php?id=<?= (int)$row['id'] ?>">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="table-footer">
        <span class="info-text">
            Showing <strong><?= $totalRows === 0 ? 0 : $pageInfo['offset'] + 1 ?></strong> – 
            <strong><?= min($pageInfo['offset'] + $pageInfo['perPage'], $totalRows) ?></strong> 
            of <strong><?= $totalRows ?></strong> hearings
        </span>
        <?= renderPagination($pageInfo, APP_URL . '/modules/hearings/index.php') ?>
    </div>
</div>