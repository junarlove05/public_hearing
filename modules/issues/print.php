<?php
/**
 * modules/issues/print.php
 * ------------------------------------------------------------------
 * Print-friendly issue list using the same filters as the main
 * Issue Logging page.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireRole([
    ROLE_ADMIN,
    ROLE_STAFF,
    ROLE_COMMITTEE,
]);

$pdo = db();

$search      = clean($_GET['search'] ?? '');
$statusFil   = clean($_GET['status'] ?? '');
$priorityFil = clean($_GET['priority'] ?? '');
$catFil      = (int)($_GET['category_id'] ?? 0);

$where  = [];
$params = [];

/* Search filter */
if ($search !== '') {
    $where[] = '(
        i.reference_number LIKE :search_reference
        OR i.title LIKE :search_title
        OR i.description LIKE :search_description
        OR o.name LIKE :search_office
        OR h.title LIKE :search_hearing
    )';

    $searchTerm = '%' . $search . '%';

    $params[':search_reference']   = $searchTerm;
    $params[':search_title']       = $searchTerm;
    $params[':search_description'] = $searchTerm;
    $params[':search_office']      = $searchTerm;
    $params[':search_hearing']     = $searchTerm;
}

/* Status filter */
if ($statusFil !== '') {
    $where[] = 'i.status = :status';
    $params[':status'] = $statusFil;
}

/* Priority filter */
if ($priorityFil !== '') {
    $where[] = 'i.priority = :priority';
    $params[':priority'] = $priorityFil;
}

/* Category filter */
if ($catFil > 0) {
    $where[] = 'i.category_id = :category_id';
    $params[':category_id'] = $catFil;
}

$whereSql = $where
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

$stmt = $pdo->prepare(
    "SELECT
        i.id,
        i.reference_number,
        i.title,
        i.description,
        i.priority,
        i.status,
        i.due_at,
        i.created_at,
        ic.name AS category_name,
        h.title AS hearing_title,
        o.name AS assigned_office
     FROM hearing_issues i
     LEFT JOIN hearing_issue_categories ic
        ON ic.id = i.category_id
     LEFT JOIN hearings h
        ON h.id = i.hearing_id
     LEFT JOIN offices o
        ON o.id = i.assigned_office_id
     $whereSql
     ORDER BY i.created_at DESC"
);

$stmt->execute($params);
$rows = $stmt->fetchAll();

$filterDescriptions = [];

if ($search !== '') {
    $filterDescriptions[] = 'Search: ' . $search;
}

if ($statusFil !== '') {
    $filterDescriptions[] = 'Status: ' . $statusFil;
}

if ($priorityFil !== '') {
    $filterDescriptions[] = 'Priority: ' . $priorityFil;
}

if ($catFil > 0) {
    $categoryStmt = $pdo->prepare(
        'SELECT name
         FROM hearing_issue_categories
         WHERE id = :id'
    );

    $categoryStmt->execute([
        ':id' => $catFil,
    ]);

    $categoryName = $categoryStmt->fetchColumn();

    if ($categoryName) {
        $filterDescriptions[] = 'Category: ' . $categoryName;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Issue Log Report</title>

    <link
        href="<?= e(vendorAsset(
            'bootstrap/bootstrap.min.css',
            'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css'
        )) ?>"
        rel="stylesheet"
    >

    <link
        href="<?= e(vendorAsset(
            'bootstrap-icons/bootstrap-icons.css',
            'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css'
        )) ?>"
        rel="stylesheet"
    >

    <style>
        body {
            padding: 28px;
            color: #0f172a;
            background: #ffffff;
            font-family: Arial, sans-serif;
            font-size: 12px;
        }

        .print-header {
            margin-bottom: 22px;
            padding-bottom: 14px;
            text-align: center;
            border-bottom: 3px solid #0b3d6e;
        }

        .print-header h4 {
            color: #0b3d6e;
            font-weight: 700;
        }

        .filter-summary {
            margin-bottom: 14px;
            padding: 8px 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-left: 4px solid #f59e0b;
            border-radius: 5px;
        }

        table {
            width: 100%;
        }

        table th {
            color: #ffffff !important;
            background: #0b3d6e !important;
            vertical-align: middle;
            font-size: 10px;
            text-transform: uppercase;
        }

        table td {
            vertical-align: top;
            font-size: 11px;
        }

        .reference-number {
            white-space: nowrap;
            font-family: monospace;
            font-size: 10px;
        }

        .report-footer {
            margin-top: 18px;
            color: #64748b;
            font-size: 10px;
        }

        @page {
            size: landscape;
            margin: 12mm;
        }

        @media print {
            body {
                padding: 0;
            }

            .no-print {
                display: none !important;
            }

            thead {
                display: table-header-group;
            }

            tr {
                page-break-inside: avoid;
            }
        }
    </style>
</head>

<body onload="window.print()">

<div class="no-print text-end mb-3">
    <button
        type="button"
        class="btn btn-primary btn-sm"
        onclick="window.print()"
    >
        <i class="bi bi-printer"></i>
        Print
    </button>
</div>

<div class="print-header">
    <h4 class="mb-1">
        <?= e(APP_NAME) ?>
    </h4>

    <div class="fw-semibold">
        Public Hearing Issue Log Report
    </div>

    <div class="small text-muted">
        Generated on <?= e(date('F j, Y g:i A')) ?>
    </div>
</div>

<?php if (!empty($filterDescriptions)): ?>
    <div class="filter-summary">
        <strong>Applied filters:</strong>
        <?= e(implode(' | ', $filterDescriptions)) ?>
    </div>
<?php endif; ?>

<table class="table table-bordered table-sm">
    <thead>
        <tr>
            <th style="width: 3%;">#</th>
            <th style="width: 10%;">Reference</th>
            <th style="width: 18%;">Title</th>
            <th style="width: 10%;">Category</th>
            <th style="width: 13%;">Related Hearing</th>
            <th style="width: 12%;">Assigned Office</th>
            <th style="width: 8%;">Priority</th>
            <th style="width: 8%;">Status</th>
            <th style="width: 9%;">Logged</th>
            <th style="width: 9%;">Due Date</th>
        </tr>
    </thead>

    <tbody>
        <?php if (empty($rows)): ?>
            <tr>
                <td
                    colspan="10"
                    class="text-center text-muted py-4"
                >
                    No issues match the selected filters.
                </td>
            </tr>
        <?php endif; ?>

        <?php foreach ($rows as $index => $row): ?>
            <tr>
                <td><?= $index + 1 ?></td>

                <td class="reference-number">
                    <?= e($row['reference_number']) ?>
                </td>

                <td>
                    <strong><?= e($row['title']) ?></strong>
                </td>

                <td>
                    <?= e($row['category_name'] ?: 'Uncategorized') ?>
                </td>

                <td>
                    <?= e($row['hearing_title'] ?: 'None') ?>
                </td>

                <td>
                    <?= e($row['assigned_office'] ?: 'Unassigned') ?>
                </td>

                <td>
                    <?= e($row['priority']) ?>
                </td>

                <td>
                    <?= e($row['status']) ?>
                </td>

                <td>
                    <?= e(formatDate($row['created_at'])) ?>
                </td>

                <td>
                    <?= $row['due_at']
                        ? e(formatDateTime($row['due_at']))
                        : 'None'
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<div class="report-footer">
    <strong>Total:</strong>
    <?= count($rows) ?> issue(s)
</div>

</body>
</html>