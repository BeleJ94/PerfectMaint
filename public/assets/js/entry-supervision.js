/*
 * Supervision des index pour l'encodeur (/saisie/supervision) : la lecture de l'écran mural en cartes,
 * une colonne sur téléphone, une grille sur ordinateur. Indicateurs cliquables pour filtrer, recherche,
 * actualisation chaque minute, fenêtre de détail avec courbe (relevés, projection, seuils).
 * Chaque engin est lu dans son compteur de suivi (heures ou km, selon sa catégorie).
 */
(function ($, Core) {
  'use strict';

  var el = document.getElementById('sup-data');
  if (!el) return;
  var boot = JSON.parse(el.textContent);
  var t = window.PM.t;
  var locale = window.PM.locale;
  var esc = Core.escapeHtml;
  var urls = boot.urls;
  var REFRESH_MS = 60000;
  var DAY = 86400000;
  var PERIODS = [30, 90, 365, 548];

  var LINE = '#1F4E79';
  var MUTED = '#5B6573';
  var GRID = '#EEF0F3';
  var STATUS = {
    due: { badge: 'breakdown', bar: '', color: '#B42318' },
    soon: { badge: 'waiting_part', bar: ' life__bar--warn', color: '#B54708' },
    ok: { badge: 'available', bar: ' life__bar--good', color: '#1E7A4C' },
    none: { badge: 'neutral', bar: '', color: MUTED }
  };
  var FILTERS = {
    all: function () { return true; },
    due: function (row) { return row.status === 'due'; },
    soon: function (row) { return row.status === 'soon'; },
    ok: function (row) { return row.status === 'ok'; },
    unread: function (row) { return row.days_since !== 0; },
    stale: function (row) { return row.stale; }
  };

  var state = { rows: boot.overview.rows, summary: boot.overview.summary, filter: 'all', search: '', loadedAt: Date.now(), failed: false,
    detail: { id: null, days: 30, metric: null, data: null, chart: null } };

  function num(value, decimals) { return Core.formatNumber(value, locale, decimals); }
  function icons() { if (window.lucide) window.lucide.createIcons(); }
  function pad(n) { return n < 10 ? '0' + n : String(n); }
  function clock(ms) { var d = new Date(ms); return pad(d.getHours()) + ':' + pad(d.getMinutes()); }
  function ts(value) {
    var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(String(value));
    return m ? new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0)).getTime() : NaN;
  }
  function unitOf(metric) { return metric === 'odometer_km' ? 'km' : 'h'; }
  function rateText(rate, unit) { return num(rate, unit === 'h' ? 1 : 0) + ' ' + unit + t('js.meters.per_day_short'); }
  function age(row) {
    if (row.days_since === null) return t('js.meters.never');
    return row.days_since === 0 ? t('js.meters.today') : t('js.meters.days_ago', { n: row.days_since });
  }
  function remainingText(plan) {
    if (plan.remaining < 0) return t('js.meters.over', { n: num(-plan.remaining, 0), unit: plan.unit });
    if (plan.remaining === 0) return t('js.meters.reached');
    return num(plan.remaining, 0) + ' ' + plan.unit;
  }
  function whenText(plan) {
    if (plan.remaining <= 0) return t('js.meters.at_hours', { n: num(plan.next_due, 0), unit: plan.unit });
    return plan.due_on === null ? t('js.meters.no_date') : Core.formatDate(plan.due_on, locale) + ' · ' + t('js.meters.in_days', { n: plan.days_left });
  }
  function bar(plan, status) {
    if (!plan || plan.pct === null) return '';
    return '<div class="life"><span class="life__bar' + STATUS[status].bar + '"><span style="width:' + Math.min(100, plan.pct) + '%"></span></span><strong>' + plan.pct + ' %</strong></div>';
  }

  // ---------------------------------------------------------------- Liste
  function cardHtml(row) {
    var plan = row.plans[0];
    var index = row.current === null ? '—' : esc(num(row.current, 0)) + ' ' + esc(row.unit);
    var html = '<article class="sup-card sup-card--' + row.status + '" data-sup-open="' + row.id + '" role="button" tabindex="0">'
      + '<header class="sup-card__head"><div><strong>' + esc(row.code) + '</strong><span class="muted small">' + esc(row.name) + ' · ' + esc(row.site) + '</span></div>'
      + '<span class="badge badge--' + STATUS[row.status].badge + '">' + esc(t('meter_status.' + row.status)) + '</span></header>'
      + '<p class="sup-card__index"><span><strong>' + index + '</strong>' + (row.rate === null ? '' : ' <span class="muted small">· ' + esc(rateText(row.rate, row.unit)) + '</span>') + '</span>'
      + '<span class="' + (row.stale ? 'badge badge--breakdown' : 'muted small') + '">' + esc(age(row)) + '</span></p>';
    if (!plan) return html + '<p class="muted small sup-card__plan">' + esc(t('js.meters.no_plan')) + '</p></article>';
    return html + '<div class="sup-card__plan"><div class="sup-card__plan-title"><span>' + esc(plan.title)
      + (row.plans.length > 1 ? ' <span class="muted small">' + esc(t('js.meters.more_plans', { n: row.plans.length - 1 })) + '</span>' : '')
      + (plan.open_work_order_id ? ' <span class="muted small">· ' + esc(t('js.planned')) + '</span>' : '') + '</span></div>'
      + bar(plan, plan.status)
      + '<div class="sup-card__due"><strong class="' + (plan.remaining <= 0 ? 'overdue' : '') + '">' + esc(remainingText(plan)) + '</strong>'
      + '<span class="muted small">' + esc(whenText(plan)) + '</span></div></div></article>';
  }

  function visibleRows() {
    var q = state.search.toLowerCase();
    return state.rows.filter(FILTERS[state.filter]).filter(function (row) {
      return !q || (row.code + ' ' + row.name + ' ' + row.site).toLowerCase().indexOf(q) !== -1;
    });
  }

  function render() {
    var s = state.summary;
    var counts = { due: s.due, soon: s.soon, ok: state.rows.filter(FILTERS.ok).length, unread: s.equipment - s.read_today, stale: s.stale };
    Object.keys(counts).forEach(function (key) {
      $('[data-sup-value="' + key + '"]').text(counts[key]);
      $('[data-sup-kpi="' + key + '"]').toggleClass('is-hot', counts[key] > 0)
        .attr('aria-pressed', state.filter === key ? 'true' : 'false');
    });
    var rows = visibleRows();
    var $kpi = $('[data-sup-kpi="' + state.filter + '"]');
    $('[data-sup-title]').text(state.filter === 'all' ? t('js.sup.all') : $kpi.find('.sup-kpi__label').text());
    $('[data-sup-count]').text(rows.length);
    $('[data-sup-list]').html(rows.map(cardHtml).join(''));
    $('[data-sup-empty]').prop('hidden', rows.length > 0);
    renderStatus();
  }

  function renderStatus() {
    var late = state.failed || Date.now() - state.loadedAt > 3 * REFRESH_MS;
    $('[data-sup-status]').toggleClass('is-offline', late)
      .text(t(late ? 'js.sup.offline' : 'js.sup.updated', { time: clock(state.loadedAt) }));
  }

  function refresh() {
    return $.getJSON(urls.data).then(function (json) {
      state.rows = json.rows;
      state.summary = json.summary;
      state.loadedAt = Date.now();
      state.failed = false;
      render();
    }, function () {
      state.failed = true;
      renderStatus();
    });
  }

  $(document).on('click', '[data-sup-kpi]', function () {
    var key = this.dataset.supKpi;
    state.filter = state.filter === key ? 'all' : key; // second appui : retour à tous les engins
    render();
  });
  $(document).on('input', '[data-sup-search]', function () { state.search = this.value.trim(); render(); });

  // ---------------------------------------------------------------- Détail d'un engin
  function vital(label, value, hint) {
    return '<div class="sup-vital"><span>' + esc(label) + '</span><strong>' + value + '</strong>' + (hint ? '<small>' + esc(hint) + '</small>' : '') + '</div>';
  }

  function detailHtml(d) {
    var metric = state.detail.metric;
    var unit = unitOf(metric);
    var rate = metric === 'odometer_km' ? d.rate_km : d.rate_hours;
    var plans = d.plans.map(function (p) {
      return '<li class="sup-plan sup-plan--' + p.status + '"><div><strong>' + esc(p.title) + '</strong>'
        + '<span class="muted small">' + esc(t('js.wall.threshold', { n: num(p.next_due, 0), unit: p.unit })) + (p.open_work_order_id ? ' · ' + esc(t('js.planned')) : '') + '</span></div>'
        + bar(p, p.status)
        + '<div class="sup-card__due"><strong class="' + (p.remaining <= 0 ? 'overdue' : '') + '">' + esc(remainingText(p)) + '</strong><span class="muted small">' + esc(whenText(p)) + '</span></div></li>';
    }).join('');
    var both = d.hour_meter !== null && d.odometer_km !== null;
    var readings = d.series.slice(-8).reverse().map(function (r) {
      return '<tr><td>' + esc(Core.formatDate(r.at, locale, true)) + '</td><td class="num">' + (r.hour_meter === null ? '—' : esc(num(r.hour_meter, 0)) + ' h')
        + '</td><td class="num">' + (r.odometer_km === null ? '—' : esc(num(r.odometer_km, 0)) + ' km') + '</td></tr>';
    }).join('');

    return '<div class="sup-detail">'
      + '<header class="sup-detail__head"><div><h2>' + esc(d.code) + '</h2><p class="muted">' + esc(d.name) + ' · ' + esc(d.site) + '</p></div>'
      + '<span class="badge badge--' + STATUS[d.status].badge + '">' + esc(t('meter_status.' + d.status)) + '</span></header>'
      + '<div class="sup-vitals">'
      + vital(t('fields.' + metric), d[metric] === null ? '—' : esc(num(d[metric], 0)) + ' ' + unit)
      + vital(t('js.meters.rate'), rate === null ? '—' : esc(rateText(rate, unit)), rate === null ? t('js.wall.no_rate') : '')
      + vital(t('js.wall.last_reading'), d.last_reading_at === null ? '—' : esc(Core.formatDate(d.last_reading_at, locale, true)), age(d))
      + '</div>'
      + '<h3>' + esc(t('js.wall.plans')) + '</h3>'
      + (plans ? '<ul class="sup-plans">' + plans + '</ul>' : '<p class="muted">' + esc(t('js.meters.no_plan')) + '</p>')
      + '<div class="sup-detail__bar"><h3>' + esc(t('js.wall.trend')) + '</h3><div class="chips">'
      + PERIODS.map(function (n) {
        return '<button type="button" class="chip-btn" data-sup-days="' + n + '" aria-pressed="' + (n === state.detail.days) + '">' + esc(n === 548 ? t('js.wall.months18') : t('js.days', { n: n })) + '</button>';
      }).join('')
      + (both ? ['hour_meter', 'odometer_km'].map(function (m) {
        return '<button type="button" class="chip-btn" data-sup-metric="' + m + '" aria-pressed="' + (m === metric) + '">' + esc(unitOf(m)) + '</button>';
      }).join('') : '')
      + '</div></div>'
      + '<div class="chart-box chart-box--sm"><canvas id="sup-chart" role="img" aria-label="' + esc(t('js.wall.trend')) + '"></canvas></div>'
      + '<p class="muted" data-sup-chart-empty hidden>' + esc(t('js.wall.no_reading')) + '</p>'
      + '<h3>' + esc(t('js.wall.recent')) + '</h3>'
      + (readings ? '<div class="table-wrap"><table class="table table--compact" data-no-dt><thead><tr><th>' + esc(t('js.date')) + '</th><th class="num">'
        + esc(t('fields.hour_meter')) + '</th><th class="num">' + esc(t('fields.odometer_km')) + '</th></tr></thead><tbody>' + readings + '</tbody></table></div>'
        : '<p class="muted">' + esc(t('js.wall.no_reading')) + '</p>')
      + '<a class="btn btn--primary btn--block" href="' + esc(urls.entry) + '"><i data-lucide="pencil"></i>' + esc(t('js.meters.new_reading')) + '</a>'
      + '</div>';
  }

  function drawChart(d) {
    var det = state.detail;
    if (det.chart) { det.chart.destroy(); det.chart = null; }
    var metric = det.metric;
    var unit = unitOf(metric);
    var rate = metric === 'odometer_km' ? d.rate_km : d.rate_hours;
    var points = [];
    d.series.forEach(function (r, i) { if (r[metric] !== null) points.push({ x: ts(r.at), y: r[metric], i: i }); });
    $('[data-sup-chart-empty]').prop('hidden', points.length > 0);
    $('#sup-chart').parent().prop('hidden', points.length === 0);
    if (!points.length || !window.Chart) return;

    var plans = d.plans.filter(function (p) { return p.metric === metric; });
    var plan = plans[0];
    var last = points[points.length - 1];
    var datasets = [{ label: t('js.meters.readings'), data: points, borderColor: LINE, backgroundColor: LINE, borderWidth: 2,
      pointRadius: points.length <= 45 ? 3 : (points.length <= 120 ? 2.2 : 1.4), pointHoverRadius: 5, tension: 0 }];
    var end = last.x;
    if (rate > 0) {
      var horizon = plan && plan.remaining > 0 && plan.days_left !== null ? Math.min(30, Math.max(plan.days_left, 1)) : 14;
      end = last.x + horizon * DAY;
      datasets.push({ label: t('js.meters.projection'), data: [last, { x: end, y: Math.round(last.y + rate * horizon) }],
        borderColor: LINE, backgroundColor: '#fff', borderWidth: 2, borderDash: [5, 5], pointRadius: [0, 4], tension: 0 });
    }
    var xMin = Math.min(points[0].x, Date.now() - d.days * DAY);
    var xMax = Math.max(end, Date.now());
    if (plan) {
      datasets.push({ label: t('js.wall.threshold', { n: num(plan.next_due, 0), unit: unit }), data: [{ x: xMin, y: plan.next_due }, { x: xMax, y: plan.next_due }],
        borderColor: STATUS[plan.status].color, borderWidth: 2, borderDash: [4, 4], pointRadius: 0 });
    }
    var readingClick = window.PMReading.chartOptions(function () { return { equipment: d, series: d.series }; });

    det.chart = new Chart(document.getElementById('sup-chart'), {
      type: 'line',
      data: { datasets: datasets },
      options: {
        maintainAspectRatio: false, animation: false, parsing: false,
        onClick: readingClick.onClick, onHover: readingClick.onHover,
        interaction: { mode: 'nearest', axis: 'x', intersect: false },
        scales: {
          x: { type: 'linear', min: xMin, max: xMax, grid: { display: false }, ticks: { maxTicksLimit: 5, callback: function (value) {
            var day = new Date(value);
            return d.days > 180 ? pad(day.getMonth() + 1) + '/' + String(day.getFullYear()).slice(2) : pad(day.getDate()) + '/' + pad(day.getMonth() + 1);
          } } },
          y: { grid: { color: GRID }, border: { display: false }, ticks: { precision: 0, maxTicksLimit: 6, callback: function (value) { return num(value, 0) + ' ' + unit; } } }
        },
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 22, boxHeight: 0 } },
          tooltip: { callbacks: {
            title: function (items) { var day = new Date(items[0].parsed.x); return pad(day.getDate()) + '/' + pad(day.getMonth() + 1) + '/' + day.getFullYear(); },
            label: function (item) { return item.dataset.label + ' : ' + num(item.parsed.y, 0) + ' ' + unit; }
          } }
        }
      }
    });
  }

  function renderDetail() {
    var d = state.detail.data;
    if (!d) return;
    if (state.detail.chart) { state.detail.chart.destroy(); state.detail.chart = null; }
    $('[data-sup-detail]').html(detailHtml(d));
    icons();
    drawChart(d);
  }

  function loadDetail() {
    var det = state.detail;
    var id = det.id;
    $.getJSON(Core.fillUrl(urls.series, { id: id }), { days: det.days }).then(function (d) {
      if (det.id !== id) return; // fenêtre fermée ou autre engin entre-temps
      det.data = d;
      if (det.metric === null) det.metric = d.metric;
      renderDetail();
    });
  }

  function openDetail(id) {
    state.detail = { id: id, days: 30, metric: null, data: null, chart: null };
    Swal.fire({
      html: '<div data-sup-detail><p class="muted">' + esc(t('js.dt.loading')) + '</p></div>',
      width: 'min(760px, 100vw)',
      showConfirmButton: false,
      showCloseButton: true,
      customClass: { popup: 'sup-popup' },
      didOpen: loadDetail,
      willClose: function () {
        if (state.detail.chart) { state.detail.chart.destroy(); }
        state.detail = { id: null, days: 30, metric: null, data: null, chart: null };
      }
    });
  }

  $(document).on('click', '[data-sup-open]', function () { openDetail(Number(this.dataset.supOpen)); });
  $(document).on('keydown', '[data-sup-open]', function (e) {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openDetail(Number(this.dataset.supOpen)); }
  });
  $(document).on('click', '[data-sup-days]', function () { state.detail.days = Number(this.dataset.supDays); loadDetail(); });
  $(document).on('click', '[data-sup-metric]', function () { state.detail.metric = this.dataset.supMetric; renderDetail(); });

  render();
  icons();
  // Actualisation automatique, suspendue onglet masqué ou fenêtre de détail ouverte.
  setInterval(function () {
    if (document.hidden || Swal.isVisible()) { renderStatus(); return; }
    refresh();
  }, REFRESH_MS);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
})(jQuery, window.PMCore);
