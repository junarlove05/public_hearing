<?php
declare(strict_types=1);

function lphTableExists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_name=:table'
    );
    $stmt->execute([':table' => $table]);
    return $cache[$table] = ((int)$stmt->fetchColumn() > 0);
}

function lphColumnExists(PDO $pdo, string $table, string $column): bool
{
    static $colCache = [];
    $key = $table . '.' . $column;
    if (isset($colCache[$key])) return $colCache[$key];

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema=DATABASE() AND table_name=:table AND column_name=:column'
    );
    $stmt->execute([':table' => $table, ':column' => $column]);
    return $colCache[$key] = ((int)$stmt->fetchColumn() > 0);
}

/**
 * Automatically ensures the database schema for multi-day attendance and stakeholder invitations/registrations.
 * Prevents "Unknown column 'r.session_day_id'" and "Unknown column 'valid_id_path'".
 */
function lphEnsureMultiDayAttendanceSchema(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) return;

    try {
        if (!lphColumnExists($pdo, 'attendance', 'attendance_date') || !lphTableExists($pdo, 'hearing_session_days')) {
            $migrationFile = __DIR__ . '/../database/migration_012_multi_day_attendance.sql';
            if (file_exists($migrationFile)) {
                $sql = file_get_contents($migrationFile);
                $statements = array_filter(
                    array_map('trim', explode(';', $sql)),
                    function($stmt) {
                        $cleaned = preg_replace('/^--.*$/m', '', $stmt);
                        return trim($cleaned) !== '';
                    }
                );
                foreach ($statements as $statement) {
                    $cleaned = trim(preg_replace('/^--.*$/m', '', $statement));
                    if ($cleaned === '') continue;
                    try {
                        $pdo->exec($cleaned);
                    } catch (Throwable $e) {
                        error_log('Migration 012 auto-exec notice: ' . $e->getMessage());
                    }
                }
            }
        }

        // Ensure session_day_id and session_date on registrations
        if (!lphColumnExists($pdo, 'registrations', 'session_day_id')) {
            $pdo->exec("ALTER TABLE `registrations` ADD COLUMN `session_day_id` INT NULL DEFAULT NULL AFTER `hearing_id`");
        }
        if (!lphColumnExists($pdo, 'registrations', 'session_date')) {
            $pdo->exec("ALTER TABLE `registrations` ADD COLUMN `session_date` DATE NULL DEFAULT NULL AFTER `session_day_id`");
        }

        // Ensure session_day_id and session_date on invitations
        if (!lphColumnExists($pdo, 'invitations', 'session_day_id')) {
            $pdo->exec("ALTER TABLE `invitations` ADD COLUMN `session_day_id` INT NULL DEFAULT NULL AFTER `hearing_id`");
        }
        if (!lphColumnExists($pdo, 'invitations', 'session_date')) {
            $pdo->exec("ALTER TABLE `invitations` ADD COLUMN `session_date` DATE NULL DEFAULT NULL AFTER `session_day_id`");
        }

        // Ensure valid_id_path on stakeholders
        if (!lphColumnExists($pdo, 'stakeholders', 'valid_id_path')) {
            $pdo->exec("ALTER TABLE `stakeholders` ADD COLUMN `valid_id_path` VARCHAR(255) NULL DEFAULT NULL AFTER `address`");
        }

        // Ensure start_time and end_time on hearing_session_days
        if (lphTableExists($pdo, 'hearing_session_days')) {
            if (!lphColumnExists($pdo, 'hearing_session_days', 'start_time')) {
                $pdo->exec("ALTER TABLE `hearing_session_days` ADD COLUMN `start_time` TIME NULL DEFAULT NULL AFTER `day_number`");
            }
            if (!lphColumnExists($pdo, 'hearing_session_days', 'end_time')) {
                $pdo->exec("ALTER TABLE `hearing_session_days` ADD COLUMN `end_time` TIME NULL DEFAULT NULL AFTER `start_time`");
            }
        }

        // Ensure cef_submission_id on hearing_issues
        if (lphTableExists($pdo, 'hearing_issues')) {
            if (!lphColumnExists($pdo, 'hearing_issues', 'cef_submission_id')) {
                $pdo->exec("ALTER TABLE `hearing_issues` ADD COLUMN `cef_submission_id` INT NULL DEFAULT NULL AFTER `feedback_id`");
            }
        }
        // Ensure hearing_actions verification columns
        lphEnsureActionsSchema($pdo);
    } catch (Throwable $t) {
        error_log('lphEnsureMultiDayAttendanceSchema notice: ' . $t->getMessage());
    }

    $ensured = true;
}

