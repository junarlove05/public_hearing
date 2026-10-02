<?php
/**
 * modules/attendance/table.php
 * ------------------------------------------------------------------
 * Filtered/sorted/paginated attendance table for a single hearing.
 * Included by index.php (initial render) and ajax_search.php.
 * Expects $_GET['hearing_id'] to be set.
 * ------------------------------------------------------------------
 */

$pdo = db();

$hearingId = (int)($_GET['hearing_id'] ?? 0);
$search    = clean($_GET['search'] ?? '');
$statusFil = clean($_GET['status'] ?? '');
$sessionDate = clean($_GET['session_date'] ?? $_GET['date'] ?? '');

$where = ['a.hearing_id = :hearing_id'];
$params = [':hearing_id' => $hearingId];

if ($sessionDate !== '') {
    $where[] = 'a.attendance_date = :adate';
    $params[':adate'] = $sessionDate;
}

if ($search !== '') {
    $where[] = '(s.full_name LIKE :search1 OR s.email LIKE :search2 OR s.organization LIKE :search3)';
    $params[':search1'] = $params[':search2'] = $params[':search3'] = '%' . $search . '%';
}
if ($statusFil !== '') { $where[] = 'a.status = :status'; $params[':status'] = $statusFil; }
$whereSql = 'WHERE ' . implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM attendance a JOIN stakeholders s ON s.id = a.stakeholder_id $whereSql");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$pageInfo = paginate($totalRows);

