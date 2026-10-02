<?php
/**
 * modules/stakeholders/table_registrations.php
 * ------------------------------------------------------------------
 * Filtered/sorted/paginated registrations table. Included by
 * registrations.php and ajax_search_registrations.php.
 * ------------------------------------------------------------------
 */

$pdo = db();

$search    = clean($_GET['search'] ?? '');
$hearingFil = (int)($_GET['hearing_id'] ?? 0);

$sortDir = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(s.full_name LIKE :search1 OR s.email LIKE :search2 OR s.organization LIKE :search3)';
    $params[':search1'] = $params[':search2'] = $params[':search3'] = '%' . $search . '%';
}
if ($hearingFil > 0) { $where[] = 'r.hearing_id = :hearing_id'; $params[':hearing_id'] = $hearingFil; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM registrations r JOIN stakeholders s ON s.id = r.stakeholder_id $whereSql"
);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$pageInfo = paginate($totalRows);

$sql = "SELECT r.*, s.full_name, s.email, s.organization, h.title AS hearing_title, h.hearing_date
        FROM registrations r
        JOIN stakeholders s ON s.id = r.stakeholder_id
        LEFT JOIN hearings h ON h.id = r.hearing_id
        $whereSql
        ORDER BY r.registered_at $sortDir
        LIMIT {$pageInfo['perPage']} OFFSET {$pageInfo['offset']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// User Avatar Colors
$userColors = ['#4A7EB5', '#a97900', '#10B981', '#8B5CF6', '#F43F5E', '#06B6D4', '#F97316', '#6366F1'];
?>
<style>
    /* ============================================================
       REGISTRATIONS TABLE - Coastal Blue Professional
       ============================================================ */
    .registrations-table-wrap {
        background: #ffffff;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 2px 20px rgba(10, 22, 40, 0.05);
        border: 1px solid rgba(10, 22, 40, 0.04);
    }

    .registrations-table-wrap .table {
        margin-bottom: 0;
    }

    .registrations-table-wrap .table thead th {
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

    .registrations-table-wrap .table thead th i {
        color: #a97900;
        margin-right: 0.4rem;
        font-size: 0.85rem;
    }

    .registrations-table-wrap .table thead th a.sort-link {
        color: #ffffff !important;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        transition: color 0.3s ease;
    }

    .registrations-table-wrap .table thead th a.sort-link:hover {
        color: #a97900 !important;
    }

    .registrations-table-wrap .table tbody td {
        padding: 0.85rem 1.25rem;
        vertical-align: middle;
        border-bottom: 1px solid #E2E8F0;
        font-size: 0.9rem;
        color: #0F2137;
        transition: background 0.2s ease;
    }

    .registrations-table-wrap .table tbody tr {
        transition: all 0.2s ease;
    }

    .registrations-table-wrap .table tbody tr:hover {
        background: #F8F5FF;
    }

    .registrations-table-wrap .table tbody tr:last-child td {
        border-bottom: none;
    }

    /* Stakeholder Avatar */
    .registrations-table-wrap .stakeholder-avatar {
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

    .registrations-table-wrap .stakeholder-info {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .registrations-table-wrap .stakeholder-name {
        font-weight: 600;
        color: #0F2137;
        font-size: 0.9rem;
    }

    .registrations-table-wrap .stakeholder-details {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    .registrations-table-wrap .stakeholder-details .stakeholder-email {
        color: #94A3B8;
        font-size: 0.75rem;
    }

    .registrations-table-wrap .stakeholder-details .stakeholder-org {
        color: #64748B;
        font-size: 0.7rem;
        background: #F1F5F9;
        padding: 0.1rem 0.5rem;
        border-radius: 10px;
        display: inline-block;
    }

    .registrations-table-wrap .stakeholder-details .stakeholder-org i {
        color: #a97900;
        font-size: 0.6rem;
        margin-right: 0.2rem;
    }

    .registrations-table-wrap .stakeholder-details .separator {
        color: #E2E8F0;
        font-weight: 300;
    }

    /* Hearing Info */
    .registrations-table-wrap .hearing-info .hearing-title {
        font-weight: 500;
        color: #0F2137;
        font-size: 0.85rem;
    }

    .registrations-table-wrap .hearing-info .hearing-title i {
        color: #a97900;
        margin-right: 0.3rem;
        font-size: 0.75rem;
    }

    .registrations-table-wrap .hearing-info .hearing-date {
        color: #94A3B8;
        font-size: 0.7rem;
    }

    .registrations-table-wrap .hearing-info .hearing-date i {
        color: #a97900;
        margin-right: 0.2rem;
    }

    /* Registered At */
    .registrations-table-wrap .registered-at {
        color: #64748B;
        font-size: 0.8rem;
        white-space: nowrap;
    }

    .registrations-table-wrap .registered-at i {
        color: #a97900;
        margin-right: 0.3rem;
        font-size: 0.7rem;
    }

    /* Action Buttons */
    .registrations-table-wrap .btn-action {
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

    .registrations-table-wrap .btn-action:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    }

    .registrations-table-wrap .btn-action.delete:hover {
        background: #F43F5E;
        color: white;
        border-color: #F43F5E;
    }

    /* Table Footer */
    .registrations-table-wrap .table-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 1.25rem;
        border-top: 1px solid #E2E8F0;
        background: #F8FAFC;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .registrations-table-wrap .table-footer .info-text {
        color: #64748B;
        font-size: 0.8rem;
    }

    .registrations-table-wrap .table-footer .info-text strong {
        color: #0F2137;
    }

    .registrations-table-wrap .pagination {
        margin: 0;
        gap: 0.2rem;
    }

    .registrations-table-wrap .pagination .page-link {
        color: #0F2137;
        border: 1px solid #E2E8F0;
        border-radius: 8px;
        padding: 0.35rem 0.75rem;
        font-size: 0.8rem;
        font-weight: 500;
        transition: all 0.3s ease;
        background: transparent;
    }

    .registrations-table-wrap .pagination .page-link:hover {
        background: #a97900;
        color: #0A1628;
        border-color: #a97900;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(245, 200, 66, 0.2);
    }

    .registrations-table-wrap .pagination .page-item.active .page-link {
        background: linear-gradient(135deg, #0A1628 0%, #1A3A5C 100%);
        border-color: #a97900;
        color: #ffffff;
        box-shadow: 0 4px 15px rgba(10, 22, 40, 0.15);
    }

    .registrations-table-wrap .pagination .page-item.disabled .page-link {
        color: #94A3B8;
        cursor: not-allowed;
        opacity: 0.5;
    }

    /* Empty State */
    .registrations-table-wrap .empty-state {
        text-align: center;
        padding: 3rem 1.5rem;
    }

    .registrations-table-wrap .empty-state i {
        font-size: 3rem;
        color: #a97900;
        opacity: 0.3;
        display: block;
        margin-bottom: 0.75rem;
    }

    .registrations-table-wrap .empty-state h6 {
        color: #0F2137;
        font-weight: 600;
        margin-bottom: 0.25rem;
    }

    .registrations-table-wrap .empty-state p {
        color: #94A3B8;
        font-size: 0.85rem;
        margin: 0;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .registrations-table-wrap .table thead th,
        .registrations-table-wrap .table tbody td {
            padding: 0.6rem 0.8rem;
            font-size: 0.8rem;
        }

        .registrations-table-wrap .stakeholder-avatar {
            width: 32px;
            height: 32px;
            font-size: 0.7rem;
        }

        .registrations-table-wrap .stakeholder-name {
            font-size: 0.8rem;
        }

        .registrations-table-wrap .btn-action {
            width: 28px;
            height: 28px;
            font-size: 0.7rem;
        }

        .registrations-table-wrap .table-footer {
            flex-direction: column;
            align-items: flex-start;
            padding: 0.6rem 0.8rem;
        }

        .registrations-table-wrap .table-footer .pagination {
            width: 100%;
            justify-content: center;
        }

        .registrations-table-wrap .hearing-info .hearing-title {
            font-size: 0.75rem;
        }

        .registrations-table-wrap .stakeholder-details .stakeholder-email {
            font-size: 0.65rem;
        }

        .registrations-table-wrap .stakeholder-details .stakeholder-org {
            font-size: 0.6rem;
            padding: 0.1rem 0.3rem;
        }
    }

    @media (max-width: 576px) {
        .registrations-table-wrap .table thead th,
        .registrations-table-wrap .table tbody td {
            padding: 0.4rem 0.5rem;
            font-size: 0.7rem;
        }

        .registrations-table-wrap .stakeholder-avatar {
            width: 26px;
            height: 26px;
            font-size: 0.6rem;
        }

        .registrations-table-wrap .stakeholder-name {
            font-size: 0.7rem;
        }

        .registrations-table-wrap .stakeholder-details {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.1rem;
        }

        .registrations-table-wrap .stakeholder-details .stakeholder-email {
            font-size: 0.6rem;
        }

        .registrations-table-wrap .stakeholder-details .stakeholder-org {
            font-size: 0.55rem;
            padding: 0.05rem 0.3rem;
        }

        .registrations-table-wrap .stakeholder-details .separator {
            display: none;
        }

        .registrations-table-wrap .btn-action {
            width: 24px;
            height: 24px;
            font-size: 0.6rem;
        }

        .registrations-table-wrap .registered-at {
            font-size: 0.6rem;
        }

        .registrations-table-wrap .hearing-info .hearing-title {
            font-size: 0.65rem;
        }

        .registrations-table-wrap .hearing-info .hearing-date {
            font-size: 0.55rem;
        }

        .registrations-table-wrap .table-footer .info-text {
            font-size: 0.65rem;
        }

        .registrations-table-wrap .pagination .page-link {
            font-size: 0.6rem;
            padding: 0.15rem 0.4rem;
        }
    }
</style>

<div class="registrations-table-wrap">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th><i class="bi bi-person"></i> Stakeholder</th>
                    <th><i class="bi bi-calendar-event"></i> Hearing</th>
                    <th>
                        <a class="sort-link" data-sort="registered_at">
                            <i class="bi bi-clock-history"></i> Registered At
                            <span class="sort-icon"><?= $sortDir === 'ASC' ? '↑' : '↓' ?></span>
                        </a>
                    </th>
                    <th class="text-end no-print"><i class="bi bi-tools"></i> Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="4">
                            <div class="empty-state">
                                <i class="bi bi-calendar-check"></i>
                                <h6>No Registrations Found</h6>
                                <p>Try adjusting your filters or search criteria.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): 
                    $userHash = crc32($row['full_name'] ?? '');
                    $avatarColor = $userColors[abs($userHash) % count($userColors)];
                    $initial = strtoupper(substr($row['full_name'] ?? '?', 0, 1));
                ?>
                    <tr>
                        <td>
                            <div class="stakeholder-info">
                                <div class="stakeholder-avatar" style="background: <?= e($avatarColor) ?>;">
                                    <?= e($initial) ?>
                                </div>
                                <div>
                                    <div class="stakeholder-name"><?= e($row['full_name']) ?></div>
                                    <div class="stakeholder-details">
                                        <span class="stakeholder-email"><i class="bi bi-envelope"></i> <?= e($row['email']) ?></span>
                                        <span class="separator">•</span>
                                        <?php if ($row['organization']): ?>
                                            <span class="stakeholder-org"><i class="bi bi-building"></i> <?= e($row['organization']) ?></span>
                                        <?php else: ?>
                                            <span class="stakeholder-org">—</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="hearing-info">
                                <div class="hearing-title">
                                    <i class="bi bi-megaphone"></i>
                                    <?= e($row['hearing_title'] ?? 'General Registration') ?>
                                </div>
                                <?php if ($row['hearing_date']): ?>
                                    <div class="hearing-date">
                                        <i class="bi bi-calendar3"></i> <?= formatDate($row['hearing_date']) ?>
                                    </div>
                                <?php else: ?>
                                    <div class="hearing-date text-muted" style="font-size: 0.7rem;">No specific hearing</div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="registered-at">
                                <i class="bi bi-clock"></i> <?= formatDateTime($row['registered_at']) ?>
                            </span>
                        </td>
                        <td class="text-end no-print">
                            <span class="text-muted" style="font-size: 0.8rem;">—</span>
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
            of <strong><?= $totalRows ?></strong> registrations
        </span>
        <?= renderPagination($pageInfo, APP_URL . '/modules/stakeholders/registrations.php') ?>
    </div>
</div>