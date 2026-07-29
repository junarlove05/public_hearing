<?php
/**
 * modules/feedback/report.php
 * ------------------------------------------------------------------
 * Feedback statistics & reporting: by category, by status, and over
 * time, plus print and PDF export of the summary.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireRole([ROLE_ADMIN, ROLE_STAFF]);

$pageTitle  = 'Feedback Reports';
$activeMenu = 'feedback';
$activeTab  = 'reports';
$pdo = db();

$byCategory = $pdo->query(
    "SELECT fc.name, COUNT(f.id) AS total
     FROM feedback_categories fc LEFT JOIN feedback f ON f.category_id = fc.id
     GROUP BY fc.id, fc.name ORDER BY total DESC"
)->fetchAll();

$byStatus = $pdo->query("SELECT status, COUNT(*) AS total FROM feedback GROUP BY status")->fetchAll();

$byMonth = $pdo->query(
    "SELECT DATE_FORMAT(submitted_at, '%Y-%m') AS ym, COUNT(*) AS total
     FROM feedback WHERE submitted_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
     GROUP BY ym ORDER BY ym"
)->fetchAll();

$total = (int)$pdo->query('SELECT COUNT(*) FROM feedback')->fetchColumn();

include __DIR__ . '/../../layouts/header.php';
?>
<style>
    /* Feedback Reports - Dark Theme */
    :root {
        --rp-dark-900: #0F172A;
        --rp-dark-800: #1E293B;
        --rp-dark-700: #334155;
        --rp-amber: #F59E0B;
        --rp-amber-light: #FBBF24;
        --rp-white: #FFFFFF;
        --rp-gray-100: #F1F5F9;
        --rp-gray-200: #E2E8F0;
        --rp-gray-300: #CBD5E1;
        --rp-gray-400: #94A3B8;
        --rp-gray-500: #64748B;
        --rp-gray-600: #475569;
        --rp-emerald: #10B981;
        --rp-rose: #F43F5E;
        --rp-violet: #8B5CF6;
        --rp-cyan: #06B6D4;
    }

    /* Breadcrumb Bar */
    .breadcrumb-bar {
        background: var(--rp-white);
        border-left: 4px solid var(--rp-amber);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
        padding: 1rem 1.5rem;
        border-radius: 12px;
        margin-bottom: 1.5rem;
    }

    .breadcrumb-bar h5 {
        color: var(--rp-dark-900);
        font-weight: 700;
    }

    .breadcrumb-bar h5 i {
        color: var(--rp-amber);
    }

    .breadcrumb-bar .text-muted {
        color: var(--rp-gray-500) !important;
    }

    /* Buttons */
    .btn-outline-secondary {
        border: 2px solid var(--rp-gray-200);
        color: var(--rp-gray-600);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        border-radius: 10px;
        font-weight: 500;
        background: transparent;
        padding: 0.4rem 1rem;
    }

    .btn-outline-secondary:hover {
        background: var(--rp-gray-100);
        border-color: var(--rp-amber);
        color: var(--rp-dark-900);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    }

    .btn-outline-secondary i {
        color: var(--rp-amber);
    }

    /* Cards */
    .card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 2px 15px rgba(0, 0, 0, 0.06);
        background: var(--rp-white);
        transition: all 0.3s ease;
        overflow: hidden;
    }

    .card:hover {
        box-shadow: 0 4px 25px rgba(0, 0, 0, 0.1);
    }

    .card-header {
        background: linear-gradient(135deg, var(--rp-dark-900) 0%, var(--rp-dark-800) 100%);
        color: var(--rp-white);
        font-weight: 600;
        padding: 0.75rem 1.25rem;
        border-bottom: 3px solid var(--rp-amber);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .card-header i {
        color: var(--rp-amber);
        font-size: 1.1rem;
    }

    .card-body {
        padding: 1.25rem;
        background: var(--rp-white);
    }

    /* Table */
    .table {
        margin-bottom: 0;
        border-radius: 16px;
        overflow: hidden;
    }

    .table thead th {
        background: linear-gradient(135deg, var(--rp-dark-900) 0%, var(--rp-dark-800) 100%);
        color: var(--rp-white) !important;
        border-bottom: 4px solid var(--rp-amber);
        font-weight: 600;
        padding: 0.85rem 1.25rem;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        border-color: transparent;
    }

    .table thead th i {
        color: var(--rp-amber);
        margin-right: 0.4rem;
        font-size: 0.9rem;
    }

    .table thead th,
    .table thead th *,
    .table thead th span,
    .table thead th div {
        color: var(--rp-white) !important;
    }

    .table tbody td {
        padding: 0.85rem 1.25rem;
        vertical-align: middle;
        color: var(--rp-dark-900);
        border-bottom: 1px solid var(--rp-gray-200);
        font-size: 0.9rem;
        transition: background 0.2s ease;
    }

    .table tbody tr {
        transition: all 0.2s ease;
    }

    .table tbody tr:hover {
        background: #FFFBEB;
        transform: scale(1.002);
    }

    .table tbody tr:last-child td {
        border-bottom: none;
    }

    .table tbody td.text-end {
        font-weight: 700;
        color: var(--rp-amber);
        font-size: 1rem;
    }

    .table-bordered {
        border: 1px solid var(--rp-gray-200);
        border-radius: 16px;
        overflow: hidden;
    }

    .table-bordered thead th {
        border-color: var(--rp-dark-900);
    }

    .table-bordered tbody td {
        border-color: var(--rp-gray-200);
    }

    /* Responsive */
    @media (max-width: 768px) {
        .breadcrumb-bar {
            flex-direction: column;
            gap: 0.5rem;
            align-items: flex-start;
            padding: 1rem;
        }
        .card-header {
            font-size: 0.9rem;
        }
        .table thead th,
        .table tbody td {
            padding: 0.6rem 0.8rem;
            font-size: 0.8rem;
        }
    }

    @media (max-width: 576px) {
        .breadcrumb-bar h5 {
            font-size: 0.95rem;
        }
        .breadcrumb-bar .text-muted {
            font-size: 0.75rem;
        }
        .card-body {
            padding: 0.75rem;
        }
        .table thead th,
        .table tbody td {
            padding: 0.4rem 0.6rem;
            font-size: 0.7rem;
        }
    }
</style>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../layouts/sidebar.php'; ?>

  <div class="main-content">
    <div class="breadcrumb-bar d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <h5 class="mb-0"><i class="bi bi-bar-chart"></i> Feedback Reports &amp; Statistics</h5>
        <small class="text-muted">Total feedback received: <?= $total ?></small>
      </div>
      <div class="d-flex gap-2 no-print">
        <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
        <a href="export_pdf.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-pdf"></i> Export PDF</a>
      </div>
    </div>

    <?php include __DIR__ . '/tabs.php'; ?>

    <div class="row g-3 mb-3">
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header"><i class="bi bi-pie-chart"></i> Feedback by Category</div>
          <div class="card-body"><canvas id="categoryChart" height="200"></canvas></div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header"><i class="bi bi-donut"></i> Feedback by Status</div>
          <div class="card-body"><canvas id="statusChart" height="200"></canvas></div>
        </div>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-12">
        <div class="card">
          <div class="card-header"><i class="bi bi-graph-up"></i> Feedback Volume (Last 6 Months)</div>
          <div class="card-body"><canvas id="trendChart" height="120"></canvas></div>
        </div>
      </div>
    </div>

    <div class="card mt-4 no-print">
      <div class="card-header"><i class="bi bi-table"></i> Feedback by Category Summary</div>
      <div class="card-body p-0">
        <table class="table table-bordered mb-0">
          <thead><tr><th><i class="bi bi-tag"></i> Category</th><th class="text-end"><i class="bi bi-counter"></i> Total</th></tr></thead>
          <tbody>
            <?php foreach ($byCategory as $c): ?>
              <tr><td><?= e($c['name']) ?></td><td class="text-end"><?= (int)$c['total'] ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../layouts/footer.php'; ?>
<script>
// ---- Feedback by Category Bar Chart ----
new Chart(document.getElementById('categoryChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_column($byCategory, 'name')) ?>,
    datasets: [{ 
      label: 'Feedback', 
      data: <?= json_encode(array_map('intval', array_column($byCategory, 'total'))) ?>, 
      backgroundColor: '#F59E0B',
      borderColor: '#D97706',
      borderWidth: 2,
      borderRadius: 6
    }]
  },
  options: { 
    plugins: { legend: { display: false } }, 
    scales: { 
      y: { beginAtZero: true, ticks: { precision: 0 } },
      x: { grid: { display: false } }
    } 
  }
});

