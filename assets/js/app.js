/**
 * assets/js/app.js
 * ------------------------------------------------------------------
 * Global, site-wide JavaScript: sidebar toggle, delete confirmations,
 * generic AJAX helper, and DataTables defaults. Module-specific logic
 * lives in assets/js/<module>.js and is loaded on top of this file.
 * ------------------------------------------------------------------
 */

/**
 * Global Mobile Drawer Close Helper
 */
window.lphCloseSidebarMobile = function () {
  var sidebar = document.getElementById('orlmsSidebar') || document.getElementById('sidebar');
  var backdrop = document.getElementById('orlmsSidebarBackdrop') || document.getElementById('sidebarBackdrop');
  if (sidebar) sidebar.classList.remove('open');
  if (backdrop) backdrop.classList.remove('show');
  if (document.body) document.body.classList.remove('sidebar-open');
};

/**
 * Global Sidebar Toggle function (desktop 74px collapse <-> mobile off-canvas drawer)
 */
window.lphToggleSidebar = function (e) {
  if (e) {
    if (e.preventDefault) e.preventDefault();
    if (e.stopPropagation) e.stopPropagation();
  }

  var now = Date.now();
  if (window._lphLastToggleTime && (now - window._lphLastToggleTime < 200)) {
    return;
  }
  window._lphLastToggleTime = now;

  var sidebar = document.getElementById('orlmsSidebar') || document.getElementById('sidebar');
  var backdrop = document.getElementById('orlmsSidebarBackdrop') || document.getElementById('sidebarBackdrop');

  if (window.innerWidth <= 1050) {
    // Mobile / Tablet: toggle off-canvas slide-in drawer
    if (sidebar) {
      var isOpen = sidebar.classList.toggle('open');
      if (backdrop) {
        backdrop.classList.toggle('show', isOpen);
      }
      if (document.body) {
        document.body.classList.toggle('sidebar-open', isOpen);
      }
    }
  } else {
    // Desktop: toggle compact icon-only mode (74px <-> 286px)
    var html = document.documentElement;
    var body = document.body;
    var isCollapsed = html.classList.toggle('sidebar-collapsed');
    if (body) {
      body.classList.toggle('sidebar-collapsed', isCollapsed);
    }
    try {
      localStorage.setItem('lph_sidebar_collapsed', isCollapsed ? '1' : '0');
    } catch (err) {}
  }
};

/* ==================================================================
 * Subsystem 7 Global Activity & View Preservation Engine (LPH)
 * ------------------------------------------------------------------
 * "kung saan ako may activity doon lang mismo ang screen dapat"
 * 
 * Ensures that whenever any action, form submit, AJAX mutation, status
 * change, or page reload occurs anywhere in Subsystem 7:
 * 1. The user's exact scroll position is preserved without jumping to top.
 * 2. Any internal scrollable container (.table-responsive, etc.) is preserved.
 * 3. The specific row/card/element where activity occurred stays in view
 *    and flashes with a smooth pulse animation (.table-highlight-flash).
 * 4. Works seamlessly across all modules (hearings, stakeholders,
 *    attendance, actions, feedback, issues, users, dashboard).
 * ================================================================== */
