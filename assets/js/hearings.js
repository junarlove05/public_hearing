/**
 * assets/js/hearings.js
 * ------------------------------------------------------------------
 * Powers modules/hearings/index.php: live AJAX search/filter/sort/
 * pagination, and the Create/Edit modal (AJAX submit with file
 * uploads via FormData).
 * ------------------------------------------------------------------
 */

(function () {
  const wrap = document.getElementById('hearingsTableWrap');
  const filterForm = document.getElementById('filterForm');
  if (!wrap || !filterForm) return;

  const AJAX_URL = window.APP_URL + '/modules/hearings/ajax_search.php';
  let currentSort = 'hearing_date';
  let currentDir = 'desc';
  let currentPage = 1;
  let searchTimer = null;

  function buildParams(extra) {
    const data = new FormData(filterForm);
    const params = new URLSearchParams();
    for (const [key, val] of data.entries()) {
      if (val !== '') params.append(key, val);
    }
    params.set('sort', currentSort);
    params.set('dir', currentDir);
    params.set('page', extra && extra.page ? extra.page : currentPage);
    return params;
  }

  function loadTable(extra) {
    const params = buildParams(extra || {});
    appGet(AJAX_URL + '?' + params.toString()).then(data => {
      if (data.success) {
        wrap.innerHTML = data.html;
        bindRowEvents();
      } else if (!data.session_expired) {
        appToast('error', data.message || 'Unable to load hearings right now.');
      }
    });
    syncPrintLink();
  }

  function syncPrintLink() {
    const printLink = document.getElementById('printScheduleLink');
    if (!printLink) return;
    const data = new FormData(filterForm);
    const params = new URLSearchParams();
    for (const [key, val] of data.entries()) {
      if (val !== '' && key !== 'search') params.append(key, val);
    }
    printLink.href = 'print.php' + (params.toString() ? '?' + params.toString() : '');
  }

  function bindRowEvents() {
    // Sort column headers
    wrap.querySelectorAll('.sort-link').forEach(el => {
      el.style.cursor = 'pointer';
      el.addEventListener('click', function () {
        const col = el.getAttribute('data-sort');
        if (currentSort === col) {
          currentDir = currentDir === 'asc' ? 'desc' : 'asc';
        } else {
          currentSort = col;
          currentDir = 'asc';
        }
        currentPage = 1;
        loadTable();
      });
    });

    // Pagination links -> intercept and load via AJAX
    wrap.querySelectorAll('.pagination a.page-link').forEach(a => {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        const url = new URL(a.href);
        currentPage = parseInt(url.searchParams.get('page') || '1', 10);
        loadTable({ page: currentPage });
      });
    });

    // Edit buttons
    wrap.querySelectorAll('.btn-edit-hearing').forEach(btn => {
      btn.addEventListener('click', function () {
        openEditModal(btn.getAttribute('data-id'));
      });
    });
  }

  // Delete buttons use app.js's document-level delegated handler (works
  // automatically for AJAX-injected rows too); we just override what
  // happens after a successful delete so it refreshes the table in place
  // instead of doing a full page reload.
  if (window.registerDeleteHandler) {
    window.registerDeleteHandler(function () { loadTable(); });
  }

  // ---- Filter inputs: live search (debounced) + instant filter changes ----
  filterForm.addEventListener('input', function (e) {
    if (e.target.id === 'searchInput') {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => { currentPage = 1; loadTable(); }, 400);
    }
  });
  filterForm.addEventListener('change', function (e) {
    if (e.target.id !== 'searchInput') {
      currentPage = 1;
      loadTable();
    }
  });

  bindRowEvents();

  /* ================= Create / Edit Modal ================= */
  const modalEl = document.getElementById('hearingModal');
  if (!modalEl) return; // read-only role, no manage permissions

  const modal = new bootstrap.Modal(modalEl);
  const form = document.getElementById('hearingForm');
  const btnAdd = document.getElementById('btnAddHearing');

  btnAdd.addEventListener('click', function () {
    form.reset();
    document.getElementById('hearing_id').value = 0;
    document.getElementById('hearingModalTitle').innerHTML = '<i class="bi bi-calendar-plus"></i> Add Hearing';
    modal.show();
  });

  function openEditModal(id) {
    appGet(window.APP_URL + '/modules/hearings/ajax_get.php?id=' + id).then(data => {
      if (!data.success) { if (!data.session_expired) appToast('error', data.message); return; }
      const h = data.hearing;
      form.reset();
      document.getElementById('hearing_id').value = h.id;
      document.getElementById('f_title').value = h.title || '';
      document.getElementById('f_type').value = h.hearing_type_id || '';
      document.getElementById('f_committee').value = h.committee_id || '';
      document.getElementById('f_date').value = h.hearing_date || '';
      document.getElementById('f_time').value = (h.hearing_time || '').substring(0, 5);
      document.getElementById('f_status').value = h.status || 'Upcoming';
      document.getElementById('f_venue').value = h.venue || '';
      document.getElementById('f_description').value = h.description || '';
      document.getElementById('hearingModalTitle').innerHTML = '<i class="bi bi-pencil-square"></i> Edit Hearing';
      modal.show();
    });
  }
  window.openEditModal = openEditModal;

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    const btn = document.getElementById('btnSaveHearing');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving...';

    appPostForm(window.APP_URL + '/modules/hearings/ajax_save.php', form)
      .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-circle"></i> Save Hearing';
        if (data.success) {
          modal.hide();
          appToast('success', data.message);
          loadTable();
        } else if (!data.session_expired) {
          Swal.fire('Error', data.message || 'Unable to save this hearing.', 'error');
        }
      });
  });
})();
