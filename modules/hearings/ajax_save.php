<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/hearing_helpers.php';

requireLogin();

if (!canManage()) {
    jsonResponse(false, 'You do not have permission to manage hearings.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

requireCsrf();

$pdo = db();

$id = (int)($_POST['id'] ?? 0);
$legislativeItemId = (int)($_POST['legislative_item_id'] ?? 0) ?: null;
$title = clean($_POST['title'] ?? '');
$hearingTypeId = (int)($_POST['hearing_type_id'] ?? 0) ?: null;
$committeeId = (int)($_POST['committee_id'] ?? 0) ?: null;
$venue = clean($_POST['venue'] ?? '');
$hearingDate = clean($_POST['hearing_date'] ?? '');
$endDate = clean($_POST['end_date'] ?? '') ?: null;
$hearingTime = clean($_POST['hearing_time'] ?? '');
$endTime = clean($_POST['end_time'] ?? '') ?: null;
$status = clean($_POST['status'] ?? 'Upcoming');
$description = trim((string)($_POST['description'] ?? ''));
$registrationDeadline = clean($_POST['registration_deadline'] ?? '') ?: null;
$maximumRaw = trim((string)($_POST['maximum_participants'] ?? ''));
$maximumParticipants = $maximumRaw === '' ? null : (int)$maximumRaw;
$meetingLink = trim((string)($_POST['meeting_link'] ?? ''));
$visibility = clean($_POST['visibility'] ?? 'Public');
$cancellationReason = trim((string)($_POST['cancellation_reason'] ?? ''));

// Multi-day sessions parsing
$rawSessionsJson = trim((string)($_POST['sessions_json'] ?? ''));
$submittedSessions = [];

if ($rawSessionsJson !== '') {
    $decoded = json_decode($rawSessionsJson, true);
    if (is_array($decoded)) {
        $submittedSessions = $decoded;
    }
} elseif (!empty($_POST['session_date']) && is_array($_POST['session_date'])) {
    $dates = $_POST['session_date'];
    $starts = $_POST['session_start_time'] ?? [];
    $ends = $_POST['session_end_time'] ?? [];
    $notesList = $_POST['session_notes'] ?? [];
    foreach ($dates as $idx => $d) {
        $submittedSessions[] = [
            'session_date' => clean((string)$d),
            'start_time' => clean((string)($starts[$idx] ?? '')),
            'end_time' => clean((string)($ends[$idx] ?? '')),
            'notes' => clean((string)($notesList[$idx] ?? '')),
        ];
    }
}

$errors = [];

if ($title === '') {
    $errors[] = 'Title is required.';
}

// Process and validate multi-day sessions if provided
$parsedSessions = [];
if (!empty($submittedSessions)) {
    $seenDates = [];
    foreach ($submittedSessions as $index => $sess) {
        $sDate = clean((string)($sess['session_date'] ?? ''));
        $sStart = clean((string)($sess['start_time'] ?? ''));
        $sEnd = clean((string)($sess['end_time'] ?? ''));
        $sNotes = trim((string)($sess['notes'] ?? ''));

        if ($sDate === '' && $sStart === '' && $sEnd === '') {
            continue; // Skip empty rows
        }

        $rowNum = $index + 1;

        if ($sDate === '') {
            $errors[] = "Session Day #{$rowNum}: Date is required.";
            continue;
        }

        if (in_array($sDate, $seenDates, true)) {
            $errors[] = "Session Day #{$rowNum}: Duplicate date '{$sDate}'. Each session day must have a distinct date.";
            continue;
        }
        $seenDates[] = $sDate;

        if ($sStart === '') {
            $errors[] = "Session Day #{$rowNum} ({$sDate}): Start time is required.";
            continue;
        }

        $sessionStartDt = hearingNormalizeDateTime($sDate, $sStart);
        if (!$sessionStartDt) {
            $errors[] = "Session Day #{$rowNum} ({$sDate}): Invalid start time.";
            continue;
        }

        $sessionEndDt = null;
        if ($sEnd !== '') {
            $sessionEndDt = hearingNormalizeDateTime($sDate, $sEnd);
            if (!$sessionEndDt || $sessionEndDt <= $sessionStartDt) {
                $errors[] = "Session Day #{$rowNum} ({$sDate}): End time must be later than start time.";
                continue;
            }
        } else {
            $sessionEndDt = $sessionStartDt->modify('+1 hour');
            $sEnd = $sessionEndDt->format('H:i');
        }

        $parsedSessions[] = [
            'session_date' => $sDate,
            'start_time' => strlen($sStart) === 5 ? ($sStart . ':00') : $sStart,
            'end_time' => strlen($sEnd) === 5 ? ($sEnd . ':00') : $sEnd,
            'notes' => $sNotes,
        ];
    }

    if (!empty($parsedSessions)) {
        // Sort sessions chronologically
        usort($parsedSessions, function ($a, $b) {
            return strcmp($a['session_date'] . ' ' . $a['start_time'], $b['session_date'] . ' ' . $b['start_time']);
        });

        // Derive overall hearing bounds from sessions
        $firstSession = $parsedSessions[0];
        $lastSession = $parsedSessions[count($parsedSessions) - 1];

        $hearingDate = $firstSession['session_date'];
        $hearingTime = substr($firstSession['start_time'], 0, 5);
        $endDate = $lastSession['session_date'];
        $endTime = substr($lastSession['end_time'], 0, 5);
    }
}

// Fallback to traditional single start/end if no sessions submitted
if (empty($parsedSessions)) {
    $start = hearingNormalizeDateTime($hearingDate, $hearingTime);

    if (!$start) {
        $errors[] = 'A valid hearing date and start time are required.';
    }

    if ($endDate === null) {
        $endDate = $hearingDate !== '' ? $hearingDate : null;
    }

    $end = null;

    if ($start) {
        if ($endTime !== null) {
            $end = hearingNormalizeDateTime($endDate ?: $hearingDate, $endTime);
        } else {
            $end = $start->modify('+1 hour');
            $endTime = $end->format('H:i:s');
        }

        if (!$end || $end <= $start) {
            $errors[] = 'End date and time must be after the hearing start.';
        }
    }

    if ($hearingDate !== '' && $hearingTime !== '') {
        $sEndClean = $endTime ?: ($start ? $start->modify('+1 hour')->format('H:i:s') : '23:59:59');
        $parsedSessions[] = [
            'session_date' => $hearingDate,
            'start_time' => strlen($hearingTime) === 5 ? ($hearingTime . ':00') : $hearingTime,
            'end_time' => strlen($sEndClean) === 5 ? ($sEndClean . ':00') : $sEndClean,
            'notes' => '',
        ];
    }
} else {
    $start = hearingNormalizeDateTime($hearingDate, $hearingTime);
    $end = hearingNormalizeDateTime($endDate ?: $hearingDate, $endTime ?: '23:59:59');
}

if (!in_array($status, hearingAllowedStatuses(), true)) {
    $errors[] = 'Invalid hearing status.';
}

if (!in_array($visibility, hearingAllowedVisibility(), true)) {
    $errors[] = 'Invalid visibility value.';
}

if ($hearingTypeId && !hearingRecordExists($pdo, 'hearing_types', $hearingTypeId)) {
    $errors[] = 'The selected hearing type no longer exists.';
}

if ($committeeId && !hearingRecordExists($pdo, 'committees', $committeeId)) {
    $errors[] = 'The selected committee no longer exists.';
}

if ($legislativeItemId && !hearingRecordExists($pdo, 'legislative_items', $legislativeItemId)) {
    $errors[] = 'The selected legislative item no longer exists.';
}

if ($maximumParticipants !== null && $maximumParticipants <= 0) {
    $errors[] = 'Maximum participants must be greater than zero.';
}

if ($maximumParticipants !== null && $maximumParticipants > 100000) {
    $errors[] = 'Maximum participants is unreasonably high.';
}

if ($meetingLink !== '' && filter_var($meetingLink, FILTER_VALIDATE_URL) === false) {
    $errors[] = 'Meeting link must be a valid URL.';
}

if ($registrationDeadline !== null) {
    $deadlineTs = strtotime($registrationDeadline);

    if ($deadlineTs === false) {
        $errors[] = 'Registration deadline is invalid.';
    } elseif ($start && $deadlineTs > $start->getTimestamp()) {
        $errors[] = 'Registration deadline cannot be later than the hearing start.';
    }
}

if ($status === 'Cancelled' && $cancellationReason === '') {
    $errors[] = 'A cancellation reason is required when cancelling a hearing.';
}

$existing = null;

if ($id > 0) {
    $existingStmt = $pdo->prepare('SELECT * FROM hearings WHERE id = :id');
    $existingStmt->execute([':id' => $id]);
    $existing = $existingStmt->fetch();

    if (!$existing) {
        $errors[] = 'Hearing not found.';
    } elseif ($existing['status'] === 'Completed') {
        $errors[] = 'Completed hearings cannot be edited. They are view-only.';
    }
}

if ($start && $end && $status !== 'Cancelled') {
    $conflicts = hearingFindConflicts(
        $pdo,
        $id,
        $hearingDate,
        $hearingTime,
        $endDate,
        $endTime,
        $committeeId,
        $venue,
        $parsedSessions
    );

    if ($conflicts) {
        $parts = [];
        $conflictsData = [];

        foreach ($conflicts as $conflict) {
            $reasons = [];

            if (
                $committeeId
                && (int)$conflict['committee_id'] === $committeeId
            ) {
                $reasons[] = 'committee';
            }

            if (
                $venue !== ''
                && isset($conflict['venue'])
                && strcasecmp(trim((string)$conflict['venue']), $venue) === 0
            ) {
                $reasons[] = 'venue';
            }

            $conflictDateLabel = !empty($conflict['conflict_date'])
                ? date('M d, Y', strtotime($conflict['conflict_date']))
                : date('M d, Y', strtotime($conflict['hearing_date']));

            $conflictTimeLabel = (!empty($conflict['conflict_time_start']) && !empty($conflict['conflict_time_end']))
                ? ' ' . date('g:i A', strtotime($conflict['conflict_time_start'])) . ' - ' . date('g:i A', strtotime($conflict['conflict_time_end']))
                : (' ' . date('g:i A', strtotime($conflict['hearing_time'])));

            $conflictDesc =
                ($conflict['reference_number'] ?: ('Hearing #' . $conflict['id']))
                . ' - '
                . $conflict['title']
                . ' ('
                . $conflictDateLabel . $conflictTimeLabel
                . ')'
                . ($reasons ? ' [' . implode(' / ', $reasons) . ' conflict]' : '');

            $parts[] = $conflictDesc;
            $conflictsData[] = [
                'id' => (int)$conflict['id'],
                'title' => $conflict['title'],
                'reference_number' => $conflict['reference_number'] ?: ('Hearing #' . $conflict['id']),
                'view_url' => APP_URL . '/modules/hearings/view.php?id=' . (int)$conflict['id'],
                'date_label' => $conflictDateLabel,
                'time_label' => trim($conflictTimeLabel),
                'reasons' => $reasons,
                'label' => $conflictDesc,
            ];
        }

        $conflictMsg =
            'Schedule conflict detected. Resolve the overlapping committee or venue schedule first: '
            . implode('; ', $parts);

        jsonResponse(false, $conflictMsg, [
            'has_conflict' => true,
            'conflicts' => $conflictsData,
        ]);
    }
}

if ($errors) {
    jsonResponse(false, implode(' ', $errors));
}

$uploadedDiskFiles = [];

try {
    $pdo->beginTransaction();

    $previousStatus = $existing['status'] ?? null;
    $cancelledAt = null;
    $cancelReasonDb = null;

    if ($status === 'Cancelled') {
        $cancelledAt = !empty($existing['cancelled_at'])
            ? $existing['cancelled_at']
            : date('Y-m-d H:i:s');
        $cancelReasonDb = $cancellationReason;
    }

    if ($id > 0) {
        $referenceNumber = trim((string)($existing['reference_number'] ?? ''));

        if ($referenceNumber === '') {
            $referenceNumber = hearingGenerateReference($pdo, $hearingDate);
        }

        $stmt = $pdo->prepare(
            'UPDATE hearings
             SET legislative_item_id = :legislative_item_id,
                 reference_number = :reference_number,
                 title = :title,
                 hearing_type_id = :hearing_type_id,
                 committee_id = :committee_id,
                 venue = :venue,
                 hearing_date = :hearing_date,
                 end_date = :end_date,
                 hearing_time = :hearing_time,
                 end_time = :end_time,
                 status = :status,
                 description = :description,
                 registration_deadline = :registration_deadline,
                 maximum_participants = :maximum_participants,
                 meeting_link = :meeting_link,
                 visibility = :visibility,
                 cancelled_at = :cancelled_at,
                 cancellation_reason = :cancellation_reason,
                 updated_at = NOW()
             WHERE id = :id'
        );

        $stmt->execute([
            ':legislative_item_id' => $legislativeItemId,
            ':reference_number' => $referenceNumber,
            ':title' => $title,
            ':hearing_type_id' => $hearingTypeId,
            ':committee_id' => $committeeId,
            ':venue' => $venue ?: null,
            ':hearing_date' => $hearingDate,
            ':end_date' => $endDate,
            ':hearing_time' => $hearingTime,
            ':end_time' => $endTime,
            ':status' => $status,
            ':description' => $description ?: null,
            ':registration_deadline' => $registrationDeadline,
            ':maximum_participants' => $maximumParticipants,
            ':meeting_link' => $meetingLink ?: null,
            ':visibility' => $visibility,
            ':cancelled_at' => $cancelledAt,
            ':cancellation_reason' => $cancelReasonDb,
            ':id' => $id,
        ]);

        hearingAddHistory(
            $pdo,
            $id,
            $previousStatus !== $status ? 'Status Change' : 'Update',
            $previousStatus,
            $status,
            'Updated hearing "' . $title . '".',
            currentUserId()
        );

        logActivity(
            currentUserId(),
            'Update Hearing',
            'Updated ' . $referenceNumber . ' - ' . $title
            . ($previousStatus !== $status ? " (status: {$previousStatus} -> {$status})" : '')
        );

        $message = 'Hearing updated successfully.';
    } else {
        $referenceNumber = hearingGenerateReference($pdo, $hearingDate);

        $stmt = $pdo->prepare(
            'INSERT INTO hearings
                (legislative_item_id, reference_number, title, hearing_type_id,
                 committee_id, venue, hearing_date, end_date, hearing_time,
                 end_time, status, description, registration_deadline,
                 maximum_participants, meeting_link, visibility, created_by,
                 cancelled_at, cancellation_reason, created_at, updated_at)
             VALUES
                (:legislative_item_id, :reference_number, :title, :hearing_type_id,
                 :committee_id, :venue, :hearing_date, :end_date, :hearing_time,
                 :end_time, :status, :description, :registration_deadline,
                 :maximum_participants, :meeting_link, :visibility, :created_by,
                 :cancelled_at, :cancellation_reason, NOW(), NOW())'
        );

        $stmt->execute([
            ':legislative_item_id' => $legislativeItemId,
            ':reference_number' => $referenceNumber,
            ':title' => $title,
            ':hearing_type_id' => $hearingTypeId,
            ':committee_id' => $committeeId,
            ':venue' => $venue ?: null,
            ':hearing_date' => $hearingDate,
            ':end_date' => $endDate,
            ':hearing_time' => $hearingTime,
            ':end_time' => $endTime,
            ':status' => $status,
            ':description' => $description ?: null,
            ':registration_deadline' => $registrationDeadline,
            ':maximum_participants' => $maximumParticipants,
            ':meeting_link' => $meetingLink ?: null,
            ':visibility' => $visibility,
            ':created_by' => currentUserId(),
            ':cancelled_at' => $cancelledAt,
            ':cancellation_reason' => $cancelReasonDb,
        ]);

        $id = (int)$pdo->lastInsertId();

        hearingAddHistory(
            $pdo,
            $id,
            'Create',
            null,
            $status,
            'Created hearing "' . $title . '".',
            currentUserId()
        );

        logActivity(
            currentUserId(),
            'Create Hearing',
            'Created ' . $referenceNumber . ' - ' . $title
        );

        $message = 'Hearing created successfully.';
    }

    // Synchronize hearing session days in hearing_session_days table
    if (hearingTableExists($pdo, 'hearing_session_days') && !empty($parsedSessions)) {
        // Fetch existing session days to retain closure states
        $existingSessionMap = [];
        $exStmt = $pdo->prepare('SELECT * FROM hearing_session_days WHERE hearing_id = :hid');
        $exStmt->execute([':hid' => $id]);
        foreach ($exStmt->fetchAll() as $row) {
            $existingSessionMap[$row['session_date']] = $row;
        }

        $activeDates = [];
        $upsertSession = $pdo->prepare('
            INSERT INTO hearing_session_days
                (hearing_id, session_date, day_number, start_time, end_time, is_closed, closed_at, closed_by, notes, created_at, updated_at)
            VALUES
                (:hid, :sdate, :dnum, :start_time, :end_time, :is_closed, :closed_at, :closed_by, :notes, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                day_number = VALUES(day_number),
                start_time = VALUES(start_time),
                end_time = VALUES(end_time),
                notes = VALUES(notes),
                updated_at = NOW()
        ');

        foreach ($parsedSessions as $index => $sess) {
            $sDate = $sess['session_date'];
            $activeDates[] = $sDate;
            $dNum = $index + 1;
            $old = $existingSessionMap[$sDate] ?? null;

            $upsertSession->execute([
                ':hid' => $id,
                ':sdate' => $sDate,
                ':dnum' => $dNum,
                ':start_time' => $sess['start_time'],
                ':end_time' => $sess['end_time'],
                ':is_closed' => $old ? (int)$old['is_closed'] : 0,
                ':closed_at' => $old['closed_at'] ?? null,
                ':closed_by' => $old['closed_by'] ?? null,
                ':notes' => $sess['notes'] ?: ($old['notes'] ?? null),
            ]);
        }

        // Clean up removed session dates if no attendance records exist for them
        if (!empty($existingSessionMap)) {
            foreach ($existingSessionMap as $oldDate => $oldRow) {
                if (!in_array($oldDate, $activeDates, true)) {
                    $attCountStmt = $pdo->prepare('SELECT COUNT(*) FROM attendance WHERE hearing_id = :hid AND attendance_date = :adate');
                    $attCountStmt->execute([':hid' => $id, ':adate' => $oldDate]);
                    if ((int)$attCountStmt->fetchColumn() === 0) {
                        $delStmt = $pdo->prepare('DELETE FROM hearing_session_days WHERE hearing_id = :hid AND session_date = :adate');
                        $delStmt->execute([':hid' => $id, ':adate' => $oldDate]);
                    }
                }
            }
        }
    }

    $uploadedCount = 0;
    $uploadErrors = [];

    if (
        !empty($_FILES['documents'])
        && is_array($_FILES['documents']['name'] ?? null)
    ) {
        $count = count($_FILES['documents']['name']);

        for ($i = 0; $i < $count; $i++) {
            if (
                ($_FILES['documents']['error'][$i] ?? UPLOAD_ERR_NO_FILE)
                === UPLOAD_ERR_NO_FILE
            ) {
                continue;
            }

            $singleFile = [
                'name' => $_FILES['documents']['name'][$i] ?? '',
                'type' => $_FILES['documents']['type'][$i] ?? '',
                'tmp_name' => $_FILES['documents']['tmp_name'][$i] ?? '',
                'error' => $_FILES['documents']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $_FILES['documents']['size'][$i] ?? 0,
            ];

            $result = handleUpload($singleFile, 'hearings');

            if (!$result['success']) {
                $uploadErrors[] = ($singleFile['name'] ?: 'File') . ': ' . $result['message'];
                continue;
            }

            $uploadedDiskFiles[] = rtrim(UPLOAD_DIR, '/') . '/' . $result['file_path'];

            $docStmt = $pdo->prepare(
                'INSERT INTO hearing_documents
                    (hearing_id, file_name, file_path, document_type,
                     description, version_number, visibility, uploaded_by,
                     uploaded_at, updated_at)
                 VALUES
                    (:hearing_id, :file_name, :file_path, :document_type,
                     NULL, 1, :visibility, :uploaded_by, NOW(), NOW())'
            );

            $docStmt->execute([
                ':hearing_id' => $id,
                ':file_name' => $result['file_name'],
                ':file_path' => $result['file_path'],
                ':document_type' => 'Supporting Document',
                ':visibility' => $visibility === 'Public' ? 'Public' : 'Internal',
                ':uploaded_by' => currentUserId(),
            ]);

            $uploadedCount++;
        }
    }

    if ($uploadedCount > 0) {
        hearingAddHistory(
            $pdo,
            $id,
            'Upload Documents',
            $status,
            $status,
            "Uploaded {$uploadedCount} hearing document(s).",
            currentUserId()
        );

        logActivity(
            currentUserId(),
            'Upload Hearing Document',
            "Uploaded {$uploadedCount} document(s) to {$referenceNumber}."
        );
    }

    $pdo->commit();

    if ($uploadErrors) {
        $message .= ' Some files were not uploaded: ' . implode('; ', $uploadErrors);
    }

    jsonResponse(true, $message, [
        'id' => $id,
        'reference_number' => $referenceNumber,
        'documents_uploaded' => $uploadedCount,
        'upload_errors' => $uploadErrors,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    foreach ($uploadedDiskFiles as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    error_log('Hearing save error: ' . $e->getMessage());

    jsonResponse(
        false,
        APP_DEBUG
            ? 'Unable to save hearing: ' . $e->getMessage()
            : 'A database error occurred while saving the hearing.'
    );
}
