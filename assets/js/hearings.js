(function () {
  'use strict';

  const wrap = document.getElementById('hearingsTableWrap');
  const filterForm = document.getElementById('filterForm');

  if (!wrap || !filterForm) return;

  const AJAX_URL =
    window.APP_URL + '/modules/hearings/ajax_search.php';

  let currentSort = 'hearing_date';
  let currentDir = 'asc';
  let currentPage = 1;
  let searchTimer = null;

  function buildParams(extra) {
    const data = new FormData(filterForm);
    const params = new URLSearchParams();

    for (const [key, value] of data.entries()) {
      if (String(value).trim() !== '') {
        params.append(key, value);
      }
    }

    params.set('sort', currentSort);
    params.set('dir', currentDir);
    params.set('page', extra && extra.page ? extra.page : currentPage);

    return params;
  }

  function syncPrintLink() {
    const printLink = document.getElementById('printScheduleLink');

    if (!printLink) return;

    const params = buildParams({ page: 1 });

    params.delete('search');
    params.delete('sort');
    params.delete('dir');
    params.delete('page');

    printLink.href =
      'print.php' + (params.toString() ? '?' + params.toString() : '');
  }

  function loadTable(extra, targetHearingId) {
    const prevScrollY = window.scrollY || window.pageYOffset || 0;
    const params = buildParams(extra || {});

    appGet(AJAX_URL + '?' + params.toString())
      .then(function (data) {
        if (data.success) {
          wrap.innerHTML = data.html;
          bindRowEvents();
          syncPrintLink();

          if (prevScrollY > 0) {
            window.scrollTo({ left: 0, top: prevScrollY, behavior: 'instant' });
          }

          if (targetHearingId) {
            const targetRow = wrap.querySelector('tr[data-id="' + targetHearingId + '"]');
            if (targetRow) {
              targetRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
              targetRow.classList.add('table-highlight-flash', 'lph-activity-highlight');
              setTimeout(function () {
                targetRow.classList.remove('table-highlight-flash', 'lph-activity-highlight');
              }, 3000);
            }
          } else if (window.lphRestoreActivity) {
            window.lphRestoreActivity();
          }

          return;
        }

        if (!data.session_expired) {
          appToast(
            'error',
            data.message || 'Unable to load the hearing schedule.'
          );
        }
      });
  }

  function bindRowEvents() {
    wrap.querySelectorAll('.sort-link').forEach(function (button) {
      button.addEventListener('click', function () {
        const column = button.getAttribute('data-sort');

        if (currentSort === column) {
          currentDir = currentDir === 'asc' ? 'desc' : 'asc';
        } else {
          currentSort = column;
          currentDir = 'asc';
        }

        currentPage = 1;
        loadTable();
      });
    });

    wrap.querySelectorAll('.pagination a.page-link').forEach(function (link) {
      link.addEventListener('click', function (event) {
        event.preventDefault();

        const url = new URL(link.href, window.location.href);

        currentPage = parseInt(
          url.searchParams.get('page') || '1',
          10
        );

        loadTable({ page: currentPage });
      });
    });

    wrap.querySelectorAll('.btn-edit-hearing').forEach(function (button) {
      button.addEventListener('click', function () {
        openEditModal(button.getAttribute('data-id'));
      });
    });
  }

  if (window.registerDeleteHandler) {
    window.registerDeleteHandler(function () {
      loadTable();
    });
  }

  filterForm.addEventListener('input', function (event) {
    if (event.target.id !== 'searchInput') {
      return;
    }

    clearTimeout(searchTimer);

    searchTimer = setTimeout(function () {
      currentPage = 1;
      loadTable();
    }, 350);
  });

  filterForm.addEventListener('change', function () {
    currentPage = 1;
    loadTable();
  });

  syncPrintLink();
  bindRowEvents();

  const modalElement = document.getElementById('hearingModal');

  if (!modalElement) {
    return;
  }

  const modal = new bootstrap.Modal(modalElement);
  const form = document.getElementById('hearingForm');
  const addButton = document.getElementById('btnAddHearing');
  const statusInput = document.getElementById('f_status');
  const cancellationWrap = document.getElementById('cancellationReasonWrap');
  const cancellationInput = document.getElementById('f_cancellation_reason');

  // Multi-day schedule elements
  const schedModeSingle = document.getElementById('sched_mode_single');
  const schedModeMulti = document.getElementById('sched_mode_multi');
  const singleDayWrap = document.getElementById('singleDayScheduleWrap');
  const multiDayWrap = document.getElementById('multiDayScheduleWrap');
  const sessionDaysContainer = document.getElementById('sessionDaysContainer');
  const btnAddSessionDay = document.getElementById('btnAddSessionDay');
  const btnGenerateDays = document.getElementById('btnGenerateDays');
  const genStartDate = document.getElementById('gen_start_date');
  const genEndDate = document.getElementById('gen_end_date');
  const genStartTime = document.getElementById('gen_start_time');
  const genEndTime = document.getElementById('gen_end_time');
  const fSessionsJson = document.getElementById('f_sessions_json');
  const fDate = document.getElementById('f_date');
  const fTime = document.getElementById('f_time');
  const fEndDate = document.getElementById('f_end_date');
  const fEndTime = document.getElementById('f_end_time');

  function formatLocalDate(d) {
    if (!d || isNaN(d.getTime())) return '';
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  }

  function parseLocalDate(dateStr) {
    if (!dateStr) return new Date();
    const parts = String(dateStr).split('-');
    if (parts.length === 3) {
      const y = parseInt(parts[0], 10);
      const m = parseInt(parts[1], 10) - 1;
      const d = parseInt(parts[2], 10);
      if (!isNaN(y) && !isNaN(m) && !isNaN(d)) {
        return new Date(y, m, d);
      }
    }
    return new Date();
  }

  function setScheduleMode(mode, autoCreateDefaultRows = false) {
    if (mode === 'multi') {
      if (schedModeMulti) schedModeMulti.checked = true;
      if (singleDayWrap) singleDayWrap.style.display = 'none';
      if (multiDayWrap) multiDayWrap.style.display = '';

      // Only auto-fill when explicitly requested by user interaction
      if (autoCreateDefaultRows && sessionDaysContainer) {
        if (sessionDaysContainer.children.length === 0) {
          const initialDate = fDate && fDate.value ? fDate.value : '';
          const initialStart = fTime && fTime.value ? fTime.value : '09:00';
          const initialEnd = fEndTime && fEndTime.value ? fEndTime.value : '12:00';
          addSessionRow({ session_date: initialDate, start_time: initialStart, end_time: initialEnd });

          let nextDateStr = '';
          if (initialDate) {
            const d = parseLocalDate(initialDate);
            d.setDate(d.getDate() + 1);
            nextDateStr = formatLocalDate(d);
          }
          addSessionRow({ session_date: nextDateStr, start_time: initialStart, end_time: initialEnd });
        } else if (sessionDaysContainer.children.length === 1) {
          const firstRow = sessionDaysContainer.querySelector('.session-day-row');
          const dInput = firstRow ? firstRow.querySelector('.sess-date') : null;
          const sInput = firstRow ? firstRow.querySelector('.sess-start') : null;
          const eInput = firstRow ? firstRow.querySelector('.sess-end') : null;
          let nextDateStr = '';
          if (dInput && dInput.value) {
            const d = parseLocalDate(dInput.value);
            d.setDate(d.getDate() + 1);
            nextDateStr = formatLocalDate(d);
          }
          addSessionRow({
            session_date: nextDateStr,
            start_time: sInput && sInput.value ? sInput.value : '09:00',
            end_time: eInput && eInput.value ? eInput.value : '12:00'
          });
        }
      }
    } else {
      if (schedModeSingle) schedModeSingle.checked = true;
      if (singleDayWrap) singleDayWrap.style.display = '';
      if (multiDayWrap) multiDayWrap.style.display = 'none';
    }
  }

  if (schedModeSingle) {
    schedModeSingle.addEventListener('change', function () {
      if (schedModeSingle.checked) setScheduleMode('single', false);
    });
  }

  if (schedModeMulti) {
    schedModeMulti.addEventListener('change', function () {
      if (schedModeMulti.checked) setScheduleMode('multi', true);
    });
  }

  // Automatic default end time calculation when start time changes
  if (fTime && fEndTime) {
    fTime.addEventListener('change', function () {
      if (this.value && (!fEndTime.value || fEndTime.dataset.autoFilled === '1')) {
        const parts = this.value.split(':');
        if (parts.length >= 2) {
          const h = parseInt(parts[0], 10);
          const m = parseInt(parts[1], 10);
          if (!isNaN(h) && !isNaN(m)) {
            const endH = (h + 1) % 24;
            fEndTime.value = String(endH).padStart(2, '0') + ':' + String(m).padStart(2, '0');
            fEndTime.dataset.autoFilled = '1';
          }
        }
      }
    });

    fEndTime.addEventListener('input', function () {
      delete this.dataset.autoFilled;
    });
  }

  function createSessionRowElement(data) {
    const row = document.createElement('div');
    row.className = 'session-day-row p-2.5 bg-white rounded-3 border shadow-2xs position-relative';
    row.style.borderColor = '#e2e8f0';

    row.innerHTML = `
      <div class="row g-2 align-items-center">
        <div class="col-auto">
          <span class="badge bg-primary text-white day-badge fw-bold px-2 py-1.5" style="font-size: 0.78rem;">
            Day 1
          </span>
        </div>
        <div class="col-sm-3 col-6">
          <label class="form-label small text-muted mb-0 d-block" style="font-size: 0.72rem;">Session Date <span class="text-danger">*</span></label>
          <input type="date" name="session_date[]" class="form-control form-control-sm sess-date" value="${data.session_date || ''}">
        </div>
        <div class="col-sm-2 col-6">
          <label class="form-label small text-muted mb-0 d-block" style="font-size: 0.72rem;">Start Time <span class="text-danger">*</span></label>
          <input type="time" name="session_start_time[]" class="form-control form-control-sm sess-start" value="${data.start_time || '09:00'}">
        </div>
        <div class="col-sm-2 col-6">
          <label class="form-label small text-muted mb-0 d-block" style="font-size: 0.72rem;">End Time</label>
          <input type="time" name="session_end_time[]" class="form-control form-control-sm sess-end" value="${data.end_time || '12:00'}">
        </div>
        <div class="col-sm-4 col-12">
          <label class="form-label small text-muted mb-0 d-block" style="font-size: 0.72rem;">Session Topic / Notes</label>
          <div class="d-flex align-items-center gap-1">
            <input type="text" name="session_notes[]" class="form-control form-control-sm sess-notes" maxlength="255" placeholder="Optional topic / consultation focus" value="${(data.notes || '').replace(/"/g, '&quot;')}">
            <button type="button" class="btn btn-outline-danger btn-sm px-2 btn-remove-day" title="Remove this day">
              <i class="bi bi-trash3"></i>
            </button>
          </div>
        </div>
      </div>
    `;

    row.querySelector('.btn-remove-day').addEventListener('click', function () {
      if (sessionDaysContainer.children.length <= 1) {
        appToast('warning', 'A multi-day hearing must have at least 1 session day.');
        return;
      }
      row.remove();
      renumberSessionDays();
    });

    row.querySelectorAll('input').forEach(function (inp) {
      inp.addEventListener('input', syncSessionsToHidden);
      inp.addEventListener('change', renumberSessionDays);
    });

    return row;
  }

  function addSessionRow(data) {
    if (!sessionDaysContainer) return;
    const rowEl = createSessionRowElement(data || {});
    sessionDaysContainer.appendChild(rowEl);
    renumberSessionDays();
  }

  function renumberSessionDays() {
    if (!sessionDaysContainer) return;
    const rows = sessionDaysContainer.querySelectorAll('.session-day-row');
    rows.forEach(function (r, idx) {
      const badge = r.querySelector('.day-badge');
      if (badge) {
        badge.textContent = 'Day ' + (idx + 1);
      }
    });
    syncSessionsToHidden();
  }

  function getSessionsData() {
    if (!sessionDaysContainer) return [];
    const sessions = [];
    const rows = sessionDaysContainer.querySelectorAll('.session-day-row');
    rows.forEach(function (r, idx) {
      const dInput = r.querySelector('.sess-date');
      const sInput = r.querySelector('.sess-start');
      const eInput = r.querySelector('.sess-end');
      const nInput = r.querySelector('.sess-notes');

      const sDate = dInput ? dInput.value.trim() : '';
      const sStart = sInput ? sInput.value.trim() : '';
      const sEnd = eInput ? eInput.value.trim() : '';
      const sNotes = nInput ? nInput.value.trim() : '';

      if (sDate !== '' || sStart !== '') {
        sessions.push({
          day_number: idx + 1,
          session_date: sDate,
          start_time: sStart,
          end_time: sEnd,
          notes: sNotes
        });
      }
    });
    return sessions;
  }

  function syncSessionsToHidden() {
    const isMulti = schedModeMulti && schedModeMulti.checked;
    if (isMulti) {
      const sessions = getSessionsData();
      if (sessions.length > 0) {
        const sorted = [...sessions].sort(function (a, b) {
          return (a.session_date + ' ' + a.start_time).localeCompare(b.session_date + ' ' + b.start_time);
        });

        const first = sorted[0];
        const last = sorted[sorted.length - 1];

        if (fDate && first.session_date) fDate.value = first.session_date;
        if (fTime && first.start_time) fTime.value = first.start_time;
        if (fEndDate && last.session_date) fEndDate.value = last.session_date;
        if (fEndTime && last.end_time) fEndTime.value = last.end_time;
      }
      if (fSessionsJson) {
        fSessionsJson.value = JSON.stringify(sessions);
      }
    } else {
      if (fDate && fEndDate) {
        fEndDate.value = fDate.value;
      }
      if (fSessionsJson) {
        if (fDate && fDate.value && fTime && fTime.value) {
          fSessionsJson.value = JSON.stringify([{
            day_number: 1,
            session_date: fDate.value,
            start_time: fTime.value,
            end_time: fEndTime ? fEndTime.value : '',
            notes: ''
          }]);
        } else {
          fSessionsJson.value = '';
        }
      }
    }
  }

  if (btnAddSessionDay) {
    btnAddSessionDay.addEventListener('click', function () {
      const sessions = getSessionsData();
      let nextDate = '';
      let nextStart = '09:00';
      let nextEnd = '12:00';

      if (sessions.length > 0) {
        const last = sessions[sessions.length - 1];
        nextStart = last.start_time || '09:00';
        nextEnd = last.end_time || '12:00';
        if (last.session_date) {
          const d = parseLocalDate(last.session_date);
          d.setDate(d.getDate() + 1);
          nextDate = formatLocalDate(d);
        }
      }

      addSessionRow({ session_date: nextDate, start_time: nextStart, end_time: nextEnd });
    });
  }

  if (btnGenerateDays) {
    btnGenerateDays.addEventListener('click', function () {
      const sDateVal = genStartDate ? genStartDate.value.trim() : '';
      const eDateVal = genEndDate ? genEndDate.value.trim() : '';
      const sTimeVal = genStartTime ? genStartTime.value.trim() : '09:00';
      const eTimeVal = genEndTime ? genEndTime.value.trim() : '12:00';

      if (!sDateVal || !eDateVal) {
        appToast('warning', 'Please specify both "From Date" and "To Date" to generate days.');
        return;
      }

      const dStart = parseLocalDate(sDateVal);
      const dEnd = parseLocalDate(eDateVal);

      if (dEnd < dStart) {
        appToast('error', '"To Date" cannot be earlier than "From Date".');
        return;
      }

      const diffDays = Math.round((dEnd - dStart) / (1000 * 60 * 60 * 24)) + 1;
      if (diffDays > 31) {
        appToast('error', 'Cannot auto-generate more than 31 days at once.');
        return;
      }

      sessionDaysContainer.innerHTML = '';
      const cur = parseLocalDate(sDateVal);
      while (cur <= dEnd) {
        const dateStr = formatLocalDate(cur);
        addSessionRow({
          session_date: dateStr,
          start_time: sTimeVal,
          end_time: eTimeVal,
          notes: ''
        });
        cur.setDate(cur.getDate() + 1);
      }

      appToast('success', `Generated ${diffDays} consecutive hearing session days.`);
    });
  }

  function setCancellationState() {
    const cancelled = statusInput.value === 'Cancelled';

    cancellationWrap.hidden = !cancelled;
    cancellationInput.required = cancelled;

    if (!cancelled) {
      cancellationInput.value = '';
    }
  }

  statusInput.addEventListener('change', setCancellationState);

  const STATUS_OPTIONS_HTML =
    '<option value="Upcoming">Upcoming</option>' +
    '<option value="Ongoing">Ongoing</option>' +
    '<option value="Completed">Completed</option>' +
    '<option value="Cancelled">Cancelled</option>';

  addButton.addEventListener('click', function () {
    form.reset();

    document.getElementById('hearing_id').value = '0';
    document.getElementById('f_visibility').value = 'Public';
    statusInput.innerHTML = STATUS_OPTIONS_HTML;
    document.getElementById('f_status').value = 'Upcoming';

    if (sessionDaysContainer) sessionDaysContainer.innerHTML = '';
    setScheduleMode('single', false);

    if (genStartDate) genStartDate.value = '';
    if (genEndDate) genEndDate.value = '';

    document.getElementById('hearingModalTitle').textContent = 'Create Hearing';

    setCancellationState();
    modal.show();
  });

  function setValue(id, value) {
    const element = document.getElementById(id);

    if (element) {
      element.value =
        value === null || value === undefined
          ? ''
          : value;
    }
  }

  function openEditModal(id) {
    appGet(
      window.APP_URL
      + '/modules/hearings/ajax_get.php?id='
      + encodeURIComponent(id)
    ).then(function (data) {
      if (!data.success) {
        if (!data.session_expired) {
          appToast(
            'error',
            data.message || 'Unable to load hearing.'
          );
        }

        return;
      }

      const h = data.hearing;

      if (h.status === 'Completed') {
        appToast('info', 'This hearing has already been Completed and is view-only.');
        window.location.href = window.APP_URL + '/modules/hearings/view.php?id=' + encodeURIComponent(id);
        return;
      }

      form.reset();

      statusInput.innerHTML = STATUS_OPTIONS_HTML;
      setValue('hearing_id', h.id);
      setValue('f_title', h.title);
      setValue('f_legislative_item', h.legislative_item_id);
      setValue('f_type', h.hearing_type_id);
      setValue('f_committee', h.committee_id);
      setValue('f_date', h.hearing_date);
      setValue('f_time', h.hearing_time);
      setValue('f_end_date', h.end_date);
      setValue('f_end_time', h.end_time);
      setValue('f_venue', h.venue);
      setValue('f_meeting_link', h.meeting_link);
      setValue('f_registration_deadline', h.registration_deadline);
      setValue('f_maximum_participants', h.maximum_participants);
      setValue('f_visibility', h.visibility || 'Public');
      setValue('f_status', h.status || 'Upcoming');
      setValue('f_cancellation_reason', h.cancellation_reason);
      setValue('f_description', h.description);

      // Handle multi-day session rows
      if (sessionDaysContainer) sessionDaysContainer.innerHTML = '';

      if (h.sessions && h.sessions.length > 1) {
        setScheduleMode('multi', false);
        h.sessions.forEach(function (s) {
          addSessionRow(s);
        });
        if (genStartDate && h.hearing_date) genStartDate.value = h.hearing_date;
        if (genEndDate && h.end_date) genEndDate.value = h.end_date;
      } else {
        setScheduleMode('single', false);
        if (h.sessions && h.sessions.length === 1) {
          addSessionRow(h.sessions[0]);
        }
      }

      document.getElementById('hearingModalTitle').textContent = 'Edit Hearing';

      setCancellationState();
      modal.show();
    });
  }

  window.openEditModal = openEditModal;

  form.addEventListener('submit', function (event) {
    event.preventDefault();

    const isMulti = schedModeMulti && schedModeMulti.checked;

    if (isMulti) {
      const sessions = getSessionsData();
      if (sessions.length < 2) {
        Swal.fire({
          icon: 'warning',
          title: 'Multi-Day Hearing Schedule',
          text: 'Mangyaring maglagay ng kahit 2 magkaibang session days para sa multi-day hearing, o lumipat sa "Single Day" mode kung isang araw lamang.',
        });
        return;
      }

      // Check for empty fields or duplicate dates
      const seenDates = new Set();
      for (let i = 0; i < sessions.length; i++) {
        const s = sessions[i];
        if (!s.session_date) {
          Swal.fire('Kulang na Petsa', `Paki-lagay ang petsa para sa Day ${i + 1}.`, 'warning');
          return;
        }
        if (seenDates.has(s.session_date)) {
          Swal.fire('Duplicate Session Date', `Ang petsang ${s.session_date} ay nauulit. Dapat ay may magkakaibang petsa ang bawat araw.`, 'warning');
          return;
        }
        seenDates.add(s.session_date);

        if (!s.start_time) {
          Swal.fire('Kulang na Oras', `Paki-lagay ang start time para sa Day ${i + 1} (${s.session_date}).`, 'warning');
          return;
        }

        if (s.end_time && s.end_time <= s.start_time) {
          Swal.fire('Maling Oras', `Sa Day ${i + 1} (${s.session_date}), dapat mas huli ang End Time kaysa sa Start Time.`, 'warning');
          return;
        }
      }

      syncSessionsToHidden();
    } else {
      if (!fDate.value || !fTime.value) {
        Swal.fire('Kulang na Detalye', 'Paki-lagay ang Hearing Date at Start Time.', 'warning');
        return;
      }
      if (fEndTime && fEndTime.value && fEndTime.value <= fTime.value) {
        Swal.fire('Maling Oras', 'Dapat mas huli ang End Time kaysa sa Start Time.', 'warning');
        return;
      }
      syncSessionsToHidden();
    }

    const button = document.getElementById('btnSaveHearing');

    button.disabled = true;
    button.innerHTML =
      '<span class="spinner-border spinner-border-sm"></span> Saving...';

    appPostForm(
      window.APP_URL + '/modules/hearings/ajax_save.php',
      form
    ).then(function (data) {
      button.disabled = false;
      button.innerHTML =
        '<i class="bi bi-check-circle"></i> Save Hearing';

      if (data.success) {
        modal.hide();
        appToast('success', data.message);
        loadTable({}, data.id);
        return;
      }

      if (!data.session_expired) {
        if (data.has_conflict && data.conflicts && data.conflicts.length > 0) {
          const firstConf = data.conflicts[0];
          let conflictHtml = '<div class="text-start mb-2"><p class="text-danger fw-semibold mb-2">'
            + (data.message || 'May schedule conflict sa ibang hearing.')
            + '</p><div class="list-group shadow-sm">';

          data.conflicts.forEach(function (c) {
            conflictHtml += `
              <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
                <div>
                  <div class="fw-bold text-dark" style="font-size: 0.9rem;">${c.reference_number}: ${c.title}</div>
                  <div class="small text-muted"><i class="bi bi-clock me-1"></i>${c.date_label} ${c.time_label}</div>
                </div>
                <a href="${c.view_url}" target="_blank" class="btn btn-sm btn-outline-primary ms-2 text-nowrap">
                  <i class="bi bi-box-arrow-up-right me-1"></i> Tingnan
                </a>
              </div>
            `;
          });
          conflictHtml += '</div></div>';

          Swal.fire({
            icon: 'warning',
            title: 'Schedule Conflict Detected',
            html: conflictHtml,
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-box-arrow-up-right me-1"></i> Buksan ang Hearing na May Conflict',
            cancelButtonText: 'I-edit ang Schedule',
            confirmButtonColor: '#0d6efd',
            cancelButtonColor: '#6c757d'
          }).then(function (result) {
            if (result.isConfirmed) {
              window.open(firstConf.view_url, '_blank');
            }
          });
          return;
        }

        Swal.fire(
          'Unable to Save Hearing',
          data.message || 'Please review the hearing details.',
          'error'
        );
      }
    }).catch(function () {
      button.disabled = false;
      button.innerHTML =
        '<i class="bi bi-check-circle"></i> Save Hearing';
    });
  });
})();