(function () {
  'use strict';

  // Disable browser's auto scroll restoration so it doesn't force scroll(0, 0)
  if ('scrollRestoration' in history) {
    try {
      history.scrollRestoration = 'manual';
    } catch (e) {}
  }

  const STORAGE_KEY = 'lph_active_activity';

  /**
   * Generates a unique or robust CSS selector for an element
   */
  function getElementSelector(el) {
    if (!el || !(el instanceof Element)) return null;
    if (el.id) return '#' + CSS.escape(el.id);
    if (el.dataset && el.dataset.id) {
      return el.tagName.toLowerCase() + '[data-id="' + CSS.escape(el.dataset.id) + '"]';
    }
    if (el.dataset && el.dataset.recordId) {
      return el.tagName.toLowerCase() + '[data-record-id="' + CSS.escape(el.dataset.recordId) + '"]';
    }
    // If it's a table row, find its table and index
    if (el.tagName === 'TR') {
      const parent = el.parentNode;
      if (parent) {
        const idx = Array.prototype.indexOf.call(parent.children, el) + 1;
        const table = el.closest('table');
        const tableSel = table && table.id ? ('#' + CSS.escape(table.id) + ' ') : '';
        return tableSel + 'tbody tr:nth-child(' + idx + ')';
      }
    }
    // Card or list item
    const card = el.closest('.card, .card-item, .hearing-card-item, .list-group-item');
    if (card && card.id) return '#' + CSS.escape(card.id);
    return null;
  }

  /**
   * Find closest scrollable container
   */
  function findScrollContainer(el) {
    let cur = el ? el.parentElement : null;
    while (cur && cur !== document.body && cur !== document.documentElement) {
      if (cur.classList && (cur.classList.contains('table-responsive') || cur.classList.contains('stakeholder-scroll-container'))) {
        return cur;
      }
      try {
        const style = window.getComputedStyle(cur);
        const ovY = style.overflowY;
        const ovX = style.overflowX;
        if ((ovY === 'auto' || ovY === 'scroll' || ovX === 'auto' || ovX === 'scroll') && (cur.scrollHeight > cur.clientHeight || cur.scrollWidth > cur.clientWidth)) {
          return cur;
        }
      } catch (err) {}
      cur = cur.parentElement;
    }
    return null;
  }

  /**
   * Save current activity snapshot
   */
  window.lphSaveActivity = function (targetEl, extraMeta) {
    try {
      const el = targetEl || document.activeElement;
      const closestRow = el && el.closest ? el.closest('tr') : null;
      const closestCard = el && el.closest ? el.closest('.card, .card-item, .hearing-card-item, .list-group-item, .lphwf-card, .lphx-card') : null;
      
      const anchorEl = closestRow || closestCard || el;
      const container = findScrollContainer(anchorEl || el);

      const targetData = {
        path: window.location.pathname,
        search: window.location.search,
        scrollX: window.scrollX || window.pageXOffset || document.documentElement.scrollLeft || 0,
        scrollY: window.scrollY || window.pageYOffset || document.documentElement.scrollTop || 0,
        timestamp: Date.now(),
        dataId: (el && el.dataset && el.dataset.id) || (closestRow && closestRow.dataset && closestRow.dataset.id) || (closestCard && closestCard.dataset && closestCard.dataset.id) || null,
        dataRecordId: (el && el.dataset && el.dataset.recordId) || (closestRow && closestRow.dataset && closestRow.dataset.recordId) || null,
        id: (anchorEl && anchorEl.id) || (el && el.id) || null,
        selector: getElementSelector(anchorEl) || getElementSelector(el),
        containerSelector: container ? (container.id ? ('#' + CSS.escape(container.id)) : (container.className ? ('.' + container.className.trim().split(/\s+/).join('.')) : null)) : null,
        containerScrollTop: container ? container.scrollTop : 0,
        containerScrollLeft: container ? container.scrollLeft : 0,
        meta: extraMeta || null
      };

      sessionStorage.setItem(STORAGE_KEY, JSON.stringify(targetData));
      window._lphLastActivity = targetData;
    } catch (e) {
      console.warn('lphSaveActivity error:', e);
    }
  };

  /**
   * Restores scroll position and highlights the active element
   */
  window.lphRestoreActivity = function () {
    try {
      const raw = sessionStorage.getItem(STORAGE_KEY);
      if (!raw) return;
      const data = JSON.parse(raw);
      if (!data || !data.timestamp) return;

      // Only restore if within last 120 seconds and on the same path
      const now = Date.now();
      if (now - data.timestamp > 120000) {
        sessionStorage.removeItem(STORAGE_KEY);
        return;
      }
      if (data.path !== window.location.pathname) {
        return;
      }

      // 1. Immediately restore window scroll
      window.scrollTo({
        left: data.scrollX,
        top: data.scrollY,
        behavior: 'instant'
      });

      // 2. Restore container scroll
      if (data.containerSelector) {
        try {
          const c = document.querySelector(data.containerSelector);
          if (c) {
            c.scrollTop = data.containerScrollTop;
            c.scrollLeft = data.containerScrollLeft;
          }
        } catch (cErr) {}
      }

      // 3. Locate target element and apply highlight
      function tryLocateAndHighlight() {
        let target = null;
        if (data.dataId) {
          try {
            target = document.querySelector('tr[data-id="' + CSS.escape(data.dataId) + '"]')
                  || document.querySelector('[data-id="' + CSS.escape(data.dataId) + '"]');
          } catch (e) {}
        }
        if (!target && data.dataRecordId) {
          try {
            target = document.querySelector('tr[data-record-id="' + CSS.escape(data.dataRecordId) + '"]')
                  || document.querySelector('[data-record-id="' + CSS.escape(data.dataRecordId) + '"]');
          } catch (e) {}
        }
        if (!target && data.id) {
          target = document.getElementById(data.id);
        }
        if (!target && data.selector) {
          try {
            target = document.querySelector(data.selector);
          } catch (selErr) {}
        }

        if (target) {
          // If it's a child element, find its row or card for prominent glow
          const rowOrCard = target.closest('tr, .card, .list-group-item, .hearing-card-item') || target;
          
          // Re-affirm container scroll if inside container
          if (data.containerSelector) {
            try {
              const cont = document.querySelector(data.containerSelector);
              if (cont) {
                cont.scrollTop = data.containerScrollTop;
                cont.scrollLeft = data.containerScrollLeft;
              }
            } catch (contErr) {}
          }

          // Smoothly ensure in view if slightly off
          try {
            rowOrCard.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
          } catch (e) {
            window.scrollTo({ left: data.scrollX, top: data.scrollY, behavior: 'instant' });
          }

          rowOrCard.classList.add('table-highlight-flash', 'lph-activity-highlight');
          if (rowOrCard.tagName === 'TR') {
            rowOrCard.querySelectorAll('td').forEach(function (td) {
              td.classList.add('table-highlight-flash');
            });
          }

          setTimeout(function () {
            rowOrCard.classList.remove('table-highlight-flash', 'lph-activity-highlight');
            if (rowOrCard.tagName === 'TR') {
              rowOrCard.querySelectorAll('td').forEach(function (td) {
                td.classList.remove('table-highlight-flash');
              });
            }
          }, 3200);

          return true;
        }
        return false;
      }

      // Try immediately
      tryLocateAndHighlight();

      // If table is loaded via AJAX (e.g. hearings schedule or search), retry every 100ms
      let retries = 0;
      const maxRetries = 25; // 2.5s
      const timer = setInterval(function () {
        retries++;
        if (tryLocateAndHighlight() || retries >= maxRetries) {
          clearInterval(timer);
          // Clean up after slight delay so future fresh navigation starts clean
          setTimeout(function () {
            try { sessionStorage.removeItem(STORAGE_KEY); } catch (e) {}
          }, 1500);
        }
      }, 100);

    } catch (err) {
      console.warn('lphRestoreActivity error:', err);
    }
  };

  // Run restoration on DOMContentLoaded and on window load
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', window.lphRestoreActivity);
  } else {
    window.lphRestoreActivity();
  }
  window.addEventListener('load', function () {
    // Re-check once images/stylesheets are fully rendered
    const raw = sessionStorage.getItem(STORAGE_KEY);
    if (raw) {
      try {
        const d = JSON.parse(raw);
        if (d && d.path === window.location.pathname) {
          window.scrollTo({ left: d.scrollX, top: d.scrollY, behavior: 'instant' });
        }
      } catch (e) {}
    }
  });

  // Track beforeunload so the very latest scroll is saved
  window.addEventListener('beforeunload', function () {
    const raw = sessionStorage.getItem(STORAGE_KEY);
    if (raw) {
      try {
        const data = JSON.parse(raw);
        data.scrollX = window.scrollX || window.pageXOffset || 0;
        data.scrollY = window.scrollY || window.pageYOffset || 0;
        if (data.containerSelector) {
          const c = document.querySelector(data.containerSelector);
          if (c) {
            data.containerScrollTop = c.scrollTop;
            data.containerScrollLeft = c.scrollLeft;
          }
        }
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify(data));
      } catch (e) {}
    }
  });

  // Global capture for user actions: clicks on buttons, forms, actions
  document.addEventListener('click', function (e) {
    // If clicking a main navigation link that leaves the current page, clear state so new page starts at top
    const navLink = e.target.closest('a[href]:not([href^="#"]):not([href=""]):not([data-bs-toggle]):not([data-confirm-delete]):not(.btn-reg-status):not(.att-action):not(.sort-link):not(.page-link)');
    if (navLink) {
      const href = navLink.getAttribute('href') || '';
      // If it points to a different HTML/PHP page entirely
      if (href && !href.startsWith('javascript:') && !navLink.classList.contains('btn-action')) {
        try {
          const targetUrl = new URL(href, window.location.href);
          if (targetUrl.pathname !== window.location.pathname) {
            sessionStorage.removeItem(STORAGE_KEY);
            return;
          }
        } catch (uErr) {}
      }
    }

    const actionable = e.target.closest('button, .btn, [data-confirm-delete], [data-action], [data-status], input[type="submit"], input[type="button"], .sort-link, .page-link, .btn-reg-status, .att-action, .response-status');
    if (actionable) {
      window.lphSaveActivity(actionable);
    }
  }, true);

  // Global submit capture for all forms
  document.addEventListener('submit', function (e) {
    const submitter = e.submitter || e.target.querySelector('button[type="submit"], input[type="submit"]') || e.target;
    window.lphSaveActivity(submitter);
  }, true);

})();

