<?php
/**
 * modules/feedback/survey_responses_table.php
 * ------------------------------------------------------------------
 * Filtered/paginated survey_responses table for a single survey.
 * Expects $_GET['id'] (survey id) to be set.
 * ------------------------------------------------------------------
 */

$pdo = db();

$surveyId = (int)($_GET['id'] ?? 0);
$search   = clean($_GET['search'] ?? '');

$where = ['r.survey_id = :sid'];
$params = [':sid' => $surveyId];
if ($search !== '') {
    $where[] = '(r.respondent_name LIKE :search1 OR r.respondent_email LIKE :search2 OR r.response_text LIKE :search3)';
    $params[':search1'] = $params[':search2'] = $params[':search3'] = '%' . $search . '%';
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM survey_responses r $whereSql");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$pageInfo = paginate($totalRows);

$sql = "SELECT r.* FROM survey_responses r $whereSql ORDER BY r.submitted_at DESC
        LIMIT {$pageInfo['perPage']} OFFSET {$pageInfo['offset']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
?>
<div class="table-responsive">
  <table class="table table-hover align-middle mb-0">
    <thead>
      <tr>
        <th>Respondent</th>
        <th>Response</th>
        <th>Submitted</th>
        <?php if (canManage()): ?><th class="text-end no-print">Actions</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="4" class="text-center text-muted py-4">No responses yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td>
            <div class="fw-semibold"><?= e($row['respondent_name'] ?: 'Anonymous') ?></div>
            <div class="text-muted small"><?= e($row['respondent_email'] ?: '-') ?></div>
          </td>
          <td class="small"><?= nl2br(e(truncate($row['response_text'], 200))) ?></td>
          <td class="small text-muted"><?= formatDateTime($row['submitted_at']) ?></td>
          <?php if (canManage()): ?>
          <td class="text-end no-print">
            <button type="button" class="btn btn-outline-danger btn-sm" title="Delete"
                    data-confirm-delete="this survey response"
                    data-delete-url="<?= e(APP_URL) ?>/modules/feedback/ajax_survey_response_delete.php?id=<?= (int)$row['id'] ?>">
              <i class="bi bi-trash"></i>
            </button>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">
  <small class="text-muted">
    Showing <?= $totalRows === 0 ? 0 : $pageInfo['offset'] + 1 ?>–<?= min($pageInfo['offset'] + $pageInfo['perPage'], $totalRows) ?>
    of <?= $totalRows ?> response(s)
  </small>
  <?= renderPagination($pageInfo, APP_URL . '/modules/feedback/survey_responses.php') ?>
</div>