function lphEnsureActionsSchema(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) return;

    try {
        if (lphTableExists($pdo, 'hearing_actions')) {
            if (!lphColumnExists($pdo, 'hearing_actions', 'verification_status')) {
                $pdo->exec("ALTER TABLE `hearing_actions` ADD COLUMN `verification_status` VARCHAR(50) NOT NULL DEFAULT 'Pending' AFTER `status`");
            }
            if (!lphColumnExists($pdo, 'hearing_actions', 'verified_by')) {
                $pdo->exec("ALTER TABLE `hearing_actions` ADD COLUMN `verified_by` INT NULL DEFAULT NULL AFTER `verification_status`");
            }
            if (!lphColumnExists($pdo, 'hearing_actions', 'verified_at')) {
                $pdo->exec("ALTER TABLE `hearing_actions` ADD COLUMN `verified_at` DATETIME NULL DEFAULT NULL AFTER `verified_by`");
            }
            if (!lphColumnExists($pdo, 'hearing_actions', 'verification_notes')) {
                $pdo->exec("ALTER TABLE `hearing_actions` ADD COLUMN `verification_notes` TEXT NULL DEFAULT NULL AFTER `verified_at`");
            }
            if (!lphColumnExists($pdo, 'hearing_actions', 'progress_notes')) {
                $pdo->exec("ALTER TABLE `hearing_actions` ADD COLUMN `progress_notes` TEXT NULL DEFAULT NULL AFTER `verification_notes`");
            }
        }
    } catch (Throwable $t) {
        error_log('lphEnsureActionsSchema notice: ' . $t->getMessage());
    }

    $ensured = true;
}

function lphUniqueCode(PDO $pdo, string $table, string $column, string $prefix): string
{
    $allowed = [
        'invitations' => ['invitation_code'],
        'registrations' => ['registration_code'],
        'qr_codes' => ['code_value'],
    ];

    if (!isset($allowed[$table]) || !in_array($column, $allowed[$table], true)) {
        throw new InvalidArgumentException('Unsupported code target.');
    }

    for ($i=0; $i<20; $i++) {
        $code = $prefix . '-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(6)),0,10));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column}=:code");
        $stmt->execute([':code'=>$code]);
        if ((int)$stmt->fetchColumn()===0) return $code;
    }

    return $prefix . '-' . strtoupper(bin2hex(random_bytes(10)));
}

function lphRegistrationAvailability(PDO $pdo, int $hearingId): array
{
    $stmt = $pdo->prepare(
        'SELECT id,title,status,registration_deadline,maximum_participants
         FROM hearings WHERE id=:id'
    );
    $stmt->execute([':id'=>$hearingId]);
    $h = $stmt->fetch();

    if (!$h) return ['ok'=>false,'message'=>'Hearing not found.','hearing'=>null];

    if (in_array($h['status'], ['Completed','Cancelled'], true)) {
        return ['ok'=>false,'message'=>'Registration is closed for this hearing.','hearing'=>$h];
    }

    if (!empty($h['registration_deadline']) && time() > strtotime($h['registration_deadline'])) {
        return ['ok'=>false,'message'=>'The registration deadline has passed.','hearing'=>$h];
    }

    $count = $pdo->prepare(
        "SELECT COUNT(*) FROM registrations
         WHERE hearing_id=:id AND registration_status IN ('Pending','Approved')"
    );
    $count->execute([':id'=>$hearingId]);
    $current = (int)$count->fetchColumn();

    $max = (int)($h['maximum_participants'] ?? 0);
    if ($max > 0 && $current >= $max) {
        return ['ok'=>false,'message'=>'The hearing has reached its participant capacity.','hearing'=>$h];
    }

    return ['ok'=>true,'message'=>'Registration available.','hearing'=>$h,'current'=>$current,'maximum'=>$max];
}

