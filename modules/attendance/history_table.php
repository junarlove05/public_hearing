<?php
/**
 * modules/attendance/history_table.php
 * ------------------------------------------------------------------
 * Filtered/sorted/paginated attendance_logs table (full audit trail
 * of check-ins, manual updates, and deletions across all hearings).
 * ------------------------------------------------------------------
 */

$pdo = db();

$search    = clean($_GET['search'] ?? '');
$hearingFil = (int)($_GET['hearing_id'] ?? 0);
$actionFil  = clean($_GET['action'] ?? '');
$dateFrom   = clean($_GET['date_from'] ?? '');
$dateTo     = clean($_GET['date_to'] ?? '');

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(s.full_name LIKE :search1 OR s.email LIKE :search2)';
    $params[':search1'] = $params[':search2'] = '%' . $search . '%';
}
if ($hearingFil > 0) { $where[] = 'al.hearing_id = :hid'; $params[':hid'] = $hearingFil; }
if ($actionFil !== '') { $where[] = 'al.action = :action'; $params[':action'] = $actionFil; }
if ($dateFrom !== '') { $where[] = 'DATE(al.created_at) >= :date_from'; $params[':date_from'] = $dateFrom; }
if ($dateTo !== '') { $where[] = 'DATE(al.created_at) <= :date_to'; $params[':date_to'] = $dateTo; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM attendance_logs al JOIN stakeholders s ON s.id = al.stakeholder_id $whereSql");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$pageInfo = paginate($totalRows);

$sql = "SELECT al.*, s.full_name, s.email, h.title AS hearing_title
        FROM attendance_logs al
        JOIN stakeholders s ON s.id = al.stakeholder_id
        LEFT JOIN hearings h ON h.id = al.hearing_id
        $whereSql
        ORDER BY al.created_at DESC
        LIMIT {$pageInfo['perPage']} OFFSET {$pageInfo['offset']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// User Avatar Colors
$userColors = ['#4A7EB5', '#F5C842', '#10B981', '#8B5CF6', '#F43F5E', '#06B6D4', '#F97316', '#6366F1'];

// Action Colors & Icons
$actionColors = [
    'Check-in' => 'success',
    'Manual Check-in' => 'primary',
    'Update' => 'warning',
    'Delete' => 'danger',
    'Bulk Import' => 'info',
    'QR Scan' => 'info',
];

$actionIcons = [
    'Check-in' => 'bi-box-arrow-in-right',
    'Manual Check-in' => 'bi-pencil-square',
    'Update' => 'bi-pencil',
    'Delete' => 'bi-trash',
    'Bulk Import' => 'bi-file-earmark-arrow-up',
    'QR Scan' => 'bi-qr-code-scan',
];
?>
<style>
    /* ============================================================
       ATTENDANCE HISTORY TABLE - Coastal Blue Professional
       ============================================================ */
    .history-table-wrap {
        background: #ffffff;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 2px 20px rgba(10, 22, 40, 0.05);
        border: 1px solid rgba(10, 22, 40, 0.04);
    }

    .history-table-wrap .table {
        margin-bottom: 0;
    }

    .history-table-wrap .table thead th {
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

    .history-table-wrap .table thead th i {
        color: #F5C842;
        margin-right: 0.4rem;
        font-size: 0.85rem;
    }

    .history-table-wrap .table tbody td {
        padding: 0.85rem 1.25rem;
        vertical-align: middle;
        border-bottom: 1px solid #E2E8F0;
        font-size: 0.9rem;
        color: #0F2137;
        transition: background 0.2s ease;
    }

    .history-table-wrap .table tbody tr {
        transition: all 0.2s ease;
    }

    .history-table-wrap .table tbody tr:hover {
        background: #F8F5FF;
    }

    .history-table-wrap .table tbody tr:last-child td {
        border-bottom: none;
    }

    /* Stakeholder Avatar */
    .history-table-wrap .stakeholder-avatar {
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

    .history-table-wrap .stakeholder-info {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .history-table-wrap .stakeholder-name {
        font-weight: 600;
        color: #0F2137;
        font-size: 0.9rem;
    }

    .history-table-wrap .stakeholder-email {
        color: #94A3B8;
        font-size: 0.75rem;
    }

    /* Hearing Info */
    .history-table-wrap .hearing-title {
        font-weight: 500;
        color: #0F2137;
        font-size: 0.85rem;
    }

    .history-table-wrap .hearing-title i {
        color: #F5C842;
        margin-right: 0.3rem;
        font-size: 0.75rem;
    }

    .history-table-wrap .hearing-title .no-hearing {
        color: #94A3B8;
        font-style: italic;
        font-size: 0.8rem;
    }

    /* Action Badges */
    .history-table-wrap .action-badge {
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

    .history-table-wrap .action-badge i {
        font-size: 0.7rem;
    }

    .action-badge.bg-success { background: #10B981 !important; color: white; }
    .action-badge.bg-success i { color: white; }

    .action-badge.bg-primary { background: #2C5282 !important; color: white; }
    .action-badge.bg-primary i { color: #F5C842; }

    .action-badge.bg-warning { background: #F5C842 !important; color: #0A1628; }
    .action-badge.bg-warning i { color: #0A1628; }

    .action-badge.bg-danger { background: #F43F5E !important; color: white; }
    .action-badge.bg-danger i { color: white; }

    .action-badge.bg-info { background: #06B6D4 !important; color: white; }
    .action-badge.bg-info i { color: white; }

    /* Notes */
    .history-table-wrap .notes-text {
        color: #475569;
        font-size: 0.8rem;
        max-width: 200px;
        display: block;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .history-table-wrap .notes-text:hover {
        white-space: normal;
        overflow: visible;
        background: #F8FAFC;
        padding: 0.25rem 0.5rem;
        border-radius: 6px;
        position: relative;
        z-index: 5;
    }

    .history-table-wrap .notes-text .no-notes {
        color: #94A3B8;
        font-style: italic;
    }

    /* Date/Time */
    .history-table-wrap .datetime {
        color: #64748B;
        font-size: 0.8rem;
        white-space: nowrap;
    }

    .history-table-wrap .datetime i {
        color: #F5C842;
        margin-right: 0.3rem;
        font-size: 0.7rem;
    }

    /* Table Footer */
    .history-table-wrap .table-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 1.25rem;
        border-top: 1px solid #E2E8F0;
        background: #F8FAFC;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .history-table-wrap .table-footer .info-text {
        color: #64748B;
        font-size: 0.8rem;
    }

    .history-table-wrap .table-footer .info-text strong {
        color: #0F2137;
    }

    .history-table-wrap .pagination {
        margin: 0;
        gap: 0.2rem;
    }

    .history-table-wrap .pagination .page-link {
        color: #0F2137;
        border: 1px solid #E2E8F0;
        border-radius: 8px;
        padding: 0.35rem 0.75rem;
        font-size: 0.8rem;
        font-weight: 500;
        transition: all 0.3s ease;
        background: transparent;
    }

    .history-table-wrap .pagination .page-link:hover {
        background: #F5C842;
        color: #0A1628;
        border-color: #F5C842;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(245, 200, 66, 0.2);
    }

    .history-table-wrap .pagination .page-item.active .page-link {
        background: linear-gradient(135deg, #0A1628 0%, #1A3A5C 100%);
        border-color: #F5C842;
        color: #ffffff;
        box-shadow: 0 4px 15px rgba(10, 22, 40, 0.15);
    }

    .history-table-wrap .pagination .page-item.disabled .page-link {
        color: #94A3B8;
        cursor: not-allowed;
        opacity: 0.5;
    }

    /* Empty State */
    .history-table-wrap .empty-state {
        text-align: center;
        padding: 3rem 1.5rem;
    }

    .history-table-wrap .empty-state i {
        font-size: 3rem;
        color: #F5C842;
        opacity: 0.3;
        display: block;
        margin-bottom: 0.75rem;
    }

    .history-table-wrap .empty-state h6 {
        color: #0F2137;
        font-weight: 600;
        margin-bottom: 0.25rem;
    }

    .history-table-wrap .empty-state p {
        color: #94A3B8;
        font-size: 0.85rem;
        margin: 0;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .history-table-wrap .table thead th,
        .history-table-wrap .table tbody td {
            padding: 0.6rem 0.8rem;
            font-size: 0.8rem;
        }

        .history-table-wrap .stakeholder-avatar {
            width: 30px;
            height: 30px;
            font-size: 0.65rem;
        }

        .history-table-wrap .stakeholder-name {
            font-size: 0.8rem;
        }

        .history-table-wrap .table-footer {
            flex-direction: column;
            align-items: flex-start;
            padding: 0.6rem 0.8rem;
        }

        .history-table-wrap .table-footer .pagination {
            width: 100%;
            justify-content: center;
        }

        .history-table-wrap .action-badge {
            font-size: 0.6rem;
            padding: 0.2rem 0.5rem;
        }

        .history-table-wrap .hearing-title {
            font-size: 0.75rem;
        }

        .history-table-wrap .notes-text {
            max-width: 120px;
            font-size: 0.75rem;
        }
    }

    @media (max-width: 576px) {
        .history-table-wrap .table thead th,
        .history-table-wrap .table tbody td {
            padding: 0.4rem 0.5rem;
            font-size: 0.7rem;
        }

        .history-table-wrap .stakeholder-avatar {
            width: 24px;
            height: 24px;
            font-size: 0.5rem;
        }

        .history-table-wrap .stakeholder-name {
            font-size: 0.7rem;
        }

        .history-table-wrap .stakeholder-email {
            font-size: 0.6rem;
        }

        .history-table-wrap .action-badge {
            font-size: 0.5rem;
            padding: 0.15rem 0.4rem;
            gap: 0.2rem;
        }

        .history-table-wrap .action-badge i {
            font-size: 0.5rem;
        }

        .history-table-wrap .hearing-title {
            font-size: 0.65rem;
        }

        .history-table-wrap .notes-text {
            max-width: 80px;
            font-size: 0.65rem;
        }

        .history-table-wrap .datetime {
            font-size: 0.6rem;
        }

        .history-table-wrap .table-footer .info-text {
            font-size: 0.65rem;
        }

        .history-table-wrap .pagination .page-link {
            font-size: 0.6rem;
            padding: 0.15rem 0.4rem;
        }
    }
</style>

<div class="history-table-wrap">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th><i class="bi bi-person"></i> Stakeholder</th>
                    <th><i class="bi bi-calendar-event"></i> Hearing</th>
                    <th><i class="bi bi-tag"></i> Action</th>
                    <th><i class="bi bi-info-circle"></i> Notes</th>
                    <th><i class="bi bi-clock-history"></i> Date/Time</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="5">
                            <div class="empty-state">
                                <i class="bi bi-clock-history"></i>
                                <h6>No Attendance History</h6>
                                <p>No attendance history found.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): 
                    $userHash = crc32($row['full_name'] ?? '');
                    $avatarColor = $userColors[abs($userHash) % count($userColors)];
                    $initial = strtoupper(substr($row['full_name'] ?? '?', 0, 1));
                    $actionColor = $actionColors[$row['action']] ?? 'secondary';
                    $actionIcon = $actionIcons[$row['action']] ?? 'bi-activity';
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
                            <div class="hearing-title">
                                <?php if ($row['hearing_title']): ?>
                                    <i class="bi bi-megaphone"></i> <?= e($row['hearing_title']) ?>
                                <?php else: ?>
                                    <span class="no-hearing"><i class="bi bi-dash"></i> General</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="action-badge bg-<?= e($actionColor) ?>">
                                <i class="bi <?= e($actionIcon) ?>"></i>
                                <?= e($row['action']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="notes-text" title="<?= e($row['notes'] ?: '') ?>">
                                <?php if ($row['notes']): ?>
                                    <?= e($row['notes']) ?>
                                <?php else: ?>
                                    <span class="no-notes">—</span>
                                <?php endif; ?>
                            </span>
                        </td>
                        <td>
                            <span class="datetime">
                                <i class="bi bi-clock"></i> <?= formatDateTime($row['created_at']) ?>
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
        <?= renderPagination($pageInfo, APP_URL . '/modules/attendance/history.php') ?>
    </div>
</div>