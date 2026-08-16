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

$errors = [];

if ($title === '') {
    $errors[] = 'Title is required.';
}

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
        $venue
    );

    if ($conflicts) {
        $parts = [];

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

            $parts[] =
                ($conflict['reference_number'] ?: ('Hearing #' . $conflict['id']))
                . ' - '
                . $conflict['title']
                . ' ('
                . date(
                    'M d, Y g:i A',
                    strtotime($conflict['hearing_date'] . ' ' . $conflict['hearing_time'])
                )
                . ')'
                . ($reasons ? ' [' . implode(' / ', $reasons) . ' conflict]' : '');
        }

        $errors[] =
            'Schedule conflict detected. Resolve the overlapping committee or venue schedule first: '
            . implode('; ', $parts);
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
