<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
if (!canManage() && !hasPermission('lph.feedback.review')) {
    jsonResponse(false, 'You do not have permission to endorse citizen submissions as issues.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

requireCsrf();

$pdo = db();
$cefId = (int)($_POST['cef_submission_id'] ?? 0);
$hearingId = (int)($_POST['hearing_id'] ?? 0) ?: null;
$legislativeItemId = (int)($_POST['legislative_item_id'] ?? 0) ?: null;
$categoryId = (int)($_POST['category_id'] ?? 0) ?: null;
$priority = clean($_POST['priority'] ?? 'Medium');
$title = clean($_POST['title'] ?? '');
$dueAt = clean($_POST['due_at'] ?? '') ?: null;
$assignedOfficeId = (int)($_POST['assigned_office_id'] ?? 0) ?: null;
$notes = trim((string)($_POST['notes'] ?? ''));

if ($cefId <= 0) {
    jsonResponse(false, 'Invalid Citizen Portal submission ID.');
}

if (!in_array($priority, ['Low', 'Medium', 'High', 'Critical'], true)) {
    jsonResponse(false, 'Invalid priority level.');
}

if ($title === '') {
    jsonResponse(false, 'Issue title is required.');
}

// 1. Fetch CEF submission
$stmt = $pdo->prepare(
    "SELECT s.*, c.name AS category_name
     FROM cef_submissions s
     LEFT JOIN cef_categories c ON c.id = s.category_id
     WHERE s.id = :id AND s.deleted_at IS NULL
     LIMIT 1"
);
$stmt->execute([':id' => $cefId]);
$cef = $stmt->fetch();

if (!$cef) {
    jsonResponse(false, 'Citizen Portal submission was not found.');
}

// 2. Check if already converted
$dup = $pdo->prepare("SELECT id, reference_number FROM hearing_issues WHERE cef_submission_id = :id LIMIT 1");
$dup->execute([':id' => $cefId]);
if ($existing = $dup->fetch()) {
    jsonResponse(false, "This submission was already endorsed as Issue {$existing['reference_number']}.");
}

// 3. Fetch optional AI analysis
$aiStmt = $pdo->prepare("SELECT * FROM lph_cef_ai_analysis WHERE submission_id = :id LIMIT 1");
$aiStmt->execute([':id' => $cefId]);
$ai = $aiStmt->fetch();

// 4. Verify Hearing & Legislative Item if given
if ($hearingId) {
    $hCheck = $pdo->prepare("SELECT id, legislative_item_id FROM hearings WHERE id = :id AND status <> 'Cancelled'");
    $hCheck->execute([':id' => $hearingId]);
    $hRow = $hCheck->fetch();
    if ($hRow && !$legislativeItemId && !empty($hRow['legislative_item_id'])) {
        $legislativeItemId = (int)$hRow['legislative_item_id'];
    }
}

if ($categoryId) {
    $cCheck = $pdo->prepare("SELECT id FROM hearing_issue_categories WHERE id = :id");
    $cCheck->execute([':id' => $categoryId]);
    if (!$cCheck->fetch()) {
        $categoryId = null;
    }
}

try {
    $pdo->beginTransaction();

    // Generate Issue Reference: ISS-YYYY-NNNN
    $year = date('Y');
    $seqStmt = $pdo->prepare(
        "SELECT MAX(CAST(SUBSTRING_INDEX(reference_number, '-', -1) AS UNSIGNED)) 
         FROM hearing_issues 
         WHERE reference_number LIKE :prefix"
    );
    $seqStmt->execute([':prefix' => "ISS-{$year}-%"]);
    $maxSeq = (int)$seqStmt->fetchColumn();
    $nextSeq = $maxSeq + 1;
    $reference = sprintf('ISS-%s-%04d', $year, $nextSeq);

    // Build rich legislative description
    $descLines = [
        "Source: Citizen Portal / CEPFMS ({$cef['reference_number']})",
        "Citizen: " . ($cef['citizen_name'] ?: 'Anonymous') . ($cef['citizen_email'] ? " <{$cef['citizen_email']}>" : ""),
        "Submission Type: {$cef['submission_type']}",
        "Source Channel: " . ($cef['source_channel'] ?: 'Citizen Portal'),
        "Portal Priority: " . ($cef['priority_level'] ?: 'Normal'),
    ];

    if (!empty($cef['summary'])) {
        $descLines[] = "Citizen Summary: " . $cef['summary'];
    }

    if ($ai && !empty($ai['sentiment'])) {
        $descLines[] = "AI Assessment: Sentiment={$ai['sentiment']} | Urgency={$ai['urgency_level']}" . 
            (!empty($ai['review_status']) ? " (Admin Verification: {$ai['review_status']})" : "");
    }

    if ($notes !== '') {
        $descLines[] = "Staff Endorsement Notes: {$notes}";
    }

    $descLines[] = "\n--- Details / Message ---\n" . $cef['details'];
    $description = implode("\n", $descLines);

    // Insert into hearing_issues
    $insert = $pdo->prepare(
        "INSERT INTO hearing_issues
            (hearing_id, legislative_item_id, cef_submission_id, category_id, reference_number,
             title, description, priority, status, assigned_office_id, assigned_user_id,
             due_at, closed_at, created_by, created_at, updated_at)
         VALUES
            (:hearing, :item, :cef_id, :cat, :ref,
             :title, :desc, :priority, 'Open', :office, NULL,
             :due, NULL, :user, NOW(), NOW())"
    );
    $insert->execute([
        ':hearing'   => $hearingId,
        ':item'      => $legislativeItemId,
        ':cef_id'    => $cefId,
        ':cat'       => $categoryId,
        ':ref'       => $reference,
        ':title'     => $title,
        ':desc'      => $description,
        ':priority'  => $priority,
        ':office'    => $assignedOfficeId,
        ':due'       => $dueAt,
        ':user'      => currentUserId(),
    ]);

    $issueId = (int)$pdo->lastInsertId();

    // Insert into hearing_issue_history
    $histStmt = $pdo->prepare(
        "INSERT INTO hearing_issue_history (issue_id, note, created_by, created_at)
         VALUES (:issue, :note, :user, NOW())"
    );
    $histStmt->execute([
        ':issue' => $issueId,
        ':note'  => "Issue created and endorsed from Citizen Portal submission {$cef['reference_number']}.",
        ':user'  => currentUserId(),
    ]);

    // Initial Office assignment if provided
    if ($assignedOfficeId) {
        $assignStmt = $pdo->prepare(
            "INSERT INTO hearing_issue_assignments
                (issue_id, assigned_office_id, assigned_user_id, assigned_by, remarks, assigned_at)
             VALUES
                (:issue, :office, NULL, :user, 'Initial assignment upon endorsement from Citizen Portal.', NOW())"
        );
        $assignStmt->execute([
            ':issue'  => $issueId,
            ':office' => $assignedOfficeId,
            ':user'   => currentUserId(),
        ]);
    }

    // Update Citizen Portal submission status
    $pdo->prepare(
        "UPDATE cef_submissions
         SET status = CASE WHEN status IN ('Submitted', 'Pending') THEN 'Under Review' ELSE status END,
             updated_at = NOW()
         WHERE id = :id"
    )->execute([':id' => $cefId]);

    // Add automated council note in cef_followups if available
    if ($pdo->query("SHOW TABLES LIKE 'cef_followups'")->fetchColumn()) {
        $pdo->prepare(
            "INSERT INTO cef_followups
                (submission_id, response_id, direction, sender_user_id, sender_name, message, public_visible, created_at)
             VALUES
                (:sub_id, NULL, 'Council to Citizen', :user_id, 'City Council Secretariat', :msg, 1, NOW())"
        )->execute([
            ':sub_id'  => $cefId,
            ':user_id' => currentUserId(),
            ':msg'     => "Your submission has been formally endorsed as Legislative Hearing Issue #{$reference} for committee review and executive action.",
        ]);
    }

    logActivity(
        currentUserId(),
        'Endorse Citizen Feedback as Issue',
        "Converted Citizen Portal submission {$cef['reference_number']} to Hearing Issue {$reference}."
    );

    $pdo->commit();

    jsonResponse(true, "Citizen report endorsed successfully as Issue {$reference}.", [
        'issue_id'         => $issueId,
        'reference_number' => $reference,
        'view_url'         => APP_URL . "/modules/issues/view.php?id={$issueId}",
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("CEF to Issue Endorsement Error: " . $e->getMessage());
    jsonResponse(false, APP_DEBUG ? $e->getMessage() : 'Unable to endorse submission as hearing issue.');
}
