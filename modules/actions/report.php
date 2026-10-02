<?php
/**
 * modules/actions/report.php
 * ------------------------------------------------------------------
 * Action statistics & reporting: by status, by office, and a 6-month
 * volume trend, plus print and PDF export.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireRole([ROLE_ADMIN, ROLE_STAFF]);

$pageTitle  = 'Action Reports';
$activeMenu = 'actions';
$pdo = db();

$byStatus = $pdo->query(
    "SELECT status, COUNT(*) AS total FROM hearing_actions GROUP BY status ORDER BY total DESC"
)->fetchAll();

$byOffice = $pdo->query(
    "SELECT COALESCE(o.name, 'Unassigned') AS office, COUNT(*) AS total
     FROM hearing_actions a
     LEFT JOIN offices o ON o.id = a.assigned_office_id
     GROUP BY COALESCE(o.name, 'Unassigned')
     ORDER BY total DESC LIMIT 10"
)->fetchAll();

$byMonth = $pdo->query(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS total
     FROM hearing_actions
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
     GROUP BY DATE_FORMAT(created_at, '%Y-%m')
     ORDER BY ym"
)->fetchAll();

$total = (int)$pdo->query('SELECT COUNT(*) FROM hearing_actions')->fetchColumn();
$overdue = (int)$pdo->query(
    "SELECT COUNT(*) FROM hearing_actions
     WHERE deadline IS NOT NULL AND deadline < CURDATE()
       AND status NOT IN ('Completed','Cancelled')"
)->fetchColumn();
$completed = (int)$pdo->query("SELECT COUNT(*) FROM hearing_actions WHERE status = 'Completed'")->fetchColumn();

include __DIR__ . '/../../layouts/header.php';
?>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../layouts/sidebar.php'; ?>

  <div class="main-content">
    <div class="breadcrumb-bar d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <a href="index.php" class="text-decoration-none small no-print"><i class="bi bi-arrow-left"></i> Back to Response &amp; Action Tracking</a>
        <h5 class="mb-0 mt-1"><i class="bi bi-bar-chart text-primary"></i> Action Reports &amp; Statistics</h5>
        <small class="text-muted">Total: <?= $total ?> &middot; Completed: <?= $completed ?> &middot; Overdue: <?= $overdue ?></small>
      </div>
      <div class="d-flex gap-2 no-print">
        <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
        <a href="export_pdf.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-pdf"></i> Export PDF</a>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header">Actions by Status</div>
          <div class="card-body"><canvas id="statusChart" height="220"></canvas></div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header">Actions by Assigned Office (current)</div>
          <div class="card-body"><canvas id="officeChart" height="220"></canvas></div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Action Volume (Last 6 Months)</div>
      <div class="card-body"><canvas id="trendChart" height="120"></canvas></div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../layouts/footer.php'; ?>
<script>
new Chart(document.getElementById('statusChart'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode(array_column($byStatus, 'status')) ?>,
    datasets: [{ data: <?= json_encode(array_map('intval', array_column($byStatus, 'total'))) ?>, backgroundColor: ['#a97900','#0F2137','#157a6e','#a4302a'] }]
  },
  options: { plugins: { legend: { position: 'bottom' } } }
});
new Chart(document.getElementById('officeChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_column($byOffice, 'office')) ?>,
    datasets: [{ label: 'Actions', data: <?= json_encode(array_map('intval', array_column($byOffice, 'total'))) ?>, backgroundColor: '#0F2137' }]
  },
  options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } }
});
new Chart(document.getElementById('trendChart'), {
  type: 'line',
  data: { labels: <?= json_encode(array_column($byMonth, 'ym')) ?>,
    datasets: [{ label: 'Actions', data: <?= json_encode(array_map('intval', array_column($byMonth, 'total'))) ?>,
      borderColor: '#0b3d6e', backgroundColor: 'rgba(11,61,110,0.12)', fill: true, tension: 0.35 }] },
  options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});
</script>