$sql = "SELECT a.*, s.full_name, s.email, s.organization
        FROM attendance a
        JOIN stakeholders s ON s.id = a.stakeholder_id
        $whereSql
        ORDER BY a.checked_in_at DESC
        LIMIT {$pageInfo['perPage']} OFFSET {$pageInfo['offset']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// User Avatar Colors
$userColors = ['#4A7EB5', '#a97900', '#10B981', '#8B5CF6', '#F43F5E', '#06B6D4', '#F97316', '#6366F1'];

// Status Icons
$statusIcons = [
    'Present' => 'bi-check-circle',
    'Absent' => 'bi-x-circle',
    'Late' => 'bi-clock',
    'Excused' => 'bi-check-circle',
];
?>
<style>
    /* ============================================================
       ATTENDANCE TABLE - Coastal Blue Professional
       ============================================================ */
    .attendance-table-wrap {
        background: #ffffff;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 2px 20px rgba(10, 22, 40, 0.05);
        border: 1px solid rgba(10, 22, 40, 0.04);
    }

    .attendance-table-wrap .table {
        margin-bottom: 0;
    }

    .attendance-table-wrap .table thead th {
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

    .attendance-table-wrap .table thead th i {
        color: #a97900;
        margin-right: 0.4rem;
        font-size: 0.85rem;
    }

    .attendance-table-wrap .table tbody td {
        padding: 0.85rem 1.25rem;
        vertical-align: middle;
        border-bottom: 1px solid #E2E8F0;
        font-size: 0.9rem;
        color: #0F2137;
        transition: background 0.2s ease;
    }

    .attendance-table-wrap .table tbody tr {
        transition: all 0.2s ease;
    }

    .attendance-table-wrap .table tbody tr:hover {
        background: #F8F5FF;
    }

    .attendance-table-wrap .table tbody tr:last-child td {
        border-bottom: none;
    }

    /* Stakeholder Avatar */
    .attendance-table-wrap .stakeholder-avatar {
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

    .attendance-table-wrap .stakeholder-info {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .attendance-table-wrap .stakeholder-name {
        font-weight: 600;
        color: #0F2137;
        font-size: 0.9rem;
    }

    .attendance-table-wrap .stakeholder-email {
        color: #94A3B8;
        font-size: 0.75rem;
    }

    /* Organization */
    .attendance-table-wrap .organization {
        display: inline-block;
        background: #F1F5F9;
        padding: 0.2rem 0.6rem;
        border-radius: 6px;
        color: #475569;
        font-size: 0.8rem;
        font-weight: 500;
    }

    .attendance-table-wrap .organization i {
        color: #a97900;
        margin-right: 0.3rem;
        font-size: 0.7rem;
    }

    /* Status Badges */
    .attendance-table-wrap .status-badge {
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

    .attendance-table-wrap .status-badge i {
        font-size: 0.75rem;
    }

    .status-badge.present { background: #D1FAE5; color: #065F46; }
    .status-badge.present i { color: #10B981; }

    .status-badge.absent { background: #FEE2E2; color: #991B1B; }
    .status-badge.absent i { color: #EF4444; }

    .status-badge.late { background: #FEF3C7; color: #92400E; }
    .status-badge.late i { color: #F59E0B; }

    .status-badge.excused { background: #DBEAFE; color: #1E40AF; }
    .status-badge.excused i { color: #3B82F6; }

    /* Checked In At */
    .attendance-table-wrap .checked-in-at {
        color: #64748B;
        font-size: 0.8rem;
        white-space: nowrap;
    }

    .attendance-table-wrap .checked-in-at i {
        color: #a97900;
        margin-right: 0.3rem;
        font-size: 0.7rem;
    }

    .attendance-table-wrap .checked-in-at .not-checked {
        color: #94A3B8;
        font-style: italic;
        font-size: 0.7rem;
    }

    /* Action Buttons */
    .attendance-table-wrap .btn-action {
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

    .attendance-table-wrap .btn-action:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    }

    .attendance-table-wrap .btn-action.delete:hover {
        background: #F43F5E;
        color: white;
        border-color: #F43F5E;
    }

    /* Table Footer */
    .attendance-table-wrap .table-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 1.25rem;
        border-top: 1px solid #E2E8F0;
        background: #F8FAFC;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .attendance-table-wrap .table-footer .info-text {
        color: #64748B;
        font-size: 0.8rem;
    }

    .attendance-table-wrap .table-footer .info-text strong {
        color: #0F2137;
    }

    .attendance-table-wrap .pagination {
        margin: 0;
        gap: 0.2rem;
    }

    .attendance-table-wrap .pagination .page-link {
        color: #0F2137;
        border: 1px solid #E2E8F0;
        border-radius: 8px;
        padding: 0.35rem 0.75rem;
        font-size: 0.8rem;
        font-weight: 500;
        transition: all 0.3s ease;
        background: transparent;
    }

    .attendance-table-wrap .pagination .page-link:hover {
        background: #a97900;
        color: #0A1628;
        border-color: #a97900;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(245, 200, 66, 0.2);
    }

    .attendance-table-wrap .pagination .page-item.active .page-link {
        background: linear-gradient(135deg, #0A1628 0%, #1A3A5C 100%);
        border-color: #a97900;
        color: #ffffff;
        box-shadow: 0 4px 15px rgba(10, 22, 40, 0.15);
    }

    .attendance-table-wrap .pagination .page-item.disabled .page-link {
        color: #94A3B8;
        cursor: not-allowed;
        opacity: 0.5;
    }

    /* Empty State */
    .attendance-table-wrap .empty-state {
        text-align: center;
        padding: 3rem 1.5rem;
    }

    .attendance-table-wrap .empty-state i {
        font-size: 3rem;
        color: #a97900;
        opacity: 0.3;
        display: block;
        margin-bottom: 0.75rem;
    }

    .attendance-table-wrap .empty-state h6 {
        color: #0F2137;
        font-weight: 600;
        margin-bottom: 0.25rem;
    }

    .attendance-table-wrap .empty-state p {
        color: #94A3B8;
        font-size: 0.85rem;
        margin: 0;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .attendance-table-wrap .table thead th,
        .attendance-table-wrap .table tbody td {
            padding: 0.6rem 0.8rem;
            font-size: 0.8rem;
        }

        .attendance-table-wrap .stakeholder-avatar {
            width: 32px;
            height: 32px;
            font-size: 0.7rem;
        }

        .attendance-table-wrap .stakeholder-name {
            font-size: 0.8rem;
        }

        .attendance-table-wrap .btn-action {
            width: 28px;
            height: 28px;
            font-size: 0.7rem;
        }

        .attendance-table-wrap .table-footer {
            flex-direction: column;
            align-items: flex-start;
            padding: 0.6rem 0.8rem;
        }

        .attendance-table-wrap .table-footer .pagination {
            width: 100%;
            justify-content: center;
        }

        .attendance-table-wrap .status-badge {
            font-size: 0.6rem;
            padding: 0.2rem 0.5rem;
        }

        .attendance-table-wrap .organization {
            font-size: 0.7rem;
            padding: 0.15rem 0.4rem;
        }
    }

    @media (max-width: 576px) {
        .attendance-table-wrap .table thead th,
        .attendance-table-wrap .table tbody td {
            padding: 0.4rem 0.5rem;
            font-size: 0.7rem;
        }

        .attendance-table-wrap .stakeholder-avatar {
            width: 26px;
            height: 26px;
            font-size: 0.6rem;
        }

        .attendance-table-wrap .stakeholder-name {
            font-size: 0.7rem;
        }

        .attendance-table-wrap .stakeholder-email {
            font-size: 0.6rem;
        }

        .attendance-table-wrap .btn-action {
            width: 24px;
            height: 24px;
            font-size: 0.6rem;
        }

        .attendance-table-wrap .checked-in-at {
            font-size: 0.6rem;
        }

        .attendance-table-wrap .status-badge {
            font-size: 0.5rem;
            padding: 0.15rem 0.4rem;
            gap: 0.2rem;
        }

        .attendance-table-wrap .status-badge i {
            font-size: 0.5rem;
        }

        .attendance-table-wrap .organization {
            font-size: 0.6rem;
            padding: 0.1rem 0.3rem;
        }

        .attendance-table-wrap .organization i {
            font-size: 0.5rem;
        }

        .attendance-table-wrap .table-footer .info-text {
            font-size: 0.65rem;
        }

        .attendance-table-wrap .pagination .page-link {
            font-size: 0.6rem;
            padding: 0.15rem 0.4rem;
        }
    }
</style>

<div class="attendance-table-wrap">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th><i class="bi bi-person"></i> Stakeholder</th>
                    <th><i class="bi bi-building"></i> Organization</th>
                    <th>Status</th>
                    <th><i class="bi bi-clock"></i> Checked In At</th>
                    <th class="text-end no-print"><i class="bi bi-tools"></i> Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="5">
                            <div class="empty-state">
                                <i class="bi bi-clipboard-check"></i>
                                <h6>No Attendance Records</h6>
                                <p>No attendance records yet for this hearing.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): 
                    $userHash = crc32($row['full_name'] ?? '');
                    $avatarColor = $userColors[abs($userHash) % count($userColors)];
                    $initial = strtoupper(substr($row['full_name'] ?? '?', 0, 1));
                    $statusLower = strtolower($row['status']);
                    $statusIcon = $statusIcons[$row['status']] ?? 'bi-circle';
                ?>
                    <tr>
                        <td>
                            <div class="stakeholder-info">
                                <div class="stakeholder-avatar" style="background: <?= e($avatarColor) ?>;">
                                    <?= e($initial) ?>
                                </div>
                                <div>
                                    <div class="stakeholder-name"><?= e($row['full_name']) ?></div>
                                    <div class="stakeholder-email"><i class="bi bi-envelope"></i> <?= e($row['email']) ?></div>
                                </div>
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
                            <span class="status-badge <?= e($statusLower) ?>">
                                <i class="bi <?= e($statusIcon) ?>"></i>
                                <?= e($row['status']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="checked-in-at">
                                <?php if ($row['checked_in_at']): ?>
                                    <i class="bi bi-clock"></i> <?= formatDateTime($row['checked_in_at']) ?>
                                <?php else: ?>
                                    <span class="not-checked"><i class="bi bi-dash-circle"></i> Not checked in</span>
                                <?php endif; ?>
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
            of <strong><?= $totalRows ?></strong> record(s)
        </span>
        <?= renderPagination(array_merge($pageInfo, []), APP_URL . '/modules/attendance/index.php') ?>
    </div>
</div>