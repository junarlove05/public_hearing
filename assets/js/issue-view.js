/**
 * assets/js/issue-view.js
 * ------------------------------------------------------------------
 * Powers modules/issues/view.php:
 * - Assign/Reassign form (Admin)
 * - Unified Status & Progress Note update (Single Save)
 * - Full Update Issue Modal form
 * ------------------------------------------------------------------
 */

(function () {
  const assignForm = document.getElementById('assignForm');
  const unifiedUpdateForm = document.getElementById('unifiedUpdateForm');
  const unifiedStatusSelect = document.getElementById('unifiedStatusSelect');
  const unifiedNoteLabel = document.getElementById('unifiedNoteLabel');
  const unifiedNoteInput = document.getElementById('unifiedNoteInput');
  const updateIssueForm = document.getElementById('updateIssueForm');
  const modalStatusSelect = document.getElementById('modalStatusSelect');
  const modalResolutionWrap = document.getElementById('modalResolutionWrap');

  // Assign Form (Admin)
  if (assignForm) {
    assignForm.addEventListener('submit', function (e) {
      e.preventDefault();
      appPost(window.APP_URL + '/modules/issues/ajax_assign.php', Object.fromEntries(new FormData(assignForm)))
        .then(data => {
          if (data.success) {
            appToast('success', data.message || 'Assignment updated successfully.');
            setTimeout(() => {
              const url = new URL(window.location.href);
              url.searchParams.set('_t', Date.now());
              window.location.href = url.toString();
            }, 500);
          } else if (!data.session_expired) {
            Swal.fire('Error', data.message || 'Failed to save assignment.', 'error');
          }
        });
    });
  }

  // Dynamic help text for Status & Note
  if (unifiedStatusSelect && unifiedNoteLabel && unifiedNoteInput) {
    unifiedStatusSelect.addEventListener('change', function () {
      const val = this.value;
      if (val === 'Resolved' || val === 'Closed') {
        unifiedNoteLabel.innerHTML = '<i class="bi bi-check-circle-fill text-success me-1"></i> Resolution Summary / Final Notes';
        unifiedNoteInput.setAttribute('placeholder', 'Describe how this issue was addressed or resolved...');
      } else {
        unifiedNoteLabel.innerHTML = '<i class="bi bi-chat-left-text text-primary me-1"></i> Progress Note / Status Remarks';
        unifiedNoteInput.setAttribute('placeholder', 'Enter progress updates, actions taken, or remarks...');
      }
    });
  }

  // Unified Status & Note Form (Single Save)
  if (unifiedUpdateForm) {
    unifiedUpdateForm.addEventListener('submit', function (e) {
      e.preventDefault();
      const btn = document.getElementById('btnSaveUnified');
      const origHtml = btn ? btn.innerHTML : '';
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';
      }

      appPost(window.APP_URL + '/modules/issues/ajax_transition.php', Object.fromEntries(new FormData(unifiedUpdateForm)))
        .then(data => {
          if (data.success) {
            appToast('success', data.message || 'Issue updated successfully.');
            // Cache-busting reload ensures the new status immediately renders on screen
            setTimeout(() => {
              const url = new URL(window.location.href);
              url.searchParams.set('_t', Date.now());
              window.location.href = url.toString();
            }, 500);
          } else {
            if (btn) {
              btn.disabled = false;
              btn.innerHTML = origHtml;
            }
            if (!data.session_expired) {
              Swal.fire('Notice', data.message || 'Failed to update issue.', 'warning');
            }
          }
        })
        .catch(err => {
          if (btn) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
          }
          console.error(err);
        });
    });
  }

  // Full Update Issue Modal
  if (modalStatusSelect && modalResolutionWrap) {
    modalStatusSelect.addEventListener('change', function () {
      const val = this.value;
      if (val === 'Resolved' || val === 'Closed') {
        modalResolutionWrap.style.display = '';
      } else {
        modalResolutionWrap.style.display = 'none';
      }
    });
  }

  if (updateIssueForm) {
    updateIssueForm.addEventListener('submit', function (e) {
      e.preventDefault();
      appPost(window.APP_URL + '/modules/issues/ajax_save.php', Object.fromEntries(new FormData(updateIssueForm)))
        .then(data => {
          if (data.success) {
            const modalEl = document.getElementById('updateIssueModal');
            if (modalEl && window.bootstrap) {
              const modal = bootstrap.Modal.getInstance(modalEl);
              if (modal) modal.hide();
            }
            appToast('success', data.message || 'Issue saved successfully.');
            setTimeout(() => {
              const url = new URL(window.location.href);
              url.searchParams.set('_t', Date.now());
              window.location.href = url.toString();
            }, 500);
          } else if (!data.session_expired) {
            Swal.fire('Error', data.message || 'Failed to save issue.', 'error');
          }
        });
    });
  }
})();
