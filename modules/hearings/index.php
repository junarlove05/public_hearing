<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/hearing_helpers.php';

requireLogin();

$pageTitle = 'Hearing Scheduling';
$activeMenu = 'hearings';
$pdo = db();

$hearingTypes = $pdo->query(
    'SELECT id, name FROM hearing_types ORDER BY name'
)->fetchAll();

$committees = $pdo->query(
    "SELECT id, name
     FROM committees
     WHERE COALESCE(status, 'Active') = 'Active'
     ORDER BY name"
)->fetchAll();

$legislativeItems = $pdo->query(
    "SELECT
        li.id,
        li.reference_number,
        li.title,
        li.current_status,
        lit.name AS type_name,
        lit.code AS type_code
     FROM legislative_items li
     JOIN legislative_item_types lit ON lit.id = li.item_type_id
     WHERE li.deleted_at IS NULL
     ORDER BY li.created_at DESC, li.id DESC
     LIMIT 500"
)->fetchAll();

$stats = [
    'total' => 0,
    'upcoming' => 0,
    'ongoing' => 0,
    'completed' => 0,
    'cancelled' => 0,
];

$statsStmt = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        SUM(status = 'Upcoming') AS upcoming,
        SUM(status = 'Ongoing') AS ongoing,
        SUM(status = 'Completed') AS completed,
        SUM(status = 'Cancelled') AS cancelled
     FROM hearings"
);

if ($statsRow = $statsStmt->fetch()) {
    foreach ($stats as $key => $value) {
        $stats[$key] = (int)($statsRow[$key] ?? 0);
    }
}

include __DIR__ . '/../../layouts/header.php';
?>
<link rel="stylesheet" href="<?= e(APP_URL . '/assets/css/hearings-complete.css') ?>">

<div class="app-wrapper">
<?php include __DIR__ . '/../../layouts/sidebar.php'; ?>

