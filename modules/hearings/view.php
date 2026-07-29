<?php
/**
 * modules/hearings/view.php
 * ------------------------------------------------------------------
 * Full detail view for a single hearing: info, committee/type,
 * uploaded documents (download + delete), registration count, and
 * any issues logged against this hearing.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
$pdo = db();

$stmt = $pdo->prepare(
    'SELECT h.*, ht.name AS type_name, c.name AS committee_name, c.description AS committee_description
     FROM hearings h
     LEFT JOIN hearing_types ht ON ht.id = h.hearing_type_id
     LEFT JOIN committees c ON c.id = h.committee_id
     WHERE h.id = :id'
);
$stmt->execute([':id' => $id]);
$hearing = $stmt->fetch();

if (!$hearing) {
    setFlash('danger', 'Hearing not found.');
    redirect(APP_URL . '/modules/hearings/index.php');
}

$documents = $pdo->prepare('SELECT * FROM hearing_documents WHERE hearing_id = :id ORDER BY uploaded_at DESC');
$documents->execute([':id' => $id]);
$documents = $documents->fetchAll();

$regStmt = $pdo->prepare(
    "SELECT r.registered_at, s.full_name, s.organization, s.email
     FROM registrations r
     JOIN stakeholders s ON s.id = r.stakeholder_id
     WHERE r.hearing_id = :id ORDER BY r.registered_at DESC LIMIT 10"
);
$regStmt->execute([':id' => $id]);
$registrations = $regStmt->fetchAll();

$regCountStmt = $pdo->prepare('SELECT COUNT(*) FROM registrations WHERE hearing_id = :id');
$regCountStmt->execute([':id' => $id]);
$regCount = (int)$regCountStmt->fetchColumn();

$issuesStmt = $pdo->prepare(
    'SELECT id, title, status, priority
     FROM hearing_issues
     WHERE hearing_id = :id
     ORDER BY created_at DESC
     LIMIT 10'
);
$issuesStmt->execute([':id' => $id]);
$issues = $issuesStmt->fetchAll();

$pageTitle  = $hearing['title'];
$activeMenu = 'hearings';

$docIcon = function (string $path): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return match ($ext) {
        'pdf' => 'bi-file-earmark-pdf text-danger',
        'doc', 'docx' => 'bi-file-earmark-word text-primary',
        'png', 'jpg', 'jpeg' => 'bi-file-earmark-image text-success',
        'xls', 'xlsx' => 'bi-file-earmark-excel text-success',
        'zip', 'rar' => 'bi-file-earmark-zip text-warning',
        default => 'bi-file-earmark',
    };
};

include __DIR__ . '/../../layouts/header.php';
?>
<style>
    /* ============================================================
       HEARING DETAIL VIEW - Coastal Blue Professional
       ============================================================ */
    .hearing-detail .breadcrumb-bar {
        background: #ffffff;
        border-left: 4px solid #F5C842;
        box-shadow: 0 2px 15px rgba(10, 22, 40, 0.06);
        padding: 1.25rem 1.75rem;
        border-radius: 16px;
        margin-bottom: 1.5rem;
        position: relative;
        overflow: hidden;
    }

    .hearing-detail .breadcrumb-bar::after {
        content: '';
        position: absolute;
        top: 0;
        right: 0;
        width: 200px;
        height: 100%;
        background: linear-gradient(135deg, transparent 0%, rgba(245, 200, 66, 0.05) 100%);
        pointer-events: none;
    }

    .hearing-detail .breadcrumb-bar .back-link {
        color: #64748B;
        font-size: 0.85rem;
        text-decoration: none;
        transition: color 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
    }

    .hearing-detail .breadcrumb-bar .back-link:hover {
        color: #F5C842;
    }

    .hearing-detail .breadcrumb-bar h5 {
        color: #0F2137;
        font-weight: 800;
        font-size: 1.1rem;
        letter-spacing: -0.3px;
        margin-bottom: 0;
    }

    .hearing-detail .breadcrumb-bar h5 i {
        color: #F5C842;
        background: rgba(245, 200, 66, 0.1);
        padding: 0.4rem;
        border-radius: 10px;
        margin-right: 0.5rem;
    }

    /* Cards */
    .hearing-detail .card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 2px 20px rgba(10, 22, 40, 0.05);
        background: #ffffff;
        transition: all 0.3s ease;
        border: 1px solid rgba(10, 22, 40, 0.04);
        overflow: hidden;
    }

    .hearing-detail .card:hover {
        box-shadow: 0 4px 30px rgba(10, 22, 40, 0.08);
        transform: translateY(-2px);
        border-color: rgba(245, 200, 66, 0.08);
    }

    .hearing-detail .card-header {
        background: linear-gradient(135deg, #0A1628 0%, #1A3A5C 100%);
        color: #ffffff;
        font-weight: 600;
        padding: 0.75rem 1.25rem;
        border-bottom: 3px solid #F5C842;
        border-radius: 16px 16px 0 0 !important;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .hearing-detail .card-header i {
        color: #F5C842;
        margin-right: 0.5rem;
    }

    .hearing-detail .card-header .badge {
        background: rgba(255, 255, 255, 0.15);
        color: #ffffff;
        font-weight: 600;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .hearing-detail .card-body {
        padding: 1.25rem;
        background: #ffffff;
    }

    /* Details Table */
    .hearing-detail .detail-table {
        width: 100%;
        border-collapse: collapse;
    }

    .hearing-detail .detail-table tr {
        border-bottom: 1px solid #F1F5F9;
    }

    .hearing-detail .detail-table tr:last-child {
        border-bottom: none;
    }

    .hearing-detail .detail-table th {
        color: #64748B;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 0.6rem 0.25rem 0.6rem 0;
        width: 30%;
        text-align: left;
    }

    .hearing-detail .detail-table td {
        color: #0F2137;
        font-size: 0.9rem;
        padding: 0.6rem 0.25rem;
        font-weight: 500;
    }

    .hearing-detail .detail-table td .description-text {
        color: #475569;
        font-weight: 400;
        line-height: 1.6;
    }

    /* Document List */
    .hearing-detail .document-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.75rem 1.25rem;
        border-bottom: 1px solid #F1F5F9;
        transition: background 0.2s ease;
    }

    .hearing-detail .document-item:hover {
        background: #F8F5FF;
    }

    .hearing-detail .document-item:last-child {
        border-bottom: none;
    }

    .hearing-detail .document-item .doc-info {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .hearing-detail .document-item .doc-info .doc-icon {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(10, 22, 40, 0.04);
        font-size: 1.1rem;
    }

    .hearing-detail .document-item .doc-info .doc-name {
        font-weight: 500;
        color: #0F2137;
        font-size: 0.9rem;
    }

    .hearing-detail .document-item .doc-info .doc-meta {
        color: #94A3B8;
        font-size: 0.7rem;
    }

    .hearing-detail .document-item .doc-info .doc-meta i {
        color: #F5C842;
        margin-right: 0.2rem;
    }

    /* Action Buttons */
    .hearing-detail .btn-action {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        border: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s ease;
        font-size: 0.85rem;
        color: #64748B;
        background: transparent;
        border: 1px solid #E2E8F0;
    }

    .hearing-detail .btn-action:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    }

    .hearing-detail .btn-action.download:hover {
        background: #2C5282;
        color: white;
        border-color: #2C5282;
    }

    .hearing-detail .btn-action.delete:hover {
        background: #F43F5E;
        color: white;
        border-color: #F43F5E;
    }

    .hearing-detail .btn-group {
        gap: 0.25rem;
    }

    /* Issue Items */
    .hearing-detail .issue-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.75rem 1.25rem;
        border-bottom: 1px solid #F1F5F9;
        transition: background 0.2s ease;
    }

    .hearing-detail .issue-item:hover {
        background: #F8F5FF;
    }

    .hearing-detail .issue-item:last-child {
        border-bottom: none;
    }

    .hearing-detail .issue-item .issue-title {
        color: #0F2137;
        font-weight: 500;
        font-size: 0.9rem;
        text-decoration: none;
        transition: color 0.3s ease;
    }

    .hearing-detail .issue-item .issue-title:hover {
        color: #F5C842;
    }

    .hearing-detail .issue-item .issue-badges {
        display: flex;
        gap: 0.5rem;
    }

    /* Registration Stats */
    .hearing-detail .reg-stats {
        text-align: center;
        padding: 0.5rem 0;
    }

    .hearing-detail .reg-stats .reg-number {
        font-size: 2.5rem;
        font-weight: 800;
        color: #0F2137;
        line-height: 1;
    }

    .hearing-detail .reg-stats .reg-number i {
        color: #F5C842;
        font-size: 2rem;
        margin-right: 0.3rem;
    }

    .hearing-detail .reg-stats .reg-label {
        color: #94A3B8;
        font-size: 0.8rem;
        margin-top: 0.25rem;
    }

    .hearing-detail .reg-list {
        margin-top: 0.75rem;
    }

    .hearing-detail .reg-list .reg-item {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.5rem 0;
        border-bottom: 1px solid #F1F5F9;
    }

    .hearing-detail .reg-list .reg-item:last-child {
        border-bottom: none;
    }

    .hearing-detail .reg-list .reg-item .reg-avatar {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.7rem;
        color: #ffffff;
        flex-shrink: 0;
    }

    .hearing-detail .reg-list .reg-item .reg-name {
        font-weight: 500;
        color: #0F2137;
        font-size: 0.85rem;
    }

    .hearing-detail .reg-list .reg-item .reg-org {
        color: #94A3B8;
        font-size: 0.7rem;
    }

    /* Committee Info */
    .hearing-detail .committee-info {
        padding: 0.25rem 0;
    }

    .hearing-detail .committee-info .committee-name {
        font-weight: 600;
        color: #0F2137;
        font-size: 0.9rem;
        margin-bottom: 0.25rem;
    }

    .hearing-detail .committee-info .committee-desc {
        color: #64748B;
        font-size: 0.85rem;
        line-height: 1.6;
    }

    /* Status Badge in header */
    .hearing-detail .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.3rem 0.8rem;
        border-radius: 20px;
        font-weight: 600;
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        border: none;
    }

    .status-badge.upcoming { background: #DBEAFE; color: #1E40AF; }
    .status-badge.ongoing { background: #D1FAE5; color: #065F46; }
    .status-badge.completed { background: #F1F5F9; color: #475569; }
    .status-badge.cancelled { background: #FEE2E2; color: #991B1B; }

    .status-badge i { font-size: 0.7rem; margin-right: 0.2rem; }

    /* Empty State */
    .hearing-detail .empty-state {
        text-align: center;
        padding: 2rem 1rem;
        color: #94A3B8;
    }

    .hearing-detail .empty-state i {
        font-size: 2rem;
        color: #F5C842;
        opacity: 0.3;
        display: block;
        margin-bottom: 0.5rem;
    }

    .hearing-detail .empty-state p {
        font-size: 0.85rem;
        margin: 0;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .hearing-detail .breadcrumb-bar {
            padding: 1rem 1.25rem;
        }

        .hearing-detail .breadcrumb-bar h5 {
            font-size: 0.95rem;
        }

        .hearing-detail .detail-table th {
            font-size: 0.65rem;
        }

        .hearing-detail .detail-table td {
            font-size: 0.8rem;
        }

        .hearing-detail .card-body {
            padding: 1rem;
        }

        .hearing-detail .document-item {
            padding: 0.6rem 0.8rem;
        }

        .hearing-detail .document-item .doc-info .doc-name {
            font-size: 0.8rem;
        }

        .hearing-detail .reg-stats .reg-number {
            font-size: 2rem;
        }
    }

    @media (max-width: 576px) {
        .hearing-detail .breadcrumb-bar {
            padding: 0.75rem 1rem;
        }

        .hearing-detail .breadcrumb-bar h5 {
            font-size: 0.85rem;
        }

        .hearing-detail .detail-table th,
        .hearing-detail .detail-table td {
            display: block;
            width: 100%;
            padding: 0.3rem 0;
        }

        .hearing-detail .detail-table th {
            padding-top: 0.6rem;
            font-size: 0.6rem;
        }

        .hearing-detail .detail-table td {
            padding-bottom: 0.6rem;
            font-size: 0.8rem;
        }

        .hearing-detail .card-body {
            padding: 0.75rem;
        }

        .hearing-detail .document-item {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.5rem;
            padding: 0.6rem 0.8rem;
        }

        .hearing-detail .document-item .doc-info .doc-name {
            font-size: 0.75rem;
        }

        .hearing-detail .btn-action {
            width: 28px;
            height: 28px;
            font-size: 0.7rem;
        }

        .hearing-detail .reg-stats .reg-number {
            font-size: 1.6rem;
        }

        .hearing-detail .reg-list .reg-item .reg-name {
            font-size: 0.75rem;
        }

        .hearing-detail .reg-list .reg-item .reg-org {
            font-size: 0.6rem;
        }

        .hearing-detail .issue-item {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.5rem;
            padding: 0.6rem 0.8rem;
        }

        .hearing-detail .issue-item .issue-title {
            font-size: 0.8rem;
        }
    }
</style>

<div class="app-wrapper hearing-detail">
  <?php include __DIR__ . '/../../layouts/sidebar.php'; ?>

  <div class="main-content">
    <!-- ===== BREADCRUMB BAR ===== -->
    <div class="breadcrumb-bar d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <a href="index.php" class="back-link"><i class="bi bi-arrow-left"></i> Back to Hearing Schedule</a>
        <h5 class="mb-0 mt-1"><i class="bi bi-calendar-event"></i> <?= e($hearing['title']) ?></h5>
      </div>
      <div class="no-print">
        <span class="status-badge <?= strtolower($hearing['status']) ?>">
          <i class="bi <?= match($hearing['status']) {
            'Upcoming' => 'bi-calendar-event',
            'Ongoing' => 'bi-play-circle',
            'Completed' => 'bi-check-circle',
            'Cancelled' => 'bi-x-circle',
            default => 'bi-circle'
          } ?>"></i>
          <?= e($hearing['status']) ?>
        </span>
      </div>
    </div>

    <div class="row g-3">
      <!-- ===== LEFT COLUMN ===== -->
      <div class="col-lg-8">
        <!-- Hearing Details -->
        <div class="card mb-3">
          <div class="card-header">
            <span><i class="bi bi-info-circle"></i> Hearing Details</span>
          </div>
          <div class="card-body">
            <table class="detail-table">
              <tr>
                <th>Type</th>
                <td><?= e($hearing['type_name'] ?? '-') ?></td>
              </tr>
              <tr>
                <th>Committee</th>
                <td><?= e($hearing['committee_name'] ?? '-') ?></td>
              </tr>
              <tr>
                <th>Date &amp; Time</th>
                <td><i class="bi bi-calendar3" style="color: #F5C842; margin-right: 0.3rem;"></i> <?= formatDate($hearing['hearing_date']) ?> at <i class="bi bi-clock" style="color: #F5C842; margin-left: 0.5rem; margin-right: 0.3rem;"></i> <?= formatTime($hearing['hearing_time']) ?></td>
              </tr>
              <tr>
                <th>Venue</th>
                <td><?php if ($hearing['venue']): ?><i class="bi bi-geo-alt" style="color: #F5C842; margin-right: 0.3rem;"></i> <?= e($hearing['venue']) ?><?php else: ?>—<?php endif; ?></td>
              </tr>
              <tr>
                <th>Status</th>
                <td>
                  <span class="status-badge <?= strtolower($hearing['status']) ?>">
                    <i class="bi <?= match($hearing['status']) {
                      'Upcoming' => 'bi-calendar-event',
                      'Ongoing' => 'bi-play-circle',
                      'Completed' => 'bi-check-circle',
                      'Cancelled' => 'bi-x-circle',
                      default => 'bi-circle'
                    } ?>"></i>
                    <?= e($hearing['status']) ?>
                  </span>
                </td>
              </tr>
              <tr>
                <th>Description</th>
                <td><span class="description-text"><?= nl2br(e($hearing['description'] ?: '-')) ?></span></td>
              </tr>
            </table>
          </div>
        </div>

        <!-- Documents -->
        <div class="card mb-3">
          <div class="card-header">
            <span><i class="bi bi-file-earmark"></i> Hearing Documents</span>
            <span class="badge"><?= count($documents) ?></span>
          </div>
          <div class="card-body" style="padding: 0;">
            <?php if (empty($documents)): ?>
              <div class="empty-state">
                <i class="bi bi-file-earmark"></i>
                <p>No documents uploaded for this hearing.</p>
              </div>
            <?php endif; ?>
            <?php foreach ($documents as $doc): ?>
              <div class="document-item">
                <div class="doc-info">
                  <div class="doc-icon">
                    <i class="bi <?= $docIcon($doc['file_path']) ?>"></i>
                  </div>
                  <div>
                    <div class="doc-name"><?= e($doc['file_name']) ?></div>
                    <div class="doc-meta">
                      <i class="bi bi-clock"></i> Uploaded <?= formatDateTime($doc['uploaded_at']) ?>
                    </div>
                  </div>
                </div>
                <div class="btn-group no-print">
                  <a href="<?= e(UPLOAD_URL . $doc['file_path']) ?>" target="_blank" class="btn-action download" title="Download">
                    <i class="bi bi-download"></i>
                  </a>
                  <?php if (canManage()): ?>
                  <button type="button" class="btn-action delete" title="Delete"
                          data-confirm-delete="document &quot;<?= e($doc['file_name']) ?>&quot;"
                          data-delete-url="<?= e(APP_URL) ?>/modules/hearings/document_delete.php?id=<?= (int)$doc['id'] ?>">
                    <i class="bi bi-trash"></i>
                  </button>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Issues -->
        <div class="card">
          <div class="card-header">
            <span><i class="bi bi-exclamation-triangle"></i> Related Issues Logged</span>
            <span class="badge"><?= count($issues) ?></span>
          </div>
          <div class="card-body" style="padding: 0;">
            <?php if (empty($issues)): ?>
              <div class="empty-state">
                <i class="bi bi-check-circle"></i>
                <p>No issues have been logged for this hearing.</p>
              </div>
            <?php endif; ?>
            <?php foreach ($issues as $iss): ?>
              <div class="issue-item">
                <a href="<?= e(APP_URL) ?>/modules/issues/view.php?id=<?= (int)$iss['id'] ?>" class="issue-title">
                  <i class="bi bi-megaphone" style="color: #F5C842; margin-right: 0.3rem;"></i>
                  <?= e($iss['title']) ?>
                </a>
                <div class="issue-badges">
                  <?= priorityBadge($iss['priority']) ?>
                  <?= statusBadge($iss['status']) ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- ===== RIGHT COLUMN ===== -->
      <div class="col-lg-4">
        <!-- Registrations -->
        <div class="card mb-3">
          <div class="card-header">
            <span><i class="bi bi-people"></i> Registrations</span>
          </div>
          <div class="card-body">
            <div class="reg-stats">
              <div class="reg-number">
                <i class="bi bi-people"></i> <?= $regCount ?>
              </div>
              <div class="reg-label">stakeholders registered</div>
            </div>
            <div class="reg-list">
              <?php if (empty($registrations)): ?>
                <div class="text-center text-muted small py-2">No registrations yet.</div>
              <?php endif; ?>
              <?php foreach ($registrations as $r): 
                $initial = strtoupper(substr($r['full_name'], 0, 1));
                $colors = ['#4A7EB5', '#F5C842', '#10B981', '#8B5CF6', '#F43F5E', '#06B6D4'];
                $color = $colors[abs(crc32($r['full_name'])) % count($colors)];
              ?>
                <div class="reg-item">
                  <div class="reg-avatar" style="background: <?= e($color) ?>;">
                    <?= e($initial) ?>
                  </div>
                  <div>
                    <div class="reg-name"><?= e($r['full_name']) ?></div>
                    <div class="reg-org"><?= e($r['organization'] ?: $r['email']) ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- Committee Description -->
        <?php if (!empty($hearing['committee_description'])): ?>
        <div class="card">
          <div class="card-header">
            <span><i class="bi bi-building"></i> About the Committee</span>
          </div>
          <div class="card-body">
            <div class="committee-info">
              <div class="committee-name">
                <i class="bi bi-building" style="color: #F5C842; margin-right: 0.3rem;"></i>
                <?= e($hearing['committee_name'] ?? 'Committee') ?>
              </div>
              <div class="committee-desc">
                <?= nl2br(e($hearing['committee_description'])) ?>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../layouts/footer.php'; ?>