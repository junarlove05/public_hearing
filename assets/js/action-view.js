/**
 * assets/js/action-view.js
 * ------------------------------------------------------------------
 * Powers modules/actions/view.php:
 * - Admin Assign Form
 * - Assigned User Status & Progress Note Form
 * - Admin Verification Form
 * - Document Upload Form
 * ------------------------------------------------------------------
 */

(function () {
  const assignForm = document.getElementById('assignForm');
  const actionUpdateForm = document.getElementById('actionUpdateForm');
  const adminVerifyForm = document.getElementById('adminVerifyForm');
  const uploadForm = document.getElementById('uploadForm');

  // Admin Assign Form
  if (assignForm) {
    assignForm.addEventListener('submit', function (e) {
      e.preventDefault();
      const btn = document.getElementById('btnSaveAssign');
      const origHtml = btn ? btn.innerHTML : '';
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';
      }

      appPost(window.APP_URL + '/modules/actions/ajax_assign.php', Object.fromEntries(new FormData(assignForm)))
        .then(data => {
          if (data.success) {
            appToast('success', data.message || 'Assignment saved successfully.');
            setTimeout(() => {
              const url = new URL(window.location.href);
              url.searchParams.set('_t', Date.now());
              window.location.href = url.toString();
            }, 450);
          } else {
            if (btn) {
              btn.disabled = false;
              btn.innerHTML = origHtml;
            }
            if (!data.session_expired) {
              Swal.fire('Error', data.message || 'Failed to save assignment.', 'error');
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

  // Assigned User Status & Progress Note Form
  if (actionUpdateForm) {
    actionUpdateForm.addEventListener('submit', function (e) {
      e.preventDefault();
      const btn = document.getElementById('btnSaveActionUpdate');
      const origHtml = btn ? btn.innerHTML : '';
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';
      }

      appPost(window.APP_URL + '/modules/actions/ajax_transition.php', Object.fromEntries(new FormData(actionUpdateForm)))
        .then(data => {
          if (data.success) {
            appToast('success', data.message || 'Update saved successfully.');
            setTimeout(() => {
              const url = new URL(window.location.href);
              url.searchParams.set('_t', Date.now());
              window.location.href = url.toString();
            }, 450);
          } else {
            if (btn) {
              btn.disabled = false;
              btn.innerHTML = origHtml;
            }
            if (!data.session_expired) {
              Swal.fire('Notice', data.message || 'Failed to save update.', 'warning');
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

  // Admin Verification Form
  const btnVerify = document.getElementById('btnAdminVerify');
  const btnReturn = document.getElementById('btnAdminReturn');

  function handleVerify(action) {
    if (!adminVerifyForm) return;
    const notesInput = document.getElementById('adminVerificationNotes');
    const notesVal = notesInput ? notesInput.value.trim() : '';

    if (action === 'return' && !notesVal) {
      Swal.fire('Remarks Required', 'Please enter remarks explaining why the update is returned for revision.', 'warning');
      if (notesInput) notesInput.focus();
      return;
    }

    const activeBtn = action === 'verify' ? btnVerify : btnReturn;
    const origHtml = activeBtn ? activeBtn.innerHTML : '';
    if (activeBtn) {
      activeBtn.disabled = true;
      activeBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Processing...';
    }

    const fd = new FormData(adminVerifyForm);
    fd.append('action', action);

    appPost(window.APP_URL + '/modules/actions/ajax_verify.php', Object.fromEntries(fd))
      .then(data => {
        if (data.success) {
          appToast('success', data.message || 'Verification completed successfully.');
          setTimeout(() => {
            const url = new URL(window.location.href);
            url.searchParams.set('_t', Date.now());
            window.location.href = url.toString();
          }, 450);
        } else {
          if (activeBtn) {
            activeBtn.disabled = false;
            activeBtn.innerHTML = origHtml;
          }
          if (!data.session_expired) {
            Swal.fire('Error', data.message || 'Verification failed.', 'error');
          }
        }
      })
      .catch(err => {
        if (activeBtn) {
          activeBtn.disabled = false;
          activeBtn.innerHTML = origHtml;
        }
        console.error('Verify error:', err);
      });
  }

  if (btnVerify) {
    btnVerify.addEventListener('click', function () { handleVerify('verify'); });
  }
  if (btnReturn) {
    btnReturn.addEventListener('click', function () { handleVerify('return'); });
  }

  // Document Upload Form
  if (uploadForm) {
    uploadForm.addEventListener('submit', function (e) {
      e.preventDefault();
      const btn = uploadForm.querySelector('button[type="submit"]');
      if (btn) btn.disabled = true;
      appPostForm(window.APP_URL + '/modules/actions/ajax_upload_document.php', uploadForm)
        .then(data => {
          if (btn) btn.disabled = false;
          if (data.success) {
            appToast('success', data.message || 'Document uploaded successfully.');
            setTimeout(() => window.location.reload(), 500);
          } else if (!data.session_expired) {
            Swal.fire('Error', data.message || 'Upload failed.', 'error');
          }
        });
    });
  }
})();
