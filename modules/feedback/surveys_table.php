<?php
/**
 * modules/feedback/surveys_table.php
 * ------------------------------------------------------------------
 * Filtered/paginated surveys table (Survey Management).
 * ------------------------------------------------------------------
 */

$pdo = db();

$search    = clean($_GET['search'] ?? '');
$statusFil = clean($_GET['status'] ?? '');

$where = [];
$params = [];
if ($search !== '') { $where[] = '(title LIKE :search1 OR description LIKE :search2)'; $params[':search1'] = $params[':search2'] = '%' . $search . '%'; }
if ($statusFil !== '') { $where[] = 'status = :status'; $params[':status'] = $statusFil; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM surveys $whereSql");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$pageInfo = paginate($totalRows);

$sql = "SELECT sv.*, (SELECT COUNT(*) FROM survey_responses r WHERE r.survey_id = sv.id) AS response_count
        FROM surveys sv
        $whereSql
        ORDER BY sv.created_at DESC
        LIMIT {$pageInfo['perPage']} OFFSET {$pageInfo['offset']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
?>
<div class="table-responsive">
  <table class="table table-hover align-middle mb-0">
    <thead>
      <tr>
        <th>Title</th>
        <th>Description</th>
        <th>Status</th>
        <th class="text-center">Responses</th>
        <th class="text-end no-print">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="5" class="text-center text-muted py-4">No surveys found.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td class="fw-semibold"><?= e($row['title']) ?></td>
          <td class="small text-muted"><?= e(truncate($row['description'] ?: '-', 60)) ?></td>
          <td><?= statusBadge($row['status']) ?></td>
          <td class="text-center">
            <?php if (canManage() || hasRole([ROLE_COMMITTEE])): ?>
            <a href="survey_responses.php?id=<?= (int)$row['id'] ?>" class="badge bg-light text-dark border text-decoration-none">
              <?= (int)$row['response_count'] ?>
            </a>
            <?php else: ?>
              <span class="badge bg-light text-dark border"><?= (int)$row['response_count'] ?></span>
            <?php endif; ?>
          </td>
          <td class="text-end no-print">
            <div class="btn-group btn-group-sm">
              <a href="survey_form.php?id=<?= (int)$row['id'] ?>" target="_blank" class="btn btn-outline-secondary" title="Open Public Form"><i class="bi bi-box-arrow-up-right"></i></a>
              <?php if (canManage() || hasRole([ROLE_COMMITTEE])): ?>
              <a href="survey_responses.php?id=<?= (int)$row['id'] ?>" class="btn btn-outline-secondary" title="View Responses"><i class="bi bi-list-check"></i></a>
              <?php endif; ?>
              <?php if (canManage()): ?>
              <button type="button" class="btn btn-outline-primary btn-edit-survey" data-id="<?= (int)$row['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">
  <small class="text-muted">
    Showing <?= $totalRows === 0 ? 0 : $pageInfo['offset'] + 1 ?>–<?= min($pageInfo['offset'] + $pageInfo['perPage'], $totalRows) ?>
    of <?= $totalRows ?> surveys
  </small>
  <?= renderPagination($pageInfo, APP_URL . '/modules/feedback/surveys.php') ?>
</div>
