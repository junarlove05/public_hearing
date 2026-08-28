<?php
/**
 * modules/issues/report.php
 * ------------------------------------------------------------------
 * Issue statistics and reporting:
 * - Issues by category
 * - Issues by priority
 * - Issues by status
 * - Six-month issue volume trend
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireRole([ROLE_ADMIN, ROLE_STAFF]);

$pageTitle  = 'Issue Reports';
$activeMenu = 'issues';

$pdo = db();

/* ============================================================
 * ISSUES BY CATEGORY
 * Includes categories that currently have zero issues.
 * ============================================================ */
$byCategory = $pdo->query(
    "SELECT
        ic.name,
        COUNT(i.id) AS total
     FROM hearing_issue_categories ic
     LEFT JOIN hearing_issues i
        ON i.category_id = ic.id
     GROUP BY ic.id, ic.name
     ORDER BY total DESC, ic.name ASC"
)->fetchAll();

/* ============================================================
 * ISSUES BY PRIORITY
 * ============================================================ */
$byPriority = $pdo->query(
    "SELECT
        priority,
        COUNT(*) AS total
     FROM hearing_issues
     GROUP BY priority
     ORDER BY FIELD(
        priority,
        'Critical',
        'High',
        'Medium',
        'Low'
     )"
)->fetchAll();

/* ============================================================
 * ISSUES BY STATUS
 * ============================================================ */
$byStatus = $pdo->query(
    "SELECT
        status,
        COUNT(*) AS total
     FROM hearing_issues
     GROUP BY status
     ORDER BY FIELD(
        status,
        'Open',
        'In Progress',
        'Resolved',
        'Closed'
     )"
)->fetchAll();

/* ============================================================
 * ISSUE VOLUME FOR THE LAST SIX MONTHS
 * ============================================================ */
$byMonth = $pdo->query(
    "SELECT
        DATE_FORMAT(created_at, '%Y-%m') AS ym,
        COUNT(*) AS total
     FROM hearing_issues
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
     GROUP BY DATE_FORMAT(created_at, '%Y-%m')
     ORDER BY ym"
)->fetchAll();

/* ============================================================
 * SUMMARY COUNTS
 * ============================================================ */
$total = (int)$pdo
    ->query("SELECT COUNT(*) FROM hearing_issues")
    ->fetchColumn();

$open = (int)$pdo
    ->query(
        "SELECT COUNT(*)
         FROM hearing_issues
         WHERE status = 'Open'"
    )
    ->fetchColumn();

$inProgress = (int)$pdo
    ->query(
        "SELECT COUNT(*)
         FROM hearing_issues
         WHERE status = 'In Progress'"
    )
    ->fetchColumn();

$resolved = (int)$pdo
    ->query(
        "SELECT COUNT(*)
         FROM hearing_issues
         WHERE status = 'Resolved'"
    )
    ->fetchColumn();

$closed = (int)$pdo
    ->query(
        "SELECT COUNT(*)
         FROM hearing_issues
         WHERE status = 'Closed'"
    )
    ->fetchColumn();

/*
 * Generate chart colors according to the actual labels returned.
 */
$priorityColorMap = [
    'Critical' => '#b91c1c',
    'High'     => '#ef4444',
    'Medium'   => '#f59e0b',
    'Low'      => '#10b981',
];

$priorityColors = array_map(
    static fn(array $row): string =>
        $priorityColorMap[$row['priority']] ?? '#64748b',
    $byPriority
);

$statusColorMap = [
    'Open'        => '#2563eb',
    'In Progress' => '#f59e0b',
    'Resolved'    => '#10b981',
    'Closed'      => '#64748b',
];

$statusColors = array_map(
    static fn(array $row): string =>
        $statusColorMap[$row['status']] ?? '#64748b',
    $byStatus
);

include __DIR__ . '/../../layouts/header.php';
?>

