<?php
/**
 * modules/issues/table.php
 * ------------------------------------------------------------------
 * Filtered/sorted/paginated issues table. Included by index.php
 * (initial render) and ajax_search.php (live AJAX refresh).
 * ------------------------------------------------------------------
 */
$pdo = db();

$search      = clean($_GET['search'] ?? '');
$statusFil   = clean($_GET['status'] ?? '');
$priorityFil = clean($_GET['priority'] ?? '');
$catFil      = (int)($_GET['category_id'] ?? 0);

$sortableColumns = [
    'title',
    'priority',
    'status',
    'created_at',
];

$sortBy = in_array(
    $_GET['sort'] ?? '',
    $sortableColumns,
    true
) ? $_GET['sort'] : 'created_at';

$sortDir = strtolower($_GET['dir'] ?? 'desc') === 'asc'
    ? 'ASC'
    : 'DESC';

$where  = [];
$params = [];

if ($search !== '') {
    $where[] = '(
        i.reference_number LIKE :search1
        OR i.title LIKE :search2
        OR i.description LIKE :search3
        OR o.name LIKE :search4
    )';

    $term = '%' . $search . '%';

    $params[':search1'] = $term;
    $params[':search2'] = $term;
    $params[':search3'] = $term;
    $params[':search4'] = $term;
}

if ($statusFil !== '') {
    $where[] = 'i.status = :status';
    $params[':status'] = $statusFil;
}

if ($priorityFil !== '') {
    $where[] = 'i.priority = :priority';
    $params[':priority'] = $priorityFil;
}

if ($catFil > 0) {
    $where[] = 'i.category_id = :category_id';
    $params[':category_id'] = $catFil;
}

$whereSql = $where
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

$fromSql = "
    FROM hearing_issues i
    LEFT JOIN hearing_issue_categories ic
        ON ic.id = i.category_id
    LEFT JOIN hearings h
        ON h.id = i.hearing_id
    LEFT JOIN offices o
        ON o.id = i.assigned_office_id
";

$countStmt = $pdo->prepare(
    "SELECT COUNT(*)
     $fromSql
     $whereSql"
);

$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$pageInfo = paginate($totalRows);

$sql = "
    SELECT
        i.*,
        ic.name AS category_name,
        h.title AS hearing_title,
        o.name AS assigned_office,
        (
            SELECT COUNT(*)
            FROM hearing_issue_history ih
            WHERE ih.issue_id = i.id
        ) AS note_count
    $fromSql
    $whereSql
    ORDER BY i.$sortBy $sortDir
    LIMIT {$pageInfo['perPage']}
    OFFSET {$pageInfo['offset']}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Priority Colors & Icons
$priorityColors = [
    'Low' => 'low',
    'Medium' => 'medium',
    'High' => 'high',
    'Critical' => 'critical',
];