function lphHistory(PDO $pdo, string $kind, int $entityId, string $action, ?string $old, ?string $new, string $details): void
{
    $maps = [
        'stakeholder' => ['stakeholder_history','stakeholder_id'],
        'invitation' => ['invitation_history','invitation_id'],
        'registration' => ['registration_history','registration_id'],
        'feedback' => ['feedback_history','feedback_id'],
    ];

    if (!isset($maps[$kind])) return;

    [$table,$fk] = $maps[$kind];
    if (!lphTableExists($pdo,$table)) return;

    if ($kind === 'stakeholder') {
        $stmt = $pdo->prepare(
            "INSERT INTO {$table} ({$fk},action,details,changed_by,created_at)
             VALUES (:id,:action,:details,:user,NOW())"
        );
        $stmt->execute([
            ':id'=>$entityId, ':action'=>$action, ':details'=>$details,
            ':user'=>currentUserId()
        ]);
        return;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO {$table}
            ({$fk},previous_status,new_status,details,changed_by,created_at)
         VALUES (:id,:old,:new,:details,:user,NOW())"
    );
    $stmt->execute([
        ':id'=>$entityId, ':old'=>$old, ':new'=>$new,
        ':details'=>$details, ':user'=>currentUserId()
    ]);
}

function lphSurveyHistory(PDO $pdo, int $surveyId, string $action, string $details): void
{
    if (!lphTableExists($pdo,'survey_history')) return;

    $stmt = $pdo->prepare(
        'INSERT INTO survey_history
         (survey_id,action,details,changed_by,created_at)
         VALUES (:id,:action,:details,:user,NOW())'
    );
    $stmt->execute([
        ':id'=>$surveyId, ':action'=>$action,
        ':details'=>$details, ':user'=>currentUserId()
    ]);
}

/**
 * Multi-Day Hearing Attendance Tracking & Daily Closure Helpers
 */

/**
 * Returns all session days for a hearing with their daily attendance status.
 * Evaluates whether each day is Open, Closed (past date, hearing completed, or manually closed), or Upcoming.
 */
