<?php
/**
 * reports/report_data.php
 * ------------------------------------------------------------------
 * Central data-fetching engine for the cross-module Reports hub.
 * Given a report $type and an optional date range, returns a
 * consistent structure used by view.php, print.php, and every
 * export_*.php endpoint — so the query logic for each report type
 * lives in exactly one place.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';

/**
 * @return array{
 *   title:string, headers:string[], rows:array[], date_from:?string, date_to:?string
 * }|null  null if $type is not recognized
 */
function getReportData(string $type, ?string $dateFrom, ?string $dateTo): ?array
{
    $pdo = db();
    $dateFrom = $dateFrom ?: null;
    $dateTo = $dateTo ?: null;

    switch ($type) {
        case 'hearings':
            $where = []; $params = [];
            if ($dateFrom) { $where[] = 'h.hearing_date >= :df'; $params[':df'] = $dateFrom; }
            if ($dateTo)   { $where[] = 'h.hearing_date <= :dt'; $params[':dt'] = $dateTo; }
            $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $stmt = $pdo->prepare(
                "SELECT h.title, ht.name AS type_name, c.name AS committee_name, h.hearing_date, h.hearing_time, h.venue, h.status
                 FROM hearings h
                 LEFT JOIN hearing_types ht ON ht.id = h.hearing_type_id
                 LEFT JOIN committees c ON c.id = h.committee_id
                 $whereSql ORDER BY h.hearing_date DESC"
            );
            $stmt->execute($params);
            $rows = array_map(fn($r) => [
                $r['title'], $r['type_name'] ?: '-', $r['committee_name'] ?: '-',
                formatDate($r['hearing_date']), formatTime($r['hearing_time']), $r['venue'] ?: '-', $r['status'],
            ], $stmt->fetchAll());
            return ['title' => 'Hearing Schedule Report',
                'headers' => ['Title', 'Type', 'Committee', 'Date', 'Time', 'Venue', 'Status'], 'rows' => $rows];

        case 'attendance':
            $where = []; $params = [];
            if ($dateFrom) { $where[] = 'DATE(a.checked_in_at) >= :df'; $params[':df'] = $dateFrom; }
            if ($dateTo)   { $where[] = 'DATE(a.checked_in_at) <= :dt'; $params[':dt'] = $dateTo; }
            $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $stmt = $pdo->prepare(
                "SELECT s.full_name, s.email, h.title AS hearing_title, a.status, a.checked_in_at
                 FROM attendance a
                 JOIN stakeholders s ON s.id = a.stakeholder_id
                 LEFT JOIN hearings h ON h.id = a.hearing_id
                 $whereSql ORDER BY a.checked_in_at DESC"
            );
            $stmt->execute($params);
            $rows = array_map(fn($r) => [
                $r['full_name'], $r['email'], $r['hearing_title'] ?: '-', $r['status'],
                $r['checked_in_at'] ? formatDateTime($r['checked_in_at']) : '-',
            ], $stmt->fetchAll());
            return ['title' => 'Attendance Report',
                'headers' => ['Stakeholder', 'Email', 'Hearing', 'Status', 'Checked In'], 'rows' => $rows];

        case 'stakeholders':
            $where = []; $params = [];
            if ($dateFrom) { $where[] = 'DATE(s.created_at) >= :df'; $params[':df'] = $dateFrom; }
            if ($dateTo)   { $where[] = 'DATE(s.created_at) <= :dt'; $params[':dt'] = $dateTo; }
            $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $stmt = $pdo->prepare(
                "SELECT s.full_name, s.email, s.organization, sc.name AS category_name, s.status, s.created_at
                 FROM stakeholders s LEFT JOIN stakeholder_categories sc ON sc.id = s.category_id
                 $whereSql ORDER BY s.created_at DESC"
            );
            $stmt->execute($params);
            $rows = array_map(fn($r) => [
                $r['full_name'], $r['email'], $r['organization'] ?: '-', $r['category_name'] ?: '-',
                $r['status'], formatDate($r['created_at']),
            ], $stmt->fetchAll());
            return ['title' => 'Stakeholders Report',
                'headers' => ['Full Name', 'Email', 'Organization', 'Category', 'Status', 'Registered'], 'rows' => $rows];

        case 'feedback':
            $where = []; $params = [];
            if ($dateFrom) { $where[] = 'DATE(f.submitted_at) >= :df'; $params[':df'] = $dateFrom; }
            if ($dateTo)   { $where[] = 'DATE(f.submitted_at) <= :dt'; $params[':dt'] = $dateTo; }
            $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $stmt = $pdo->prepare(
                "SELECT f.name, f.email, fc.name AS category_name, f.subject, f.status, f.submitted_at
                 FROM feedback f LEFT JOIN feedback_categories fc ON fc.id = f.category_id
                 $whereSql ORDER BY f.submitted_at DESC"
            );
            $stmt->execute($params);
            $rows = array_map(fn($r) => [
                $r['name'], $r['email'], $r['category_name'] ?: '-', $r['subject'] ?: '-', $r['status'], formatDateTime($r['submitted_at']),
            ], $stmt->fetchAll());
            return ['title' => 'Feedback Report',
                'headers' => ['Name', 'Email', 'Category', 'Subject', 'Status', 'Submitted'], 'rows' => $rows];

        case 'issues':
            $where = []; $params = [];
            if ($dateFrom) { $where[] = 'DATE(i.created_at) >= :df'; $params[':df'] = $dateFrom; }
            if ($dateTo)   { $where[] = 'DATE(i.created_at) <= :dt'; $params[':dt'] = $dateTo; }
            $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $stmt = $pdo->prepare(
                "SELECT i.title, ic.name AS category_name, i.assigned_office, i.priority, i.status, i.created_at
                 FROM issues i LEFT JOIN issue_categories ic ON ic.id = i.category_id
                 $whereSql ORDER BY i.created_at DESC"
            );
            $stmt->execute($params);
            $rows = array_map(fn($r) => [
                $r['title'], $r['category_name'] ?: '-', $r['assigned_office'] ?: 'Unassigned', $r['priority'], $r['status'], formatDate($r['created_at']),
            ], $stmt->fetchAll());
            return ['title' => 'Issue Log Report',
                'headers' => ['Title', 'Category', 'Assigned Office', 'Priority', 'Status', 'Logged'], 'rows' => $rows];

        case 'actions':
            $where = []; $params = [];
            if ($dateFrom) { $where[] = 'DATE(a.created_at) >= :df'; $params[':df'] = $dateFrom; }
            if ($dateTo)   { $where[] = 'DATE(a.created_at) <= :dt'; $params[':dt'] = $dateTo; }
            $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $stmt = $pdo->prepare(
                "SELECT a.title, i.title AS issue_title, a.status, a.deadline, a.created_at,
                        (SELECT aa.assigned_office FROM action_assignments aa WHERE aa.action_id = a.id ORDER BY aa.assigned_at DESC LIMIT 1) AS current_office
                 FROM actions a LEFT JOIN issues i ON i.id = a.issue_id
                 $whereSql ORDER BY a.created_at DESC"
            );
            $stmt->execute($params);
            $rows = array_map(fn($r) => [
                $r['title'], $r['issue_title'] ?: '-', $r['current_office'] ?: 'Unassigned',
                $r['deadline'] ? formatDate($r['deadline']) : '-', $r['status'], formatDate($r['created_at']),
            ], $stmt->fetchAll());
            return ['title' => 'Response & Action Log Report',
                'headers' => ['Title', 'Linked Issue', 'Assigned Office', 'Deadline', 'Status', 'Logged'], 'rows' => $rows];

        case 'activity_logs':
            $where = []; $params = [];
            if ($dateFrom) { $where[] = 'DATE(al.created_at) >= :df'; $params[':df'] = $dateFrom; }
            if ($dateTo)   { $where[] = 'DATE(al.created_at) <= :dt'; $params[':dt'] = $dateTo; }
            $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $stmt = $pdo->prepare(
                "SELECT u.full_name, al.action, al.details, al.created_at
                 FROM activity_logs al LEFT JOIN users u ON u.id = al.user_id
                 $whereSql ORDER BY al.created_at DESC LIMIT 2000"
            );
            $stmt->execute($params);
            $rows = array_map(fn($r) => [
                $r['full_name'] ?: 'System', $r['action'], $r['details'] ?: '-', formatDateTime($r['created_at']),
            ], $stmt->fetchAll());
            return ['title' => 'Activity Log Report',
                'headers' => ['User', 'Action', 'Details', 'Date/Time'], 'rows' => $rows];

        default:
            return null;
    }
}

/** Human-readable label + icon for each report type, used by the hub UI. */
function reportTypeMeta(): array
{
    return [
        'hearings'      => ['label' => 'Hearings',        'icon' => 'bi-calendar-event'],
        'attendance'    => ['label' => 'Attendance',      'icon' => 'bi-qr-code-scan'],
        'stakeholders'  => ['label' => 'Stakeholders',    'icon' => 'bi-people'],
        'feedback'      => ['label' => 'Feedback',        'icon' => 'bi-chat-square-text'],
        'issues'        => ['label' => 'Issues',          'icon' => 'bi-exclamation-triangle'],
        'actions'       => ['label' => 'Actions',         'icon' => 'bi-list-check'],
        'activity_logs' => ['label' => 'Activity Logs',   'icon' => 'bi-clock-history'],
    ];
}
