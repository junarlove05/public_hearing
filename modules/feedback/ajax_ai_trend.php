<?php
/**
 * modules/feedback/ajax_ai_trend.php
 * ------------------------------------------------------------------
 * Returns sentiment trend data (counts of Positive/Neutral/Negative
 * per time bucket) for the AI Analytics trend chart, for a given
 * period: day, week, month, or year.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/AI/AIAnalysisManager.php';
requireRole([ROLE_ADMIN, ROLE_STAFF]);

if (!AIAnalysisManager::tablesExist()) {
    jsonResponse(true, '', ['labels' => [], 'positive' => [], 'neutral' => [], 'negative' => []]);
}

$period = clean($_GET['period'] ?? 'day');
$allowed = ['day', 'week', 'month', 'year'];
if (!in_array($period, $allowed, true)) $period = 'day';

$pdo = db();

switch ($period) {
    case 'week':
        $bucketExpr = "DATE_FORMAT(f.submitted_at, '%x-W%v')"; // ISO year-week
        $sinceExpr = 'DATE_SUB(CURDATE(), INTERVAL 12 WEEK)';
        break;
    case 'month':
        $bucketExpr = "DATE_FORMAT(f.submitted_at, '%Y-%m')";
        $sinceExpr = 'DATE_SUB(CURDATE(), INTERVAL 12 MONTH)';
        break;
    case 'year':
        $bucketExpr = "DATE_FORMAT(f.submitted_at, '%Y')";
        $sinceExpr = 'DATE_SUB(CURDATE(), INTERVAL 5 YEAR)';
        break;
    case 'day':
    default:
        $bucketExpr = "DATE_FORMAT(f.submitted_at, '%Y-%m-%d')";
        $sinceExpr = 'DATE_SUB(CURDATE(), INTERVAL 30 DAY)';
        break;
}

$sql = "SELECT $bucketExpr AS bucket, ai.sentiment, COUNT(*) AS total
        FROM feedback f
        JOIN feedback_ai_analysis ai ON ai.feedback_id = f.id
        WHERE ai.status = 'completed' AND ai.sentiment IS NOT NULL AND f.submitted_at >= $sinceExpr
        GROUP BY bucket, ai.sentiment
        ORDER BY bucket ASC";
$rows = $pdo->query($sql)->fetchAll();

$buckets = [];
foreach ($rows as $row) {
    $b = $row['bucket'];
    if (!isset($buckets[$b])) $buckets[$b] = ['Positive' => 0, 'Neutral' => 0, 'Negative' => 0];
    $buckets[$b][$row['sentiment']] = (int)$row['total'];
}
ksort($buckets);

jsonResponse(true, '', [
    'labels' => array_keys($buckets),
    'positive' => array_map(fn($b) => $b['Positive'], $buckets),
    'neutral' => array_map(fn($b) => $b['Neutral'], $buckets),
    'negative' => array_map(fn($b) => $b['Negative'], $buckets),
]);
