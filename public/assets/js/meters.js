/*
 * Supervision des index : tableau des échéances au compteur (actualisé chaque minute),
 * saisie rapide d'un relevé et courbe d'évolution par engin.
 * Courbe : une seule mesure à la fois (heures OU km, jamais deux axes), relevés en trait plein,
 * projection au rythme moyen en pointillés, seuils de maintenance en lignes horizontales libellées.
 */
(function ($, Core) {
  'use strict';

  var el = document.getElementById('meters-data');
  if (!el) return;
  var boot = JSON.parse(el.textContent);
  var t = window.PM.t;
  var locale = window.PM.locale;
  var esc = Core.escapeHtml;
  var urls = boot.urls;
  var REFRESH_MS = 60000;
  var DAY = 86400000;

  var INK = '#1B2430';
  var MUTED = '#5B6573';
  var GRID = '#EEF0F3';
  var SURFACE = '#FFFFFF';
  var LINE = '#1F4E79';
  var STATUS = {
    due: { badge: 'breakdown', bar: '', color: '#B42318' },
    soon: { badge: 'waiting_part', bar: ' life__bar--warn', color: '#B54708' },
    ok: { badge: 'available', bar: ' life__bar--good', color: '#1E7A4C' },
    none: { badge: 'neutral', bar: '', color: MUTED }
  };

  var state = { rows: boot.overview.rows, filter: 'all', selected: null, days: 30, metric: 'hour_meter', detail: null, chart: null };

  function num(value, decimals) { return Core.formatNumber(value, locale, decimals); }
  function icons() { if (window.lucide) window.lucide.createIcons(); }

  /** Date SQL locale (« AAAA-MM-JJ HH:MM:SS ») → horodatage, sans conversion de fuseau. */
  function ts(value) {
    var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(String(value));
    return m ? new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0)).getTime() : NaN;
  }
  function sqlDate(ms) {
    var d = new Date(ms);
    var p = function (n) { return n < 10 ? '0' + n : String(n); };
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
  }

  // ---------------------------------------------------------------- Tableau (DataTables, données côté client)
  var FILTERS = {
    all: function () { return true; },
    due: function (row) { return row.status === 'due'; },
    soon: function (row) { return row.status === 'soon'; },
    unread: function (row) { return row.days_since !== 0; },
    stale: function (row) { return row.stale; }
  };
  var RANK = { due: 0, soon: 1, ok: 2, none: 3 };
  var LAST = Number.MAX_SAFE_INTEGER; // valeur absente : en fin de tri

  function readingCell(row) {
    if (row.last_reading_at === null) return '<span class="badge badge--breakdown">' + esc(t('js.meters.never')) + '</span>';
    var age = row.days_since === 0 ? t('js.meters.today') : t('js.meters.days_ago', { n: row.days_since });
    return '<span class="nowrap" title="' + esc(Core.formatDate(row.last_reading_at, locale, true)) + '">' + esc(Core.formatDate(row.last_reading_at, locale)) + '</span><br>'
      + (row.stale ? '<span class="badge badge--breakdown">' + esc(age) + '</span>' : '<span class="muted small">' + esc(age) + '</span>');
  }

  /** Reste avant le seuil, puis date prévue au rythme actuel. */
  function dueCell(plan) {
    if (plan.remaining < 0) return '<span class="overdue">' + esc(t('js.meters.over', { n: num(-plan.remaining, 0) })) + '</span>';
    if (plan.remaining === 0) return '<span class="overdue">' + esc(t('js.meters.reached')) + '</span>';
    var remaining = '<strong>' + esc(num(plan.remaining, 0)) + ' h</strong>';
    if (plan.due_on === null) return remaining + '<br><span class="muted small" title="' + esc(t('js.meters.no_rate_hint')) + '">' + esc(t('js.meters.no_date')) + '</span>';
    return remaining + '<br><span class="muted small">' + esc(Core.formatDate(plan.due_on, locale)) + ' · ' + esc(t('js.meters.in_days', { n: plan.days_left })) + '</span>';
  }

  function planCell(row) {
    var plan = row.plans[0];
    if (!plan) return '<span class="muted">' + esc(t('js.meters.no_plan')) + '</span>';
    var more = row.plans.length > 1 ? ' <span class="muted small">' + esc(t('js.meters.more_plans', { n: row.plans.length - 1 })) + '</span>' : '';
    var link = plan.open_work_order_id ? Core.fillUrl(urls.work_order, { id: plan.open_work_order_id }) : (boot.can_plan ? Core.fillUrl(urls.plan, { id: plan.id }) : null);
    return (link ? '<a href="' + esc(link) + '">' + esc(plan.title) + '</a>' : esc(plan.title)) + more
      + '<br><span class="muted small">' + esc(t('js.meters.at_hours', { n: num(plan.next_due_hours, 0) }))
      + (plan.open_work_order_id ? ' · ' + esc(t('js.planned')) : '') + '</span>';
  }

  function progressBar(plan, status) {
    if (!plan || plan.pct === null) return '<span class="muted">—</span>';
    return '<div class="life"><span class="life__bar' + STATUS[status].bar + '"><span style="width:' + Math.min(100, plan.pct) + '%"></span></span><strong>' + plan.pct + ' %</strong></div>';
  }

  function actionsCell(row) {
    return '<button type="button" class="btn btn--sm" data-meters-chart="' + row.id + '"><i data-lucide="trending-up"></i>' + esc(t('js.meters.evolution')) + '</button>'
      + (boot.can_read ? ' <button type="button" class="btn btn--sm" data-meters-read="' + row.id + '" title="' + esc(t('js.meters.new_reading')) + '" aria-label="' + esc(t('js.meters.new_reading')) + '"><i data-lucide="gauge"></i></button>' : '');
  }

  /** Colonne : rendu HTML à l'affichage, valeur brute pour le tri, texte pour la recherche. */
  function column(display, sort, search, options) {
    return $.extend({
      data: null,
      render: function (row, type) {
        if (type === 'display') return display(row);
        if (type === 'filter') return search ? search(row) : '';
        return sort ? sort(row) : '';
      }
    }, options);
  }

  var table = $('[data-meters-table]').DataTable({
    data: state.rows,
    rowId: function (row) { return 'meter-' + row.id; },
    pageLength: 10,
    lengthMenu: [10, 25, 50, 100],
    layout: { topStart: 'search', topEnd: 'pageLength', bottomStart: 'info', bottomEnd: 'paging' },
    autoWidth: false,
    order: [], // ordre du serveur : le plus urgent d'abord
    language: {
      search: t('js.dt.search'), lengthMenu: t('js.dt.length'), info: t('js.dt.info'), infoEmpty: t('js.dt.info_empty'),
      infoFiltered: t('js.dt.info_filtered'), zeroRecords: t('js.dt.zero'), emptyTable: t('js.dt.empty'),
      paginate: { first: '«', last: '»', next: '›', previous: '‹' }
    },
    columns: [
      column(function (row) {
        return '<button type="button" class="link-btn nowrap" data-meters-chart="' + row.id + '" title="' + esc(t('js.meters.show_evolution')) + '"><strong>' + esc(row.code) + '</strong></button>'
          + '<span class="meters-name muted small">' + esc(row.name) + ' · ' + esc(row.site) + '</span>';
      }, function (row) { return row.code; }, function (row) { return row.code + ' ' + row.name + ' ' + row.site; }),
      // Index horaire et rythme moyen ; le kilométrage est en infobulle et dans la fenêtre de détail.
      column(function (row) {
        var km = row.odometer_km === null ? '' : num(row.odometer_km, 0) + ' km' + (row.rate_km === null ? '' : ' · ' + num(row.rate_km, 0) + ' km' + t('js.meters.per_day_short'));
        return '<span title="' + esc(km) + '">' + (row.hour_meter === null ? '—' : esc(num(row.hour_meter, 0)) + ' h') + '</span><br>'
          + (row.rate_hours === null ? '<span class="muted small" title="' + esc(t('js.meters.no_rate_hint')) + '">—</span>'
            : '<span class="muted small">' + esc(num(row.rate_hours, 1)) + ' ' + esc(t('js.meters.per_day')) + '</span>');
      }, function (row) { return row.hour_meter === null ? -1 : row.hour_meter; }, null, { className: 'dt-right nowrap' }),
      column(readingCell, function (row) { return row.days_since === null ? LAST : row.days_since; }),
      column(planCell, function (row) { return row.plans[0] ? row.plans[0].title : ''; }, function (row) { return row.plans[0] ? row.plans[0].title : ''; }),
      column(function (row) { return progressBar(row.plans[0], row.status); }, function (row) { return row.plans[0] && row.plans[0].pct !== null ? row.plans[0].pct : -1; }),
      column(function (row) { return row.plans[0] ? dueCell(row.plans[0]) : '<span class="muted">—</span>'; },
        function (row) { return row.plans[0] ? row.plans[0].remaining : LAST; }, null, { className: 'nowrap' }),
      column(function (row) { return '<span class="badge badge--' + STATUS[row.status].badge + '">' + esc(t('meter_status.' + row.status)) + '</span>'; },
        function (row) { return RANK[row.status]; }, function (row) { return t('meter_status.' + row.status); }),
      column(actionsCell, null, null, { orderable: false, className: 'nowrap meters-actions' })
    ],
    drawCallback: icons,
    initComplete: function () { $(this.api().table().container()).find('.dt-search input').attr('placeholder', t('js.dt.search_placeholder')); }
  });

  /** Recharge les lignes (filtre rapide appliqué) en conservant page, tri et recherche. */
  function renderTable(resetPage) {
    table.clear().rows.add(state.rows.filter(FILTERS[state.filter])).draw(!!resetPage);
  }

  function renderSummary(summary) {
    Object.keys(summary).forEach(function (key) {
      $('[data-meters-value="' + key + '"]').text(summary[key]);
    });
    $('[data-meters-kpi="due"]').toggleClass('kpi--alert', summary.due > 0);
    $('[data-meters-kpi="soon"]').toggleClass('kpi--warn', summary.soon > 0);
    $('[data-meters-kpi="unread"]').toggleClass('kpi--warn', summary.read_today < summary.equipment);
    $('[data-meters-kpi="stale"]').toggleClass('kpi--alert', summary.stale > 0);
  }

  function refresh() {
    return $.getJSON(urls.data).then(function (json) {
      state.rows = json.rows;
      renderSummary(json.summary);
      renderTable();
      $('[data-meters-updated]').text(Core.formatDate(json.generated_at, locale, true));
    });
  }

  function setFilter(filter) {
    state.filter = filter;
    $('[data-meters-filter]').each(function () { this.setAttribute('aria-pressed', this.dataset.metersFilter === filter ? 'true' : 'false'); });
    renderTable(true);
  }

  $(document).on('click', '[data-meters-filter]', function () { setFilter(this.dataset.metersFilter); });
  $(document).on('click', '[data-meters-kpi]', function () { setFilter(this.dataset.metersKpi); });

  // ---------------------------------------------------------------- Saisie d'un relevé
  $(document).on('click', '[data-meters-read]', function () {
    var id = Number(this.dataset.metersRead);
    var row = state.rows.filter(function (r) { return r.id === id; })[0];
    if (!row) return;
    var field = function (name, label, current) {
      return '<div class="field"><label for="mr-' + name + '">' + esc(label) + '</label>'
        + '<input id="mr-' + name + '" name="' + name + '" type="number" class="input" step="1" inputmode="numeric" min="' + (current === null ? 0 : current) + '" placeholder="' + (current === null ? '' : current) + '"></div>';
    };
    Swal.fire({
      html: '<form class="grid" style="text-align:left"><h3 class="dialog-title"><i data-lucide="gauge"></i>' + esc(t('js.meters.new_reading')) + ' · ' + esc(row.code) + '</h3>'
        + '<div class="form-grid">' + field('hour_meter', t('fields.hour_meter'), row.hour_meter) + field('odometer_km', t('fields.odometer_km'), row.odometer_km) + '</div>'
        + '<div class="field"><label for="mr-note">' + esc(t('fields.note')) + '</label><input id="mr-note" name="note" class="input" maxlength="255"></div></form>',
      showCancelButton: true,
      confirmButtonText: t('js.save'),
      cancelButtonText: t('js.cancel'),
      confirmButtonColor: LINE,
      reverseButtons: true,
      focusConfirm: false,
      showLoaderOnConfirm: true,
      didOpen: function (popup) { icons(); popup.querySelector('input').focus(); },
      preConfirm: function () {
        var form = Swal.getHtmlContainer().querySelector('form');
        if (!form.reportValidity()) return false;
        return $.post(Core.fillUrl(urls.reading, { id: id }), $(form).serialize()).then(null, function (xhr) {
          var json = xhr.responseJSON || {};
          var message = json.errors ? Object.keys(json.errors).map(function (k) { return json.errors[k]; }).join(' ') : (json.error || t('js.error_generic'));
          Swal.showValidationMessage(message);
          return $.Deferred().resolve(false);
        });
      }
    }).then(function (res) {
      if (!res.isConfirmed || !res.value) return;
      refresh();
      if (state.selected === id) loadDetail();
    });
  });

  // ---------------------------------------------------------------- Évolution d'un engin
  /** Seuils affichés : le plus proche toujours, les suivants s'ils n'écrasent pas la courbe. */
  function thresholds(detail, minValue) {
    if (state.metric !== 'hour_meter' || !detail.plans.length) return [];
    var nearest = detail.plans[0].next_due_hours;
    var limit = nearest + Math.max(nearest - minValue, 1);
    return detail.plans.filter(function (p) { return p.next_due_hours <= limit; });
  }

  var thresholdPlugin = {
    id: 'pmThresholds',
    afterDatasetsDraw: function (chart, args, opts) {
      var ctx = chart.ctx;
      var area = chart.chartArea;
      var labelled = []; // positions des libellés déjà tracés : deux seuils proches ne se superposent pas
      (opts.lines || []).forEach(function (line) {
        var y = chart.scales.y.getPixelForValue(line.value);
        if (y < area.top - 1 || y > area.bottom + 1) return;
        var free = labelled.every(function (other) { return Math.abs(other - y) >= 18; });
        if (free) labelled.push(y);
        ctx.save();
        ctx.strokeStyle = line.color;
        ctx.lineWidth = 1.5;
        ctx.setLineDash([6, 4]);
        ctx.beginPath();
        ctx.moveTo(area.left, y);
        ctx.lineTo(area.right, y);
        ctx.stroke();
        ctx.setLineDash([]);
        if (!free) { ctx.restore(); return; }
        ctx.font = '600 11px ' + Chart.defaults.font.family;
        ctx.textBaseline = 'bottom';
        var width = ctx.measureText(line.label).width;
        ctx.fillStyle = SURFACE;
        ctx.fillRect(area.left + 6, y - 16, width + 8, 14);
        ctx.fillStyle = INK;
        ctx.fillText(line.label, area.left + 10, y - 3);
        ctx.restore();
      });
    }
  };

  function vital(icon, label, value, cls) {
    return '<div class="vital"><span class="vital__icon' + (cls ? ' vital__icon--' + cls : '') + '"><i data-lucide="' + icon + '"></i></span>'
      + '<div><div class="vital__label">' + esc(label) + '</div><div class="vital__value">' + value + '</div></div></div>';
  }

  function renderDetail() {
    var d = state.detail;
    if (!d) return;
    var metric = state.metric;
    var unit = metric === 'hour_meter' ? 'h' : 'km';
    var rate = metric === 'hour_meter' ? d.rate_hours : d.rate_km;
    var current = d[metric];
    var plan = d.plans[0];
    var points = d.series.filter(function (r) { return r[metric] !== null; }).map(function (r) { return { x: ts(r.at), y: r[metric] }; });

    var age = d.days_since === null ? t('js.meters.never') : (d.days_since === 0 ? t('js.meters.today') : t('js.meters.days_ago', { n: d.days_since }));
    $('[data-meters-detail-title]').text(d.code);
    $('[data-meters-detail-sub]').text(d.name + ' · ' + d.site);
    $('[data-meters-detail-status]').html('<span class="badge badge--' + STATUS[d.status].badge + '">' + esc(t('meter_status.' + d.status)) + '</span>');
    $('[data-meters-detail-link]').attr('href', Core.fillUrl(urls.equipment, { id: d.id }));
    $('[data-meters-detail-plans]').html(d.plans.length ? d.plans.map(function (p) {
      return '<tr><td><strong>' + esc(p.title) + '</strong>' + (p.open_work_order_id ? ' <span class="muted small">· ' + esc(t('js.planned')) + '</span>' : '') + '</td>'
        + '<td class="num nowrap">' + esc(num(p.next_due_hours, 0)) + ' h</td><td>' + progressBar(p, p.status) + '</td>'
        + '<td class="nowrap">' + dueCell(p) + '</td>'
        + '<td><span class="badge badge--' + STATUS[p.status].badge + '">' + esc(t('meter_status.' + p.status)) + '</span></td></tr>';
    }).join('') : '<tr><td colspan="5" class="muted">' + esc(t('js.meters.no_plan')) + '</td></tr>');
    $('[data-meters-detail-vitals]').html(
      vital(metric === 'hour_meter' ? 'timer' : 'route', t('fields.' + metric), current === null ? '—' : esc(num(current, 0)) + '<small> ' + unit + '</small>')
      + vital('activity', t('js.meters.rate'), rate === null ? '—' : esc(num(rate, 1)) + '<small> ' + unit + esc(t('js.meters.per_day_short')) + '</small>')
      + (metric === 'hour_meter'
        ? vital('route', t('fields.odometer_km'), d.odometer_km === null ? '—' : esc(num(d.odometer_km, 0)) + '<small> km</small>')
        : vital('timer', t('fields.hour_meter'), d.hour_meter === null ? '—' : esc(num(d.hour_meter, 0)) + '<small> h</small>'))
      + vital('gauge', t('js.wall.last_reading') + ' · ' + age, d.last_reading_at === null ? '—' : esc(Core.formatDate(d.last_reading_at, locale)), d.stale ? 'bad' : '')
    );
    $('[data-meters-detail-rows]').html(d.series.slice(-10).reverse().map(function (r) {
      return '<tr><td class="nowrap">' + esc(Core.formatDate(r.at, locale, true)) + '</td><td class="num">' + (r.hour_meter === null ? '—' : esc(num(r.hour_meter, 0)))
        + '</td><td class="num">' + (r.odometer_km === null ? '—' : esc(num(r.odometer_km, 0))) + '</td></tr>';
    }).join(''));
    icons();

    if (state.chart) { state.chart.destroy(); state.chart = null; }
    var empty = points.length === 0;
    $('[data-meters-detail-empty]').prop('hidden', !empty);
    $('#chart-meter').parent().prop('hidden', empty);
    if (empty || !window.Chart) return;

    var last = points[points.length - 1];
    var lines = thresholds(d, points[0].y).map(function (p) {
      return { value: p.next_due_hours, color: STATUS[p.status].color, label: p.title + ' · ' + num(p.next_due_hours, 0) + ' h' };
    });
    // Projection au rythme moyen : jusqu'au premier seuil, sans dépasser la période affichée.
    var projection = [];
    if (rate > 0) {
      var horizon = Math.min(d.days, 30); // sans seuil à venir, une projection courte suffit et n'écrase pas l'historique
      if (metric === 'hour_meter' && plan && plan.remaining > 0) horizon = Math.min(horizon, Math.max(plan.days_left, 1));
      projection = [last, { x: last.x + horizon * DAY, y: Math.round(last.y + rate * horizon) }];
    }
    var datasets = [{
      label: t('js.meters.readings'), data: points, borderColor: LINE, backgroundColor: LINE, borderWidth: 2,
      // Chaque relevé reste visible : points plus petits quand la période en contient beaucoup.
      pointRadius: points.length <= 45 ? 3 : (points.length <= 120 ? 2.5 : (points.length <= 400 ? 1.8 : 1.4)),
      pointHoverRadius: 5, tension: 0
    }];
    if (projection.length) {
      datasets.push({
        label: t('js.meters.projection'), data: projection, borderColor: LINE, backgroundColor: SURFACE, borderWidth: 2, borderDash: [5, 5],
        pointRadius: [0, 4], pointHoverRadius: 5, pointBorderColor: LINE, pointBorderWidth: 2, tension: 0
      });
    }
    var top = Math.max.apply(null, [last.y].concat(lines.map(function (l) { return l.value; }), projection.length ? [projection[1].y] : []));

    Chart.defaults.font.family = '"IBM Plex Sans", system-ui, sans-serif';
    Chart.defaults.font.size = 12;
    Chart.defaults.color = MUTED;
    state.chart = new Chart(document.getElementById('chart-meter'), {
      type: 'line',
      data: { datasets: datasets },
      plugins: [thresholdPlugin],
      options: {
        maintainAspectRatio: false,
        animation: false, // redessiné à chaque actualisation : pas d'effet de clignotement
        parsing: false,
        interaction: { mode: 'nearest', axis: 'x', intersect: false },
        scales: {
          x: {
            type: 'linear',
            // L'axe couvre toujours la période demandée : un relevé unique ne doit pas dérégler l'échelle des dates.
            min: Math.min(points[0].x, Date.now() - d.days * DAY),
            max: Math.max(projection.length ? projection[1].x : last.x, Date.now()),
            grid: { display: false },
            border: { color: '#DDE1E6' },
            ticks: { maxTicksLimit: 8, callback: function (value) {
              // Longue période : mois/année, sinon jour/mois.
              var date = sqlDate(value);
              return d.days > 180 ? date.slice(5, 7) + '/' + date.slice(2, 4) : Core.formatDate(date, locale).slice(0, 5);
            } }
          },
          y: {
            suggestedMax: top + Math.max((top - points[0].y) * 0.08, 1),
            grid: { color: GRID },
            border: { display: false },
            ticks: { precision: 0, callback: function (value) { return num(value, 0) + ' ' + unit; } }
          }
        },
        plugins: {
          pmThresholds: { lines: lines },
          legend: { display: datasets.length > 1, position: 'top', align: 'end', labels: { boxWidth: 26, boxHeight: 0, color: INK } },
          tooltip: {
            backgroundColor: SURFACE, titleColor: INK, bodyColor: INK, borderColor: '#DDE1E6', borderWidth: 1, padding: 10, boxPadding: 4, usePointStyle: true,
            callbacks: {
              title: function (items) { return Core.formatDate(sqlDate(items[0].parsed.x), locale); },
              label: function (item) { return item.dataset.label + ' : ' + num(item.parsed.y, 0) + ' ' + unit; }
            }
          }
        }
      }
    });
  }

  function loadDetail() {
    if (state.selected === null) return $.Deferred().resolve();
    return $.getJSON(Core.fillUrl(urls.series, { id: state.selected }), { days: state.days }).then(function (json) {
      state.detail = json;
      $('[data-meters-detail]').prop('hidden', false);
      renderDetail();
    });
  }

  /** Détail et évolution d'un engin dans une fenêtre (contenu : gabarit #meters-detail-tpl). */
  function openDetail(id) {
    state.selected = id;
    state.days = 30;
    state.metric = 'hour_meter';
    state.detail = null;
    Swal.fire({
      html: document.getElementById('meters-detail-tpl').innerHTML,
      width: 'min(1100px, 96vw)',
      showConfirmButton: false,
      showCloseButton: true,
      customClass: { popup: 'meters-popup' },
      didOpen: function () { icons(); loadDetail(); },
      willClose: function () {
        if (state.chart) { state.chart.destroy(); state.chart = null; }
        state.selected = null;
      }
    });
  }

  $(document).on('click', '[data-meters-chart]', function () { openDetail(Number(this.dataset.metersChart)); });
  $(document).on('click', '[data-meters-days]', function () {
    state.days = Number(this.dataset.metersDays);
    $('[data-meters-days]').attr('aria-pressed', 'false');
    this.setAttribute('aria-pressed', 'true');
    loadDetail();
  });
  $(document).on('click', '[data-meters-metric]', function () {
    state.metric = this.dataset.metersMetric;
    $('[data-meters-metric]').attr('aria-pressed', 'false');
    this.setAttribute('aria-pressed', 'true');
    renderDetail();
  });

  // Arrivée depuis une fiche engin (« Voir l'évolution ») : sa fenêtre s'ouvre directement.
  if (boot.selected) openDetail(boot.selected);

  // Actualisation automatique (suspendue pendant une saisie, une consultation ou quand l'onglet est masqué).
  setInterval(function () {
    if (document.hidden || Swal.isVisible()) return;
    refresh();
  }, REFRESH_MS);
})(jQuery, window.PMCore);