<div class="main-content hearing-module">

    <div class="hearing-page-head">
        <div>
            <div class="hearing-eyebrow">
                <i class="bi bi-calendar-event"></i>
                Public Hearing and Consultation Management
            </div>

            <h1>Hearing Scheduling</h1>

            <p>
                Create, schedule, link, monitor, and manage public hearings
                and consultation sessions with committee, registration,
                document, visibility, and calendar controls.
            </p>
        </div>

        <div class="d-flex gap-2 flex-wrap no-print">
            <a href="calendar.php" class="btn btn-outline-secondary">
                <i class="bi bi-calendar3"></i> Calendar
            </a>

            <a href="print.php" target="_blank" id="printScheduleLink" class="btn btn-outline-secondary">
                <i class="bi bi-printer"></i> Print
            </a>

            <?php if (canManage()): ?>
                <button type="button" class="btn btn-primary" id="btnAddHearing">
                    <i class="bi bi-plus-circle"></i> Create Hearing
                </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <?php
        $cards = [
            ['Total Hearings', $stats['total'], 'bi-calendar3', ''],
            ['Upcoming', $stats['upcoming'], 'bi-calendar-event', 'primary'],
            ['Ongoing', $stats['ongoing'], 'bi-broadcast', 'warning'],
            ['Completed', $stats['completed'], 'bi-check-circle', 'success'],
            ['Cancelled', $stats['cancelled'], 'bi-x-circle', 'danger'],
        ];
        ?>

        <?php foreach ($cards as [$label, $value, $icon, $tone]): ?>
            <div class="col-6 col-md hearing-stat-col">
                <div class="hearing-stat-card <?= e($tone) ?>">
                    <span><i class="bi <?= e($icon) ?>"></i></span>
                    <div>
                        <strong><?= (int)$value ?></strong>
                        <small><?= e($label) ?></small>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card mb-3 no-print hearing-filter-card">
        <div class="card-body">
            <form id="filterForm" class="row g-2 align-items-end">
                <div class="col-xl-3 col-lg-4">
                    <label class="form-label small">Search</label>
                    <input
                        type="text"
                        class="form-control form-control-sm"
                        name="search"
                        id="searchInput"
                        placeholder="Reference, title, venue, legislative item..."
                    >
                </div>

                <div class="col-xl-2 col-lg-4">
                    <label class="form-label small">Status</label>
                    <select class="form-select form-select-sm" name="status">
                        <option value="">All Statuses</option>
                        <?php foreach (hearingAllowedStatuses() as $status): ?>
                            <option value="<?= e($status) ?>"><?= e($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-xl-2 col-lg-4">
                    <label class="form-label small">Hearing Type</label>
                    <select class="form-select form-select-sm" name="hearing_type_id">
                        <option value="">All Types</option>
                        <?php foreach ($hearingTypes as $type): ?>
                            <option value="<?= (int)$type['id'] ?>"><?= e($type['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-xl-2 col-lg-4">
                    <label class="form-label small">Committee</label>
                    <select class="form-select form-select-sm" name="committee_id">
                        <option value="">All Committees</option>
                        <?php foreach ($committees as $committee): ?>
                            <option value="<?= (int)$committee['id'] ?>"><?= e($committee['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-xl-1 col-lg-4">
                    <label class="form-label small">Item Type</label>
                    <select class="form-select form-select-sm" name="legislative_type">
                        <option value="">All</option>
                        <option value="ordinance">Ordinance</option>
                        <option value="resolution">Resolution</option>
                        <option value="proposal">Proposal</option>
                        <option value="petition">Petition</option>
                        <option value="policy_matter">Policy</option>
                        <option value="public_issue">Public Issue</option>
                    </select>
                </div>

                <div class="col-xl-1 col-lg-4">
                    <label class="form-label small">From</label>
                    <input type="date" class="form-control form-control-sm" name="date_from">
                </div>

                <div class="col-xl-1 col-lg-4">
                    <label class="form-label small">To</label>
                    <input type="date" class="form-control form-control-sm" name="date_to">
                </div>
            </form>
        </div>
    </div>

    <div class="card hearing-list-card">
        <div id="hearingsTableWrap">
            <?php include __DIR__ . '/table.php'; ?>
        </div>
    </div>
</div>
</div>

<?php if (canManage()): ?>
<div class="modal fade" id="hearingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form id="hearingForm" enctype="multipart/form-data">
                <?= csrfField() ?>

                <input type="hidden" name="id" id="hearing_id" value="0">

                <div class="modal-header">
                    <div>
                        <div class="small text-warning fw-semibold">HEARING SCHEDULING</div>
                        <h5 class="modal-title" id="hearingModalTitle">
                            <i class="bi bi-calendar-plus"></i> Create Hearing
                        </h5>
                    </div>

                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="hearing-form-section">
                        <h6><span>1</span> Legislative and Hearing Information</h6>

                        <div class="row g-3">
                            <div class="col-lg-7">
                                <label class="form-label">
                                    Hearing Title <span class="text-danger">*</span>
                                </label>
                                <input type="text" name="title" id="f_title" class="form-control" maxlength="255" required>
                            </div>

                            <div class="col-lg-5">
                                <label class="form-label">Related Legislative Item</label>
                                <select name="legislative_item_id" id="f_legislative_item" class="form-select">
                                    <option value="">-- Not linked to a legislative item --</option>
                                    <?php foreach ($legislativeItems as $item): ?>
                                        <option value="<?= (int)$item['id'] ?>">
                                            <?= e('[' . $item['type_name'] . '] ' . $item['reference_number'] . ' - ' . $item['title']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Hearing Type</label>
                                <select name="hearing_type_id" id="f_type" class="form-select">
                                    <option value="">-- Select Type --</option>
                                    <?php foreach ($hearingTypes as $type): ?>
                                        <option value="<?= (int)$type['id'] ?>"><?= e($type['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Responsible Committee</label>
                                <select name="committee_id" id="f_committee" class="form-select">
                                    <option value="">-- Select Committee --</option>
                                    <?php foreach ($committees as $committee): ?>
                                        <option value="<?= (int)$committee['id'] ?>"><?= e($committee['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="hearing-form-section">
                        <h6><span>2</span> Schedule and Venue</h6>

                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Start Date <span class="text-danger">*</span></label>
                                <input type="date" name="hearing_date" id="f_date" class="form-control" required>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Start Time <span class="text-danger">*</span></label>
                                <input type="time" name="hearing_time" id="f_time" class="form-control" required>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">End Date</label>
                                <input type="date" name="end_date" id="f_end_date" class="form-control">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">End Time</label>
                                <input type="time" name="end_time" id="f_end_time" class="form-control">
                            </div>

                            <div class="col-lg-7">
                                <label class="form-label">Physical Venue</label>
                                <input
                                    type="text"
                                    name="venue"
                                    id="f_venue"
                                    class="form-control"
                                    maxlength="255"
                                    placeholder="Example: Session Hall, Manila City Hall"
                                >
                            </div>

                            <div class="col-lg-5">
                                <label class="form-label">Online Meeting Link</label>
                                <input
                                    type="url"
                                    name="meeting_link"
                                    id="f_meeting_link"
                                    class="form-control"
                                    maxlength="500"
                                    placeholder="https://..."
                                >
                            </div>
                        </div>

                        <div class="hearing-conflict-note">
                            <i class="bi bi-shield-check"></i>
                            <div>
                                <strong>Schedule conflict protection</strong>
                                <span>Saving is blocked when another active hearing overlaps using the same committee or physical venue.</span>
                            </div>
                        </div>
                    </div>

                    <div class="hearing-form-section">
                        <h6><span>3</span> Registration and Access</h6>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Registration Deadline</label>
                                <input type="datetime-local" name="registration_deadline" id="f_registration_deadline" class="form-control">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Maximum Participants</label>
                                <input
                                    type="number"
                                    name="maximum_participants"
                                    id="f_maximum_participants"
                                    min="1"
                                    max="100000"
                                    class="form-control"
                                    placeholder="No limit"
                                >
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Visibility</label>
                                <select name="visibility" id="f_visibility" class="form-select">
                                    <option value="Public">Public</option>
                                    <option value="Internal">Internal</option>
                                    <option value="Restricted">Restricted</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="hearing-form-section">
                        <h6><span>4</span> Status, Description and Documents</h6>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Status</label>
                                <select name="status" id="f_status" class="form-select">
                                    <?php foreach (hearingAllowedStatuses() as $status): ?>
                                        <option value="<?= e($status) ?>"><?= e($status) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-8" id="cancellationReasonWrap" hidden>
                                <label class="form-label">
                                    Cancellation Reason <span class="text-danger">*</span>
                                </label>
                                <input
                                    type="text"
                                    name="cancellation_reason"
                                    id="f_cancellation_reason"
                                    class="form-control"
                                    maxlength="1000"
                                >
                            </div>

                            <div class="col-12">
                                <label class="form-label">Description / Consultation Purpose</label>
                                <textarea name="description" id="f_description" class="form-control" rows="4"></textarea>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Attach Supporting Documents</label>
                                <input
                                    type="file"
                                    name="documents[]"
                                    id="f_documents"
                                    class="form-control"
                                    multiple
                                    accept=".pdf,.doc,.docx,.png,.jpg,.jpeg"
                                >
                                <div class="form-text">
                                    Additional documents can also be uploaded later from the hearing detail page.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btnSaveHearing">
                        <i class="bi bi-check-circle"></i> Save Hearing
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$extraJs = [APP_URL . '/assets/js/hearings.js'];
include __DIR__ . '/../../layouts/footer.php';
?>