// ---- Feedback by Status Doughnut Chart ----
new Chart(document.getElementById('statusChart'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode(array_column($byStatus, 'status')) ?>,
    datasets: [{ 
      data: <?= json_encode(array_map('intval', array_column($byStatus, 'total'))) ?>, 
      backgroundColor: ['#1E293B', '#F59E0B', '#10B981', '#F43F5E', '#8B5CF6']
    }]
  },
  options: { 
    plugins: { 
      legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 12, weight: '600' } } } 
    },
    cutout: '65%'
  }
});

// ---- Feedback Trend Line Chart ----
new Chart(document.getElementById('trendChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode(array_column($byMonth, 'ym')) ?>,
    datasets: [{ 
      label: 'Feedback', 
      data: <?= json_encode(array_map('intval', array_column($byMonth, 'total'))) ?>,
      borderColor: '#F59E0B',
      backgroundColor: 'rgba(245, 158, 11, 0.12)',
      fill: true, 
      tension: 0.4,
      pointBackgroundColor: '#F59E0B',
      pointBorderColor: '#D97706',
      pointBorderWidth: 2,
      pointRadius: 5
    }]
  },
  options: { 
    plugins: { legend: { display: false } }, 
    scales: { 
      y: { beginAtZero: true, ticks: { precision: 0 } },
      x: { grid: { display: false } }
    } 
  }
});
</script>