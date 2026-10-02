<?php
/**
 * modules/stakeholders/table_invitations.php
 * ------------------------------------------------------------------
 * Filtered/sorted/paginated invitations table. Included by
 * invitations.php and ajax_search_invitations.php.
 * ------------------------------------------------------------------
 */

$pdo = db();

$search    = clean($_GET['search'] ?? '');
$statusFil = clean($_GET['status'] ?? '');
$hearingFil = (int)($_GET['hearing_id'] ?? 0);

$sortableColumns = ['status', 'sent_at', 'created_at'];
$sortBy  = in_array($_GET['sort'] ?? '', $sortableColumns, true) ? $_GET['sort'] : 'created_at';
$sortDir = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(s.full_name LIKE :search1 OR s.email LIKE :search2 OR i.invitation_code LIKE :search3)';
    $params[':search1'] = $params[':search2'] = $params[':search3'] = '%' . $search . '%';
}
if ($statusFil !== '') { $where[] = 'i.status = :status'; $params[':status'] = $statusFil; }
if ($hearingFil > 0) { $where[] = 'i.hearing_id = :hearing_id'; $params[':hearing_id'] = $hearingFil; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM invitations i
     JOIN stakeholders s ON s.id = i.stakeholder_id
     $whereSql"
);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$pageInfo = paginate($totalRows);