function lphGetHearingSessionDays(array $hearing, ?PDO $pdo = null): array
{
    $startDateStr = $hearing['hearing_date'] ?? '';
    if (!$startDateStr || !strtotime($startDateStr)) {
        return [];
    }

    $endDateStr = (!empty($hearing['end_date']) && strtotime($hearing['end_date']))
        ? $hearing['end_date']
        : $startDateStr;

    if (strtotime($endDateStr) < strtotime($startDateStr)) {
        $endDateStr = $startDateStr;
    }

    $hearingId = (int)($hearing['id'] ?? 0);
    $hearingStatus = $hearing['status'] ?? 'Upcoming';
    $isHearingConcluded = in_array($hearingStatus, ['Completed', 'Cancelled'], true);

    // Fetch any session day records from hearing_session_days
    $dbSessions = [];
    if ($pdo) {
        lphEnsureMultiDayAttendanceSchema($pdo);
    }
    if ($pdo && $hearingId > 0 && lphTableExists($pdo, 'hearing_session_days')) {
        $q = $pdo->prepare('SELECT * FROM hearing_session_days WHERE hearing_id = :hid ORDER BY session_date ASC, day_number ASC');
        $q->execute([':hid' => $hearingId]);
        $dbSessions = $q->fetchAll(PDO::FETCH_ASSOC);
    }

    $days = [];
    $todayStr = date('Y-m-d');

    if (!empty($dbSessions)) {
        foreach ($dbSessions as $idx => $row) {
            $dateStr = $row['session_date'];
            $currentTs = strtotime($dateStr);
            $dayNumber = (int)($row['day_number'] ?: ($idx + 1));

            $isToday = ($dateStr === $todayStr);
            $isPast = ($dateStr < $todayStr);
            $isFuture = ($dateStr > $todayStr);

            $isForcedOpen = ((int)$row['is_closed'] === 2);
            $isManualClosed = ((int)$row['is_closed'] === 1);

            $isClosed = false;
            $status = 'open';
            $statusReason = '';

            if ($isForcedOpen) {
                $isClosed = false;
                $status = 'open';
                $statusReason = 'Opened by administrator (Admin Override: Open)';
            } elseif ($isHearingConcluded) {
                $isClosed = true;
                $status = 'closed';
                $statusReason = 'Hearing status is ' . $hearingStatus;
            } elseif ($isManualClosed) {
                $isClosed = true;
                $status = 'closed';
                $statusReason = 'Attendance was closed by session administrator';
            } elseif ($isPast) {
                $isClosed = true;
                $status = 'closed';
                $statusReason = 'Concluded session date';
            } elseif ($isFuture) {
                $isClosed = true;
                $status = 'upcoming';
                $statusReason = 'Upcoming session (Scheduled future date)';
            } else {
                $isClosed = false;
                $status = 'open';
                $statusReason = 'In session today';
            }

            $startTimeStr = !empty($row['start_time']) ? substr((string)$row['start_time'], 0, 5) : (!empty($hearing['hearing_time']) ? substr((string)$hearing['hearing_time'], 0, 5) : '');
            $endTimeStr = !empty($row['end_time']) ? substr((string)$row['end_time'], 0, 5) : (!empty($hearing['end_time']) ? substr((string)$hearing['end_time'], 0, 5) : '');

            $days[] = [
                'session_day_id' => (int)($row['id'] ?? 0),
                'hearing_id' => $hearingId,
                'day_number' => $dayNumber,
                'day_label' => 'Day ' . $dayNumber,
                'date' => $dateStr,
                'formatted_date' => date('M d, Y', $currentTs),
                'weekday' => date('l', $currentTs),
                'start_time' => $startTimeStr,
                'end_time' => $endTimeStr,
                'time_label' => ($startTimeStr && $endTimeStr)
                    ? (date('g:i A', strtotime($dateStr . ' ' . $startTimeStr)) . ' - ' . date('g:i A', strtotime($dateStr . ' ' . $endTimeStr)))
                    : ($startTimeStr ? date('g:i A', strtotime($dateStr . ' ' . $startTimeStr)) : ''),
                'notes' => (string)($row['notes'] ?? ''),
                'is_today' => $isToday,
                'is_past' => $isPast,
                'is_future' => $isFuture,
                'is_closed' => $isClosed,
                'is_manual_closed' => $isManualClosed,
                'is_forced_open' => $isForcedOpen,
                'closed_at' => $row['closed_at'] ?? null,
                'closed_by' => $row['closed_by'] ?? null,
                'status' => $status,
                'status_reason' => $statusReason,
                'status_badge_text' => match($status) {
                    'closed' => 'Closed · Concluded',
                    'open' => 'Active · Open Today',
                    'upcoming' => 'Upcoming',
                    default => 'Scheduled'
                },
                'status_badge_class' => match($status) {
                    'closed' => 'bg-secondary',
                    'open' => 'bg-success',
                    'upcoming' => 'bg-info text-dark',
                    default => 'bg-light text-dark'
                },
            ];
        }
        return $days;
    }

    $currentTs = strtotime($startDateStr);
    $endTs = strtotime($endDateStr);
    $dayNumber = 1;
    $maxDays = 60;
    $count = 0;

    while ($currentTs <= $endTs && $count < $maxDays) {
        $dateStr = date('Y-m-d', $currentTs);

        $isToday = ($dateStr === $todayStr);
        $isPast = ($dateStr < $todayStr);
        $isFuture = ($dateStr > $todayStr);

        $isClosed = false;
        $status = 'open';
        $statusReason = '';

        if ($isHearingConcluded) {
            $isClosed = true;
            $status = 'closed';
            $statusReason = 'Hearing status is ' . $hearingStatus;
        } elseif ($isPast) {
            $isClosed = true;
            $status = 'closed';
            $statusReason = 'Concluded session date';
        } elseif ($isFuture) {
            $isClosed = true;
            $status = 'upcoming';
            $statusReason = 'Upcoming session (Scheduled future date)';
        } else {
            $isClosed = false;
            $status = 'open';
            $statusReason = 'In session today';
        }

        $startTimeStr = !empty($hearing['hearing_time']) ? substr((string)$hearing['hearing_time'], 0, 5) : '';
        $endTimeStr = !empty($hearing['end_time']) ? substr((string)$hearing['end_time'], 0, 5) : '';

        $days[] = [
            'session_day_id' => 0,
            'hearing_id' => $hearingId,
            'day_number' => $dayNumber,
            'day_label' => 'Day ' . $dayNumber,
            'date' => $dateStr,
            'formatted_date' => date('M d, Y', $currentTs),
            'weekday' => date('l', $currentTs),
            'start_time' => $startTimeStr,
            'end_time' => $endTimeStr,
            'time_label' => ($startTimeStr && $endTimeStr)
                ? (date('g:i A', strtotime($dateStr . ' ' . $startTimeStr)) . ' - ' . date('g:i A', strtotime($dateStr . ' ' . $endTimeStr)))
                : ($startTimeStr ? date('g:i A', strtotime($dateStr . ' ' . $startTimeStr)) : ''),
            'notes' => '',
            'is_today' => $isToday,
            'is_past' => $isPast,
            'is_future' => $isFuture,
            'is_closed' => $isClosed,
            'is_manual_closed' => false,
            'is_forced_open' => false,
            'closed_at' => null,
            'closed_by' => null,
            'status' => $status,
            'status_reason' => $statusReason,
            'status_badge_text' => match($status) {
                'closed' => 'Closed · Concluded',
                'open' => 'Active · Open Today',
                'upcoming' => 'Upcoming',
                default => 'Scheduled'
            },
            'status_badge_class' => match($status) {
                'closed' => 'bg-secondary',
                'open' => 'bg-success',
                'upcoming' => 'bg-info text-dark',
                default => 'bg-light text-dark'
            },
        ];

        $currentTs = strtotime('+1 day', $currentTs);
        $dayNumber++;
        $count++;
    }

    return $days;
}