document.addEventListener('DOMContentLoaded', function () {

  /* ---------- Sidebar toggle (desktop collapse / mobile slide-in) ---------- */
  const toggleBtns = document.querySelectorAll('#sidebarToggleBtn, #sidebarToggleDesktop, #sidebarToggle, .orlms-collapse-toggle, .topbar-menu-button');
  toggleBtns.forEach(function (btn) {
    btn.onclick = function (e) {
      window.lphToggleSidebar(e);
      return false;
    };
  });

  const backdrop = document.getElementById('orlmsSidebarBackdrop') || document.getElementById('sidebarBackdrop');
  if (backdrop) {
    backdrop.addEventListener('click', function () {
      window.lphCloseSidebarMobile();
    });
  }

  // Dismiss mobile drawer on Escape key
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && window.innerWidth <= 1050) {
      window.lphCloseSidebarMobile();
    }
  });

  // Automatically close mobile drawer when resizing back to desktop
  window.addEventListener('resize', function () {
    if (window.innerWidth > 1050) {
      window.lphCloseSidebarMobile();
    }
  });

  /* ---------- Generic "confirm delete" wiring ----------
     Any element with [data-confirm-delete] and [data-delete-url]
     will show a SweetAlert2 confirmation, then POST to the URL
     (with CSRF token) via fetch and reload/redirect on success.

     Uses event delegation on document so it keeps working for rows
     injected later by AJAX (e.g. module live-search table refreshes)
     without needing to be re-bound, and without double-binding.
     Modules that want a custom post-delete action (e.g. reload just
     the table instead of the whole page) can call
     window.registerDeleteHandler(fn) to override the default reload.
  ------------------------------------------------------------- */
  let onDeleteSuccess = function () { window.location.reload(); };
  window.registerDeleteHandler = function (fn) { onDeleteSuccess = fn; };

  document.addEventListener('click', function (e) {
    const el = e.target.closest('[data-confirm-delete]');
    if (!el) return;
    e.preventDefault();

    const url = el.getAttribute('data-delete-url');
    const label = el.getAttribute('data-confirm-delete') || 'this record';
    const csrfToken = window.APP_CSRF_TOKEN || '';

    // Save activity location immediately on the row being deleted
    window.lphSaveActivity(el);

    Swal.fire({
      title: 'Are you sure?',
      text: 'This will permanently delete ' + label + '. This action cannot be undone.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#a4302a',
      cancelButtonColor: '#6c757d',
      confirmButtonText: 'Yes, delete it'
    }).then(function (result) {
      if (!result.isConfirmed) return;

      // Re-save right before deleting
      window.lphSaveActivity(el);

      appFetchJson(url, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'csrf_token=' + encodeURIComponent(csrfToken)
      }).then(function (data) {
        if (data.success) {
          appToast('success', data.message || 'Deleted successfully.');
          onDeleteSuccess();
        } else if (!data.session_expired) {
          Swal.fire('Error', data.message || 'Unable to delete this record.', 'error');
        }
      });
    });
  });


  /* ---------- Default DataTables initialization for tables marked .data-table ---------- */
  if (window.jQuery && jQuery.fn.DataTable) {
    jQuery('.data-table').each(function () {
      if (!jQuery.fn.DataTable.isDataTable(this)) {
        jQuery(this).DataTable({
          pageLength: 10,
          responsive: true,
          language: { search: '', searchPlaceholder: 'Search...' }
        });
      }
    });
  }
});

