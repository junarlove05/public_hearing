<?php
/**
 * pages/users_table.php
 * ------------------------------------------------------------------
 * Filtered/sorted/paginated users table (Administrator only).
 * ------------------------------------------------------------------
 */

$pdo = db();

$search    = clean($_GET['search'] ?? '');
$roleFil   = (int)($_GET['role_id'] ?? 0);
$statusFil = clean($_GET['status'] ?? '');

$sortableColumns = ['full_name', 'email', 'status', 'created_at'];
$sortBy  = in_array($_GET['sort'] ?? '', $sortableColumns, true) ? $_GET['sort'] : 'full_name';
$sortDir = strtolower($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

$where = [];
$params = [];
if ($search !== '') { $where[] = '(u.full_name LIKE :search1 OR u.email LIKE :search2)'; $params[':search1'] = $params[':search2'] = '%' . $search . '%'; }
if ($roleFil > 0) { $where[] = 'u.role_id = :role'; $params[':role'] = $roleFil; }
if ($statusFil !== '') { $where[] = 'u.status = :status'; $params[':status'] = $statusFil; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u $whereSql");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$pageInfo = paginate($totalRows);

$sql = "SELECT u.*, r.name AS role_name
        FROM users u LEFT JOIN roles r ON r.id = u.role_id
        $whereSql
        ORDER BY u.$sortBy $sortDir
        LIMIT {$pageInfo['perPage']} OFFSET {$pageInfo['offset']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$currentUserId = currentUserId();

// User Avatar Colors
$userColors = ['#4A7EB5', '#a97900', '#10B981', '#8B5CF6', '#F43F5E', '#06B6D4', '#F97316', '#6366F1'];

// Role Colors
$roleColors = [
    'Administrator' => 'danger',
    'Staff' => 'primary',
    'Committee' => 'warning',
    'Stakeholder' => 'success',
    'Public' => 'info',
];
?>
<style>
    /* ============================================================
       USERS TABLE - Coastal Blue Professional
       ============================================================ */
    .users-table-wrap {
        background: #ffffff;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 2px 20px rgba(10, 22, 40, 0.05);
        border: 1px solid rgba(10, 22, 40, 0.04);
    }

    .users-table-wrap .table {
        margin-bottom: 0;
    }

    .users-table-wrap .table thead th {
        background: linear-gradient(135deg, #0A1628 0%, #1A3A5C 100%);
        color: #ffffff;
        border-bottom: 3px solid #a97900;
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

    .users-table-wrap .table thead th i {
        color: #a97900;
        margin-right: 0.4rem;
        font-size: 0.85rem;
    }

    .users-table-wrap .table thead th a.sort-link {
        color: #ffffff !important;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        transition: color 0.3s ease;
    }

    .users-table-wrap .table thead th a.sort-link:hover {
        color: #a97900 !important;
    }

    .users-table-wrap .table thead th .sort-icon {
        font-size: 0.6rem;
        opacity: 0.6;
    }

    .users-table-wrap .table tbody td {
        padding: 0.85rem 1.25rem;
        vertical-align: middle;
        border-bottom: 1px solid #E2E8F0;
        font-size: 0.9rem;
        color: #0F2137;
        transition: background 0.2s ease;
    }

    .users-table-wrap .table tbody tr {
        transition: all 0.2s ease;
    }

    .users-table-wrap .table tbody tr:hover {
        background: #F8F5FF;
    }

    .users-table-wrap .table tbody tr:last-child td {
        border-bottom: none;
    }

    /* User Avatar */
    .users-table-wrap .user-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.9rem;
        color: #ffffff;
        flex-shrink: 0;
    }

    .users-table-wrap .user-info {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .users-table-wrap .user-name {
        font-weight: 600;
        color: #0F2137;
        font-size: 0.9rem;
        display: flex;
        align-items: center;
        gap: 0.4rem;
        flex-wrap: wrap;
    }

    .users-table-wrap .user-name .you-badge {
        background: linear-gradient(135deg, #a97900, #D4A820);
        color: #0A1628;
        font-size: 0.55rem;
        font-weight: 700;
        padding: 0.1rem 0.5rem;
        border-radius: 20px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .users-table-wrap .user-email {
        color: #94A3B8;
        font-size: 0.75rem;
    }

    .users-table-wrap .user-email i {
        color: #a97900;
        margin-right: 0.2rem;
        font-size: 0.65rem;
    }

    /* Role Badges */
    .users-table-wrap .role-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.25rem 0.7rem;
        border-radius: 20px;
        font-weight: 600;
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        border: none;
        transition: all 0.3s ease;
    }

    .users-table-wrap .role-badge i {
        font-size: 0.6rem;
    }

    .role-badge.bg-danger { background: #F43F5E !important; color: white; }
    .role-badge.bg-primary { background: #2C5282 !important; color: white; }
    .role-badge.bg-warning { background: #a97900 !important; color: #0A1628; }
    .role-badge.bg-success { background: #10B981 !important; color: white; }
    .role-badge.bg-info { background: #06B6D4 !important; color: white; }
    .role-badge.bg-secondary { background: #94A3B8 !important; color: white; }

    /* Status Badges */
    .users-table-wrap .status-badge {
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

    .users-table-wrap .status-badge i {
        font-size: 0.7rem;
    }

    .status-badge.active { background: #D1FAE5; color: #065F46; }
    .status-badge.active i { color: #10B981; }

    .status-badge.inactive { background: #F1F5F9; color: #475569; }
    .status-badge.inactive i { color: #94A3B8; }

    .status-badge.suspended { background: #FEE2E2; color: #991B1B; }
    .status-badge.suspended i { color: #EF4444; }

    /* Created At */
    .users-table-wrap .created-at {
        color: #64748B;
        font-size: 0.8rem;
        white-space: nowrap;
    }

    .users-table-wrap .created-at i {
        color: #a97900;
        margin-right: 0.3rem;
        font-size: 0.7rem;
    }

    /* Action Buttons */
    .users-table-wrap .btn-action {
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

    .users-table-wrap .btn-action:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    }

    .users-table-wrap .btn-action.edit:hover {
        background: #a97900;
        color: #0A1628;
        border-color: #a97900;
    }

    .users-table-wrap .btn-action.delete:hover {
        background: #F43F5E;
        color: white;
        border-color: #F43F5E;
    }

    .users-table-wrap .btn-group {
        gap: 0.25rem;
    }

    /* Table Footer */
    .users-table-wrap .table-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 1.25rem;
        border-top: 1px solid #E2E8F0;
        background: #F8FAFC;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .users-table-wrap .table-footer .info-text {
        color: #64748B;
        font-size: 0.8rem;
    }

    .users-table-wrap .table-footer .info-text strong {
        color: #0F2137;
    }

    .users-table-wrap .pagination {
        margin: 0;
        gap: 0.2rem;
    }

    .users-table-wrap .pagination .page-link {
        color: #0F2137;
        border: 1px solid #E2E8F0;
        border-radius: 8px;
        padding: 0.35rem 0.75rem;
        font-size: 0.8rem;
        font-weight: 500;
        transition: all 0.3s ease;
        background: transparent;
    }

    .users-table-wrap .pagination .page-link:hover {
        background: #a97900;
        color: #0A1628;
        border-color: #a97900;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(245, 200, 66, 0.2);
    }

    .users-table-wrap .pagination .page-item.active .page-link {
        background: linear-gradient(135deg, #0A1628 0%, #1A3A5C 100%);
        border-color: #a97900;
        color: #ffffff;
        box-shadow: 0 4px 15px rgba(10, 22, 40, 0.15);
    }

    .users-table-wrap .pagination .page-item.disabled .page-link {
        color: #94A3B8;
        cursor: not-allowed;
        opacity: 0.5;
    }

    /* Empty State */
    .users-table-wrap .empty-state {
        text-align: center;
        padding: 3rem 1.5rem;
    }

    .users-table-wrap .empty-state i {
        font-size: 3rem;
        color: #a97900;
        opacity: 0.3;
        display: block;
        margin-bottom: 0.75rem;
    }

    .users-table-wrap .empty-state h6 {
        color: #0F2137;
        font-weight: 600;
        margin-bottom: 0.25rem;
    }

    .users-table-wrap .empty-state p {
        color: #94A3B8;
        font-size: 0.85rem;
        margin: 0;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .users-table-wrap .table thead th,
        .users-table-wrap .table tbody td {
            padding: 0.6rem 0.8rem;
            font-size: 0.8rem;
        }

        .users-table-wrap .user-avatar {
            width: 32px;
            height: 32px;
            font-size: 0.7rem;
        }

        .users-table-wrap .user-name {
            font-size: 0.8rem;
        }

        .users-table-wrap .btn-action {
            width: 28px;
            height: 28px;
            font-size: 0.7rem;
        }

        .users-table-wrap .table-footer {
            flex-direction: column;
            align-items: flex-start;
            padding: 0.6rem 0.8rem;
        }

        .users-table-wrap .table-footer .pagination {
            width: 100%;
            justify-content: center;
        }

        .users-table-wrap .role-badge,
        .users-table-wrap .status-badge {
            font-size: 0.6rem;
            padding: 0.2rem 0.5rem;
        }

        .users-table-wrap .user-name .you-badge {
            font-size: 0.5rem;
            padding: 0.05rem 0.35rem;
        }
    }

    @media (max-width: 576px) {
        .users-table-wrap .table thead th,
        .users-table-wrap .table tbody td {
            padding: 0.4rem 0.5rem;
            font-size: 0.7rem;
        }

        .users-table-wrap .user-avatar {
            width: 26px;
            height: 26px;
            font-size: 0.6rem;
        }

        .users-table-wrap .user-name {
            font-size: 0.7rem;
        }

        .users-table-wrap .user-email {
            font-size: 0.6rem;
        }

        .users-table-wrap .btn-action {
            width: 24px;
            height: 24px;
            font-size: 0.6rem;
        }

        .users-table-wrap .role-badge,
        .users-table-wrap .status-badge {
            font-size: 0.5rem;
            padding: 0.15rem 0.4rem;
            gap: 0.2rem;
        }

        .users-table-wrap .role-badge i,
        .users-table-wrap .status-badge i {
            font-size: 0.5rem;
        }

        .users-table-wrap .created-at {
            font-size: 0.6rem;
        }

        .users-table-wrap .user-name .you-badge {
            font-size: 0.45rem;
            padding: 0.05rem 0.3rem;
        }

        .users-table-wrap .table-footer .info-text {
            font-size: 0.65rem;
        }

        .users-table-wrap .pagination .page-link {
            font-size: 0.6rem;
            padding: 0.15rem 0.4rem;
        }
    }
</style>

<div class="users-table-wrap">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>
                        <a class="sort-link" data-sort="full_name">
                            <i class="bi bi-person"></i> Name
                            <?php if ($sortBy === 'full_name'): ?>
                                <span class="sort-icon"><?= $sortDir === 'ASC' ? '↑' : '↓' ?></span>
                            <?php endif; ?>
                        </a>
                    </th>
                    <th>
                        <a class="sort-link" data-sort="email">
                            <i class="bi bi-envelope"></i> Email
                            <?php if ($sortBy === 'email'): ?>
                                <span class="sort-icon"><?= $sortDir === 'ASC' ? '↑' : '↓' ?></span>
                            <?php endif; ?>
                        </a>
                    </th>
                    <th><i class="bi bi-shield"></i> Role</th>
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
                            <i class="bi bi-clock"></i> Created
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
                        <td colspan="6">
                            <div class="empty-state">
                                <i class="bi bi-people"></i>
                                <h6>No Users Found</h6>
                                <p>Try adjusting your filters or search criteria.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): 
                    $userHash = crc32($row['full_name'] ?? '');
                    $avatarColor = $userColors[abs($userHash) % count($userColors)];
                    $initial = strtoupper(substr($row['full_name'] ?? '?', 0, 1));
                    $isCurrentUser = (int)$row['id'] === $currentUserId;
                    $statusLower = strtolower($row['status']);
                    $roleColor = $roleColors[$row['role_name']] ?? 'secondary';
                ?>
                    <tr>
                        <td>
                            <div class="user-info">
                                <div class="user-avatar" style="background: <?= e($avatarColor) ?>;">
                                    <?= e($initial) ?>
                                </div>
                                <div>
                                    <div class="user-name">
                                        <?= e($row['full_name']) ?>
                                        <?php if ($isCurrentUser): ?>
                                            <span class="you-badge"><i class="bi bi-person-check"></i> You</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="user-email"><i class="bi bi-envelope"></i> <?= e($row['email']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="user-email" style="font-size: 0.85rem; color: #0F2137; font-weight: 500;">
                                <?= e($row['email']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="role-badge bg-<?= e($roleColor) ?>">
                                <i class="bi <?= match($row['role_name']) {
                                    'Administrator' => 'bi-shield-fill',
                                    'Staff' => 'bi-person-badge',
                                    'Committee' => 'bi-person-check',
                                    'Stakeholder' => 'bi-person',
                                    'Public' => 'bi-person',
                                    default => 'bi-person'
                                } ?>"></i>
                                <?= e($row['role_name'] ?? '-') ?>
                            </span>
                        </td>
                        <td>
                            <span class="status-badge <?= e($statusLower) ?>">
                                <i class="bi <?= match($row['status']) {
                                    'Active' => 'bi-check-circle',
                                    'Inactive' => 'bi-pause-circle',
                                    'Suspended' => 'bi-x-circle',
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
                                <button type="button" class="btn-action edit btn-edit-user" 
                                        data-id="<?= (int)$row['id'] ?>" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <?php if ((int)$row['id'] !== $currentUserId): ?>
                                    <button type="button" class="btn-action delete" 
                                            title="Delete"
                                            data-confirm-delete="user &quot;<?= e($row['full_name']) ?>&quot;"
                                            data-delete-url="<?= e(APP_URL) ?>/pages/ajax_user_delete.php?id=<?= (int)$row['id'] ?>">
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
            of <strong><?= $totalRows ?></strong> user(s)
        </span>
        <?= renderPagination($pageInfo, APP_URL . '/pages/users.php') ?>
    </div>
</div>