/**
 * Returns session day info for a specific date of a hearing.
 */
function lphGetHearingDayInfo(PDO $pdo, int $hearingId, string $date, ?array $hearing = null): array
{
    if (!$hearing) {
        $stmt = $pdo->prepare('SELECT * FROM hearings WHERE id = :id');
        $stmt->execute([':id' => $hearingId]);
        $hearing = $stmt->fetch();
    }

    if (!$hearing) {
        return ['valid' => false, 'message' => 'Hearing not found.', 'day' => null];
    }

    $days = lphGetHearingSessionDays($hearing, $pdo);
    foreach ($days as $d) {
        if ($d['date'] === $date) {
            return ['valid' => true, 'message' => 'Day found.', 'day' => $d, 'hearing' => $hearing];
        }
    }

    return ['valid' => false, 'message' => 'Date ' . $date . ' is not within the hearing schedule.', 'day' => null, 'hearing' => $hearing];
}

/**
 * Checks whether attendance is closed for a specific hearing date.
 */
function lphIsDayAttendanceClosed(PDO $pdo, int $hearingId, string $date, ?array $hearing = null): bool
{
    $info = lphGetHearingDayInfo($pdo, $hearingId, $date, $hearing);
    if (!$info['valid']) {
        return true;
    }
    return (bool)($info['day']['is_closed'] || $info['day']['status'] !== 'open');
}

/**
 * Returns user-friendly explanation when attendance is closed/blocked for a date.
 */
function lphGetDayAttendanceBlockReason(PDO $pdo, int $hearingId, string $date, ?array $hearing = null): string
{
    $info = lphGetHearingDayInfo($pdo, $hearingId, $date, $hearing);
    if (!$info['valid']) {
        return 'The date ' . htmlspecialchars($date) . ' is not within the schedule of this hearing.';
    }

    $day = $info['day'];
    if ($day['status'] === 'closed') {
        return 'Attendance for this date (' . $day['day_label'] . ' · ' . $day['formatted_date'] . ') is closed because the session day has concluded.';
    }
    if ($day['status'] === 'upcoming') {
        return 'Attendance is not yet open for ' . $day['day_label'] . ' (' . $day['formatted_date'] . '). It will automatically open on the session date.';
    }

    return '';
}

