<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lph_module_helpers.php';
require_once __DIR__ . '/hearing_helpers.php';

requireLogin();

$id = (int)($_GET['id'] ?? 0);
$pdo = db();

$stmt = $pdo->prepare(
    'SELECT
        h.*,
        ht.name AS type_name,
        c.name AS committee_name,
        c.description AS committee_description,
        li.reference_number AS legislative_reference,
        li.title AS legislative_title,
        li.current_status AS legislative_status,
        lit.name AS legislative_type,
        o.name AS originating_office_name,
        creator.full_name AS created_by_name
     FROM hearings h
     LEFT JOIN hearing_types ht ON ht.id = h.hearing_type_id
     LEFT JOIN committees c ON c.id = h.committee_id
     LEFT JOIN legislative_items li ON li.id = h.legislative_item_id
     LEFT JOIN legislative_item_types lit ON lit.id = li.item_type_id
     LEFT JOIN offices o ON o.id = li.originating_office_id
     LEFT JOIN users creator ON creator.id = h.created_by
     WHERE h.id = :id'
);
$stmt->execute([':id' => $id]);
$hearing = $stmt->fetch();

if (!$hearing) {
    setFlash('danger', 'Hearing not found.');
    redirect(APP_URL . '/modules/hearings/index.php');
}

$documentsStmt = $pdo->prepare(
    'SELECT d.*, u.full_name AS uploaded_by_name
     FROM hearing_documents d
     LEFT JOIN users u ON u.id = d.uploaded_by
     WHERE d.hearing_id = :id
     ORDER BY d.uploaded_at DESC, d.id DESC'
);
$documentsStmt->execute([':id' => $id]);
$documents = $documentsStmt->fetchAll();

$regStmt = $pdo->prepare(
    'SELECT
        r.id,
        r.registration_status,
        r.attendance_type,
        r.registered_at,
        s.full_name,
        s.organization,
        s.email
     FROM registrations r
     JOIN stakeholders s ON s.id = r.stakeholder_id
     WHERE r.hearing_id = :id
     ORDER BY r.registered_at DESC
     LIMIT 12'
);
$regStmt->execute([':id' => $id]);
$registrations = $regStmt->fetchAll();

$regCountStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM registrations WHERE hearing_id = :id AND registration_status = 'Approved'"
);
$regCountStmt->execute([':id' => $id]);
$regCount = (int)$regCountStmt->fetchColumn();

// Detailed registration breakdown
$regBreakdownStmt = $pdo->prepare(
    "SELECT 
        SUM(CASE WHEN registration_status = 'Approved' THEN 1 ELSE 0 END) AS approved_count,
        SUM(CASE WHEN registration_status = 'Pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN registration_status IN ('Declined', 'Rejected') THEN 1 ELSE 0 END) AS declined_count,
        COUNT(*) AS total_count
     FROM registrations WHERE hearing_id = :id"
);
$regBreakdownStmt->execute([':id' => $id]);
$regBreakdown = $regBreakdownStmt->fetch() ?: [];
$pendingRegCount = (int)($regBreakdown['pending_count'] ?? 0);
$declinedRegCount = (int)($regBreakdown['declined_count'] ?? 0);
$totalRegCount = (int)($regBreakdown['total_count'] ?? 0);

$sessionDays = lphGetHearingSessionDays($hearing, $pdo);

$attendanceCountStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM attendance
     WHERE hearing_id = :id
       AND status = 'Present'"
);
$attendanceCountStmt->execute([':id' => $id]);
$attendanceCount = (int)$attendanceCountStmt->fetchColumn();

$feedbackCountStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM feedback WHERE hearing_id = :id'
);
$feedbackCountStmt->execute([':id' => $id]);
$feedbackCount = (int)$feedbackCountStmt->fetchColumn();

$issuesStmt = $pdo->prepare(
    'SELECT id, reference_number, title, status, priority
     FROM hearing_issues
     WHERE hearing_id = :id
     ORDER BY created_at DESC
     LIMIT 12'
);
$issuesStmt->execute([':id' => $id]);
$issues = $issuesStmt->fetchAll();

