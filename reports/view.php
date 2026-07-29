<?php
/**
 * reports/view.php
 * ------------------------------------------------------------------
 * Displays a single report type as a searchable/sortable/paginated
 * table (via the DataTables integration already wired in app.js for
 * any table with class="data-table" — no extra AJAX plumbing needed
 * for what is fundamentally a read-only report view). Print, PDF,
 * Excel, and CSV export all honor the same type + date range.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_ADMIN, ROLE_STAFF]);
require_once __DIR__ . '/report_data.php';

$type     = clean($_GET['type'] ?? '');
$dateFrom = clean($_GET['date_from'] ?? '');
$dateTo   = clean($_GET['date_to'] ?? '');

$report = getReportData($type, $dateFrom ?: null, $dateTo ?: null);
if (!$report) {
    setFlash('danger', 'Unknown report type.');
    redirect(APP_URL . '/reports/index.php');
}

$exportParams = http_build_query(array_filter(['type' => $type, 'date_from' => $dateFrom, 'date_to' => $dateTo]));

$pageTitle  = $report['title'];
$activeMenu = 'reports';

include __DIR__ . '/../layouts/header.php';
?>
<style>
    /* Reports View - Dark Theme */
    :root {
        --rv-dark-900: #0F172A;
        --rv-dark-800: #1E293B;
        --rv-dark-700: #334155;
        --rv-amber: #F59E0B;
        --rv-amber-light: #FBBF24;
        --rv-white: #FFFFFF;
        --rv-gray-100: #F1F5F9;
        --rv-gray-200: #E2E8F0;
        --rv-gray-300: #CBD5E1;
        --rv-gray-400: #94A3B8;
        --rv-gray-500: #64748B;
        --rv-gray-600: #475569;
        --rv-emerald: #10B981;
        --rv-rose: #F43F5E;
        --rv-violet: #8B5CF6;
        --rv-cyan: #06B6D4;
        --rv-orange: #F97316;
        --rv-teal: #14B8A6;
        --rv-indigo: #6366F1;
    }

    /* Breadcrumb Bar */
    .breadcrumb-bar {
        background: var(--rv-white);
        border-left: 4px solid var(--rv-amber);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
        padding: 1rem 1.5rem;
        border-radius: 12px;
        margin-bottom: 1.5rem;
    }

    .breadcrumb-bar h5 {
        color: var(--rv-dark-900);
        font-weight: 700;
    }

    .breadcrumb-bar h5 i {
        color: var(--rv-amber);
    }

    .breadcrumb-bar .text-muted {
        color: var(--rv-gray-500) !important;
    }

    .breadcrumb-bar a {
        color: var(--rv-amber) !important;
        text-decoration: none;
        font-weight: 500;
        transition: color 0.3s ease;
    }

    .breadcrumb-bar a:hover {
        color: var(--rv-dark-900) !important;
        text-decoration: underline;
    }

    /* Buttons */
    .btn-outline-secondary {
        border: 2px solid var(--rv-gray-200);
        color: var(--rv-gray-600);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        border-radius: 10px;
        font-weight: 500;
        background: transparent;
        padding: 0.35rem 0.9rem;
        font-size: 0.8rem;
    }

    .btn-outline-secondary:hover {
        background: var(--rv-gray-100);
        border-color: var(--rv-amber);
        color: var(--rv-dark-900);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    }

    .btn-outline-secondary i {
        color: var(--rv-amber);
    }

    /* Cards */
    .card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 2px 15px rgba(0, 0, 0, 0.06);
        background: var(--rv-white);
        transition: all 0.3s ease;
        overflow: hidden;
    }

    .card:hover {
        box-shadow: 0 4px 25px rgba(0, 0, 0, 0.1);
    }

    .card-body {
        padding: 1.25rem;
        background: var(--rv-white);
    }

    /* Table */
    .table {
        margin-bottom: 0;
    }

    .table thead th {
        background: linear-gradient(135deg, var(--rv-dark-900) 0%, var(--rv-dark-800) 100%);
        color: var(--rv-white) !important;
        border-bottom: 4px solid var(--rv-amber);
        font-weight: 600;
        padding: 0.85rem 1.25rem;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        border-color: transparent;
        position: sticky;
        top: 0;
        z-index: 10;
    }

    .table thead th i {
        color: var(--rv-amber);
        margin-right: 0.4rem;
        font-size: 0.9rem;
    }

    .table thead th,
    .table thead th *,
    .table thead th span,
    .table thead th div {
        color: var(--rv-white) !important;
    }

    .table tbody td {
        padding: 0.75rem 1.25rem;
        vertical-align: middle;
        color: var(--rv-dark-900);
        border-bottom: 1px solid var(--rv-gray-200);
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

    /* Table Striped */
    .table-striped tbody tr:nth-of-type(odd) {
        background-color: var(--rv-gray-100);
    }

    .table-striped tbody tr:nth-of-type(odd):hover {
        background: #FFFBEB;
    }

    /* DataTables overrides */
    .dataTables_wrapper .dataTables_filter input {
        border: 2px solid var(--rv-gray-200);
        border-radius: 10px;
        padding: 0.4rem 0.75rem;
        font-size: 0.875rem;
        transition: all 0.3s ease;
        background: var(--rv-gray-100);
        color: var(--rv-dark-900);
        font-weight: 500;
        margin-left: 0.5rem;
    }

    .dataTables_wrapper .dataTables_filter input:focus {
        border-color: var(--rv-amber);
        box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.12);
        background: var(--rv-white);
        outline: none;
    }

    .dataTables_wrapper .dataTables_length select {
        border: 2px solid var(--rv-gray-200);
        border-radius: 10px;
        padding: 0.3rem 0.6rem;
        font-size: 0.875rem;
        background: var(--rv-gray-100);
        color: var(--rv-dark-900);
        font-weight: 500;
    }

    .dataTables_wrapper .dataTables_length select:focus {
        border-color: var(--rv-amber);
        box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.12);
        outline: none;
    }

    .dataTables_wrapper .dataTables_info {
        color: var(--rv-gray-500);
        font-size: 0.85rem;
    }

    .dataTables_wrapper .dataTables_paginate .paginate_button {
        color: var(--rv-dark-900) !important;
        border: 1px solid var(--rv-gray-200);
        border-radius: 8px;
        padding: 0.3rem 0.8rem;
        margin: 0 2px;
        transition: all 0.3s ease;
        background: transparent !important;
    }

    .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
        background: var(--rv-amber) !important;
        color: var(--rv-dark-900) !important;
        border-color: var(--rv-amber);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(245, 158, 11, 0.2);
    }

    .dataTables_wrapper .dataTables_paginate .paginate_button.current {
        background: linear-gradient(135deg, var(--rv-dark-900) 0%, var(--rv-dark-800) 100%) !important;
        border-color: var(--rv-amber) !important;
        color: var(--rv-white) !important;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    }

    .dataTables_wrapper .dataTables_paginate .paginate_button.disabled {
        color: var(--rv-gray-400) !important;
        border-color: var(--rv-gray-200) !important;
        opacity: 0.6;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .breadcrumb-bar {
            flex-direction: column;
            gap: 0.5rem;
            align-items: flex-start;
            padding: 1rem;
        }
        .table thead th,
        .table tbody td {
            padding: 0.6rem 0.8rem;
            font-size: 0.8rem;
        }
        .breadcrumb-bar .d-flex.gap-2 {
            flex-wrap: wrap;
            width: 100%;
        }
        .breadcrumb-bar .d-flex.gap-2 .btn {
            flex: 1;
            min-width: 60px;
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
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
        .dataTables_wrapper .dataTables_filter input {
            font-size: 0.75rem;
            padding: 0.3rem 0.6rem;
            width: 150px !important;
        }
        .dataTables_wrapper .dataTables_length select {
            font-size: 0.75rem;
            padding: 0.2rem 0.4rem;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
        }
    }
</style>
<div class="app-wrapper">
  <?php include __DIR__ . '/../layouts/sidebar.php'; ?>

  <div class="main-content">
    <div class="breadcrumb-bar d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <a href="index.php" class="text-decoration-none small no-print"><i class="bi bi-arrow-left"></i> Back to Reports</a>
        <h5 class="mb-0 mt-1"><i class="bi bi-file-earmark-bar-graph"></i> <?= e($report['title']) ?></h5>
        <small class="text-muted">
          <?= count($report['rows']) ?> record(s)
          <?php if ($dateFrom || $dateTo): ?>
            &middot; <?= $dateFrom ? formatDate($dateFrom) : 'the beginning' ?> to <?= $dateTo ? formatDate($dateTo) : 'now' ?>
          <?php endif; ?>
        </small>
      </div>
      <div class="d-flex gap-2 no-print">
        <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
        <a href="print.php?<?= e($exportParams) ?>" target="_blank" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark"></i> Print View</a>
        <a href="export_pdf.php?<?= e($exportParams) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
        <a href="export_excel.php?<?= e($exportParams) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-excel"></i> Excel</a>
        <a href="export_csv.php?<?= e($exportParams) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-filetype-csv"></i> CSV</a>
      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-striped table-hover data-table w-100">
            <thead>
              <tr><?php foreach ($report['headers'] as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr>
            </thead>
            <tbody>
              <?php if (empty($report['rows'])): ?>
                <tr><td colspan="<?= count($report['headers']) ?>" class="text-center text-muted py-4">No records found for this range.</td></tr>
              <?php endif; ?>
              <?php foreach ($report['rows'] as $row): ?>
                <tr><?php foreach ($row as $cell): ?><td><?= e((string)$cell) ?></td><?php endforeach; ?></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../layouts/footer.php'; ?>