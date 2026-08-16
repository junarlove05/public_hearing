<?php
declare(strict_types=1);

function hearingAllowedStatuses(): array
{
    return ['Upcoming', 'Ongoing', 'Completed', 'Cancelled'];
}

function hearingAllowedVisibility(): array
{
    return ['Public', 'Internal', 'Restricted'];
}

function hearingTableExists(PDO $pdo, string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = :table_name'
    );
    $stmt->execute([':table_name' => $table]);

    return $cache[$table] = ((int)$stmt->fetchColumn() > 0);
}

function hearingGenerateReference(PDO $pdo, ?string $date = null): string
{
    $year = $date && strtotime($date)
        ? date('Y', strtotime($date))
        : date('Y');

    $prefix = 'PHC-' . $year . '-';

    $stmt = $pdo->prepare(
        'SELECT reference_number
         FROM hearings
         WHERE reference_number LIKE :prefix
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([':prefix' => $prefix . '%']);

    $last = (string)($stmt->fetchColumn() ?: '');
    $next = 1;

    if ($last !== '' && preg_match('/(\d{4,})$/', $last, $match)) {
        $next = ((int)$match[1]) + 1;
    }

    for ($attempt = 0; $attempt < 20; $attempt++, $next++) {
        $candidate = $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);

        $check = $pdo->prepare(
            'SELECT COUNT(*) FROM hearings WHERE reference_number = :ref'
        );
        $check->execute([':ref' => $candidate]);

        if ((int)$check->fetchColumn() === 0) {
            return $candidate;
        }
    }

    return $prefix . strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
}

function hearingRecordExists(PDO $pdo, string $table, ?int $id): bool
{
    if (!$id || $id <= 0) {
        return true;
    }

    $allowed = ['hearing_types', 'committees', 'legislative_items'];

    if (!in_array($table, $allowed, true)) {
        return false;
    }

    $sql = "SELECT COUNT(*) FROM {$table} WHERE id = :id";

    if ($table === 'legislative_items') {
        $sql .= ' AND deleted_at IS NULL';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);

    return (int)$stmt->fetchColumn() > 0;
}

function hearingNormalizeDateTime(
    string $date,
    string $time,
    ?string $fallbackDate = null,
    ?string $fallbackTime = null
): ?DateTimeImmutable {
    $date = trim($date) ?: trim((string)$fallbackDate);
    $time = trim($time) ?: trim((string)$fallbackTime);

    if ($date === '' || $time === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($date . ' ' . $time);
    } catch (Throwable $e) {
        return null;
    }
}

function hearingFindConflicts(
    PDO $pdo,
    int $id,
    string $hearingDate,
    string $hearingTime,
    ?string $endDate,
    ?string $endTime,
    ?int $committeeId,
    string $venue
): array {
    $start = hearingNormalizeDateTime($hearingDate, $hearingTime);

    if (!$start) {
        return [];
    }

    $end = hearingNormalizeDateTime(
        $endDate ?: $hearingDate,
        $endTime ?: '',
        $hearingDate,
        $endTime ?: $start->modify('+1 hour')->format('H:i:s')
    );

    if (!$end) {
        return [];
    }

    $conditions = [];
    $params = [
        ':current_id' => $id,
        ':new_start' => $start->format('Y-m-d H:i:s'),
        ':new_end' => $end->format('Y-m-d H:i:s'),
    ];

    if ($committeeId) {
        $conditions[] = 'h.committee_id = :committee_id';
        $params[':committee_id'] = $committeeId;
    }

    $venue = trim($venue);

    if ($venue !== '') {
        $conditions[] = 'LOWER(TRIM(h.venue)) = LOWER(:venue)';
        $params[':venue'] = $venue;
    }

    if (!$conditions) {
        return [];
    }

    $sql = "
        SELECT
            h.id,
            h.reference_number,
            h.title,
            h.hearing_date,
            h.hearing_time,
            h.end_date,
            h.end_time,
            h.venue,
            h.committee_id,
            c.name AS committee_name
        FROM hearings h
        LEFT JOIN committees c ON c.id = h.committee_id
        WHERE h.id <> :current_id
          AND h.status <> 'Cancelled'
          AND (" . implode(' OR ', $conditions) . ")
          AND TIMESTAMP(h.hearing_date, h.hearing_time) < :new_end
          AND TIMESTAMP(
                COALESCE(h.end_date, h.hearing_date),
                COALESCE(h.end_time, ADDTIME(h.hearing_time, '01:00:00'))
              ) > :new_start
        ORDER BY h.hearing_date, h.hearing_time
        LIMIT 10
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function hearingAddHistory(
    PDO $pdo,
    int $hearingId,
    string $action,
    ?string $previousStatus,
    ?string $newStatus,
    string $details,
    ?int $changedBy
): void {
    if (!hearingTableExists($pdo, 'hearing_history')) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO hearing_history
            (hearing_id, action, previous_status, new_status, details, changed_by, created_at)
         VALUES
            (:hearing_id, :action, :previous_status, :new_status, :details, :changed_by, NOW())'
    );

    $stmt->execute([
        ':hearing_id' => $hearingId,
        ':action' => $action,
        ':previous_status' => $previousStatus,
        ':new_status' => $newStatus,
        ':details' => $details,
        ':changed_by' => $changedBy,
    ]);
}

function hearingDependencyCounts(PDO $pdo, int $hearingId): array
{
    $tables = [
        'registrations' => 'registrations',
        'invitations' => 'invitations',
        'attendance' => 'attendance',
        'feedback' => 'feedback',
        'hearing_issues' => 'issues',
        'surveys' => 'surveys',
    ];

    $result = [];

    foreach ($tables as $table => $label) {
        if (!hearingTableExists($pdo, $table)) {
            $result[$label] = 0;
            continue;
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE hearing_id = :hearing_id"
        );
        $stmt->execute([':hearing_id' => $hearingId]);
        $result[$label] = (int)$stmt->fetchColumn();
    }

    return $result;
}

function hearingHasDependencies(array $counts): bool
{
    foreach ($counts as $value) {
        if ((int)$value > 0) {
            return true;
        }
    }

    return false;
}

function hearingRegistrationState(array $hearing, int $registrationCount): array
{
    if (($hearing['status'] ?? '') === 'Cancelled') {
        return [
            'open' => false,
            'label' => 'Cancelled',
            'message' => 'Registration is unavailable because the hearing is cancelled.',
        ];
    }

    if (($hearing['status'] ?? '') === 'Completed') {
        return [
            'open' => false,
            'label' => 'Closed',
            'message' => 'The hearing has already been completed.',
        ];
    }

    $deadline = trim((string)($hearing['registration_deadline'] ?? ''));

    if ($deadline !== '' && strtotime($deadline) !== false && time() > strtotime($deadline)) {
        return [
            'open' => false,
            'label' => 'Deadline Passed',
            'message' => 'The registration deadline has passed.',
        ];
    }

    $max = (int)($hearing['maximum_participants'] ?? 0);

    if ($max > 0 && $registrationCount >= $max) {
        return [
            'open' => false,
            'label' => 'Full',
            'message' => 'The maximum participant capacity has been reached.',
        ];
    }

    return [
        'open' => true,
        'label' => 'Open',
        'message' => 'Registration can accept additional stakeholders.',
    ];
}
