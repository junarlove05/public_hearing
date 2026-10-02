<?php
/**
 * modules/stakeholders/table.php
 * ------------------------------------------------------------------
 * Filtered/sorted/paginated stakeholders table. Included by index.php
 * (initial render) and ajax_search.php (live AJAX refresh).
 * ------------------------------------------------------------------
 */

$pdo = db();

$search    = clean($_GET['search'] ?? '');
$statusFil = clean($_GET['status'] ?? '');
$catFil    = (int)($_GET['category_id'] ?? 0);

$sortableColumns = ['full_name', 'organization', 'status', 'created_at'];
$sortBy  = in_array($_GET['sort'] ?? '', $sortableColumns, true) ? $_GET['sort'] : 'created_at';
$sortDir = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(s.full_name LIKE :search1 OR s.email LIKE :search2 OR s.organization LIKE :search3)';
    $params[':search1'] = $params[':search2'] = $params[':search3'] = '%' . $search . '%';
}
if ($statusFil !== '') { $where[] = 's.status = :status'; $params[':status'] = $statusFil; }
if ($catFil > 0) { $where[] = 's.category_id = :cat'; $params[':cat'] = $catFil; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM stakeholders s $whereSql");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$pageInfo = paginate($totalRows);

$sql = "SELECT s.*, sc.name AS category_name,
               (SELECT COUNT(*) FROM invitations i WHERE i.stakeholder_id = s.id) AS invitation_count,
               (SELECT COUNT(*) FROM registrations r WHERE r.stakeholder_id = s.id) AS registration_count,
               (SELECT code_value FROM qr_codes q WHERE q.stakeholder_id = s.id LIMIT 1) AS qr_code
        FROM stakeholders s
        LEFT JOIN stakeholder_categories sc ON sc.id = s.category_id
        $whereSql
        ORDER BY s.$sortBy $sortDir
        LIMIT {$pageInfo['perPage']} OFFSET {$pageInfo['offset']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// User Avatar Colors
$userColors = ['#4A7EB5', '#a97900', '#10B981', '#8B5CF6', '#F43F5E', '#06B6D4', '#F97316', '#6366F1'];
?>
<style>
    /* ============================================================
       STAKEHOLDERS TABLE - Coastal Blue Professional
       ============================================================ */
    .stakeholders-table-wrap {
        background: #ffffff;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 2px 20px rgba(10, 22, 40, 0.05);
        border: 1px solid rgba(10, 22, 40, 0.04);
    }

    .stakeholders-table-wrap .table-responsive {
        max-height: 65vh;
        overflow-y: auto;
        overflow-x: auto;
        position: relative;
    }

    .stakeholders-table-wrap .table-responsive::-webkit-scrollbar {
        width: 7px;
        height: 7px;
    }
    .stakeholders-table-wrap .table-responsive::-webkit-scrollbar-track {
        background: #f1f5f9;
    }
    .stakeholders-table-wrap .table-responsive::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 4px;
    }
    .stakeholders-table-wrap .table-responsive::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
    }

    .stakeholders-table-wrap .table {
        margin-bottom: 0;
    }

    .stakeholders-table-wrap .table thead th {
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

    .stakeholders-table-wrap .table thead th i {
        color: #a97900;
        margin-right: 0.4rem;
        font-size: 0.85rem;
    }

    .stakeholders-table-wrap .table thead th a.sort-link {
        color: #ffffff !important;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        transition: color 0.3s ease;
    }

    .stakeholders-table-wrap .table thead th a.sort-link:hover {
        color: #a97900 !important;
    }

    .stakeholders-table-wrap .table thead th .sort-icon {
        font-size: 0.6rem;
        opacity: 0.6;
    }

    .stakeholders-table-wrap .table tbody td {
        padding: 0.85rem 1.25rem;
        vertical-align: middle;
        border-bottom: 1px solid #E2E8F0;
        font-size: 0.9rem;
        color: #0F2137;
        transition: background 0.2s ease;
    }

    .stakeholders-table-wrap .table tbody tr {
        transition: all 0.2s ease;
    }

    .stakeholders-table-wrap .table tbody tr:hover {
        background: #F8F5FF;
    }

    .stakeholders-table-wrap .table tbody tr:last-child td {
        border-bottom: none;
    }

    /* Checkbox */
    .stakeholders-table-wrap .form-check-input {
        cursor: pointer;
        border-radius: 4px;
        border: 2px solid #CBD5E1;
        transition: all 0.2s ease;
        width: 18px;
        height: 18px;
    }

    .stakeholders-table-wrap .form-check-input:checked {
        background-color: #a97900;
        border-color: #a97900;
    }

    .stakeholders-table-wrap .form-check-input:focus {
        box-shadow: 0 0 0 3px rgba(245, 200, 66, 0.2);
    }

    /* Stakeholder Avatar */
    .stakeholders-table-wrap .stakeholder-avatar {
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

    .stakeholders-table-wrap .stakeholder-info {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .stakeholders-table-wrap .stakeholder-name {
        font-weight: 600;
        color: #0F2137;
        font-size: 0.9rem;
    }

    .stakeholders-table-wrap .stakeholder-registrations {
        color: #94A3B8;
        font-size: 0.7rem;
    }

    .stakeholders-table-wrap .stakeholder-registrations i {
        color: #a97900;
        margin-right: 0.2rem;
    }

    /* Contact Info */
    .stakeholders-table-wrap .contact-info .contact-email {
        color: #0F2137;
        font-size: 0.8rem;
    }

    .stakeholders-table-wrap .contact-info .contact-phone {
        color: #94A3B8;
        font-size: 0.75rem;
    }

    .stakeholders-table-wrap .contact-info .contact-phone i {
        color: #a97900;
        margin-right: 0.2rem;
        font-size: 0.65rem;
    }

    /* Organization */
    .stakeholders-table-wrap .organization {
        display: inline-block;
        background: #F1F5F9;
        padding: 0.2rem 0.6rem;
        border-radius: 6px;
        color: #475569;
        font-size: 0.8rem;
        font-weight: 500;
    }

    .stakeholders-table-wrap .organization i {
        color: #a97900;
        margin-right: 0.3rem;
        font-size: 0.7rem;
    }

    /* Category */
    .stakeholders-table-wrap .category-badge {
        display: inline-block;
        background: #EDE9FE;
        color: #5B21B6;
        padding: 0.2rem 0.6rem;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 500;
    }

    /* Status Badges */
    .stakeholders-table-wrap .status-badge {
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

    .stakeholders-table-wrap .status-badge i {
        font-size: 0.7rem;
    }

    .status-badge.active, .status-badge.verified { background: #D1FAE5; color: #065F46; }
    .status-badge.active i, .status-badge.verified i { color: #10B981; }

    .status-badge.pending, .status-badge.pending-verification { background: #FEF3C7; color: #92400E; }
    .status-badge.pending i, .status-badge.pending-verification i { color: #F59E0B; }

    .status-badge.inactive { background: #F1F5F9; color: #475569; }
    .status-badge.inactive i { color: #94A3B8; }

    .status-badge.suspended { background: #FEE2E2; color: #991B1B; }
    .status-badge.suspended i { color: #EF4444; }

    .status-badge.approved { background: #D1FAE5; color: #065F46; }
    .status-badge.approved i { color: #10B981; }

    .status-badge.rejected { background: #FEE2E2; color: #991B1B; }
    .status-badge.rejected i { color: #EF4444; }

    /* Invitation Count */
    .stakeholders-table-wrap .invite-count {
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

    .stakeholders-table-wrap .invite-count:hover {
        background: #a97900;
        border-color: #a97900;
        color: #0A1628;
        transform: scale(1.05);
    }

    .stakeholders-table-wrap .invite-count i {
        font-size: 0.7rem;
        color: #a97900;
        margin-right: 0.2rem;
    }

    .stakeholders-table-wrap .invite-count:hover i {
        color: #0A1628;
    }

    /* Action Buttons */
    .stakeholders-table-wrap .btn-action {
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

    .stakeholders-table-wrap .btn-action:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    }

    .stakeholders-table-wrap .btn-action.view {
        color: #0284c7;
        background: #f0f9ff;
        border-color: #bae6fd;
    }

    .stakeholders-table-wrap .btn-action.view:hover {
        background: linear-gradient(135deg, #1e3a8a 0%, #0284c7 100%);
        color: #ffffff;
        border-color: #0284c7;
        box-shadow: 0 4px 10px rgba(2, 132, 199, 0.35);
    }

    .stakeholders-table-wrap .btn-action.qr:hover {
        background: #8B5CF6;
        color: white;
        border-color: #8B5CF6;
    }

    .stakeholders-table-wrap .btn-action.approve:hover {
        background: #10B981;
        color: white;
        border-color: #10B981;
    }

    .stakeholders-table-wrap .btn-action.reject:hover {
        background: #F43F5E;
        color: white;
        border-color: #F43F5E;
    }

    .stakeholders-table-wrap .btn-action.edit:hover {
        background: #2C5282;
        color: white;
        border-color: #2C5282;
    }

    .stakeholders-table-wrap .btn-action.delete:hover {
        background: #F43F5E;
        color: white;
        border-color: #F43F5E;
    }

    .stakeholders-table-wrap .btn-group {
        gap: 0.25rem;
    }

    /* Table Footer */
    .stakeholders-table-wrap .table-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 1.25rem;
        border-top: 1px solid #E2E8F0;
        background: #F8FAFC;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .stakeholders-table-wrap .table-footer .info-text {
        color: #64748B;
        font-size: 0.8rem;
    }

    .stakeholders-table-wrap .table-footer .info-text strong {
        color: #0F2137;
    }

    .stakeholders-table-wrap .pagination {
        margin: 0;
        gap: 0.2rem;
    }

    .stakeholders-table-wrap .pagination .page-link {
        color: #0F2137;
        border: 1px solid #E2E8F0;
        border-radius: 8px;
        padding: 0.35rem 0.75rem;
        font-size: 0.8rem;
        font-weight: 500;
        transition: all 0.3s ease;
        background: transparent;
    }

    .stakeholders-table-wrap .pagination .page-link:hover {
        background: #a97900;
        color: #0A1628;
        border-color: #a97900;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(245, 200, 66, 0.2);
    }

    .stakeholders-table-wrap .pagination .page-item.active .page-link {
        background: linear-gradient(135deg, #0A1628 0%, #1A3A5C 100%);
        border-color: #a97900;
        color: #ffffff;
        box-shadow: 0 4px 15px rgba(10, 22, 40, 0.15);
    }

    .stakeholders-table-wrap .pagination .page-item.disabled .page-link {
        color: #94A3B8;
        cursor: not-allowed;
        opacity: 0.5;
    }

    /* Empty State */
    .stakeholders-table-wrap .empty-state {
        text-align: center;
        padding: 3rem 1.5rem;
    }

    .stakeholders-table-wrap .empty-state i {
        font-size: 3rem;
        color: #a97900;
        opacity: 0.3;
        display: block;
        margin-bottom: 0.75rem;
    }

    .stakeholders-table-wrap .empty-state h6 {
        color: #0F2137;
        font-weight: 600;
        margin-bottom: 0.25rem;
    }

    .stakeholders-table-wrap .empty-state p {
        color: #94A3B8;
        font-size: 0.85rem;
        margin: 0;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .stakeholders-table-wrap .table thead th,
        .stakeholders-table-wrap .table tbody td {
            padding: 0.6rem 0.8rem;
            font-size: 0.8rem;
        }

        .stakeholders-table-wrap .stakeholder-avatar {
            width: 32px;
            height: 32px;
            font-size: 0.7rem;
        }

        .stakeholders-table-wrap .stakeholder-name {
            font-size: 0.8rem;
        }

        .stakeholders-table-wrap .btn-action {
            width: 28px;
            height: 28px;
            font-size: 0.7rem;
        }

        .stakeholders-table-wrap .table-footer {
            flex-direction: column;
            align-items: flex-start;
            padding: 0.6rem 0.8rem;
        }

        .stakeholders-table-wrap .table-footer .pagination {
            width: 100%;
            justify-content: center;
        }

        .stakeholders-table-wrap .invite-count {
            min-width: 28px;
            height: 28px;
            font-size: 0.7rem;
        }

        .stakeholders-table-wrap .organization {
            font-size: 0.7rem;
            padding: 0.15rem 0.4rem;
        }

        .stakeholders-table-wrap .category-badge {
            font-size: 0.6rem;
            padding: 0.15rem 0.4rem;
        }

        .stakeholders-table-wrap .status-badge {
            font-size: 0.6rem;
            padding: 0.2rem 0.5rem;
        }
    }

    @media (max-width: 576px) {
        .stakeholders-table-wrap .table thead th,
        .stakeholders-table-wrap .table tbody td {
            padding: 0.4rem 0.5rem;
            font-size: 0.7rem;
        }

        .stakeholders-table-wrap .stakeholder-avatar {
            width: 26px;
            height: 26px;
            font-size: 0.6rem;
        }

        .stakeholders-table-wrap .stakeholder-name {
            font-size: 0.7rem;
        }

        .stakeholders-table-wrap .stakeholder-registrations {
            font-size: 0.6rem;
        }

        .stakeholders-table-wrap .contact-info .contact-email {
            font-size: 0.65rem;
        }

        .stakeholders-table-wrap .contact-info .contact-phone {
            font-size: 0.6rem;
        }

        .stakeholders-table-wrap .btn-action {
            width: 24px;
            height: 24px;
            font-size: 0.6rem;
        }

        .stakeholders-table-wrap .invite-count {
            min-width: 24px;
            height: 24px;
            font-size: 0.6rem;
        }

        .stakeholders-table-wrap .status-badge {
            font-size: 0.5rem;
            padding: 0.15rem 0.4rem;
            gap: 0.2rem;
        }

        .stakeholders-table-wrap .status-badge i {
            font-size: 0.5rem;
        }

        .stakeholders-table-wrap .organization {
            font-size: 0.6rem;
            padding: 0.1rem 0.3rem;
        }

        .stakeholders-table-wrap .category-badge {
            font-size: 0.55rem;
            padding: 0.1rem 0.3rem;
        }

        .stakeholders-table-wrap .table-footer .info-text {
            font-size: 0.65rem;
        }

        .stakeholders-table-wrap .pagination .page-link {
            font-size: 0.6rem;
            padding: 0.15rem 0.4rem;
        }
    }
</style>

<div class="stakeholders-table-wrap">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="no-print" style="width: 40px;">
                        <input type="checkbox" id="checkAll" class="form-check-input">
                    </th>
                    <th>
                        <a class="sort-link" data-sort="full_name">
                            <i class="bi bi-person"></i> Name
                            <?php if ($sortBy === 'full_name'): ?>
                                <span class="sort-icon"><?= $sortDir === 'ASC' ? '↑' : '↓' ?></span>
                            <?php endif; ?>
                        </a>
                    </th>
                    <th><i class="bi bi-envelope"></i> Contact</th>
                    <th>
                        <a class="sort-link" data-sort="organization">
                            <i class="bi bi-building"></i> Organization
                            <?php if ($sortBy === 'organization'): ?>
                                <span class="sort-icon"><?= $sortDir === 'ASC' ? '↑' : '↓' ?></span>
                            <?php endif; ?>
                        </a>
                    </th>
                    <th><i class="bi bi-tags"></i> Category</th>
                    <th>
                        <a class="sort-link" data-sort="status">
                            Status
                            <?php if ($sortBy === 'status'): ?>
                                <span class="sort-icon"><?= $sortDir === 'ASC' ? '↑' : '↓' ?></span>
                            <?php endif; ?>
                        </a>
                    </th>
                    <th class="text-center"><i class="bi bi-envelope-paper"></i> Invites</th>
                    <th class="text-end no-print"><i class="bi bi-tools"></i> Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                <i class="bi bi-people"></i>
                                <h6>No Stakeholders Found</h6>
                                <p>Try adjusting your filters or search criteria.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): 
                    $userHash = crc32($row['full_name'] ?? '');
                    $avatarColor = $userColors[abs($userHash) % count($userColors)];
                    $initial = strtoupper(substr($row['full_name'] ?? '?', 0, 1));
                    $statusClass = strtolower(str_replace(' ', '-', $row['status'] ?? ''));
                    $statusIcon = match($row['status']) {
                        'Active', 'Approved', 'Verified' => 'bi-check-circle',
                        'Pending' => 'bi-clock-history',
                        'Inactive' => 'bi-pause-circle',
                        'Suspended', 'Rejected' => 'bi-x-circle',
                        default => 'bi-circle'
                    };
                ?>
                    <tr>
                        <td class="no-print">
                            <input type="checkbox" class="form-check-input stakeholder-check" value="<?= (int)$row['id'] ?>">
                        </td>
                        <td>
                            <div class="stakeholder-info">
                                <div class="stakeholder-avatar" style="background: <?= e($avatarColor) ?>;">
                                    <?= e($initial) ?>
                                </div>
                                <div>
                                    <div class="stakeholder-name"><?= e($row['full_name']) ?></div>
                                    <div class="stakeholder-registrations">
                                        <i class="bi bi-calendar-check"></i> <?= (int)$row['registration_count'] ?> registration(s)
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="contact-info">
                                <div class="contact-email"><i class="bi bi-envelope"></i> <?= e($row['email']) ?></div>
                                <div class="contact-phone"><i class="bi bi-telephone"></i> <?= e($row['phone'] ?: '-') ?></div>
                            </div>
                        </td>
                        <td>
                            <?php if ($row['organization']): ?>
                                <span class="organization"><i class="bi bi-building"></i> <?= e($row['organization']) ?></span>
                            <?php else: ?>
                                <span class="text-muted" style="font-size: 0.8rem;">—</span>
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
                            <span class="status-badge <?= e($statusClass) ?>">
                                <i class="bi <?= e($statusIcon) ?>"></i>
                                <?= e($row['status']) ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <span class="invite-count">
                                <i class="bi bi-envelope-paper"></i> <?= (int)$row['invitation_count'] ?>
                            </span>
                        </td>
                        <td class="text-end no-print">
                            <div class="btn-group">
                                <button type="button" class="btn-action view btn-view-assigned-hearings" 
                                        data-bs-toggle="modal"
                                        data-bs-target="#stakeholderHearingsModal"
                                        data-stakeholder-id="<?= (int)$row['id'] ?>" 
                                        data-name="<?= e($row['full_name']) ?>"
                                        data-avatar-style="<?= e(lphAvatarStyle($row['full_name'])) ?>"
                                        data-initials="<?= e(lphInitials($row['full_name'])) ?>"
                                        data-email="<?= e($row['email'] ?? '') ?>"
                                        data-org="<?= e($row['organization'] ?? '') ?>"
                                        title="View Assigned Hearings">
                                    <i class="bi bi-calendar2-week"></i>
                                </button>
                                <?php if (!empty($row['qr_code']) && in_array($row['status'], ['Verified', 'Approved', 'Active'], true)): ?>
                                    <a href="<?= e(APP_URL) ?>/modules/stakeholders/qr.php?id=<?= (int)$row['id'] ?>"
                                       class="btn-action qr" title="Attendance QR Pass">
                                        <i class="bi bi-qr-code"></i>
                                    </a>
                                <?php endif; ?>
                                <?php if (canManage()): ?>
                                    <?php if ($row['status'] !== 'Approved' && $row['status'] !== 'Active' && $row['status'] !== 'Verified'): ?>
                                        <button type="button" class="btn-action approve btn-set-status" 
                                                data-id="<?= (int)$row['id'] ?>" data-status="Approved" title="Approve">
                                            <i class="bi bi-check-lg"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($row['status'] !== 'Rejected' && $row['status'] !== 'Suspended'): ?>
                                        <button type="button" class="btn-action reject btn-set-status" 
                                                data-id="<?= (int)$row['id'] ?>" data-status="Rejected" title="Reject">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn-action edit btn-edit-stakeholder" 
                                            data-id="<?= (int)$row['id'] ?>" 
                                            data-row="<?= htmlspecialchars(json_encode($row, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>" 
                                            title="Edit">
                                        <i class="bi bi-pencil"></i>
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
            of <strong><?= $totalRows ?></strong> stakeholders
        </span>
        <?= renderPagination($pageInfo, APP_URL . '/modules/stakeholders/index.php') ?>
    </div>
</div>