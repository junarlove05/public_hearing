<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/hearing_helpers.php';

requireLogin();

if (!canManage()) {
    jsonResponse(false, 'You do not have permission to delete hearings.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

requireCsrf();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($id <= 0) {
    jsonResponse(false, 'Invalid hearing id.');
}

$pdo = db();

try {
    $stmt = $pdo->prepare(
        'SELECT id, reference_number, title, status
         FROM hearings
         WHERE id = :id'
    );
    $stmt->execute([':id' => $id]);
    $hearing = $stmt->fetch();

    if (!$hearing) {
        jsonResponse(false, 'Hearing not found.');
    }

    $counts = hearingDependencyCounts($pdo, $id);

    if (hearingHasDependencies($counts)) {
        $parts = [];

        foreach ($counts as $label => $count) {
            if ((int)$count > 0) {
                $parts[] = $count . ' ' . str_replace('_', ' ', $label);
            }
        }

        jsonResponse(
            false,
            'This hearing already has linked operational records ('
            . implode(', ', $parts)
            . '). For audit integrity, cancel the hearing instead of deleting it.'
        );
    }

    $docStmt = $pdo->prepare(
        'SELECT file_path FROM hearing_documents WHERE hearing_id = :id'
    );
    $docStmt->execute([':id' => $id]);
    $docs = $docStmt->fetchAll();

    $pdo->beginTransaction();

    $del = $pdo->prepare('DELETE FROM hearings WHERE id = :id');
    $del->execute([':id' => $id]);

    logActivity(
        currentUserId(),
        'Delete Hearing',
        'Deleted '
        . ($hearing['reference_number'] ?: ('Hearing #' . $id))
        . ' - '
        . $hearing['title']
    );

    $pdo->commit();

    foreach ($docs as $doc) {
        $fullPath = rtrim(UPLOAD_DIR, '/') . '/' . ltrim((string)$doc['file_path'], '/');

        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    jsonResponse(true, 'Hearing deleted successfully.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Hearing delete error: ' . $e->getMessage());

    jsonResponse(
        false,
        APP_DEBUG
            ? 'Unable to delete hearing: ' . $e->getMessage()
            : 'A database error occurred while deleting the hearing.'
    );
}
