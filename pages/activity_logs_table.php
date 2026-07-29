<?php
/**
 * pages/activity_logs_table.php
 * ------------------------------------------------------------------
 * Filtered/sorted/paginated activity_logs table. Included by
 * activity_logs.php (initial render) and ajax_activity_logs_search.php.
 * ------------------------------------------------------------------
 */

$pdo = db();

$search    = clean($_GET['search'] ?? '');
$userFil   = (int)($_GET['user_id'] ?? 0);
$actionFil = clean($_GET['action'] ?? '');
$dateFrom  = clean($_GET['date_from'] ?? '');
$dateTo    = clean($_GET['date_to'] ?? '');

$sortDir = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(al.details LIKE :search1 OR u.full_name LIKE :search2)';
    $params[':search1'] = $params[':search2'] = '%' . $search . '%';
}
if ($userFil > 0) { $where[] = 'al.user_id = :uid'; $params[':uid'] = $userFil; }
if ($actionFil !== '') { $where[] = 'al.action = :action'; $params[':action'] = $actionFil; }
if ($dateFrom !== '') { $where[] = 'DATE(al.created_at) >= :df'; $params[':df'] = $dateFrom; }
if ($dateTo !== '') { $where[] = 'DATE(al.created_at) <= :dt'; $params[':dt'] = $dateTo; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs al LEFT JOIN users u ON u.id = al.user_id $whereSql");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$pageInfo = paginate($totalRows, 25);

$sql = "SELECT al.*, u.full_name, u.email
        FROM activity_logs al
        LEFT JOIN users u ON u.id = al.user_id
        $whereSql
        ORDER BY al.created_at $sortDir
        LIMIT {$pageInfo['perPage']} OFFSET {$pageInfo['offset']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$actionColors = [
    'Login' => 'success', 
    'Logout' => 'secondary', 
    'Insert' => 'primary', 
    'Update' => 'warning', 
    'Delete' => 'danger', 
    'Upload' => 'info', 
    'Download' => 'info', 
    'Export' => 'info',
    'Import' => 'info', 
    'Reply' => 'success', 
    'Login Failed' => 'danger',
];

// Action Icons
$actionIcons = [
    'Login' => 'bi-box-arrow-in-right',
    'Logout' => 'bi-box-arrow-left',
    'Insert' => 'bi-plus-circle',
    'Update' => 'bi-pencil-square',
    'Delete' => 'bi-trash',
    'Upload' => 'bi-cloud-upload',
    'Download' => 'bi-cloud-download',
    'Export' => 'bi-file-earmark-arrow-down',
    'Import' => 'bi-file-earmark-arrow-up',
    'Reply' => 'bi-reply',
    'Login Failed' => 'bi-x-circle',
];

// User Avatar Colors
$userColors = ['#4A7EB5', '#F5C842', '#10B981', '#8B5CF6', '#F43F5E', '#06B6D4', '#F97316', '#6366F1'];
?>
<style>
    /* ============================================================
       ACTIVITY LOGS TABLE - Coastal Blue Professional
       ============================================================ */
    .activity-table-wrap {
        background: #ffffff;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 2px 20px rgba(10, 22, 40, 0.05);
        border: 1px solid rgba(10, 22, 40, 0.04);
    }

    .activity-table-wrap .table {
        margin-bottom: 0;
    }

    .activity-table-wrap .table thead th {
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

    .activity-table-wrap .table thead th i {
        color: #F5C842;
        margin-right: 0.4rem;
        font-size: 0.85rem;
    }

    .activity-table-wrap .table thead th a.sort-link {
        color: #ffffff !important;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        transition: color 0.3s ease;
    }

    .activity-table-wrap .table thead th a.sort-link:hover {
        color: #F5C842 !important;
    }

    .activity-table-wrap .table thead th a.sort-link .sort-icon {
        font-size: 0.6rem;
        opacity: 0.6;
    }

    .activity-table-wrap .table tbody td {
        padding: 0.85rem 1.25rem;
        vertical-align: middle;
        border-bottom: 1px solid #E2E8F0;
        font-size: 0.9rem;
        color: #0F2137;
        transition: background 0.2s ease;
    }

    .activity-table-wrap .table tbody tr {
        transition: all 0.2s ease;
    }

    .activity-table-wrap .table tbody tr:hover {
        background: #F8F5FF;
        transform: scale(1.002);
    }

    .activity-table-wrap .table tbody tr:last-child td {
        border-bottom: none;
    }

    /* User Avatar */
    .activity-table-wrap .user-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.8rem;
        color: #ffffff;
        flex-shrink: 0;
    }

    .activity-table-wrap .user-info {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .activity-table-wrap .user-name {
        font-weight: 600;
        color: #0F2137;
        font-size: 0.9rem;
    }

    .activity-table-wrap .user-email {
        color: #94A3B8;
        font-size: 0.75rem;
    }

    /* Action Badges */
    .activity-table-wrap .action-badge {
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

    .activity-table-wrap .action-badge i {
        font-size: 0.75rem;
    }

    .action-badge.bg-success { background: #10B981 !important; color: white; }
    .action-badge.bg-secondary { background: #94A3B8 !important; color: white; }
    .action-badge.bg-primary { background: #2C5282 !important; color: white; }
    .action-badge.bg-warning { background: #F5C842 !important; color: #0A1628; }
    .action-badge.bg-danger { background: #F43F5E !important; color: white; }
    .action-badge.bg-info { background: #06B6D4 !important; color: white; }

    /* Details */
    .activity-table-wrap .details-text {
        color: #475569;
        font-size: 0.85rem;
        max-width: 300px;
        display: block;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .activity-table-wrap .details-text:hover {
        white-space: normal;
        overflow: visible;
        background: #F8FAFC;
        padding: 0.25rem 0.5rem;
        border-radius: 6px;
        position: relative;
        z-index: 5;
    }

    /* Date/Time */
    .activity-table-wrap .datetime {
        color: #64748B;
        font-size: 0.8rem;
        white-space: nowrap;
    }

    .activity-table-wrap .datetime i {
        color: #F5C842;
        margin-right: 0.3rem;
        font-size: 0.7rem;
    }

    /* Pagination */
    .activity-table-wrap .table-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 1.25rem;
        border-top: 1px solid #E2E8F0;
        background: #F8FAFC;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .activity-table-wrap .table-footer .info-text {
        color: #64748B;
        font-size: 0.8rem;
    }

    .activity-table-wrap .table-footer .info-text strong {
        color: #0F2137;
    }

    .activity-table-wrap .pagination {
        margin: 0;
        gap: 0.2rem;
    }

    .activity-table-wrap .pagination .page-link {
        color: #0F2137;
        border: 1px solid #E2E8F0;
        border-radius: 8px;
        padding: 0.35rem 0.75rem;
        font-size: 0.8rem;
        font-weight: 500;
        transition: all 0.3s ease;
        background: transparent;
    }

    .activity-table-wrap .pagination .page-link:hover {
        background: #F5C842;
        color: #0A1628;
        border-color: #F5C842;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(245, 200, 66, 0.2);
    }

    .activity-table-wrap .pagination .page-item.active .page-link {
        background: linear-gradient(135deg, #0A1628 0%, #1A3A5C 100%);
        border-color: #F5C842;
        color: #ffffff;
        box-shadow: 0 4px 15px rgba(10, 22, 40, 0.15);
    }

    .activity-table-wrap .pagination .page-item.disabled .page-link {
        color: #94A3B8;
        cursor: not-allowed;
        opacity: 0.5;
    }

    /* Empty State */
    .activity-table-wrap .empty-state {
        text-align: center;
        padding: 3rem 1.5rem;
    }

    .activity-table-wrap .empty-state i {
        font-size: 3rem;
        color: #F5C842;
        opacity: 0.3;
        display: block;
        margin-bottom: 0.75rem;
    }

    .activity-table-wrap .empty-state h6 {
        color: #0F2137;
        font-weight: 600;
        margin-bottom: 0.25rem;
    }

    .activity-table-wrap .empty-state p {
        color: #94A3B8;
        font-size: 0.85rem;
        margin: 0;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .activity-table-wrap .table thead th,
        .activity-table-wrap .table tbody td {
            padding: 0.6rem 0.8rem;
            font-size: 0.8rem;
        }

        .activity-table-wrap .user-avatar {
            width: 30px;
            height: 30px;
            font-size: 0.65rem;
        }

        .activity-table-wrap .user-name {
            font-size: 0.8rem;
        }

        .activity-table-wrap .action-badge {
            font-size: 0.6rem;
            padding: 0.2rem 0.5rem;
        }

        .activity-table-wrap .table-footer {
            flex-direction: column;
            align-items: flex-start;
            padding: 0.6rem 0.8rem;
        }

        .activity-table-wrap .table-footer .pagination {
            width: 100%;
            justify-content: center;
        }

        .activity-table-wrap .details-text {
            max-width: 150px;
        }
    }

    @media (max-width: 576px) {
        .activity-table-wrap .table thead th,
        .activity-table-wrap .table tbody td {
            padding: 0.4rem 0.5rem;
            font-size: 0.7rem;
        }

        .activity-table-wrap .user-avatar {
            width: 24px;
            height: 24px;
            font-size: 0.5rem;
        }

        .activity-table-wrap .user-name {
            font-size: 0.7rem;
        }

        .activity-table-wrap .user-email {
            font-size: 0.6rem;
        }

        .activity-table-wrap .action-badge {
            font-size: 0.5rem;
            padding: 0.15rem 0.4rem;
            gap: 0.2rem;
        }

        .activity-table-wrap .action-badge i {
            font-size: 0.5rem;
        }

        .activity-table-wrap .datetime {
            font-size: 0.65rem;
        }

        .activity-table-wrap .details-text {
            max-width: 80px;
            font-size: 0.7rem;
        }

        .activity-table-wrap .table-footer .info-text {
            font-size: 0.7rem;
        }

        .activity-table-wrap .pagination .page-link {
            font-size: 0.65rem;
            padding: 0.2rem 0.5rem;
        }
    }
</style>

<div class="activity-table-wrap">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th><i class="bi bi-person"></i> User</th>
                    <th><i class="bi bi-tag"></i> Action</th>
                    <th><i class="bi bi-info-circle"></i> Details</th>
                    <th>
                        <a class="sort-link" data-sort="created_at">
                            <i class="bi bi-clock"></i> Date/Time
                            <span class="sort-icon"><?= $sortDir === 'ASC' ? '↑' : '↓' ?></span>
                        </a>
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="4">
                            <div class="empty-state">
                                <i class="bi bi-inbox"></i>
                                <h6>No Activity Logs Found</h6>
                                <p>Try adjusting your filters or search criteria.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): 
                    $userHash = crc32($row['full_name'] ?? 'system');
                    $avatarColor = $userColors[abs($userHash) % count($userColors)];
                    $initial = strtoupper(substr($row['full_name'] ?? 'S', 0, 1));
                    $actionIcon = $actionIcons[$row['action']] ?? 'bi-activity';
                ?>
                    <tr>
                        <td>
                            <div class="user-info">
                                <div class="user-avatar" style="background: <?= e($avatarColor) ?>;">
                                    <?= e($initial) ?>
                                </div>
                                <div>
                                    <div class="user-name"><?= e($row['full_name'] ?? 'System / Anonymous') ?></div>
                                    <?php if ($row['email']): ?>
                                        <div class="user-email"><?= e($row['email']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="action-badge bg-<?= $actionColors[$row['action']] ?? 'secondary' ?>">
                                <i class="bi <?= e($actionIcon) ?>"></i>
                                <?= e($row['action']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="details-text" title="<?= e($row['details'] ?: '-') ?>">
                                <?= e(truncate($row['details'] ?: '-', 120)) ?>
                            </span>
                        </td>
                        <td>
                            <span class="datetime">
                                <i class="bi bi-clock"></i>
                                <?= formatDateTime($row['created_at']) ?>
                            </span>
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
            of <strong><?= $totalRows ?></strong> log entries
        </span>
        <?= renderPagination($pageInfo, APP_URL . '/pages/activity_logs.php') ?>
    </div>
</div>