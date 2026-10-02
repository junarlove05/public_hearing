<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
if(!canManage())redirect(APP_URL.'/dashboard.php');

$pdo=db();$pageTitle='Feedback to Issue Handoff';$activeMenu='issues';
$categories=$pdo->query('SELECT id,name FROM hearing_issue_categories ORDER BY name')->fetchAll();
$offices=$pdo->query("SELECT id,name FROM offices WHERE status='Active' ORDER BY name")->fetchAll();

$rows=$pdo->query(
 "SELECT f.id,f.name,f.subject,f.message,f.feedback_position,f.status,f.submitted_at,
         h.reference_number hearing_reference,h.title hearing_title,
         i.id existing_issue_id,i.reference_number issue_reference
  FROM feedback f
  LEFT JOIN hearings h ON h.id=f.hearing_id
  LEFT JOIN hearing_issues i ON i.feedback_id=f.id
  WHERE f.status NOT IN ('Rejected','Archived')
  ORDER BY f.submitted_at DESC"
)->fetchAll();

include __DIR__.'/../../layouts/header.php';
?>
<link rel="stylesheet" href="<?= e(APP_URL.'/assets/css/lph-workflow-final.css') ?>">
<div class="app-wrapper"><?php include __DIR__.'/../../layouts/sidebar.php'; ?><div class="main-content">
<div class="lphwf-head"><div><div class="lphwf-eyebrow">Step 6 · Workflow Handoff</div><h1>Feedback → Issue Handoff</h1><p>Convert validated or actionable public input into a traceable issue while preserving the original feedback, hearing and legislative-item context.</p></div><a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Issue Log</a></div>

<div class="card lphwf-card"><div class="card-header"><i class="bi bi-arrow-left-right"></i> Actionable Public Feedback</div><div class="table-responsive">
<table class="table table-hover lphwf-table mb-0"><thead><tr><th>Feedback</th><th>Citizen / Position</th><th>Hearing</th><th>Status</th><th class="text-end">Handoff</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr>
<td><strong><?= e($r['subject']?:'Public Feedback #'.$r['id']) ?></strong><div class="small text-muted"><?= e(mb_strimwidth($r['message'],0,150,'…')) ?></div></td>
<td><?= e($r['name']) ?><div class="small text-muted"><?= e($r['feedback_position']?:'Comment') ?></div></td>
<td><?= e(($r['hearing_reference']?:'').' '.($r['hearing_title']?:'General feedback')) ?></td>
<td><span class="badge text-bg-light"><?= e($r['status']) ?></span></td>
<td class="text-end"><?php if($r['existing_issue_id']): ?><a class="btn btn-sm btn-outline-success" href="workflow.php?id=<?= (int)$r['existing_issue_id'] ?>"><i class="bi bi-check-circle"></i> <?= e($r['issue_reference']) ?></a><?php else: ?><button class="btn btn-sm btn-primary btn-convert" data-row='<?= e(json_encode($r,JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'><i class="bi bi-arrow-right-circle"></i> Create Issue</button><?php endif; ?></td>
</tr><?php endforeach; ?>
</tbody></table></div></div>
</div></div><div class="modal fade" id="convertModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: 820px;">
    <div class="modal-content border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
      <form id="convertForm"><?= csrfField() ?>
        <input type="hidden" name="feedback_id" id="cf_feedback">
        <div class="modal-header py-3 px-4 bg-light border-bottom">
          <div class="d-flex align-items-center gap-2.5">
            <div class="rounded-circle d-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary" style="width: 36px; height: 36px;">
              <i class="bi bi-arrow-right-circle fs-5"></i>
            </div>
            <div>
              <h5 class="modal-title fw-bold text-dark mb-0" style="font-size: 1.05rem;">Create Issue from Feedback</h5>
              <small class="text-muted" style="font-size: 0.8rem;">Escalate public feedback into a formal tracked issue</small>
            </div>
          </div>
          <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4">
          <div id="cf_source" class="lphwf-message p-3 bg-light rounded mb-3 small border"></div>
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label small fw-semibold text-secondary mb-1">Issue Title *</label>
              <input class="form-control" name="title" id="cf_title" required>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold text-secondary mb-1">Priority</label>
              <select class="form-select" name="priority">
                <option>Low</option>
                <option selected>Medium</option>
                <option>High</option>
                <option>Critical</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Category</label>
              <select class="form-select" name="category_id">
                <option value="">Uncategorized</option>
                <?php foreach($categories as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Assigned Office</label>
              <select class="form-select" name="assigned_office_id">
                <option value="">Unassigned</option>
                <?php foreach($offices as $o): ?>
                  <option value="<?= (int)$o['id'] ?>"><?= e($o['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Due Date</label>
              <input type="datetime-local" class="form-control" name="due_at">
            </div>
          </div>
        </div>
        <div class="modal-footer py-2.5 px-4 bg-light border-top d-flex justify-content-end gap-2">
          <button class="btn btn-light border px-3" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary px-4 fw-semibold shadow-sm" type="submit"><i class="bi bi-arrow-right-circle me-1"></i> Create Issue</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded',function(){
 const modal=new bootstrap.Modal(document.getElementById('convertModal'));
 document.querySelectorAll('.btn-convert').forEach(b=>b.onclick=function(){const r=JSON.parse(this.dataset.row);cf_feedback.value=r.id;cf_title.value=r.subject||('Public Feedback #'+r.id);cf_source.textContent=r.message;modal.show();});
 convertForm.onsubmit=async e=>{e.preventDefault();const r=await appPostForm(APP_URL+'/modules/issues/ajax_from_feedback.php',convertForm);if(r.success){appToast('success',r.message);setTimeout(()=>location.href='workflow.php?id='+r.issue_id,400);}else if(!r.session_expired)Swal.fire('Handoff Error',r.message,'error');};
});
</script>
<?php include __DIR__.'/../../layouts/footer.php'; ?>