$sql = "SELECT i.*, s.full_name, s.email, s.organization, h.title AS hearing_title, h.hearing_date, h.hearing_time
        FROM invitations i
        JOIN stakeholders s ON s.id = i.stakeholder_id
        LEFT JOIN hearings h ON h.id = i.hearing_id
        $whereSql
        ORDER BY i.$sortBy $sortDir
        LIMIT {$pageInfo['perPage']} OFFSET {$pageInfo['offset']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Status icons
$statusIcons = [
    'Pending' => 'bi-clock-history',
    'Sent' => 'bi-send',
    'Accepted' => 'bi-check-circle',
    'Declined' => 'bi-x-circle',
    'Expired' => 'bi-clock',
];
?>
<style>
    /* ============================================================
       INVITATIONS TABLE - Coastal Blue Professional
       ============================================================ */
    .invitations-table-wrap {
        background: #ffffff;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 2px 20px rgba(10, 22, 40, 0.05);
        border: 1px solid rgba(10, 22, 40, 0.04);
    }

    .invitations-table-wrap .table {
        margin-bottom: 0;
    }

    .invitations-table-wrap .table thead th {
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

    .invitations-table-wrap .table thead th i {
        color: #a97900;
        margin-right: 0.4rem;
        font-size: 0.85rem;
    }

    .invitations-table-wrap .table thead th a.sort-link {
        color: #ffffff !important;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        transition: color 0.3s ease;
    }

    .invitations-table-wrap .table thead th a.sort-link:hover {
        color: #a97900 !important;
    }

    .invitations-table-wrap .table tbody td {
        padding: 0.85rem 1.25rem;
        vertical-align: middle;
        border-bottom: 1px solid #E2E8F0;
        font-size: 0.9rem;
        color: #0F2137;
        transition: background 0.2s ease;
    }

    .invitations-table-wrap .table tbody tr {
        transition: all 0.2s ease;
    }

    .invitations-table-wrap .table tbody tr:hover {
        background: #F8F5FF;
    }

    .invitations-table-wrap .table tbody tr:last-child td {
        border-bottom: none;
    }

    /* Stakeholder Info */
    .invitations-table-wrap .stakeholder-info .stakeholder-name {
        font-weight: 600;
        color: #0F2137;
        font-size: 0.9rem;
    }

    .invitations-table-wrap .stakeholder-info .stakeholder-email {
        color: #94A3B8;
        font-size: 0.75rem;
    }

    .invitations-table-wrap .stakeholder-info .stakeholder-org {
        color: #64748B;
        font-size: 0.7rem;
        background: #F1F5F9;
        padding: 0.1rem 0.5rem;
        border-radius: 10px;
        display: inline-block;
        margin-top: 0.1rem;
    }

    /* Hearing Info */
    .invitations-table-wrap .hearing-info .hearing-title {
        font-weight: 500;
        color: #0F2137;
        font-size: 0.85rem;
    }

    .invitations-table-wrap .hearing-info .hearing-date {
        color: #94A3B8;
        font-size: 0.7rem;
    }

    .invitations-table-wrap .hearing-info .hearing-date i {
        color: #a97900;
        margin-right: 0.2rem;
    }

    /* Invitation Code */
    .invitations-table-wrap .invitation-code {
        font-family: 'Courier New', monospace;
        font-size: 0.8rem;
        background: #F1F5F9;
        padding: 0.2rem 0.6rem;
        border-radius: 6px;
        color: #1A3A5C;
        font-weight: 600;
        letter-spacing: 0.5px;
    }

    /* Status Badges */
    .invitations-table-wrap .status-badge {
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

    .invitations-table-wrap .status-badge i {
        font-size: 0.75rem;
    }

    .status-badge.pending { background: #FEF3C7; color: #92400E; }
    .status-badge.pending i { color: #F59E0B; }

    .status-badge.sent { background: #DBEAFE; color: #1E40AF; }
    .status-badge.sent i { color: #3B82F6; }

    .status-badge.accepted { background: #D1FAE5; color: #065F46; }
    .status-badge.accepted i { color: #10B981; }

    .status-badge.declined { background: #FEE2E2; color: #991B1B; }
    .status-badge.declined i { color: #EF4444; }

    .status-badge.expired { background: #F1F5F9; color: #475569; }
    .status-badge.expired i { color: #94A3B8; }

    /* Action Buttons */
    .invitations-table-wrap .btn-action {
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

    .invitations-table-wrap .btn-action:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    }

    .invitations-table-wrap .btn-action.print:hover {
        background: #a97900;
        color: #0A1628;
        border-color: #a97900;
    }

    .invitations-table-wrap .btn-action.download:hover {
        background: #2C5282;
        color: white;
        border-color: #2C5282;
    }

    .invitations-table-wrap .btn-action.send:hover {
        background: #10B981;
        color: white;
        border-color: #10B981;
    }

    .invitations-table-wrap .btn-action.email:hover {
        background: #8B5CF6;
        color: white;
        border-color: #8B5CF6;
    }

    .invitations-table-wrap .btn-action.delete:hover {
        background: #F43F5E;
        color: white;
        border-color: #F43F5E;
    }

    .invitations-table-wrap .btn-group {
        gap: 0.25rem;
    }

    /* Sent At */
    .invitations-table-wrap .sent-at {
        color: #64748B;
        font-size: 0.8rem;
        white-space: nowrap;
    }

    .invitations-table-wrap .sent-at i {
        color: #a97900;
        margin-right: 0.3rem;
        font-size: 0.7rem;
    }

    .invitations-table-wrap .sent-at .not-sent {
        color: #94A3B8;
        font-style: italic;
        font-size: 0.7rem;
    }

    /* Table Footer */
    .invitations-table-wrap .table-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 1.25rem;
        border-top: 1px solid #E2E8F0;
        background: #F8FAFC;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .invitations-table-wrap .table-footer .info-text {
        color: #64748B;
        font-size: 0.8rem;
    }

    .invitations-table-wrap .table-footer .info-text strong {
        color: #0F2137;
    }

    .invitations-table-wrap .pagination {
        margin: 0;
        gap: 0.2rem;
    }

    .invitations-table-wrap .pagination .page-link {
        color: #0F2137;
        border: 1px solid #E2E8F0;
        border-radius: 8px;
        padding: 0.35rem 0.75rem;
        font-size: 0.8rem;
        font-weight: 500;
        transition: all 0.3s ease;
        background: transparent;
    }

    .invitations-table-wrap .pagination .page-link:hover {
        background: #a97900;
        color: #0A1628;
        border-color: #a97900;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(245, 200, 66, 0.2);
    }

    .invitations-table-wrap .pagination .page-item.active .page-link {
        background: linear-gradient(135deg, #0A1628 0%, #1A3A5C 100%);
        border-color: #a97900;
        color: #ffffff;
        box-shadow: 0 4px 15px rgba(10, 22, 40, 0.15);
    }

    .invitations-table-wrap .pagination .page-item.disabled .page-link {
        color: #94A3B8;
        cursor: not-allowed;
        opacity: 0.5;
    }

    /* Empty State */
    .invitations-table-wrap .empty-state {
        text-align: center;
        padding: 3rem 1.5rem;
    }

    .invitations-table-wrap .empty-state i {
        font-size: 3rem;
        color: #a97900;
        opacity: 0.3;
        display: block;
        margin-bottom: 0.75rem;
    }

    .invitations-table-wrap .empty-state h6 {
        color: #0F2137;
        font-weight: 600;
        margin-bottom: 0.25rem;
    }

    .invitations-table-wrap .empty-state p {
        color: #94A3B8;
        font-size: 0.85rem;
        margin: 0;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .invitations-table-wrap .table thead th,
        .invitations-table-wrap .table tbody td {
            padding: 0.6rem 0.8rem;
            font-size: 0.8rem;
        }

        .invitations-table-wrap .stakeholder-info .stakeholder-name {
            font-size: 0.8rem;
        }

        .invitations-table-wrap .hearing-info .hearing-title {
            font-size: 0.75rem;
        }

        .invitations-table-wrap .status-badge {
            font-size: 0.6rem;
            padding: 0.2rem 0.5rem;
        }

        .invitations-table-wrap .btn-action {
            width: 28px;
            height: 28px;
            font-size: 0.7rem;
        }

        .invitations-table-wrap .table-footer {
            flex-direction: column;
            align-items: flex-start;
            padding: 0.6rem 0.8rem;
        }

        .invitations-table-wrap .table-footer .pagination {
            width: 100%;
            justify-content: center;
        }

        .invitations-table-wrap .invitation-code {
            font-size: 0.65rem;
            padding: 0.15rem 0.4rem;
        }
    }

    @media (max-width: 576px) {
        .invitations-table-wrap .table thead th,
        .invitations-table-wrap .table tbody td {
            padding: 0.4rem 0.5rem;
            font-size: 0.7rem;
        }

        .invitations-table-wrap .stakeholder-info .stakeholder-name {
            font-size: 0.7rem;
        }

        .invitations-table-wrap .stakeholder-info .stakeholder-email {
            font-size: 0.6rem;
        }

        .invitations-table-wrap .hearing-info .hearing-title {
            font-size: 0.65rem;
        }

        .invitations-table-wrap .hearing-info .hearing-date {
            font-size: 0.55rem;
        }

        .invitations-table-wrap .status-badge {
            font-size: 0.5rem;
            padding: 0.15rem 0.4rem;
            gap: 0.2rem;
        }

        .invitations-table-wrap .status-badge i {
            font-size: 0.5rem;
        }

        .invitations-table-wrap .btn-action {
            width: 24px;
            height: 24px;
            font-size: 0.6rem;
        }

        .invitations-table-wrap .sent-at {
            font-size: 0.6rem;
        }

        .invitations-table-wrap .invitation-code {
            font-size: 0.55rem;
            padding: 0.1rem 0.3rem;
        }

        .invitations-table-wrap .table-footer .info-text {
            font-size: 0.65rem;
        }

        .invitations-table-wrap .pagination .page-link {
            font-size: 0.6rem;
            padding: 0.15rem 0.4rem;
        }
    }
</style>

<div class="invitations-table-wrap">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th><i class="bi bi-person"></i> Stakeholder</th>
                    <th><i class="bi bi-calendar-event"></i> Hearing</th>
                    <th><i class="bi bi-tag"></i> Invitation Code</th>
                    <th>
                        <a class="sort-link" data-sort="status">
                            Status
                            <?php if ($sortBy === 'status'): ?>
                                <span class="sort-icon"><?= $sortDir === 'ASC' ? '↑' : '↓' ?></span>
                            <?php endif; ?>
                        </a>
                    </th>
                    <th>
                        <a class="sort-link" data-sort="sent_at">
                            <i class="bi bi-send"></i> Sent At
                            <?php if ($sortBy === 'sent_at'): ?>
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
                                <i class="bi bi-envelope-open"></i>
                                <h6>No Invitations Found</h6>
                                <p>Try adjusting your filters or search criteria.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): 
                    $statusClass = strtolower($row['status']);
                    $statusIcon = $statusIcons[$row['status']] ?? 'bi-question-circle';
                ?>
                    <tr>
                        <td>
                            <div class="stakeholder-info">
                                <div class="stakeholder-name"><?= e($row['full_name']) ?></div>
                                <div class="stakeholder-email"><?= e($row['email']) ?></div>
                                <?php if ($row['organization']): ?>
                                    <span class="stakeholder-org"><i class="bi bi-building"></i> <?= e($row['organization']) ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="hearing-info">
                                <div class="hearing-title"><?= e($row['hearing_title'] ?? 'General / No specific hearing') ?></div>
                                <?php if ($row['hearing_date']): ?>
                                    <div class="hearing-date">
                                        <i class="bi bi-calendar3"></i> <?= formatDate($row['hearing_date']) ?>
                                        <?php if ($row['hearing_time']): ?>
                                            <i class="bi bi-clock ms-1"></i> <?= formatTime($row['hearing_time']) ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="invitation-code"><?= e($row['invitation_code']) ?></span>
                        </td>
                        <td>
                            <span class="status-badge <?= e($statusClass) ?>">
                                <i class="bi <?= e($statusIcon) ?>"></i>
                                <?= e($row['status']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="sent-at">
                                <?php if ($row['sent_at']): ?>
                                    <i class="bi bi-clock"></i> <?= formatDateTime($row['sent_at']) ?>
                                <?php else: ?>
                                    <span class="not-sent">Not sent yet</span>
                                <?php endif; ?>
                            </span>
                        </td>
                        <td class="text-end no-print">
                            <div class="btn-group">
                                <a href="<?= e(APP_URL) ?>/modules/stakeholders/invitation_print.php?id=<?= (int)$row['id'] ?>"
                                   target="_blank" class="btn-action print" title="Print">
                                    <i class="bi bi-printer"></i>
                                </a>
                                <a href="<?= e(APP_URL) ?>/modules/stakeholders/invitation_download.php?id=<?= (int)$row['id'] ?>"
                                   class="btn-action download" title="Download">
                                    <i class="bi bi-download"></i>
                                </a>
                                <?php if (canManage()): ?>
                                    <?php if ($row['status'] === 'Pending'): ?>
                                        <button type="button" class="btn-action send btn-mark-sent" 
                                                data-id="<?= (int)$row['id'] ?>" title="Mark as Sent">
                                            <i class="bi bi-send"></i>
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn-action email btn-email-template" 
                                            title="Email Template"
                                            data-id="<?= (int)$row['id'] ?>">
                                        <i class="bi bi-envelope"></i>
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
            of <strong><?= $totalRows ?></strong> invitations
        </span>
        <?= renderPagination($pageInfo, APP_URL . '/modules/stakeholders/invitations.php') ?>
    </div>
</div>