/**
 * ------------------------------------------------------------------
 * Robust AJAX helpers (network-error root-cause fixes, client side)
 * ------------------------------------------------------------------
 * Every helper below ALWAYS resolves (never rejects) with a normalized
 * {success, message, ...} object. This means every existing call site
 * across the app that does `.then(data => { if (data.success) ... else
 * Swal.fire('Error', data.message...) })` automatically gets accurate,
 * specific error messages instead of a generic "network error" — without
 * needing to change each module file individually.
 *
 * The three failure cases are now distinguished instead of collapsed into
 * one generic message:
 *   1. Request never reached the server (offline, server down, wrong URL)
 *      -> "Could not reach the server..." (a genuine network problem)
 *   2. Server responded but not with valid JSON (a PHP fatal error/warning
 *      leaked HTML into the response — should no longer happen after the
 *      server-side fixes, but if it ever does, this says so explicitly)
 *   3. Server responded with valid JSON but success:false (validation
 *      error, permission denied, etc.) -> shows the server's real message
 *
 * If the server reports the session expired, the user is notified and
 * redirected to the login page automatically instead of the form just
 * silently failing.
 * ------------------------------------------------------------------ */

function appFetchJson(url, options) {
  return fetch(url, options)
    .then(function (response) {
      return response.text().then(function (text) {
        let data;
        try {
          data = text ? JSON.parse(text) : {};
        } catch (parseErr) {
          // The server responded, but the body wasn't valid JSON — most
          // likely an HTML error page. Surface that clearly rather than
          // pretending it was a network failure.
          console.error('appFetchJson: non-JSON response from', url, text.substring(0, 500));
          return {
            success: false,
            message: response.ok
              ? 'The server sent back an unexpected response. Please refresh the page and try again.'
              : 'Server error (HTTP ' + response.status + '). Please try again or contact your administrator.',
          };
        }
        if (!response.ok && data.message === undefined) {
          data.message = 'Server error (HTTP ' + response.status + ').';
        }
        return data;
      });
    })
    .then(function (data) {
      if (data && data.session_expired) {
        Swal.fire({
          icon: 'warning',
          title: 'Session Expired',
          text: data.message || 'Your session has expired. Please log in again.',
          confirmButtonText: 'Go to Login'
        }).then(function () {
          window.location.href = window.APP_URL + '/login.php';
        });
      }
      return data;
    })
    .catch(function (err) {
      // A true network-level failure: the request never got a response at
      // all (server unreachable, DNS failure, connection refused, etc.)
      console.error('appFetchJson: network failure for', url, err);
      return {
        success: false,
        message: 'Could not reach the server. Please check that your local server (XAMPP/Apache/MySQL) is running, then try again.',
      };
    });
}

