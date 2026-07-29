/**
 * assets/js/ai-analytics.js
 * ------------------------------------------------------------------
 * Powers modules/feedback/ai_analytics.php: sentiment distribution pie
 * chart, top-topics bar chart, and a period-switchable trend line chart.
 * ------------------------------------------------------------------
 */

(function () {
  const totals = window.SENTIMENT_TOTALS || { Positive: 0, Neutral: 0, Negative: 0 };
  const topicLabels = window.TOPIC_LABELS || [];
  const topicCounts = window.TOPIC_COUNTS || [];

  const pieEl = document.getElementById('pieChart');
  if (pieEl) {
    new Chart(pieEl, {
      type: 'pie',
      data: {
        labels: ['🟢 Positive', '🟡 Neutral', '🔴 Negative'],
        datasets: [{
          data: [totals.Positive || 0, totals.Neutral || 0, totals.Negative || 0],
          backgroundColor: ['#157a6e', '#b5750f', '#a4302a']
        }]
      },
      options: { plugins: { legend: { position: 'bottom' } } }
    });
  }

  const topicsEl = document.getElementById('topicsChart');
  if (topicsEl) {
    new Chart(topicsEl, {
      type: 'bar',
      data: {
        labels: topicLabels,
        datasets: [{ label: 'Mentions', data: topicCounts, backgroundColor: '#1d6fb8' }]
      },
      options: {
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, ticks: { precision: 0 } } }
      }
    });
  }

  /* ---------------- Trend chart (period-switchable) ---------------- */
  const trendEl = document.getElementById('trendChart');
  if (!trendEl) return;

  let trendChart = null;

  function loadTrend(period) {
    appGet(window.APP_URL + '/modules/feedback/ajax_ai_trend.php?period=' + period).then(data => {
      if (!data.success) return;
      const datasets = [
        { label: '🟢 Positive', data: data.positive, borderColor: '#157a6e', backgroundColor: 'rgba(21,122,110,0.1)', tension: 0.3 },
        { label: '🟡 Neutral', data: data.neutral, borderColor: '#b5750f', backgroundColor: 'rgba(181,117,15,0.1)', tension: 0.3 },
        { label: '🔴 Negative', data: data.negative, borderColor: '#a4302a', backgroundColor: 'rgba(164,48,42,0.1)', tension: 0.3 },
      ];

      if (trendChart) {
        trendChart.data.labels = data.labels;
        trendChart.data.datasets = datasets;
        trendChart.update();
      } else {
        trendChart = new Chart(trendEl, {
          type: 'line',
          data: { labels: data.labels, datasets },
          options: {
            plugins: { legend: { position: 'bottom' } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
          }
        });
      }
    });
  }

  document.querySelectorAll('#trendPeriodToggle button').forEach(btn => {
    btn.addEventListener('click', function () {
      document.querySelectorAll('#trendPeriodToggle button').forEach(b => b.classList.remove('active'));
      this.classList.add('active');
      loadTrend(this.getAttribute('data-period'));
    });
  });

  loadTrend('day');
})();
