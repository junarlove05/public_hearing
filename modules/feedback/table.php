<?php
/**
 * modules/feedback/table.php
 * ------------------------------------------------------------------
 * Filtered/sorted/paginated feedback table (management view). Only
 * shown when canManage() is true; ordinary logged-in users see their
 * own submissions on index.php instead (see my_feedback_table.php).
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/AI/AIAnalysisManager.php';

$pdo = db();
$aiAvailable = AIAnalysisManager::tablesExist();

$search    = clean($_GET['search'] ?? '');
$statusFil = clean($_GET['status'] ?? '');
$catFil    = (int)($_GET['category_id'] ?? 0);
$sentimentFil = clean($_GET['sentiment'] ?? '');

$sortableColumns = ['name', 'status', 'submitted_at'];
$sortBy  = in_array($_GET['sort'] ?? '', $sortableColumns, true) ? $_GET['sort'] : 'submitted_at';
$sortDir = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(f.name LIKE :search1 OR f.email LIKE :search2 OR f.subject LIKE :search3 OR f.message LIKE :search4)';
    $params[':search1'] = $params[':search2'] = $params[':search3'] = $params[':search4'] = '%' . $search . '%';
}
if ($statusFil !== '') { $where[] = 'f.status = :status'; $params[':status'] = $statusFil; }
if ($catFil > 0) { $where[] = 'f.category_id = :cat'; $params[':cat'] = $catFil; }
if ($sentimentFil !== '' && $aiAvailable) { $where[] = 'ai.sentiment = :sentiment'; $params[':sentiment'] = $sentimentFil; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$aiJoin = $aiAvailable ? 'LEFT JOIN feedback_ai_analysis ai ON ai.feedback_id = f.id' : '';
$aiSelect = $aiAvailable ? ', ai.sentiment, ai.confidence_score, ai.flagged_for_review, ai.status AS ai_status' : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM feedback f $aiJoin $whereSql");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$pageInfo = paginate($totalRows);

$sql = "SELECT f.*, fc.name AS category_name $aiSelect
        FROM feedback f
        LEFT JOIN feedback_categories fc ON fc.id = f.category_id
        $aiJoin
        $whereSql
        ORDER BY f.$sortBy $sortDir
        LIMIT {$pageInfo['perPage']} OFFSET {$pageInfo['offset']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// User Avatar Colors
$userColors = ['#4A7EB5', '#a97900', '#10B981', '#8B5CF6', '#F43F5E', '#06B6D4', '#F97316', '#6366F1'];

// Sentiment Icons
$sentimentIcons = [
    'Positive' => 'bi-emoji-smile',
    'Neutral' => 'bi-emoji-neutral',
    'Negative' => 'bi-emoji-frown',
];
?>
<style>
    /* ============================================================
       FEEDBACK TABLE - Coastal Blue Professional
       ============================================================ */
    .feedback-table-wrap {
        background: #ffffff;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 2px 20px rgba(10, 22, 40, 0.05);
        border: 1px solid rgba(10, 22, 40, 0.04);
    }

    .feedback-table-wrap .table {
        margin-bottom: 0;
    }

    .feedback-table-wrap .table thead th {
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

    .feedback-table-wrap .table thead th i {
        color: #a97900;
        margin-right: 0.4rem;
        font-size: 0.85rem;
    }

    .feedback-table-wrap .table thead th a.sort-link {
        color: #ffffff !important;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        transition: color 0.3s ease;
    }

    .feedback-table-wrap .table thead th a.sort-link:hover {
        color: #a97900 !important;
    }

    .feedback-table-wrap .table thead th .sort-icon {
        font-size: 0.6rem;
        opacity: 0.6;
    }

    .feedback-table-wrap .table tbody td {
        padding: 0.85rem 1.25rem;
        vertical-align: middle;
        border-bottom: 1px solid #E2E8F0;
        font-size: 0.9rem;
        color: #0F2137;
        transition: background 0.2s ease;
    }

    .feedback-table-wrap .table tbody tr {
        transition: all 0.2s ease;
    }

    .feedback-table-wrap .table tbody tr:hover {
        background: #F8F5FF;
    }

    .feedback-table-wrap .table tbody tr:last-child td {
        border-bottom: none;
    }

    .feedback-table-wrap .table tbody tr.flagged-row {
        background: #FEF2F2;
        border-left: 3px solid #F43F5E;
    }

    .feedback-table-wrap .table tbody tr.flagged-row:hover {
        background: #FEE2E2;
    }

    /* User Avatar */
    .feedback-table-wrap .user-avatar {
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

    .feedback-table-wrap .user-info {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .feedback-table-wrap .user-name {
        font-weight: 600;
        color: #0F2137;
        font-size: 0.9rem;
    }

    .feedback-table-wrap .user-name .flag-icon {
        color: #F43F5E;
        font-size: 0.75rem;
        margin-left: 0.3rem;
    }

    .feedback-table-wrap .user-email {
        color: #94A3B8;
        font-size: 0.75rem;
    }

    /* Subject */
    .feedback-table-wrap .subject-text {
        color: #0F2137;
        font-size: 0.85rem;
        font-weight: 500;
    }

    .feedback-table-wrap .subject-text .no-subject {
        color: #94A3B8;
        font-weight: 400;
        font-style: italic;
    }

    /* Category */
    .feedback-table-wrap .category-badge {
        display: inline-block;
        background: #EDE9FE;
        color: #5B21B6;
        padding: 0.2rem 0.6rem;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 500;
    }

    /* Sentiment Badges */
    .feedback-table-wrap .sentiment-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.25rem 0.6rem;
        border-radius: 20px;
        font-weight: 600;
        font-size: 0.65rem;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        border: none;
        transition: all 0.3s ease;
    }

    .feedback-table-wrap .sentiment-badge i {
        font-size: 0.7rem;
    }

    .sentiment-badge.positive { background: #D1FAE5; color: #065F46; }
    .sentiment-badge.positive i { color: #10B981; }

    .sentiment-badge.neutral { background: #FEF3C7; color: #92400E; }
    .sentiment-badge.neutral i { color: #F59E0B; }

    .sentiment-badge.negative { background: #FEE2E2; color: #991B1B; }
    .sentiment-badge.negative i { color: #EF4444; }

    .sentiment-badge.analyzing {
        background: #F1F5F9;
        color: #475569;
        gap: 0.4rem;
    }

    .sentiment-badge.analyzing .spinner {
        width: 0.7rem;
        height: 0.7rem;
        border-width: 2px;
    }

    .sentiment-badge.failed {
        background: #F1F5F9;
        color: #DC2626;
        border: 1px solid #FCA5A5;
    }

    /* Confidence Score */
    .feedback-table-wrap .confidence-score {
        font-size: 0.6rem;
        color: #94A3B8;
        margin-left: 0.2rem;
    }

    .feedback-table-wrap .confidence-score .high { color: #10B981; }
    .feedback-table-wrap .confidence-score .medium { color: #F59E0B; }
    .feedback-table-wrap .confidence-score .low { color: #EF4444; }

    /* Status Badges */
    .feedback-table-wrap .status-badge {
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

    .feedback-table-wrap .status-badge i {
        font-size: 0.7rem;
    }

    .status-badge.new { background: #DBEAFE; color: #1E40AF; }
    .status-badge.new i { color: #3B82F6; }

    .status-badge.read { background: #F1F5F9; color: #475569; }
    .status-badge.read i { color: #94A3B8; }

    .status-badge.replied { background: #D1FAE5; color: #065F46; }
    .status-badge.replied i { color: #10B981; }

    .status-badge.closed { background: #F1F5F9; color: #475569; }
    .status-badge.closed i { color: #94A3B8; }

    /* Submitted At */
    .feedback-table-wrap .submitted-at {
        color: #64748B;
        font-size: 0.8rem;
        white-space: nowrap;
    }

    .feedback-table-wrap .submitted-at i {
        color: #a97900;
        margin-right: 0.3rem;
        font-size: 0.7rem;
    }

    /* Action Buttons */
    .feedback-table-wrap .btn-action {
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

    .feedback-table-wrap .btn-action:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    }

    .feedback-table-wrap .btn-action.view:hover {
        background: #2C5282;
        color: white;
        border-color: #2C5282;
    }

    .feedback-table-wrap .btn-action.delete:hover {
        background: #F43F5E;
        color: white;
        border-color: #F43F5E;
    }

    .feedback-table-wrap .btn-group {
        gap: 0.25rem;
    }

    /* Table Footer */
    .feedback-table-wrap .table-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 1.25rem;
        border-top: 1px solid #E2E8F0;
        background: #F8FAFC;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .feedback-table-wrap .table-footer .info-text {
        color: #64748B;
        font-size: 0.8rem;
    }

    .feedback-table-wrap .table-footer .info-text strong {
        color: #0F2137;
    }

    .feedback-table-wrap .pagination {
        margin: 0;
        gap: 0.2rem;
    }

    .feedback-table-wrap .pagination .page-link {
        color: #0F2137;
        border: 1px solid #E2E8F0;
        border-radius: 8px;
        padding: 0.35rem 0.75rem;
        font-size: 0.8rem;
        font-weight: 500;
        transition: all 0.3s ease;
        background: transparent;
    }

    .feedback-table-wrap .pagination .page-link:hover {
        background: #a97900;
        color: #0A1628;
        border-color: #a97900;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(245, 200, 66, 0.2);
    }

    .feedback-table-wrap .pagination .page-item.active .page-link {
        background: linear-gradient(135deg, #0A1628 0%, #1A3A5C 100%);
        border-color: #a97900;
        color: #ffffff;
        box-shadow: 0 4px 15px rgba(10, 22, 40, 0.15);
    }

    .feedback-table-wrap .pagination .page-item.disabled .page-link {
        color: #94A3B8;
        cursor: not-allowed;
        opacity: 0.5;
    }

    /* Empty State */
    .feedback-table-wrap .empty-state {
        text-align: center;
        padding: 3rem 1.5rem;
    }

    .feedback-table-wrap .empty-state i {
        font-size: 3rem;
        color: #a97900;
        opacity: 0.3;
        display: block;
        margin-bottom: 0.75rem;
    }

    .feedback-table-wrap .empty-state h6 {
        color: #0F2137;
        font-weight: 600;
        margin-bottom: 0.25rem;
    }

    .feedback-table-wrap .empty-state p {
        color: #94A3B8;
        font-size: 0.85rem;
        margin: 0;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .feedback-table-wrap .table thead th,
        .feedback-table-wrap .table tbody td {
            padding: 0.6rem 0.8rem;
            font-size: 0.8rem;
        }

        .feedback-table-wrap .user-avatar {
            width: 30px;
            height: 30px;
            font-size: 0.65rem;
        }

        .feedback-table-wrap .user-name {
            font-size: 0.8rem;
        }

        .feedback-table-wrap .btn-action {
            width: 28px;
            height: 28px;
            font-size: 0.7rem;
        }

        .feedback-table-wrap .table-footer {
            flex-direction: column;
            align-items: flex-start;
            padding: 0.6rem 0.8rem;
        }

        .feedback-table-wrap .table-footer .pagination {
            width: 100%;
            justify-content: center;
        }

        .feedback-table-wrap .status-badge,
        .feedback-table-wrap .sentiment-badge {
            font-size: 0.6rem;
            padding: 0.2rem 0.5rem;
        }

        .feedback-table-wrap .subject-text {
            font-size: 0.75rem;
        }

        .feedback-table-wrap .category-badge {
            font-size: 0.6rem;
            padding: 0.15rem 0.4rem;
        }
    }

    @media (max-width: 576px) {
        .feedback-table-wrap .table thead th,
        .feedback-table-wrap .table tbody td {
            padding: 0.4rem 0.5rem;
            font-size: 0.7rem;
        }

        .feedback-table-wrap .user-avatar {
            width: 24px;
            height: 24px;
            font-size: 0.5rem;
        }

        .feedback-table-wrap .user-name {
            font-size: 0.7rem;
        }

        .feedback-table-wrap .user-email {
            font-size: 0.6rem;
        }

        .feedback-table-wrap .btn-action {
            width: 24px;
            height: 24px;
            font-size: 0.6rem;
        }

        .feedback-table-wrap .status-badge,
        .feedback-table-wrap .sentiment-badge {
            font-size: 0.5rem;
            padding: 0.15rem 0.4rem;
            gap: 0.2rem;
        }

        .feedback-table-wrap .status-badge i,
        .feedback-table-wrap .sentiment-badge i {
            font-size: 0.5rem;
        }

        .feedback-table-wrap .subject-text {
            font-size: 0.65rem;
        }

        .feedback-table-wrap .category-badge {
            font-size: 0.55rem;
            padding: 0.1rem 0.3rem;
        }

        .feedback-table-wrap .submitted-at {
            font-size: 0.6rem;
        }

        .feedback-table-wrap .confidence-score {
            font-size: 0.5rem;
        }

        .feedback-table-wrap .table-footer .info-text {
            font-size: 0.65rem;
        }

        .feedback-table-wrap .pagination .page-link {
            font-size: 0.6rem;
            padding: 0.15rem 0.4rem;
        }
    }
</style>

<div class="feedback-table-wrap">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>
                        <a class="sort-link" data-sort="name">
                            <i class="bi bi-person"></i> Submitted By
                            <?php if ($sortBy === 'name'): ?>
                                <span class="sort-icon"><?= $sortDir === 'ASC' ? '↑' : '↓' ?></span>
                            <?php endif; ?>
                        </a>
                    </th>
                    <th><i class="bi bi-envelope"></i> Subject</th>
                    <th><i class="bi bi-tags"></i> Category</th>
                    <?php if ($aiAvailable): ?><th><i class="bi bi-robot"></i> AI Sentiment</th><?php endif; ?>
                    <th>
                        <a class="sort-link" data-sort="status">
                            <i class="bi bi-circle"></i> Status
                            <?php if ($sortBy === 'status'): ?>
                                <span class="sort-icon"><?= $sortDir === 'ASC' ? '↑' : '↓' ?></span>
                            <?php endif; ?>
                        </a>
                    </th>
                    <th>
                        <a class="sort-link" data-sort="submitted_at">
                            <i class="bi bi-clock"></i> Submitted
                            <?php if ($sortBy === 'submitted_at'): ?>
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
                        <td colspan="<?= $aiAvailable ? 7 : 6 ?>">
                            <div class="empty-state">
                                <i class="bi bi-chat-square-text"></i>
                                <h6>No Feedback Found</h6>
                                <p>Try adjusting your filters or search criteria.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): 
                    $isFlagged = $aiAvailable && !empty($row['flagged_for_review']);
                    $userHash = crc32($row['name'] ?? '');
                    $avatarColor = $userColors[abs($userHash) % count($userColors)];
                    $initial = strtoupper(substr($row['name'] ?? '?', 0, 1));
                    $statusLower = strtolower($row['status']);
                    $sentiment = $row['sentiment'] ?? null;
                    $confidence = isset($row['confidence_score']) ? (float)$row['confidence_score'] : null;
                    $aiStatus = $row['ai_status'] ?? null;
                ?>
                    <tr class="<?= $isFlagged ? 'flagged-row' : '' ?>">
                        <td>
                            <div class="user-info">
                                <div class="user-avatar" style="background: <?= e($avatarColor) ?>;">
                                    <?= e($initial) ?>
                                </div>
                                <div>
                                    <div class="user-name">
                                        <?= e($row['name']) ?>
                                        <?php if ($isFlagged): ?>
                                            <i class="bi bi-flag-fill flag-icon" title="Flagged for review by AI"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="user-email"><i class="bi bi-envelope"></i> <?= e($row['email']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="subject-text">
                                <?php if ($row['subject']): ?>
                                    <?= e(truncate($row['subject'], 50)) ?>
                                <?php else: ?>
                                    <span class="no-subject"><?= e(truncate($row['message'], 50)) ?></span>
                                <?php endif; ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($row['category_name']): ?>
                                <span class="category-badge"><?= e($row['category_name']) ?></span>
                            <?php else: ?>
                                <span class="text-muted" style="font-size: 0.8rem;">—</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($aiAvailable): ?>
                        <td>
                            <?php if ($aiStatus === 'pending'): ?>
                                <span class="sentiment-badge analyzing">
                                    <span class="spinner-border spinner-border-sm spinner"></span>
                                    Analyzing...
                                </span>
                            <?php elseif ($aiStatus === 'failed'): ?>
                                <span class="sentiment-badge failed" title="AI analysis failed">
                                    <i class="bi bi-exclamation-triangle"></i> Failed
                                </span>
                            <?php elseif ($sentiment): ?>
                                <span class="sentiment-badge <?= strtolower($sentiment) ?>">
                                    <i class="bi <?= e($sentimentIcons[$sentiment] ?? 'bi-circle') ?>"></i>
                                    <?= e($sentiment) ?>
                                    <?php if ($confidence): ?>
                                        <span class="confidence-score">
                                            <span class="<?= $confidence >= 0.7 ? 'high' : ($confidence >= 0.4 ? 'medium' : 'low') ?>">
                                                (<?= round($confidence * 100) ?>%)
                                            </span>
                                        </span>
                                    <?php endif; ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted" style="font-size: 0.8rem;">—</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td>
                            <span class="status-badge <?= e($statusLower) ?>">
                                <i class="bi <?= match($row['status']) {
                                    'New' => 'bi-envelope',
                                    'Read' => 'bi-eye',
                                    'Replied' => 'bi-reply',
                                    'Closed' => 'bi-check-circle',
                                    default => 'bi-circle'
                                } ?>"></i>
                                <?= e($row['status']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="submitted-at">
                                <i class="bi bi-clock"></i> <?= formatDateTime($row['submitted_at']) ?>
                            </span>
                        </td>
                        <td class="text-end no-print">
                            <div class="btn-group">
                                <button type="button" class="btn-action view btn-view-feedback" 
                                        data-id="<?= (int)$row['id'] ?>" title="View / Reply">
                                    <i class="bi bi-envelope-open"></i>
                                </button>
                                <button type="button" class="btn-action delete" 
                                        title="Delete"
                                        data-confirm-delete="this feedback entry"
                                        data-delete-url="<?= e(APP_URL) ?>/modules/feedback/ajax_delete.php?id=<?= (int)$row['id'] ?>">
                                    <i class="bi bi-trash"></i>
                                </button>
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
            of <strong><?= $totalRows ?></strong> feedback entries
        </span>
        <?= renderPagination($pageInfo, APP_URL . '/modules/feedback/index.php') ?>
    </div>
</div>