/**
 * POST a plain object as application/x-www-form-urlencoded.
 * Always resolves; never rejects (see appFetchJson above).
 * @param {string} url
 * @param {Object} data
 * @returns {Promise<Object>}
 */
function appPost(url, data) {
  try {
    if (window.lphSaveActivity) {
      window.lphSaveActivity(document.activeElement);
    }
  } catch (e) {}

  const params = new URLSearchParams(data);
  return appFetchJson(url, {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
    body: params.toString()
  });
}

/**
 * POST a <form> element as multipart/form-data (required whenever the form
 * includes a file input, e.g. document/CSV uploads). Always resolves;
 * never rejects.
 * @param {string} url
 * @param {HTMLFormElement} formElement
 * @returns {Promise<Object>}
 */
function appPostForm(url, formElement) {
  try {
    if (window.lphSaveActivity) {
      window.lphSaveActivity(formElement);
    }
  } catch (e) {}

  return appFetchJson(url, {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: new FormData(formElement)
  });
}

/**
 * GET a URL and parse JSON, with the same robust error handling as
 * appPost(). Used by every module's live-search/table-refresh calls.
 * @param {string} url
 * @returns {Promise<Object>}
 */
function appGet(url) {
  return appFetchJson(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
}

/** Small toast helper reusable by module scripts. */
function appToast(icon, message) {
  Swal.fire({ icon, title: message, toast: true, position: 'top-end', showConfirmButton: false, timer: 3000 });
}

/* ==================================================================
 * Global Auto-Set & Close for Time Pickers (AM / PM Selection)
 * ------------------------------------------------------------------
 * When setting hearing time, clicking AM or PM in the native Chromium /
 * Edge time picker or clicking quick AM/PM buttons automatically commits
 * the time value and immediately closes the picker popup.
 * ================================================================== */
(function() {
  const timeInputState = new WeakMap();

  document.addEventListener('focusin', function(e) {
    if (e.target && e.target.matches('input[type="time"]')) {
      timeInputState.set(e.target, {
        initialVal: e.target.value,
        lastVal: e.target.value,
        wasEmpty: !e.target.value
      });
    }
  }, true);

  document.addEventListener('input', function(e) {
    const el = e.target;
    if (!el || !el.matches('input[type="time"]')) return;

    const state = timeInputState.get(el) || { initialVal: '', lastVal: '', wasEmpty: !el.value };
    const curVal = el.value;

    // Check if AM or PM was clicked:
    // 1. Started empty (--:-- --) and now has full value (e.g. 10:49) -> AM or PM was chosen to complete
    const justCompleted = state.wasEmpty && curVal && curVal.length >= 5;

    // 2. Or hour shifted by 12 hours (e.g. 09:00 -> 21:00 or 14:30 -> 02:30), meaning AM/PM was toggled
    let amPmToggled = false;
    if (state.lastVal && curVal && state.lastVal.includes(':') && curVal.includes(':')) {
      const [oldH, oldM] = state.lastVal.split(':').map(Number);
      const [newH, newM] = curVal.split(':').map(Number);
      if (oldM === newM && Math.abs(newH - oldH) === 12) {
        amPmToggled = true;
      }
    }

    state.lastVal = curVal;
    if (curVal) state.wasEmpty = false;
    timeInputState.set(el, state);

    if (justCompleted || amPmToggled) {
      setTimeout(() => {
        el.blur();
        el.dispatchEvent(new Event('change', { bubbles: true }));
      }, 70);
    }
  }, true);

  // Quick AM / PM button handler for any button with class .btn-ampm-quick
  document.addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-ampm-quick');
    if (!btn) return;
    e.preventDefault();

    const targetId = btn.dataset.target;
    const input = targetId ? document.getElementById(targetId) : btn.closest('.input-group')?.querySelector('input[type="time"]');
    if (!input) return;

    const requestedAmPm = btn.dataset.ampm; // 'AM' or 'PM'
    let curVal = input.value;
    if (!curVal) {
      curVal = requestedAmPm === 'PM' ? '13:00' : '09:00';
    }

    const [h, m] = curVal.split(':').map(Number);
    let newH = isNaN(h) ? 9 : h;
    const minutes = isNaN(m) ? 0 : m;

    if (requestedAmPm === 'AM' && newH >= 12) {
      newH = newH === 12 ? 0 : newH - 12;
    } else if (requestedAmPm === 'PM' && newH < 12) {
      newH = newH === 0 ? 12 : newH + 12;
    }

    const formattedTime = String(newH).padStart(2, '0') + ':' + String(minutes).padStart(2, '0');
    input.value = formattedTime;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
    input.blur();

    // Visual feedback on the clicked button
    const siblings = btn.parentElement?.querySelectorAll('.btn-ampm-quick');
    if (siblings) {
      siblings.forEach(s => s.classList.remove('active', 'btn-primary', 'text-white'));
      siblings.forEach(s => s.classList.add('btn-outline-secondary'));
    }
    btn.classList.remove('btn-outline-secondary');
    btn.classList.add('active', 'btn-primary', 'text-white');
  });
})();
