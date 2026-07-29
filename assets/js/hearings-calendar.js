/**
 * assets/js/hearings-calendar.js
 * ------------------------------------------------------------------
 * Renders a simple month-grid calendar for modules/hearings/calendar.php,
 * fetching events from ajax_calendar_events.php for the visible month.
 * ------------------------------------------------------------------
 */

(function () {
  const grid = document.getElementById('calendarGrid');
  const label = document.getElementById('calMonthLabel');
  if (!grid) return;

  const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  const statusColor = { Upcoming: '#0b3d6e', Ongoing: '#b5750f', Completed: '#157a6e', Cancelled: '#a4302a' };

  const today = new Date();
  let viewYear = today.getFullYear();
  let viewMonth = today.getMonth() + 1; // 1-12

  function load() {
    label.textContent = monthNames[viewMonth - 1] + ' ' + viewYear;
    appGet(window.APP_URL + '/modules/hearings/ajax_calendar_events.php?year=' + viewYear + '&month=' + viewMonth)
      .then(data => render(data.success ? data.events : []));
  }

  function render(events) {
    const firstDay = new Date(viewYear, viewMonth - 1, 1);
    const startOffset = firstDay.getDay(); // 0=Sun
    const daysInMonth = new Date(viewYear, viewMonth, 0).getDate();

    const eventsByDay = {};
    events.forEach(ev => {
      const day = parseInt(ev.hearing_date.split('-')[2], 10);
      (eventsByDay[day] = eventsByDay[day] || []).push(ev);
    });

    let html = '';
    ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(d => { html += `<div class="cal-head">${d}</div>`; });

    for (let i = 0; i < startOffset; i++) html += '<div class="cal-day empty"></div>';

    const isCurrentMonth = viewYear === today.getFullYear() && viewMonth === today.getMonth() + 1;

    for (let day = 1; day <= daysInMonth; day++) {
      const isToday = isCurrentMonth && day === today.getDate();
      html += `<div class="cal-day${isToday ? ' today' : ''}"><div class="day-num">${day}</div>`;
      (eventsByDay[day] || []).forEach(ev => {
        const color = statusColor[ev.status] || '#6c757d';
        const title = ev.title.replace(/"/g, '&quot;');
        html += `<div class="cal-event" style="background:${color}" title="${title} — ${ev.hearing_time}"
                    onclick="window.location.href='${window.APP_URL}/modules/hearings/view.php?id=${ev.id}'">${title}</div>`;
      });
      html += '</div>';
    }

    grid.innerHTML = html;
  }

  document.getElementById('prevMonth').addEventListener('click', function () {
    viewMonth--; if (viewMonth < 1) { viewMonth = 12; viewYear--; }
    load();
  });
  document.getElementById('nextMonth').addEventListener('click', function () {
    viewMonth++; if (viewMonth > 12) { viewMonth = 1; viewYear++; }
    load();
  });

  load();
})();
