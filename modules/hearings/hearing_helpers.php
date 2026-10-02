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
    string $venue,
    array $sessions = []
): array {
    $committeeId = $committeeId && $committeeId > 0 ? $committeeId : null;
    $venue = trim($venue);

    if (!$committeeId && $venue === '') {
        return [];
    }

    // Normalize sessions list
    $normalizedSessions = [];
    if (!empty($sessions)) {
        foreach ($sessions as $s) {
            $sDate = trim((string)($s['session_date'] ?? $s['date'] ?? ''));
            $sStart = trim((string)($s['start_time'] ?? $s['hearing_time'] ?? ''));
            $sEnd = trim((string)($s['end_time'] ?? ''));

            if ($sDate === '' || $sStart === '') {
                continue;
            }

            if ($sEnd === '') {
                $dtStart = hearingNormalizeDateTime($sDate, $sStart);
                $sEnd = $dtStart ? $dtStart->modify('+1 hour')->format('H:i:s') : '23:59:59';
            }

            $normalizedSessions[] = [
                'date' => $sDate,
                'start_time' => strlen($sStart) === 5 ? ($sStart . ':00') : $sStart,
                'end_time' => strlen($sEnd) === 5 ? ($sEnd . ':00') : $sEnd,
            ];
        }
    }

    if (empty($normalizedSessions)) {
        if ($hearingDate !== '' && $hearingTime !== '') {
            $endT = $endTime ?: '';
            if ($endT === '') {
                $dtStart = hearingNormalizeDateTime($hearingDate, $hearingTime);
                $endT = $dtStart ? $dtStart->modify('+1 hour')->format('H:i:s') : '23:59:59';
            }
            $normalizedSessions[] = [
                'date' => $hearingDate,
                'start_time' => strlen($hearingTime) === 5 ? ($hearingTime . ':00') : $hearingTime,
                'end_time' => strlen($endT) === 5 ? ($endT . ':00') : $endT,
            ];
        } else {
            return [];
        }
    }

    $conditions = [];
    $baseParams = [':current_id' => $id];

    if ($committeeId) {
        $conditions[] = 'h.committee_id = :committee_id';
        $baseParams[':committee_id'] = $committeeId;
    }

    if ($venue !== '') {
        $conditions[] = 'LOWER(TRIM(h.venue)) = LOWER(:venue)';
        $baseParams[':venue'] = $venue;
    }

    if (!$conditions) {
        return [];
    }

    $hasSessionDaysTable = hearingTableExists($pdo, 'hearing_session_days');

    // Retrieve other active hearings matching committee or venue
    $candidateSql = "
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
    ";

    $stmt = $pdo->prepare($candidateSql);
    $stmt->execute($baseParams);
    $candidates = $stmt->fetchAll();

    if (empty($candidates)) {
        return [];
    }

    // Preload session days for candidates if table exists
    $candidateSessionMap = [];
    if ($hasSessionDaysTable) {
        $candidateIds = array_column($candidates, 'id');
        if (!empty($candidateIds)) {
            $inClause = implode(',', array_map('intval', $candidateIds));
            $sStmt = $pdo->query("
                SELECT hearing_id, session_date, start_time, end_time
                FROM hearing_session_days
                WHERE hearing_id IN ({$inClause})
                ORDER BY session_date, day_number
            ");
            while ($row = $sStmt->fetch()) {
                $candidateSessionMap[$row['hearing_id']][] = $row;
            }
        }
    }

    $conflicts = [];

    foreach ($candidates as $cand) {
        $candId = (int)$cand['id'];
        $candSessions = [];

        if (!empty($candidateSessionMap[$candId])) {
            foreach ($candidateSessionMap[$candId] as $cs) {
                $candSessions[] = [
                    'date' => $cs['session_date'],
                    'start_time' => $cs['start_time'] ?: $cand['hearing_time'],
                    'end_time' => $cs['end_time'] ?: ($cand['end_time'] ?: '23:59:59'),
                ];
            }
        } else {
            // Fallback for single-day or legacy multi-day hearings
            $candStart = $cand['hearing_date'];
            $candEnd = $cand['end_date'] ?: $cand['hearing_date'];
            $candStartTime = $cand['hearing_time'];
            $candEndTime = $cand['end_time'] ?: '';

            if ($candStartTime && $candEndTime === '') {
                $dt = hearingNormalizeDateTime($candStart, $candStartTime);
                $candEndTime = $dt ? $dt->modify('+1 hour')->format('H:i:s') : '23:59:59';
            }

            if ($candStart === $candEnd) {
                $candSessions[] = [
                    'date' => $candStart,
                    'start_time' => $candStartTime,
                    'end_time' => $candEndTime,
                ];
            } else {
                // If legacy contiguous multi-day hearing without explicit session days,
                // treat each day in range with the daily time window
                $curTs = strtotime($candStart);
                $endTs = strtotime($candEnd);
                $guard = 0;
                while ($curTs <= $endTs && $guard < 60) {
                    $candSessions[] = [
                        'date' => date('Y-m-d', $curTs),
                        'start_time' => $candStartTime,
                        'end_time' => $candEndTime,
                    ];
                    $curTs = strtotime('+1 day', $curTs);
                    $guard++;
                }
            }
        }

        // Compare each session of the new hearing against candidate sessions
        foreach ($normalizedSessions as $newSession) {
            foreach ($candSessions as $cSession) {
                if ($newSession['date'] !== $cSession['date']) {
                    continue;
                }

                // Check time overlap on same date: startA < endB AND endA > startB
                $newStartTs = strtotime($newSession['date'] . ' ' . $newSession['start_time']);
                $newEndTs = strtotime($newSession['date'] . ' ' . $newSession['end_time']);
                $candStartTs = strtotime($cSession['date'] . ' ' . $cSession['start_time']);
                $candEndTs = strtotime($cSession['date'] . ' ' . $cSession['end_time']);

                if ($newStartTs !== false && $newEndTs !== false && $candStartTs !== false && $candEndTs !== false) {
                    if ($newStartTs < $candEndTs && $newEndTs > $candStartTs) {
                        $cand['conflict_date'] = $newSession['date'];
                        $cand['conflict_time_start'] = $cSession['start_time'];
                        $cand['conflict_time_end'] = $cSession['end_time'];
                        $conflicts[$candId] = $cand;
                        break 2;
                    }
                }
            }
        }
    }

    return array_values($conflicts);
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
