<?php
/**
 * modules/actions/table.php
 * ------------------------------------------------------------------
 * Filtered/sorted/paginated actions table. Included by index.php
 * (initial render) and ajax_search.php (live AJAX refresh). Current
 * assigned office is derived as the most recent action_assignments
 * row (the `actions` table itself has no assigned_office column).
 * ------------------------------------------------------------------
 */

$pdo = db();

$search    = clean($_GET['search'] ?? '');
$statusFil = clean($_GET['status'] ?? '');
$issueFil  = (int)($_GET['issue_id'] ?? 0);

$sortableColumns = ['title', 'status', 'deadline', 'created_at'];
$sortBy  = in_array($_GET['sort'] ?? '', $sortableColumns, true) ? $_GET['sort'] : 'created_at';
$sortDir = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(a.title LIKE :search1 OR a.description LIKE :search2)';
    $params[':search1'] = $params[':search2'] = '%' . $search . '%';
}
if ($statusFil !== '') { $where[] = 'a.status = :status'; $params[':status'] = $statusFil; }
if ($issueFil > 0) { $where[] = 'a.issue_id = :issue_id'; $params[':issue_id'] = $issueFil; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM hearing_actions a $whereSql");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$pageInfo = paginate($totalRows);

$sql = "SELECT a.*, i.title AS issue_title,
               COALESCE(o.name, au.full_name) AS current_office,
               (SELECT COUNT(*) FROM hearing_action_documents d WHERE d.action_id = a.id) AS document_count,
               (SELECT COUNT(*) FROM hearing_action_updates u WHERE u.action_id = a.id) AS update_count
        FROM hearing_actions a
        LEFT JOIN hearing_issues i ON i.id = a.issue_id
        LEFT JOIN offices o ON o.id = a.assigned_office_id
        LEFT JOIN users au ON au.id = a.assigned_user_id
        $whereSql
        ORDER BY a.$sortBy $sortDir
        LIMIT {$pageInfo['perPage']} OFFSET {$pageInfo['offset']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$today = date('Y-m-d');
?>
<style>
    /* ============================================================
       ACTIONS TABLE - Coastal Blue Professional
       ============================================================ */
    .actions-table-wrap {
        background: #ffffff;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 2px 20px rgba(10, 22, 40, 0.05);
        border: 1px solid rgba(10, 22, 40, 0.04);
    }

    .actions-table-wrap .table {
        margin-bottom: 0;
    }

    .actions-table-wrap .table thead th {
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

    .actions-table-wrap .table thead th i {
        color: #F5C842;
        margin-right: 0.4rem;
        font-size: 0.85rem;
    }

    .actions-table-wrap .table thead th a.sort-link {
        color: #ffffff !important;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        transition: color 0.3s ease;
    }

    .actions-table-wrap .table thead th a.sort-link:hover {
        color: #F5C842 !important;
    }

    .actions-table-wrap .table thead th .sort-icon {
        font-size: 0.6rem;
        opacity: 0.6;
    }

    .actions-table-wrap .table tbody td {
        padding: 0.85rem 1.25rem;
        vertical-align: middle;
        border-bottom: 1px solid #E2E8F0;
        font-size: 0.9rem;
        color: #0F2137;
        transition: background 0.2s ease;
    }

    .actions-table-wrap .table tbody tr {
        transition: all 0.2s ease;
    }

    .actions-table-wrap .table tbody tr:hover {
        background: #F8F5FF;
    }

    .actions-table-wrap .table tbody tr:last-child td {
        border-bottom: none;
    }

    /* Overdue Row */
    .actions-table-wrap .table tbody tr.overdue-row {
        background: #FEF2F2;
        border-left: 3px solid #EF4444;
    }

    .actions-table-wrap .table tbody tr.overdue-row:hover {
        background: #FEE2E2;
    }

    /* Action Title */
    .actions-table-wrap .action-title {
        font-weight: 600;
        color: #0F2137;
        font-size: 0.9rem;
        text-decoration: none;
        transition: color 0.3s ease;
    }

    .actions-table-wrap .action-title:hover {
        color: #F5C842;
    }

    .actions-table-wrap .action-title i {
        color: #F5C842;
        margin-right: 0.3rem;
        font-size: 0.75rem;
    }

    .actions-table-wrap .action-updates {
        color: #94A3B8;
        font-size: 0.7rem;
    }

    .actions-table-wrap .action-updates i {
        color: #F5C842;
        margin-right: 0.2rem;
    }

    /* Linked Issue */
    .actions-table-wrap .linked-issue {
        color: #475569;
        font-size: 0.85rem;
    }

    .actions-table-wrap .linked-issue i {
        color: #F5C842;
        margin-right: 0.3rem;
        font-size: 0.7rem;
    }

    .actions-table-wrap .linked-issue .no-issue {
        color: #94A3B8;
        font-style: italic;
        font-size: 0.8rem;
    }

    /* Assigned Office */
    .actions-table-wrap .assigned-office {
        display: inline-block;
        background: #F1F5F9;
        color: #475569;
        padding: 0.2rem 0.6rem;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 500;
    }

    .actions-table-wrap .assigned-office i {
        color: #F5C842;
        margin-right: 0.2rem;
        font-size: 0.6rem;
    }

    .actions-table-wrap .assigned-office .unassigned {
        color: #94A3B8;
        font-style: italic;
        font-weight: 400;
    }

    /* Deadline */
    .actions-table-wrap .deadline-info {
        display: flex;
        flex-direction: column;
        gap: 0.15rem;
    }

    .actions-table-wrap .deadline-date {
        font-size: 0.8rem;
        color: #475569;
    }

    .actions-table-wrap .deadline-date i {
        color: #F5C842;
        margin-right: 0.3rem;
        font-size: 0.7rem;
    }

    .actions-table-wrap .deadline-date .no-deadline {
        color: #94A3B8;
        font-style: italic;
    }

    .actions-table-wrap .overdue-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        font-size: 0.6rem;
        font-weight: 700;
        color: #EF4444;
        background: rgba(239, 68, 68, 0.1);
        padding: 0.1rem 0.5rem;
        border-radius: 12px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        animation: pulse-overdue 2s ease-in-out infinite;
    }

    @keyframes pulse-overdue {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
    }

    .actions-table-wrap .overdue-badge i {
        font-size: 0.5rem;
    }

    /* Status Badges */
    .actions-table-wrap .status-badge {
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

    .actions-table-wrap .status-badge i {
        font-size: 0.7rem;
    }

    .status-badge.pending { background: #FEF3C7; color: #92400E; }
    .status-badge.pending i { color: #F59E0B; }

    .status-badge.in-progress, .status-badge.on-going { background: #DBEAFE; color: #1E40AF; }
    .status-badge.in-progress i, .status-badge.on-going i { color: #3B82F6; }

    .status-badge.completed { background: #D1FAE5; color: #065F46; }
    .status-badge.completed i { color: #10B981; }

    .status-badge.cancelled { background: #F1F5F9; color: #475569; }
    .status-badge.cancelled i { color: #94A3B8; }

    /* Document Count */
    .actions-table-wrap .doc-count {
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

    .actions-table-wrap .doc-count:hover {
        background: #F5C842;
        border-color: #F5C842;
        color: #0A1628;
        transform: scale(1.05);
    }

    .actions-table-wrap .doc-count i {
        font-size: 0.7rem;
        color: #F5C842;
        margin-right: 0.2rem;
    }

    .actions-table-wrap .doc-count:hover i {
        color: #0A1628;
    }

    /* Action Buttons */
    .actions-table-wrap .btn-action {
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

    .actions-table-wrap .btn-action:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    }

    .actions-table-wrap .btn-action.view:hover {
        background: #2C5282;
        color: white;
        border-color: #2C5282;
    }

    .actions-table-wrap .btn-action.edit:hover {
        background: #F5C842;
        color: #0A1628;
        border-color: #F5C842;
    }

    .actions-table-wrap .btn-action.delete:hover {
        background: #F43F5E;
        color: white;
        border-color: #F43F5E;
    }

    .actions-table-wrap .btn-group {
        gap: 0.25rem;
    }

    /* Table Footer */
    .actions-table-wrap .table-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 1.25rem;
        border-top: 1px solid #E2E8F0;
        background: #F8FAFC;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .actions-table-wrap .table-footer .info-text {
        color: #64748B;
        font-size: 0.8rem;
    }

    .actions-table-wrap .table-footer .info-text strong {
        color: #0F2137;
    }

    .actions-table-wrap .pagination {
        margin: 0;
        gap: 0.2rem;
    }

    .actions-table-wrap .pagination .page-link {
        color: #0F2137;
        border: 1px solid #E2E8F0;
        border-radius: 8px;
        padding: 0.35rem 0.75rem;
        font-size: 0.8rem;
        font-weight: 500;
        transition: all 0.3s ease;
        background: transparent;
    }

    .actions-table-wrap .pagination .page-link:hover {
        background: #F5C842;
        color: #0A1628;
        border-color: #F5C842;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(245, 200, 66, 0.2);
    }

    .actions-table-wrap .pagination .page-item.active .page-link {
        background: linear-gradient(135deg, #0A1628 0%, #1A3A5C 100%);
        border-color: #F5C842;
        color: #ffffff;
        box-shadow: 0 4px 15px rgba(10, 22, 40, 0.15);
    }

    .actions-table-wrap .pagination .page-item.disabled .page-link {
        color: #94A3B8;
        cursor: not-allowed;
        opacity: 0.5;
    }

    /* Empty State */
    .actions-table-wrap .empty-state {
        text-align: center;
        padding: 3rem 1.5rem;
    }

    .actions-table-wrap .empty-state i {
        font-size: 3rem;
        color: #F5C842;
        opacity: 0.3;
        display: block;
        margin-bottom: 0.75rem;
    }

    .actions-table-wrap .empty-state h6 {
        color: #0F2137;
        font-weight: 600;
        margin-bottom: 0.25rem;
    }

    .actions-table-wrap .empty-state p {
        color: #94A3B8;
        font-size: 0.85rem;
        margin: 0;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .actions-table-wrap .table thead th,
        .actions-table-wrap .table tbody td {
            padding: 0.6rem 0.8rem;
            font-size: 0.8rem;
        }

        .actions-table-wrap .action-title {
            font-size: 0.8rem;
        }

        .actions-table-wrap .btn-action {
            width: 28px;
            height: 28px;
            font-size: 0.7rem;
        }

        .actions-table-wrap .table-footer {
            flex-direction: column;
            align-items: flex-start;
            padding: 0.6rem 0.8rem;
        }

        .actions-table-wrap .table-footer .pagination {
            width: 100%;
            justify-content: center;
        }

        .actions-table-wrap .status-badge {
            font-size: 0.6rem;
            padding: 0.2rem 0.5rem;
        }

        .actions-table-wrap .assigned-office {
            font-size: 0.6rem;
            padding: 0.15rem 0.4rem;
        }

        .actions-table-wrap .doc-count {
            min-width: 28px;
            height: 28px;
            font-size: 0.7rem;
        }

        .actions-table-wrap .deadline-date {
            font-size: 0.7rem;
        }

        .actions-table-wrap .linked-issue {
            font-size: 0.75rem;
        }
    }

    @media (max-width: 576px) {
        .actions-table-wrap .table thead th,
        .actions-table-wrap .table tbody td {
            padding: 0.4rem 0.5rem;
            font-size: 0.7rem;
        }

        .actions-table-wrap .action-title {
            font-size: 0.7rem;
        }

        .actions-table-wrap .action-updates {
            font-size: 0.6rem;
        }

        .actions-table-wrap .btn-action {
            width: 24px;
            height: 24px;
            font-size: 0.6rem;
        }

        .actions-table-wrap .status-badge {
            font-size: 0.5rem;
            padding: 0.15rem 0.4rem;
            gap: 0.2rem;
        }

        .actions-table-wrap .status-badge i {
            font-size: 0.5rem;
        }

        .actions-table-wrap .assigned-office {
            font-size: 0.55rem;
            padding: 0.1rem 0.3rem;
        }

        .actions-table-wrap .doc-count {
            min-width: 24px;
            height: 24px;
            font-size: 0.6rem;
        }

        .actions-table-wrap .doc-count i {
            font-size: 0.5rem;
        }

        .actions-table-wrap .deadline-date {
            font-size: 0.6rem;
        }

        .actions-table-wrap .linked-issue {
            font-size: 0.65rem;
        }

        .actions-table-wrap .overdue-badge {
            font-size: 0.5rem;
            padding: 0.05rem 0.3rem;
        }

        .actions-table-wrap .table-footer .info-text {
            font-size: 0.65rem;
        }

        .actions-table-wrap .pagination .page-link {
            font-size: 0.6rem;
            padding: 0.15rem 0.4rem;
        }
    }
</style>

<div class="actions-table-wrap">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>
                        <a class="sort-link" data-sort="title">
                            <i class="bi bi-list-check"></i> Title
                            <?php if ($sortBy === 'title'): ?>
                                <span class="sort-icon"><?= $sortDir === 'ASC' ? '↑' : '↓' ?></span>
                            <?php endif; ?>
                        </a>
                    </th>
                    <th><i class="bi bi-link-45deg"></i> Linked Issue</th>
                    <th><i class="bi bi-building"></i> Assigned Office</th>
                    <th>
                        <a class="sort-link" data-sort="deadline">
                            <i class="bi bi-calendar-clock"></i> Deadline
                            <?php if ($sortBy === 'deadline'): ?>
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
                        <td colspan="7">
                            <div class="empty-state">
                                <i class="bi bi-list-check"></i>
                                <h6>No Action Records Found</h6>
                                <p>Try adjusting your filters or search criteria.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): 
                    $overdue = $row['deadline'] && $row['deadline'] < $today && !in_array($row['status'], ['Completed', 'Cancelled'], true);
                    $statusLower = strtolower($row['status']);
                ?>
                    <tr class="<?= $overdue ? 'overdue-row' : '' ?>">
                        <td>
                            <a href="view.php?id=<?= (int)$row['id'] ?>" class="action-title">
                                <i class="bi bi-list-check"></i> <?= e($row['title']) ?>
                            </a>
                            <div class="action-updates">
                                <i class="bi bi-clock-history"></i> <?= (int)$row['update_count'] ?> update(s)
                            </div>
                        </td>
                        <td>
                            <?php if ($row['issue_title']): ?>
                                <span class="linked-issue">
                                    <i class="bi bi-link-45deg"></i> <?= e($row['issue_title']) ?>
                                </span>
                            <?php else: ?>
                                <span class="linked-issue no-issue">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['current_office']): ?>
                                <span class="assigned-office">
                                    <i class="bi bi-building"></i> <?= e($row['current_office']) ?>
                                </span>
                            <?php else: ?>
                                <span class="assigned-office">
                                    <span class="unassigned">Unassigned</span>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="deadline-info">
                                <span class="deadline-date">
                                    <?php if ($row['deadline']): ?>
                                        <i class="bi bi-calendar3"></i> <?= formatDate($row['deadline']) ?>
                                    <?php else: ?>
                                        <span class="no-deadline">No deadline</span>
                                    <?php endif; ?>
                                </span>
                                <?php if ($overdue): ?>
                                    <span class="overdue-badge">
                                        <i class="bi bi-exclamation-triangle-fill"></i> Overdue
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="status-badge <?= e($statusLower) ?>">
                                <i class="bi <?= match($row['status']) {
                                    'Pending' => 'bi-clock',
                                    'In Progress', 'On Going' => 'bi-play-circle',
                                    'Completed' => 'bi-check-circle',
                                    'Cancelled' => 'bi-x-circle',
                                    default => 'bi-circle'
                                } ?>"></i>
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
                                <a href="view.php?id=<?= (int)$row['id'] ?>" class="btn-action view" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php if (canManage()): ?>
                                    <button type="button" class="btn-action edit btn-edit-action" 
                                            data-id="<?= (int)$row['id'] ?>" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn-action delete" 
                                            title="Delete"
                                            data-confirm-delete="action &quot;<?= e($row['title']) ?>&quot;"
                                            data-delete-url="<?= e(APP_URL) ?>/modules/actions/ajax_delete.php?id=<?= (int)$row['id'] ?>">
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
            of <strong><?= $totalRows ?></strong> action(s)
        </span>
        <?= renderPagination($pageInfo, APP_URL . '/modules/actions/index.php') ?>
    </div>
</div>