<style>
.main-content {
.main-content,
.orlms-main-content {
    margin-left: 286px !important;
    padding-top: 0.5rem !important;
    padding-left: 1.5rem !important;
    padding-right: 1.5rem !important;
    min-height: 100vh !important;
    position: relative !important;
    transition: margin-left 0.25s ease !important;
}

body.sidebar-collapsed .main-content,
body.sidebar-collapsed .orlms-main-content,
.main-content.sidebar-collapsed {
    margin-left: 74px !important;
}

.report-summary-card {
    height: 100%;
    padding: 1rem 1.2rem;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-left: 4px solid #f59e0b;
    border-radius: 12px;
    box-shadow: 0 3px 15px rgba(15, 23, 42, 0.06);
}

.report-summary-value {
    color: #0f172a;
    font-size: 1.75rem;
    font-weight: 800;
}

.report-summary-label {
    color: #64748b;
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.chart-card {
    height: 100%;
    overflow: hidden;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    box-shadow: 0 4px 20px rgba(15, 23, 42, 0.07);
}

.chart-card .card-header {
    color: #ffffff;
    background: #102a46;
    border-bottom: 3px solid #f59e0b;
    font-weight: 700;
}

.chart-container {
    position: relative;
    min-height: 280px;
}

@media (max-width: 992px) {
    .main-content {
        margin-left: 0 !important;
        max-width: 100% !important;
        padding: 15px !important;
    }
}

@media print {
    .no-print,
    .sidebar,
    .app-sidebar {
        display: none !important;
    }

    .main-content {
        margin: 0 !important;
        max-width: 100% !important;
        padding: 0 !important;
    }
}
</style>

<div class="app-wrapper">
    <?php include __DIR__ . '/../../layouts/sidebar.php'; ?>

    <div class="main-content">
        <div
            class="breadcrumb-bar d-flex justify-content-between
                   align-items-center flex-wrap gap-2"
        >
            <div>
                <a
                    href="index.php"
                    class="text-decoration-none small no-print"
                >
                    <i class="bi bi-arrow-left"></i>
                    Back to Issue Logging
                </a>

                <h5 class="mb-0 mt-1">
                    <i class="bi bi-bar-chart text-primary"></i>
                    Issue Reports &amp; Statistics
                </h5>

                <small class="text-muted">
                    Statistics for public-hearing issues and consultations
                </small>
            </div>

            <div class="d-flex gap-2 no-print">
                <button
                    type="button"
                    class="btn btn-outline-secondary btn-sm"
                    onclick="window.print()"
                >
                    <i class="bi bi-printer"></i>
                    Print
                </button>

                <a
                    href="export_pdf.php"
                    class="btn btn-outline-secondary btn-sm"
                >
                    <i class="bi bi-file-earmark-pdf"></i>
                    Export PDF
                </a>
            </div>
        </div>

        <!-- Summary cards -->
        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-lg">
                <div class="report-summary-card">
                    <div class="report-summary-value">
                        <?= $total ?>
                    </div>
                    <div class="report-summary-label">
                        Total Issues
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-lg">
                <div class="report-summary-card">
                    <div class="report-summary-value">
                        <?= $open ?>
                    </div>
                    <div class="report-summary-label">
                        Open
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-lg">
                <div class="report-summary-card">
                    <div class="report-summary-value">
                        <?= $inProgress ?>
                    </div>
                    <div class="report-summary-label">
                        In Progress
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-lg">
                <div class="report-summary-card">
                    <div class="report-summary-value">
                        <?= $resolved ?>
                    </div>
                    <div class="report-summary-label">
                        Resolved
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-lg">
                <div class="report-summary-card">
                    <div class="report-summary-value">
                        <?= $closed ?>
                    </div>
                    <div class="report-summary-label">
                        Closed
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="row g-3 mb-3">
            <div class="col-lg-4">
                <div class="card chart-card">
                    <div class="card-header">
                        Issues by Category
                    </div>

                    <div class="card-body chart-container">
                        <canvas id="categoryChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card chart-card">
                    <div class="card-header">
                        Issues by Priority
                    </div>

                    <div class="card-body chart-container">
                        <canvas id="priorityChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card chart-card">
                    <div class="card-header">
                        Issues by Status
                    </div>

                    <div class="card-body chart-container">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="card chart-card">
            <div class="card-header">
                Issue Volume — Last Six Months
            </div>

            <div class="card-body chart-container">
                <canvas id="trendChart"></canvas>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../layouts/footer.php'; ?>

<script>
new Chart(document.getElementById('categoryChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(
            array_column($byCategory, 'name'),
            JSON_UNESCAPED_UNICODE
        ) ?>,
        datasets: [{
            label: 'Issues',
            data: <?= json_encode(
                array_map(
                    'intval',
                    array_column($byCategory, 'total')
                )
            ) ?>,
            backgroundColor: '#1d6fb8',
            borderRadius: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    precision: 0
                }
            }
        }
    }
});

new Chart(document.getElementById('priorityChart'), {
    type: 'pie',
    data: {
        labels: <?= json_encode(
            array_column($byPriority, 'priority')
        ) ?>,
        datasets: [{
            data: <?= json_encode(
                array_map(
                    'intval',
                    array_column($byPriority, 'total')
                )
            ) ?>,
            backgroundColor: <?= json_encode($priorityColors) ?>
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});

new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(
            array_column($byStatus, 'status')
        ) ?>,
        datasets: [{
            data: <?= json_encode(
                array_map(
                    'intval',
                    array_column($byStatus, 'total')
                )
            ) ?>,
            backgroundColor: <?= json_encode($statusColors) ?>
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});

new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(
            array_column($byMonth, 'ym')
        ) ?>,
        datasets: [{
            label: 'Issues',
            data: <?= json_encode(
                array_map(
                    'intval',
                    array_column($byMonth, 'total')
                )
            ) ?>,
            borderColor: '#0b3d6e',
            backgroundColor: 'rgba(11, 61, 110, 0.12)',
            fill: true,
            tension: 0.35
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    precision: 0
                }
            }
        }
    }
});
</script>