/**
 * Set manual closure (1), manual reopen (2 = forced open), or reset (0) for a session day.
 */
function lphSetSessionDayClosure(PDO $pdo, int $hearingId, string $date, int $mode, ?int $userId = null, ?string $notes = null): bool
{
    $dayInfo = lphGetHearingDayInfo($pdo, $hearingId, $date);
    if (!$dayInfo['valid']) return false;

    $dayNumber = $dayInfo['day']['day_number'];
    $stmt = $pdo->prepare("
        INSERT INTO hearing_session_days
            (hearing_id, session_date, day_number, is_closed, closed_at, closed_by, notes)
        VALUES
            (:hid, :sdate, :dnum, :is_closed, :closed_at, :closed_by, :notes)
        ON DUPLICATE KEY UPDATE
            is_closed = VALUES(is_closed),
            closed_at = VALUES(closed_at),
            closed_by = VALUES(closed_by),
            notes = VALUES(notes),
            updated_at = NOW()
    ");

    $closedAt = ($mode === 1) ? date('Y-m-d H:i:s') : null;
    return $stmt->execute([
        ':hid' => $hearingId,
        ':sdate' => $date,
        ':dnum' => $dayNumber,
        ':is_closed' => $mode,
        ':closed_at' => $closedAt,
        ':closed_by' => $userId,
        ':notes' => $notes,
    ]);
}

/**
 * Generates an expanded list of hearing session items so that multi-day hearings
 * have distinct entries for each day/date in dropdowns and filter selectors.
 */
function lphGetHearingSessionDropdownOptions(PDO $pdo, array $hearings): array
{
    $options = [];
    foreach ($hearings as $h) {
        $days = lphGetHearingSessionDays($h, $pdo);
        $totalDays = count($days);
        $title = (string)($h['title'] ?? 'Hearing #' . ($h['id'] ?? ''));
        $ref = (string)($h['reference_number'] ?? '');
        $status = (string)($h['status'] ?? '');

        if (empty($days)) {
            $hdate = $h['hearing_date'] ?? '';
            $key = ($h['id'] ?? 0) . '_0_' . $hdate;
            $options[] = [
                'key'              => $key,
                'hearing_id'       => (int)($h['id'] ?? 0),
                'session_day_id'   => 0,
                'session_date'     => $hdate,
                'day_number'       => 1,
                'day_label'        => 'Day 1',
                'formatted_date'   => !empty($hdate) ? date('M d, Y', strtotime($hdate)) : '',
                'hearing_title'    => $title,
                'reference_number' => $ref,
                'hearing_status'   => $status,
                'total_days'       => 1,
                'dropdown_label'   => ($ref ? "[$ref] " : '') . $title . (!empty($hdate) ? ' · ' . date('M d, Y', strtotime($hdate)) : '') . ($status ? " ($status)" : ''),
            ];
            continue;
        }

        foreach ($days as $d) {
            $sessId = (int)($d['session_day_id'] ?? 0);
            $key = $h['id'] . '_' . $sessId . '_' . $d['date'];
            
            // Format label: [PHC-2026-0001] waste management 1 · Day 1: Sep 28, 2026 (Upcoming)
            $dayPart = $totalDays > 1 
                ? ' · ' . $d['day_label'] . ': ' . $d['formatted_date']
                : ' · ' . $d['formatted_date'];

            $label = ($ref ? "[$ref] " : '') . $title . $dayPart . ($status ? " ($status)" : '');

            $options[] = [
                'key'              => $key,
                'hearing_id'       => (int)$h['id'],
                'session_day_id'   => $sessId,
                'session_date'     => $d['date'],
                'day_number'       => (int)$d['day_number'],
                'day_label'        => $d['day_label'],
                'formatted_date'   => $d['formatted_date'],
                'hearing_title'    => $title,
                'reference_number' => $ref,
                'hearing_status'   => $status,
                'total_days'       => $totalDays,
                'dropdown_label'   => $label,
            ];
        }
    }
    return $options;
}