$history = [];

if (hearingTableExists($pdo, 'hearing_history')) {
    $historyStmt = $pdo->prepare(
        'SELECT hh.*, u.full_name AS changed_by_name
         FROM hearing_history hh
         LEFT JOIN users u ON u.id = hh.changed_by
         WHERE hh.hearing_id = :id
         ORDER BY hh.created_at DESC, hh.id DESC
         LIMIT 50'
    );
    $historyStmt->execute([':id' => $id]);
    $history = $historyStmt->fetchAll();
}

$registrationState = hearingRegistrationState($hearing, $regCount);
$dependencyCounts = hearingDependencyCounts($pdo, $id);

$pageTitle = $hearing['title'];
$activeMenu = 'hearings';

include __DIR__ . '/../../layouts/header.php';
?>
<link rel="stylesheet" href="<?= e(APP_URL . '/assets/css/hearings-complete.css') ?>">

<div class="app-wrapper">
<?php include __DIR__ . '/../../layouts/sidebar.php'; ?>

<div class="main-content hearing-module">

    <div class="hearing-page-head">
        <div>
            <a href="index.php" class="small text-decoration-none">
                <i class="bi bi-arrow-left"></i> Back to Hearing Scheduling
            </a>

            <div class="hearing-eyebrow mt-2">
                <?= e($hearing['reference_number'] ?: ('Hearing #' . $hearing['id'])) ?>
            </div>

            <h1><?= e($hearing['title']) ?></h1>

            <p>
                <?= e($hearing['type_name'] ?: 'Public hearing / consultation') ?>
                · <?= e($hearing['committee_name'] ?: 'No committee assigned') ?>
            </p>
        </div>

        <div class="d-flex gap-2 flex-wrap align-items-start no-print">
            <span class="badge fs-6 text-bg-<?= match ($hearing['status']) {
                'Upcoming' => 'primary',
                'Ongoing' => 'warning',
                'Completed' => 'success',
                'Cancelled' => 'danger',
                default => 'secondary',
            } ?>">
                <?= e($hearing['status']) ?>
            </span>

            <?php if ($hearing['status'] !== 'Completed' && $hearing['status'] !== 'Cancelled'): ?>
                <a
                    href="../attendance/index.php?hearing_id=<?= (int)$hearing['id'] ?>"
                    class="btn btn-outline-primary btn-sm"
                >
                    <i class="bi bi-qr-code-scan"></i> Attendance Tracking
                </a>
            <?php endif; ?>

            <a
                href="print.php?id=<?= (int)$hearing['id'] ?>"
                target="_blank"
                class="btn btn-outline-secondary btn-sm"
            >
                <i class="bi bi-printer"></i> Print Detail
            </a>
        </div>
    </div>

    <?php if ($hearing['status'] === 'Cancelled'): ?>
        <div class="alert alert-danger">
            <strong><i class="bi bi-x-octagon"></i> Hearing Cancelled</strong>
            <div class="mt-1">
                <?= nl2br(e($hearing['cancellation_reason'] ?: 'No cancellation reason recorded.')) ?>
            </div>
            <?php if (!empty($hearing['cancelled_at'])): ?>
                <div class="small mt-1">
                    Cancelled <?= formatDateTime($hearing['cancelled_at']) ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="hearing-mini-card">
                <i class="bi bi-people"></i>
                <strong><?= $regCount ?></strong>
                <span>Registrations</span>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="hearing-mini-card">
                <i class="bi bi-person-check"></i>
                <strong><?= $attendanceCount ?></strong>
                <span>Present</span>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="hearing-mini-card">
                <i class="bi bi-chat-left-text"></i>
                <strong><?= $feedbackCount ?></strong>
                <span>Feedback</span>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="hearing-mini-card">
                <i class="bi bi-exclamation-triangle"></i>
                <strong><?= (int)$dependencyCounts['issues'] ?></strong>
                <span>Issues</span>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-xl-8">

            <div class="card hearing-detail-card mb-3">
                <div class="card-header">
                    <strong><i class="bi bi-info-circle"></i> Hearing Information</strong>
                </div>

                <div class="card-body">
                    <div class="hearing-detail-grid">
                        <div>
                            <small>Reference Number</small>
                            <strong><?= e($hearing['reference_number'] ?: 'Not assigned') ?></strong>
                        </div>

                        <div>
                            <small>Hearing Type</small>
                            <strong><?= e($hearing['type_name'] ?: '—') ?></strong>
                        </div>

                        <div>
                            <small>Committee</small>
                            <strong><?= e($hearing['committee_name'] ?: '—') ?></strong>
                        </div>

                        <div>
                            <small>Visibility</small>
                            <strong><?= e($hearing['visibility'] ?: 'Public') ?></strong>
                        </div>

                        <div>
                            <small>Start</small>
                            <strong>
                                <?= formatDate($hearing['hearing_date']) ?>
                                · <?= formatTime($hearing['hearing_time']) ?>
                            </strong>
                        </div>

                        <div>
                            <small>End</small>
                            <strong>
                                <?= formatDate($hearing['end_date'] ?: $hearing['hearing_date']) ?>
                                · <?= formatTime($hearing['end_time']) ?>
                            </strong>
                        </div>

                        <div>
                            <small>Venue</small>
                            <strong><?= e($hearing['venue'] ?: 'No physical venue') ?></strong>
                        </div>

                        <div>
                            <small>Online Meeting</small>
                            <?php if (!empty($hearing['meeting_link'])): ?>
                                <a href="<?= e($hearing['meeting_link']) ?>" target="_blank" rel="noopener" class="fw-semibold">
                                    Open meeting link <i class="bi bi-box-arrow-up-right"></i>
                                </a>
                            <?php else: ?>
                                <strong>—</strong>
                            <?php endif; ?>
                        </div>

                        <div>
                            <small>Registration Deadline</small>
                            <strong>
                                <?= !empty($hearing['registration_deadline'])
                                    ? formatDateTime($hearing['registration_deadline'])
                                    : 'No deadline' ?>
                            </strong>
                        </div>

                        <div>
                            <small>Maximum Participants</small>
                            <strong>
                                <?= !empty($hearing['maximum_participants'])
                                    ? (int)$hearing['maximum_participants']
                                    : 'No limit' ?>
                            </strong>
                        </div>

                        <div>
                            <small>Registration Status</small>
                            <strong class="<?= $registrationState['open'] ? 'text-success' : 'text-secondary' ?>">
                                <?= e($registrationState['label']) ?>
                            </strong>
                        </div>

                        <div>
                            <small>Created By</small>
                            <strong><?= e($hearing['created_by_name'] ?: 'System / unknown') ?></strong>
                        </div>
                    </div>

                    <hr>

                    <div>
                        <small class="text-muted text-uppercase fw-semibold">
                            Description / Consultation Purpose
                        </small>
                        <div class="mt-2">
                            <?= nl2br(e($hearing['description'] ?: 'No description provided.')) ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (count($sessionDays) > 1): ?>
            <div class="card hearing-detail-card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <strong><i class="bi bi-calendar3-range text-primary me-1"></i> Multi-Day Session Schedule &amp; Daily Attendance</strong>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?= count($sessionDays) ?> Scheduled Days</span>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php foreach ($sessionDays as $sd): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center py-2.5 px-3 flex-wrap gap-2">
                            <div>
                                <strong><?= e($sd['day_label']) ?> · <?= e($sd['formatted_date']) ?></strong>
                                <span class="small text-muted ms-1">(<?= e($sd['weekday']) ?>)</span>
                                <?php if (!empty($sd['time_label'])): ?>
                                    <span class="badge bg-light text-dark border ms-2" style="font-size: 0.75rem;">
                                        <i class="bi bi-clock me-1 text-primary"></i><?= e($sd['time_label']) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($sd['notes'])): ?>
                                    <div class="small text-primary-emphasis mt-0.5 fw-medium"><i class="bi bi-tag me-1"></i><?= e($sd['notes']) ?></div>
                                <?php endif; ?>
                                <div class="small text-muted"><?= e($sd['status_reason']) ?></div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge <?= $sd['status_badge_class'] ?>"><?= e($sd['status_badge_text']) ?></span>
                                <?php if ($hearing['status'] !== 'Completed' && $hearing['status'] !== 'Cancelled'): ?>
                                    <a href="../attendance/index.php?hearing_id=<?= (int)$hearing['id'] ?>&date=<?= urlencode($sd['date']) ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-qr-code-scan me-1"></i> <?= $sd['status'] === 'closed' ? 'View Attendance' : 'Take Attendance' ?>
                                    </a>
                                <?php else: ?>
                                    <a href="../attendance/history.php?hearing_id=<?= (int)$hearing['id'] ?>&date=<?= urlencode($sd['date']) ?>" class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-clock-history me-1"></i> Attendance History
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="card hearing-detail-card mb-3">
                <div class="card-header">
                    <strong><i class="bi bi-file-earmark-text"></i> Related Legislative Item</strong>
                </div>

                <div class="card-body">
                    <?php if (!empty($hearing['legislative_reference'])): ?>
                        <span class="badge text-bg-light">
                            <?= e($hearing['legislative_type'] ?: 'Legislative Item') ?>
                        </span>

                        <strong class="ms-1"><?= e($hearing['legislative_reference']) ?></strong>

                        <h6 class="mt-2 mb-1"><?= e($hearing['legislative_title']) ?></h6>

                        <div class="small text-muted">
                            Current status:
                            <?= e($hearing['legislative_status'] ?: '—') ?>
                            <?php if (!empty($hearing['originating_office_name'])): ?>
                                · <?= e($hearing['originating_office_name']) ?>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-muted">
                            This hearing is not currently linked to a legislative item.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card hearing-detail-card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong><i class="bi bi-folder2-open"></i> Hearing Documents</strong>
                    <span class="badge text-bg-light"><?= count($documents) ?></span>
                </div>

                <?php if (canManage()): ?>
                    <div class="card-body border-bottom no-print">
                        <form id="hearingDocumentForm" enctype="multipart/form-data" class="row g-2 align-items-end">
                            <?= csrfField() ?>
                            <input type="hidden" name="hearing_id" value="<?= (int)$hearing['id'] ?>">

                            <div class="col-lg-4">
                                <label class="form-label small">Document</label>
                                <input
                                    type="file"
                                    name="document"
                                    class="form-control form-control-sm"
                                    accept=".pdf,.doc,.docx,.png,.jpg,.jpeg"
                                    required
                                >
                            </div>

                            <div class="col-lg-3">
                                <label class="form-label small">Type</label>
                                <select name="document_type" class="form-select form-select-sm">
                                    <option>Supporting Document</option>
                                    <option>Agenda</option>
                                    <option>Notice</option>
                                    <option>Presentation</option>
                                    <option>Committee Document</option>
                                    <option>Minutes</option>
                                </select>
                            </div>

                            <div class="col-lg-2">
                                <label class="form-label small">Visibility</label>
                                <select name="visibility" class="form-select form-select-sm">
                                    <option>Internal</option>
                                    <option>Public</option>
                                    <option>Restricted</option>
                                </select>
                            </div>

                            <div class="col-lg-3">
                                <button type="submit" class="btn btn-primary btn-sm w-100" id="btnUploadHearingDocument">
                                    <i class="bi bi-upload"></i> Upload Document
                                </button>
                            </div>

                            <div class="col-12">
                                <input
                                    type="text"
                                    name="description"
                                    class="form-control form-control-sm"
                                    placeholder="Optional document description"
                                >
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <div class="list-group list-group-flush">
                    <?php if (!$documents): ?>
                        <div class="p-4 text-center text-muted">No hearing documents uploaded yet.</div>
                    <?php endif; ?>

                    <?php foreach ($documents as $document): ?>
                        <div class="list-group-item hearing-document-row">
                            <div>
                                <strong><?= e($document['file_name']) ?></strong>
                                <div class="small text-muted">
                                    <?= e($document['document_type'] ?: 'Supporting Document') ?>
                                    · Version <?= (int)($document['version_number'] ?: 1) ?>
                                    · <?= e($document['visibility'] ?: 'Internal') ?>
                                    · Uploaded <?= formatDateTime($document['uploaded_at']) ?>
                                    <?php if (!empty($document['uploaded_by_name'])): ?>
                                        by <?= e($document['uploaded_by_name']) ?>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($document['description'])): ?>
                                    <div class="small mt-1"><?= e($document['description']) ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="d-flex gap-1 no-print">
                                <a
                                    href="<?= e(UPLOAD_URL . ltrim($document['file_path'], '/')) ?>"
                                    target="_blank"
                                    class="btn btn-outline-secondary btn-sm"
                                    title="Download Document"
                                >
                                    <i class="bi bi-download"></i>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card hearing-detail-card">
                <div class="card-header d-flex justify-content-between">
                    <strong><i class="bi bi-exclamation-triangle"></i> Related Issues</strong>
                    <span class="badge text-bg-light"><?= (int)$dependencyCounts['issues'] ?></span>
                </div>

                <div class="list-group list-group-flush">
                    <?php if (!$issues): ?>
                        <div class="p-4 text-center text-muted">No issues logged for this hearing.</div>
                    <?php endif; ?>

                    <?php foreach ($issues as $issue): ?>
                        <a
                            href="<?= e(APP_URL) ?>/modules/issues/view.php?id=<?= (int)$issue['id'] ?>"
                            class="list-group-item list-group-item-action"
                        >
                            <div class="d-flex justify-content-between gap-2">
                                <div>
                                    <div class="small text-muted"><?= e($issue['reference_number']) ?></div>
                                    <strong><?= e($issue['title']) ?></strong>
                                </div>

                                <div class="text-end">
                                    <?= priorityBadge($issue['priority']) ?>
                                    <?= statusBadge($issue['status']) ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card hearing-detail-card mb-3">
                <div class="card-header">
                    <strong><i class="bi bi-person-plus"></i> Registration</strong>
                </div>

                <div class="card-body">
                    <div class="hearing-registration-state <?= $registrationState['open'] ? 'open' : 'closed' ?>">
                        <strong><?= e($registrationState['label']) ?></strong>
                        <span><?= e($registrationState['message']) ?></span>
                    </div>

                    <?php if (!empty($hearing['maximum_participants'])): ?>
                        <?php
                        $capacity = max(1, (int)$hearing['maximum_participants']);
                        $percent = min(100, (int)round(($regCount / $capacity) * 100));
                        ?>

                        <div class="mt-3">
                            <div class="d-flex justify-content-between small">
                                <span>Capacity (Approved)</span>
                                <strong><?= $regCount ?> / <?= $capacity ?></strong>
                            </div>

                            <div class="progress mt-1" style="height:8px">
                                <div class="progress-bar <?= $percent >= 90 ? 'bg-danger' : ($percent >= 70 ? 'bg-warning' : 'bg-primary') ?>" style="width:<?= $percent ?>%"></div>
                            </div>

                            <?php if ($pendingRegCount > 0 || $declinedRegCount > 0): ?>
                                <div class="small text-muted mt-1.5 d-flex gap-2 flex-wrap" style="font-size: 0.75rem;">
                                    <?php if ($pendingRegCount > 0): ?>
                                        <span class="text-warning-emphasis"><i class="bi bi-clock-history me-1"></i><?= $pendingRegCount ?> Pending Review</span>
                                    <?php endif; ?>
                                    <?php if ($declinedRegCount > 0): ?>
                                        <span class="text-danger"><i class="bi bi-x-circle me-1"></i><?= $declinedRegCount ?> Declined</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="list-group list-group-flush">
                    <?php if (!$registrations): ?>
                        <div class="p-3 text-center text-muted small">No registrations yet.</div>
                    <?php endif; ?>

                    <?php foreach ($registrations as $registration): ?>
                        <?php
                        $regSt = $registration['registration_status'] ?: 'Pending';
                        $regBadgeClass = match($regSt) {
                            'Approved' => 'bg-success-subtle text-success border border-success-subtle',
                            'Declined', 'Rejected' => 'bg-danger-subtle text-danger border border-danger-subtle',
                            'Cancelled' => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
                            default => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle'
                        };
                        $regIcon = match($regSt) {
                            'Approved' => 'bi-check-circle-fill',
                            'Declined', 'Rejected' => 'bi-x-circle-fill',
                            'Cancelled' => 'bi-dash-circle',
                            default => 'bi-clock-history'
                        };
                        ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
                            <div class="me-2" style="min-width:0;">
                                <strong class="d-block text-dark text-truncate" style="font-size: 0.9rem;"><?= e($registration['full_name']) ?></strong>
                                <div class="small text-muted text-truncate">
                                    <?= e($registration['organization'] ?: $registration['email']) ?>
                                </div>
                            </div>
                            <div class="flex-shrink-0">
                                <span class="badge <?= $regBadgeClass ?> px-2 py-1" style="font-size: 0.72rem;">
                                    <i class="bi <?= $regIcon ?> me-1"></i><?= e($regSt) ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="card-body border-top no-print">
                    <a
                        href="<?= e(APP_URL) ?>/modules/stakeholders/registrations.php?hearing_id=<?= (int)$hearing['id'] ?>"
                        class="btn btn-outline-primary btn-sm w-100"
                    >
                        <i class="bi bi-people"></i> Open Registration Management
                    </a>
                </div>
            </div>

            <?php if (!empty($hearing['committee_description'])): ?>
                <div class="card hearing-detail-card mb-3">
                    <div class="card-header">
                        <strong><i class="bi bi-building"></i> Committee</strong>
                    </div>

                    <div class="card-body">
                        <strong><?= e($hearing['committee_name']) ?></strong>
                        <div class="small text-muted mt-2">
                            <?= nl2br(e($hearing['committee_description'])) ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card hearing-detail-card">
                <div class="card-header">
                    <strong><i class="bi bi-clock-history"></i> Hearing History</strong>
                </div>

                <div class="hearing-history-list">
                    <?php if (!$history): ?>
                        <div class="p-3 text-center text-muted small">
                            Run migration 005 to enable hearing workflow history.
                        </div>
                    <?php endif; ?>

                    <?php foreach ($history as $event): ?>
                        <div class="hearing-history-item">
                            <span></span>

                            <div>
                                <strong><?= e($event['action']) ?></strong>
                                <div class="small"><?= e($event['details'] ?: '') ?></div>

                                <?php if (
                                    !empty($event['previous_status'])
                                    && $event['previous_status'] !== $event['new_status']
                                ): ?>
                                    <div class="small text-muted">
                                        <?= e($event['previous_status']) ?> → <?= e($event['new_status']) ?>
                                    </div>
                                <?php endif; ?>

                                <div class="small text-muted mt-1">
                                    <?= formatDateTime($event['created_at']) ?>
                                    <?php if (!empty($event['changed_by_name'])): ?>
                                        · <?= e($event['changed_by_name']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('hearingDocumentForm');

    if (!form) return;

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        const button = document.getElementById('btnUploadHearingDocument');
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Uploading...';

        try {
            const result = await appPostForm(
                window.APP_URL + '/modules/hearings/ajax_upload_document.php',
                form
            );

            if (result.success) {
                appToast('success', result.message);
                setTimeout(function () {
                    window.location.reload();
                }, 500);
                return;
            }

            if (!result.session_expired) {
                Swal.fire(
                    'Upload Failed',
                    result.message || 'Unable to upload document.',
                    'error'
                );
            }
        } finally {
            button.disabled = false;
            button.innerHTML = '<i class="bi bi-upload"></i> Upload Document';
        }
    });
});
</script>

<?php include __DIR__ . '/../../layouts/footer.php'; ?>
