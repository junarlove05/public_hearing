<?php
/**
 * modules/feedback/ajax_survey_save.php
 * ------------------------------------------------------------------
 * Creates or updates a survey (id=0 means create).
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!canManage()) jsonResponse(false, 'You do not have permission to perform this action.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.');
requireCsrf();

$id          = (int)($_POST['id'] ?? 0);
$title       = clean($_POST['title'] ?? '');
$description = clean($_POST['description'] ?? '');
$status      = clean($_POST['status'] ?? 'Active');
$allowedStatus = ['Active', 'Inactive'];

$errors = [];
if ($title === '') $errors[] = 'Survey title is required.';
if (!in_array($status, $allowedStatus, true)) $errors[] = 'Invalid status value.';
if (!empty($errors)) jsonResponse(false, implode(' ', $errors));

$pdo = db();
try {
    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE surveys SET title = :title, description = :desc, status = :status WHERE id = :id');
        $stmt->execute([':title' => $title, ':desc' => $description, ':status' => $status, ':id' => $id]);
        logActivity(currentUserId(), 'Update', 'Updated survey #' . $id . ' (' . $title . ')');
        jsonResponse(true, 'Survey updated successfully.', ['id' => $id]);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO surveys (title, description, status, created_at) VALUES (:title, :desc, :status, NOW())'
    );
    $stmt->execute([':title' => $title, ':desc' => $description, ':status' => $status]);
    $id = (int)$pdo->lastInsertId();

    logActivity(currentUserId(), 'Insert', 'Created survey #' . $id . ' (' . $title . ')');
    jsonResponse(true, 'Survey created successfully.', ['id' => $id]);

} catch (PDOException $e) {
    error_log('Survey save error: ' . $e->getMessage());
    jsonResponse(false, 'A database error occurred while saving the survey.');
}