$priorityIcons = [
    'Low' => 'bi-arrow-down-circle',
    'Medium' => 'bi-dash-circle',
    'High' => 'bi-arrow-up-circle',
    'Critical' => 'bi-exclamation-triangle-fill',
];
?>
<style>
    /* ============================================================
       ISSUES TABLE - Coastal Blue Professional
       ============================================================ */
    .issues-table-wrap {
        background: #ffffff;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 2px 20px rgba(10, 22, 40, 0.05);
        border: 1px solid rgba(10, 22, 40, 0.04);
    }

    .issues-table-wrap .table {
        margin-bottom: 0;
    }

    .issues-table-wrap .table thead th {
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

    .issues-table-wrap .table thead th i {
        color: #F5C842;
        margin-right: 0.4rem;
        font-size: 0.85rem;
    }

    .issues-table-wrap .table thead th a.sort-link {
        color: #ffffff !important;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        transition: color 0.3s ease;
    }

    .issues-table-wrap .table thead th a.sort-link:hover {
        color: #F5C842 !important;
    }

    .issues-table-wrap .table thead th .sort-icon {
        font-size: 0.6rem;
        opacity: 0.6;
    }

    .issues-table-wrap .table tbody td {
        padding: 0.85rem 1.25rem;
        vertical-align: middle;
        border-bottom: 1px solid #E2E8F0;
        font-size: 0.9rem;
        color: #0F2137;
        transition: background 0.2s ease;
    }

    .issues-table-wrap .table tbody tr {
        transition: all 0.2s ease;
    }

    .issues-table-wrap .table tbody tr:hover {
        background: #F8F5FF;
    }

    .issues-table-wrap .table tbody tr:last-child td {
        border-bottom: none;
    }

    /* Issue Title */
    .issues-table-wrap .issue-title {
        font-weight: 600;
        color: #0F2137;
        font-size: 0.9rem;
        text-decoration: none;
        transition: color 0.3s ease;
    }

    .issues-table-wrap .issue-title:hover {
        color: #F5C842;
    }

    .issues-table-wrap .issue-title i {
        color: #F5C842;
        margin-right: 0.3rem;
        font-size: 0.75rem;
    }

    .issues-table-wrap .issue-hearing {
        color: #94A3B8;
        font-size: 0.7rem;
    }

    .issues-table-wrap .issue-hearing i {
        color: #F5C842;
        margin-right: 0.2rem;
    }

    /* Category */
    .issues-table-wrap .category-badge {
        display: inline-block;
        background: #EDE9FE;
        color: #5B21B6;
        padding: 0.2rem 0.6rem;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 500;
    }

    /* Assigned Office */
    .issues-table-wrap .assigned-office {
        display: inline-block;
        background: #F1F5F9;
        color: #475569;
        padding: 0.2rem 0.6rem;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 500;
    }

    .issues-table-wrap .assigned-office i {
        color: #F5C842;
        margin-right: 0.2rem;
        font-size: 0.6rem;
    }

    .issues-table-wrap .assigned-office .unassigned {
        color: #94A3B8;
        font-style: italic;
        font-weight: 400;
    }

    /* Priority Badges */
    .issues-table-wrap .priority-badge {
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

    .issues-table-wrap .priority-badge i {
        font-size: 0.7rem;
    }

    .priority-badge.low { background: #D1FAE5; color: #065F46; }
    .priority-badge.low i { color: #10B981; }

    .priority-badge.medium { background: #FEF3C7; color: #92400E; }
    .priority-badge.medium i { color: #F59E0B; }

    .priority-badge.high { background: #FEE2E2; color: #991B1B; }
    .priority-badge.high i { color: #EF4444; }

    .priority-badge.critical { background: #FEE2E2; color: #991B1B; border: 1px solid #EF4444; }
    .priority-badge.critical i { color: #EF4444; }

    /* Status Badges */
    .issues-table-wrap .status-badge {
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

    .issues-table-wrap .status-badge i {
        font-size: 0.7rem;
    }

    .status-badge.open { background: #FEE2E2; color: #991B1B; }
    .status-badge.open i { color: #EF4444; }

    .status-badge.in-progress { background: #DBEAFE; color: #1E40AF; }
    .status-badge.in-progress i { color: #3B82F6; }

    .status-badge.resolved { background: #D1FAE5; color: #065F46; }
    .status-badge.resolved i { color: #10B981; }

    .status-badge.closed { background: #F1F5F9; color: #475569; }
    .status-badge.closed i { color: #94A3B8; }

    /* Created At */
    .issues-table-wrap .created-at {
        color: #64748B;
        font-size: 0.8rem;
        white-space: nowrap;
    }

    .issues-table-wrap .created-at i {
        color: #F5C842;
        margin-right: 0.3rem;
        font-size: 0.7rem;
    }

    /* Action Buttons */
    .issues-table-wrap .btn-action {
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

    .issues-table-wrap .btn-action:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    }

    .issues-table-wrap .btn-action.view:hover {
        background: #2C5282;
        color: white;
        border-color: #2C5282;
    }

    .issues-table-wrap .btn-action.edit:hover {
        background: #F5C842;
        color: #0A1628;
        border-color: #F5C842;
    }

    .issues-table-wrap .btn-action.delete:hover {
        background: #F43F5E;
        color: white;
        border-color: #F43F5E;
    }

    .issues-table-wrap .btn-group {
        gap: 0.25rem;
    }

    /* Table Footer */
    .issues-table-wrap .table-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 1.25rem;
        border-top: 1px solid #E2E8F0;
        background: #F8FAFC;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .issues-table-wrap .table-footer .info-text {
        color: #64748B;
        font-size: 0.8rem;
    }

    .issues-table-wrap .table-footer .info-text strong {
        color: #0F2137;
    }

    .issues-table-wrap .pagination {
        margin: 0;
        gap: 0.2rem;
    }

    .issues-table-wrap .pagination .page-link {
        color: #0F2137;
        border: 1px solid #E2E8F0;
        border-radius: 8px;
        padding: 0.35rem 0.75rem;
        font-size: 0.8rem;
        font-weight: 500;
        transition: all 0.3s ease;
        background: transparent;
    }

    .issues-table-wrap .pagination .page-link:hover {
        background: #F5C842;
        color: #0A1628;
        border-color: #F5C842;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(245, 200, 66, 0.2);
    }

    .issues-table-wrap .pagination .page-item.active .page-link {
        background: linear-gradient(135deg, #0A1628 0%, #1A3A5C 100%);
        border-color: #F5C842;
        color: #ffffff;
        box-shadow: 0 4px 15px rgba(10, 22, 40, 0.15);
    }

    .issues-table-wrap .pagination .page-item.disabled .page-link {
        color: #94A3B8;
        cursor: not-allowed;
        opacity: 0.5;
    }

    /* Empty State */
    .issues-table-wrap .empty-state {
        text-align: center;
        padding: 3rem 1.5rem;
    }

    .issues-table-wrap .empty-state i {
        font-size: 3rem;
        color: #F5C842;
        opacity: 0.3;
        display: block;
        margin-bottom: 0.75rem;
    }

    .issues-table-wrap .empty-state h6 {
        color: #0F2137;
        font-weight: 600;
        margin-bottom: 0.25rem;
    }

    .issues-table-wrap .empty-state p {
        color: #94A3B8;
        font-size: 0.85rem;
        margin: 0;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .issues-table-wrap .table thead th,
        .issues-table-wrap .table tbody td {
            padding: 0.6rem 0.8rem;
            font-size: 0.8rem;
        }

        .issues-table-wrap .issue-title {
            font-size: 0.8rem;
        }

        .issues-table-wrap .btn-action {
            width: 28px;
            height: 28px;
            font-size: 0.7rem;
        }

        .issues-table-wrap .table-footer {
            flex-direction: column;
            align-items: flex-start;
            padding: 0.6rem 0.8rem;
        }

        .issues-table-wrap .table-footer .pagination {
            width: 100%;
            justify-content: center;
        }

        .issues-table-wrap .priority-badge,
        .issues-table-wrap .status-badge {
            font-size: 0.6rem;
            padding: 0.2rem 0.5rem;
        }

        .issues-table-wrap .category-badge,
        .issues-table-wrap .assigned-office {
            font-size: 0.6rem;
            padding: 0.15rem 0.4rem;
        }
    }

    @media (max-width: 576px) {
        .issues-table-wrap .table thead th,
        .issues-table-wrap .table tbody td {
            padding: 0.4rem 0.5rem;
            font-size: 0.7rem;
        }

        .issues-table-wrap .issue-title {
            font-size: 0.7rem;
        }

        .issues-table-wrap .issue-hearing {
            font-size: 0.6rem;
        }

        .issues-table-wrap .btn-action {
            width: 24px;
            height: 24px;
            font-size: 0.6rem;
        }

        .issues-table-wrap .priority-badge,
        .issues-table-wrap .status-badge {
            font-size: 0.5rem;
            padding: 0.15rem 0.4rem;
            gap: 0.2rem;
        }

        .issues-table-wrap .priority-badge i,
        .issues-table-wrap .status-badge i {
            font-size: 0.5rem;
        }

        .issues-table-wrap .category-badge,
        .issues-table-wrap .assigned-office {
            font-size: 0.55rem;
            padding: 0.1rem 0.3rem;
        }

        .issues-table-wrap .created-at {
            font-size: 0.6rem;
        }

        .issues-table-wrap .table-footer .info-text {
            font-size: 0.65rem;
        }

        .issues-table-wrap .pagination .page-link {
            font-size: 0.6rem;
            padding: 0.15rem 0.4rem;
        }
    }
</style>

<div class="issues-table-wrap">
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
                    <th><i class="bi bi-tags"></i> Category</th>
                    <th><i class="bi bi-building"></i> Assigned Office</th>
                    <th>
                        <a class="sort-link" data-sort="priority">
                            <i class="bi bi-flag"></i> Priority
                            <?php if ($sortBy === 'priority'): ?>
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
                    <th>
                        <a class="sort-link" data-sort="created_at">
                            <i class="bi bi-clock"></i> Logged
                            <?php if ($sortBy === 'created_at'): ?>
                                <span class="sort-icon"><?= $sortDir === 'ASC' ? '↑' : '↓' ?></span>
                            <?php endif; ?>
                        </a>
                    </th>
                    <th class="text-end no-print"><i class="bi bi-tools"></i> Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                <i class="bi bi-exclamation-triangle"></i>
                                <h6>No Issues Found</h6>
                                <p>Try adjusting your filters or search criteria.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): 
                    $priorityClass = $priorityColors[$row['priority']] ?? 'medium';
                    $priorityIcon = $priorityIcons[$row['priority']] ?? 'bi-dash-circle';
                    $statusLower = strtolower($row['status']);
                ?>
                    <tr>
                        <td>
                            <a href="view.php?id=<?= (int)$row['id'] ?>" class="issue-title">
                                <i class="bi bi-megaphone"></i> <?= e($row['title']) ?>
                            </a>
                            <?php if ($row['hearing_title']): ?>
                                <div class="issue-hearing">
                                    <i class="bi bi-calendar-event"></i> Hearing: <?= e($row['hearing_title']) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['category_name']): ?>
                                <span class="category-badge"><?= e($row['category_name']) ?></span>
                            <?php else: ?>
                                <span class="text-muted" style="font-size: 0.8rem;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['assigned_office']): ?>
                                <span class="assigned-office">
                                    <i class="bi bi-building"></i> <?= e($row['assigned_office']) ?>
                                </span>
                            <?php else: ?>
                                <span class="assigned-office">
                                    <span class="unassigned">Unassigned</span>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="priority-badge <?= e($priorityClass) ?>">
                                <i class="bi <?= e($priorityIcon) ?>"></i>
                                <?= e($row['priority']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="status-badge <?= e($statusLower) ?>">
                                <i class="bi <?= match($row['status']) {
                                    'Open' => 'bi-exclamation-circle',
                                    'In Progress' => 'bi-play-circle',
                                    'Resolved' => 'bi-check-circle',
                                    'Closed' => 'bi-check-circle',
                                    default => 'bi-circle'
                                } ?>"></i>
                                <?= e($row['status']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="created-at">
                                <i class="bi bi-calendar3"></i> <?= formatDate($row['created_at']) ?>
                            </span>
                        </td>
                        <td class="text-end no-print">
                            <div class="btn-group">
                                <a href="view.php?id=<?= (int)$row['id'] ?>" class="btn-action view" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php if (canManage()): ?>
                                    <button type="button" class="btn-action edit btn-edit-issue" 
                                            data-id="<?= (int)$row['id'] ?>" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn-action delete" 
                                            title="Delete"
                                            data-confirm-delete="issue &quot;<?= e($row['title']) ?>&quot;"
                                            data-delete-url="<?= e(APP_URL) ?>/modules/issues/ajax_delete.php?id=<?= (int)$row['id'] ?>">
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
            of <strong><?= $totalRows ?></strong> issue(s)
        </span>
        <?= renderPagination($pageInfo, APP_URL . '/modules/issues/index.php') ?>
    </div>
</div>
