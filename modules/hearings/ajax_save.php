<?php
/**
 * modules/hearings/ajax_save.php
 * ------------------------------------------------------------------
 * Handles both CREATE and UPDATE for the `hearings` table (id=0 means
 * create) plus optional document uploads into `hearing_documents`.
 * Only Administrator / Legislative Staff may call this (RBAC).
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!canManage()) {
    jsonResponse(false, 'You do not have permission to perform this action.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}
requireCsrf();

$id             = (int)($_POST['id'] ?? 0);
$title          = clean($_POST['title'] ?? '');
$hearingTypeId  = (int)($_POST['hearing_type_id'] ?? 0) ?: null;
$committeeId    = (int)($_POST['committee_id'] ?? 0) ?: null;
$venue          = clean($_POST['venue'] ?? '');
$hearingDate    = clean($_POST['hearing_date'] ?? '');
$hearingTime    = clean($_POST['hearing_time'] ?? '');
$status         = clean($_POST['status'] ?? 'Upcoming');
$description    = clean($_POST['description'] ?? '');

$allowedStatus = ['Upcoming', 'Ongoing', 'Completed', 'Cancelled'];

/* ---- Validation ---- */
$errors = [];
if ($title === '') $errors[] = 'Title is required.';
if ($hearingDate === '' || !strtotime($hearingDate)) $errors[] = 'A valid hearing date is required.';
if ($hearingTime === '') $errors[] = 'Hearing time is required.';
if (!in_array($status, $allowedStatus, true)) $errors[] = 'Invalid status value.';

if (!empty($errors)) {
    jsonResponse(false, implode(' ', $errors));
}

$pdo = db();

try {
    if ($id > 0) {
        // ---- UPDATE ----
        $stmt = $pdo->prepare(
            'UPDATE hearings SET title = :title, hearing_type_id = :type_id, committee_id = :committee_id,
             venue = :venue, hearing_date = :hdate, hearing_time = :htime, status = :status, description = :description
             WHERE id = :id'
        );
        $stmt->execute([
            ':title' => $title, ':type_id' => $hearingTypeId, ':committee_id' => $committeeId,
            ':venue' => $venue, ':hdate' => $hearingDate, ':htime' => $hearingTime,
            ':status' => $status, ':description' => $description, ':id' => $id,
        ]);
        logActivity(currentUserId(), 'Update', 'Updated hearing #' . $id . ' (' . $title . ')');
        $message = 'Hearing updated successfully.';
    } else {
        // ---- CREATE ----
        $stmt = $pdo->prepare(
            'INSERT INTO hearings (title, hearing_type_id, committee_id, venue, hearing_date, hearing_time, status, description, created_at)
             VALUES (:title, :type_id, :committee_id, :venue, :hdate, :htime, :status, :description, NOW())'
        );
        $stmt->execute([
            ':title' => $title, ':type_id' => $hearingTypeId, ':committee_id' => $committeeId,
            ':venue' => $venue, ':hdate' => $hearingDate, ':htime' => $hearingTime,
            ':status' => $status, ':description' => $description,
        ]);
        $id = (int)$pdo->lastInsertId();
        logActivity(currentUserId(), 'Insert', 'Created hearing #' . $id . ' (' . $title . ')');
        $message = 'Hearing created successfully.';
    }

    /* ---- Optional document uploads (multiple files, input name="documents[]") ---- */
    $uploadedCount = 0;
    $uploadErrors = [];
    if (!empty($_FILES['documents']) && is_array($_FILES['documents']['name'])) {
        $fileCount = count($_FILES['documents']['name']);
        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['documents']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;

            $singleFile = [
                'name'     => $_FILES['documents']['name'][$i],
                'type'     => $_FILES['documents']['type'][$i],
                'tmp_name' => $_FILES['documents']['tmp_name'][$i],
                'error'    => $_FILES['documents']['error'][$i],
                'size'     => $_FILES['documents']['size'][$i],
            ];
            $result = handleUpload($singleFile, 'hearings');
            if ($result['success']) {
                $docStmt = $pdo->prepare(
                    'INSERT INTO hearing_documents (hearing_id, file_name, file_path, uploaded_at) VALUES (:hid, :fname, :fpath, NOW())'
                );
                $docStmt->execute([':hid' => $id, ':fname' => $result['file_name'], ':fpath' => $result['file_path']]);
                $uploadedCount++;
            } else {
                $uploadErrors[] = $singleFile['name'] . ': ' . $result['message'];
            }
        }
        if ($uploadedCount > 0) {
            logActivity(currentUserId(), 'Upload', "Uploaded $uploadedCount document(s) to hearing #$id");
        }
        if (!empty($uploadErrors)) {
            error_log('Hearing document upload errors for hearing #' . $id . ': ' . implode(' | ', $uploadErrors));
        }
    }

    if (!empty($uploadErrors)) {
        $message .= ' However, some file(s) could not be uploaded: ' . implode('; ', $uploadErrors);
    }

    jsonResponse(true, $message, ['id' => $id, 'documents_uploaded' => $uploadedCount, 'upload_errors' => $uploadErrors]);

} catch (PDOException $e) {
    error_log('Hearing save error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while saving the hearing.');
}
