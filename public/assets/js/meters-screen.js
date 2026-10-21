/*
 * Supervision des index — affichage mural (salle de supervision).
 * Aucune interaction : la liste des échéances à traiter défile par pages, la courbe suit l'engin
 * mis en avant, les données sont rechargées seules et toute perte de liaison est signalée.
 */
(function ($, Core) {
  'use strict';

  var el = document.getElementById('wall-data');
  if (!el) return;
  var boot = JSON.parse(el.textContent);
  var t = window.PM.t;
  var locale = window.PM.locale;
  var esc = Core.escapeHtml;
  var urls = boot.urls;

  var REFRESH_MS = 30000;
  var ROTATE_MS = 12000;
  var STALE_DATA_MS = 120000; // au-delà, les chiffres affichés ne sont plus garantis
  var FLASH_MS = 20000;
  var PAGE_SIZE = 6;
  var MAX_CHIPS = 18;
  var PAUSE_MS = 60000; // après un clic, la rotation reprend seule au bout de ce délai
  var DAY = 86400000;
  var RANK = { due: 0, soon: 1, check: 2, ok: 3, none: 4 };
  var M = window.PMMeters;

  var TEXT = '#C5CED8';
  var GRID = '#263243';
  var LINE = '#6CB0F2';
  var STATUS_COLOR = { due: '#F97066', soon: '#FDB022', check: '#6CB0F2', ok: '#47CD89', none: '#98A2B3' };

  var state = { rows: boot.overview.rows, loadedAt: Date.now(), view: 0, step: 0, chart: null, series: {}, flash: {}, failed: false, filter: null, search: '', pausedUntil: 0, chartId: null };

  function num(value, decimals) { return Core.formatNumber(value, locale, decimals); }
  function pad(n) { return n < 10 ? '0' + n : String(n); }
  /** Rythme moyen dans le compteur de référence de l'engin : « 12,5 h/j » ou « 180 km/j ». */
  function rateText(d) { return num(d.rate, d.unit === 'h' ? 1 : 0) + ' ' + d.unit + t('js.meters.per_day_short'); }
  function clock(ms) { var d = new Date(ms); return pad(d.getHours()) + ':' + pad(d.getMinutes()); }
  function ts(value) {
    var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(String(value));
    return m ? new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0)).getTime() : NaN;
  }
  function icons() { if (window.lucide) window.lucide.createIcons(); }

  // ---------------------------------------------------------------- Vues (tous sites, ou un site à la fois)
  function views() {
    if (!boot.rotate) return [null];
    var sites = [];
    state.rows.forEach(function (r) { if (sites.indexOf(r.site) === -1) sites.push(r.site); });
    return sites.length > 1 ? sites.sort() : [null];
  }
  function viewRows(site) {
    return site === null ? state.rows : state.rows.filter(function (r) { return r.site === site; });
  }
  function urgent(rows) {
    return rows.filter(function (r) { return r.status === 'due' || r.status === 'soon'; });
  }
  /** Filtres ouverts par un clic sur un indicateur. */
  var FILTERS = {
    due: function (r) { return r.status === 'due'; },
    soon: function (r) { return r.status === 'soon'; },
    check: function (r) { return r.status === 'check'; },
    ok: function (r) { return r.status === 'ok'; },
    read: function (r) { return r.days_since === 0; },
    unread: function (r) { return r.days_since !== 0; },
    stale: function (r) { return r.stale; }
  };
  /** Relevés du jour : le plus récent en tête. */
  function byLastReading(a, b) {
    var at = function (r) { return r.last_reading ? r.last_reading.at : (r.last_reading_at || ''); };
    return at(b) < at(a) ? -1 : (at(b) > at(a) ? 1 : 0);
  }
  /** Lignes de la liste : les échéances à traiter, ou le filtre choisi au clic. */
  function listRows(rows) {
    if (state.filter) rows = rows.filter(FILTERS[state.filter]);
    if (state.filter === 'read') rows = rows.slice().sort(byLastReading);
    // Une recherche porte sur tous les engins (ou sur ceux de l'indicateur choisi), pas seulement sur les échéances à traiter.
    if (state.search) {
      return rows.filter(function (r) { return (r.code + ' ' + r.name + ' ' + r.site).toLowerCase().indexOf(state.search) !== -1; });
    }
    return state.filter ? rows : urgent(rows);
  }
  /** Engins parcourus par la rotation : ceux de la liste, sinon tous les engins suivis (pour la courbe). */
  function pool(rows) {
    var list = listRows(rows);
    return list.length || state.filter || state.search ? list : rows.filter(function (r) { return r.current !== null; });
  }

  // ---------------------------------------------------------------- Rendu
  /**
   * Détail d'un relevé du jour : index saisi, heure et auteur, écart avec le relevé précédent,
   * puis la prochaine échéance (reste) pour situer l'engin.
   */
  function readingCells(row) {
    var r = row.last_reading;
    var at = r ? r.at : row.last_reading_at;
    var who = [at ? t('js.wall.read_at', { time: clock(ts(at)) }) : null, r && r.by ? r.by : null].filter(Boolean).join(' · ');
    var delta = !r || r.delta === null
      ? '<strong>—</strong><small>' + esc(t('js.wall.first_reading')) + '</small>'
      : '<strong' + (r.delta === 0 ? ' class="wall__issue"' : '') + '>+' + esc(num(r.delta, 0)) + ' ' + esc(row.unit) + '</strong><small>'
        + esc(t('js.wall.since', { date: Core.formatDate(r.previous_at, locale) })) + (r.delta === 0 ? ' · ' + esc(t('js.wall.no_change')) : '') + '</small>';
    var plan = row.plans[0];
    var due = plan && plan.remaining !== null
      ? '<strong>' + esc(M.remaining(plan)) + '</strong><small>' + esc(plan.title) + '</small>'
      : '<strong>—</strong><small>' + esc(plan ? (M.issue(plan, row) || plan.title) : t('js.meters.no_plan')) + '</small>';
    return '<span class="wall__plan"><strong>' + (r ? esc(num(r.value, 0)) + ' ' + esc(row.unit) : '—') + '</strong><small>' + esc(who) + '</small></span>'
      + '<span class="wall__progress">' + delta + '</span>'
      + '<span class="wall__due">' + due + '</span>';
  }

  function rowHtml(row, active, index) {
    var plan = row.plans[0];
    var open = '<div class="wall__row wall__row--' + row.status + (active ? ' is-active' : '') + (state.flash[row.id] > Date.now() ? ' is-new' : '')
      + '" data-wall-row="' + index + '" role="button" tabindex="0">'
      + '<span class="wall__badge">' + esc(t('meter_status.' + row.status)) + '</span>'
      + '<span class="wall__engine"><strong>' + esc(row.code) + '</strong><small>' + esc(row.name) + ' · ' + esc(row.site) + '</small></span>';
    if (state.filter === 'read') return open + readingCells(row) + '</div>';
    if (!plan) {
      return open + '<span class="wall__plan wall__plan--wide"><strong>' + esc(t('js.meters.no_plan')) + '</strong><small>'
        + (row.current === null ? '—' : esc(num(row.current, 0)) + ' ' + esc(row.unit)) + (row.rate === null ? '' : ' · ' + esc(rateText(row))) + '</small></span></div>';
    }
    // Avancement entre ses deux bornes (dernière réalisation → échéance) ; la raison d'un « à vérifier » remplace la date prévue.
    var issue = M.issue(plan, row);
    return open
      + '<span class="wall__plan"><strong>' + esc(plan.title) + '</strong><small>' + (row.current === null ? '—' : esc(num(row.current, 0)) + ' ' + esc(row.unit))
      + (row.rate === null ? '' : ' · ' + esc(rateText(row))) + '</small></span>'
      + '<span class="wall__progress"><span class="wall__progress-line"><span class="wall__bar"><span style="width:' + Math.min(100, plan.pct === null ? 0 : plan.pct) + '%"></span></span>'
      + '<b>' + (plan.pct === null ? '—' : plan.pct + ' %') + '</b></span><small>' + esc(M.bounds(plan)) + '</small></span>'
      + '<span class="wall__due"><strong>' + esc(M.remaining(plan)) + '</strong><small' + (issue ? ' class="wall__issue"' : '') + '>' + esc(issue || M.when(plan)) + '</small></span></div>';
  }

  function render() {
    var list = views();
    if (state.view >= list.length) state.view = 0;
    var site = list[state.view];
    var rows = viewRows(site);
    var todo = listRows(rows);
    var cycle = pool(rows);
    if (state.step >= Math.max(cycle.length, 1)) state.step = 0;
    var pages = Math.max(1, Math.ceil(todo.length / PAGE_SIZE));
    var page = todo.length ? Math.floor(state.step / PAGE_SIZE) : 0;

    $('[data-wall-scope]').text((site === null ? boot.scope : site) + (list.length > 1 ? ' · ' + (state.view + 1) + ' / ' + list.length : ''));

    var count = function (fn) { return rows.filter(fn).length; };
    var values = {
      due: count(function (r) { return r.status === 'due'; }),
      soon: count(function (r) { return r.status === 'soon'; }),
      check: count(function (r) { return r.status === 'check'; }),
      ok: count(function (r) { return r.status === 'ok'; }),
      read: count(function (r) { return r.days_since === 0; }) + ' / ' + rows.length,
      stale: count(function (r) { return r.stale; })
    };
    Object.keys(values).forEach(function (key) { $('[data-wall-value="' + key + '"]').text(values[key]); });
    $('[data-wall-kpi="due"]').toggleClass('is-on', values.due > 0);
    $('[data-wall-kpi="soon"]').toggleClass('is-on', values.soon > 0);
    $('[data-wall-kpi="check"]').toggleClass('is-on', values.check > 0);
    $('[data-wall-kpi="stale"]').toggleClass('is-on', values.stale > 0);
    $('[data-wall-kpi="read"]').toggleClass('is-on', count(function (r) { return r.days_since !== 0; }) > 0);

    $('[data-wall-kpi]').each(function () { this.classList.toggle('is-selected', this.dataset.wallKpi === state.filter); });
    $('[data-wall-list-title]').text((state.filter ? $('[data-wall-kpi="' + state.filter + '"]').data('title') : (state.search ? t('js.wall.all_equipment') : defaultTitle))
      + (state.search ? ' · ' + t('js.wall.results', { n: todo.length }) : ''));
    $('[data-wall-pages]').html(pages > 1
      ? '<button type="button" class="wall__nav" data-wall-page="-1" aria-label="' + esc(t('js.wall.prev')) + '">‹</button>'
        + esc(t('js.wall.page', { n: page + 1, total: pages }))
        + '<button type="button" class="wall__nav" data-wall-page="1" aria-label="' + esc(t('js.wall.next')) + '">›</button>'
      : '');
    $('[data-wall-rows]').html(todo.length
      ? todo.slice(page * PAGE_SIZE, (page + 1) * PAGE_SIZE).map(function (r, i) { return rowHtml(r, page * PAGE_SIZE + i === state.step, page * PAGE_SIZE + i); }).join('')
      : '<div class="wall__empty"><i data-lucide="shield-check"></i><p>' + esc(state.filter || state.search ? t('js.no_result') : t('js.wall.none')) + '</p></div>');

    // Engins non relevés aujourd'hui : les plus anciens relevés d'abord (jamais relevé en tête).
    var unread = rows.filter(function (r) { return r.days_since !== 0; }).sort(function (a, b) {
      return (b.days_since === null ? Infinity : b.days_since) - (a.days_since === null ? Infinity : a.days_since);
    });
    $('[data-wall-unread-count]').text(unread.length ? unread.length + ' / ' + rows.length : '');
    $('[data-wall-unread]').html(unread.length
      ? unread.slice(0, MAX_CHIPS).map(function (r) {
        return '<button type="button" class="wall__chip' + (r.stale ? ' wall__chip--stale' : '') + '" data-wall-chip="' + r.id + '"><strong>' + esc(r.code) + '</strong>'
          + '<small>' + esc(r.days_since === null ? t('js.meters.never') : t('js.days', { n: r.days_since })) + '</small></button>';
      }).join('') + (unread.length > MAX_CHIPS ? '<span class="wall__chip wall__chip--more">+' + (unread.length - MAX_CHIPS) + '</span>' : '')
      : '<div class="wall__empty wall__empty--sm"><i data-lucide="check-circle-2"></i><p>' + esc(t('js.wall.all_read')) + '</p></div>');
    icons();

    // Courbe : l'engin mis en avant, sinon les engins suivis tour à tour.
    showChart(cycle.length ? cycle[state.step] : null);
  }

  // ---------------------------------------------------------------- Courbe d'évolution
  function showChart(row) {
    if (!row) { drawChart(null); return; }
    state.chartId = row.id;
    if (state.series[row.id]) { drawChart(state.series[row.id]); return; }
    $.getJSON(Core.fillUrl(urls.series, { id: row.id }), { days: 30 }).then(function (json) {
      state.series[row.id] = json;
      if (state.chartId === row.id) drawChart(json); // une réponse tardive ne remplace pas l'engin choisi depuis
    });
  }

  function drawChart(d) {
    if (state.chart) { state.chart.destroy(); state.chart = null; }
    var points = d ? seriesPoints(d) : [];
    $('[data-wall-chart-title]').text(d ? t('js.meters.evolution_of', { code: d.code }) : '');
    $('[data-wall-equipment]').prop('hidden', !d).attr('href', d ? Core.fillUrl(urls.equipment, { id: d.id }) : '#');
    $('[data-wall-chart-rate]').text(d && d.rate !== null ? rateText(d) : '');
    $('[data-wall-chart-empty]').prop('hidden', !d || points.length > 0);
    state.chart = buildChart(document.getElementById('wall-chart'), d, points);
  }

  /** Points de la courbe du compteur de référence (heures ou km) ; « i » est la position du relevé dans la série (détail au clic sur le point). */
  function seriesPoints(d) {
    var points = [];
    d.series.forEach(function (r, i) { if (r[d.metric] !== null) points.push({ x: ts(r.at), y: r[d.metric], i: i }); });
    return points;
  }

  /** Courbe des relevés d'un engin, avec projection au rythme moyen et seuil de la prochaine maintenance. */
  function buildChart(canvas, d, points) {
    if (!points.length || !window.Chart) return null;
    // Clic sur un point : détail du relevé ; la rotation se met en pause comme pour tout autre clic.
    var readingClick = window.PMReading.chartOptions(function () {
      hold();
      return { equipment: d, series: d.series };
    });

    var last = points[points.length - 1];
    var plan = d.plans.filter(function (p) { return p.metric === d.metric; })[0]; // seuil dans le compteur de la courbe
    // Chaque relevé est un point : gros quand il y en a peu, plus fin quand la période en contient beaucoup.
    var unit = parseFloat(getComputedStyle(document.body).fontSize) / 20; // 1 en Full HD
    var radius = (points.length <= 3 ? 6 : (points.length <= 45 ? 4 : (points.length <= 120 ? 3 : (points.length <= 400 ? 2.2 : 1.7)))) * unit;
    var datasets = [{
      label: t('js.meters.readings'), data: points, borderColor: LINE, backgroundColor: LINE,
      borderWidth: points.length > 120 ? 2 : 3, pointRadius: radius, pointHoverRadius: radius + 3, tension: 0
    }];
    var end = last.x;
    if (d.rate > 0) {
      var horizon = plan && plan.remaining > 0 ? Math.min(30, Math.max(plan.days_left, 1)) : 14;
      end = last.x + horizon * DAY;
      datasets.push({
        label: t('js.meters.projection'), data: [last, { x: end, y: Math.round(last.y + d.rate * horizon) }],
        borderColor: LINE, backgroundColor: 'transparent', borderWidth: 3, borderDash: [8, 6], pointRadius: [0, 6], pointBorderWidth: 3, tension: 0
      });
    }
    // L'axe couvre toujours la période demandée : un relevé unique ne doit pas dérégler l'échelle des dates.
    var xMin = Math.min(points[0].x, Date.now() - d.days * DAY);
    var xMax = Math.max(end, Date.now());
    if (plan) {
      datasets.push({
        label: t('js.wall.threshold', { n: num(plan.next_due, 0), unit: plan.unit }), data: [{ x: xMin, y: plan.next_due }, { x: xMax, y: plan.next_due }],
        borderColor: STATUS_COLOR[plan.status], backgroundColor: 'transparent', borderWidth: 2, borderDash: [4, 4], pointRadius: 0
      });
    }

    var size = Math.max(12, Math.round(parseFloat(getComputedStyle(document.body).fontSize) * 0.8));
    return new Chart(canvas, {
      type: 'line',
      data: { datasets: datasets },
      options: {
        maintainAspectRatio: false,
        animation: false,
        parsing: false,
        onClick: readingClick.onClick,
        onHover: readingClick.onHover,
        interaction: { mode: 'nearest', axis: 'x', intersect: false },
        scales: {
          x: {
            type: 'linear', min: xMin, max: xMax, grid: { display: false }, border: { color: GRID },
            ticks: { color: TEXT, font: { size: size }, maxTicksLimit: 5, callback: function (value) {
              // Longue période : mois/année, sinon jour/mois.
              var day = new Date(value);
              return d.days > 180 ? pad(day.getMonth() + 1) + '/' + String(day.getFullYear()).slice(2) : pad(day.getDate()) + '/' + pad(day.getMonth() + 1);
            } }
          },
          y: {
            grid: { color: GRID }, border: { display: false },
            ticks: { color: TEXT, font: { size: size }, precision: 0, maxTicksLimit: 6, callback: function (value) { return num(value, 0) + ' ' + d.unit; } }
          }
        },
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: size * 2, boxHeight: 0, color: TEXT, font: { size: size } } },
          tooltip: {
            titleFont: { size: size }, bodyFont: { size: size },
            callbacks: {
              title: function (items) { var day = new Date(items[0].parsed.x); return pad(day.getDate()) + '/' + pad(day.getMonth() + 1) + '/' + day.getFullYear(); },
              label: function (item) { return item.dataset.label + ' : ' + num(item.parsed.y, 0) + ' ' + d.unit; }
            }
          }
        }
      }
    });
  }

  // ---------------------------------------------------------------- Données et état de la liaison
  function status() {
    var late = Date.now() - state.loadedAt > STALE_DATA_MS;
    var broken = state.failed || late;
    $('[data-wall-status]').toggleClass('is-down', broken);
    $('[data-wall-updated]').text(t('js.wall.updated', { time: clock(state.loadedAt) }));
    $('[data-wall-alert]').prop('hidden', !broken);
    if (broken) $('[data-wall-alert-text]').text(t('js.wall.offline', { time: clock(state.loadedAt) }));
  }

  function refresh() {
    $.getJSON(urls.data).then(function (json) {
      var before = {};
      state.rows.forEach(function (r) { before[r.id] = RANK[r.status]; });
      json.rows.forEach(function (r) {
        if (before[r.id] !== undefined && RANK[r.status] < before[r.id]) state.flash[r.id] = Date.now() + FLASH_MS;
      });
      state.rows = json.rows;
      state.series = {};
      state.loadedAt = Date.now();
      state.failed = false;
      status();
      render();
    }, function (xhr) {
      state.failed = true;
      status();
      // Session perdue : seule une reconnexion peut rétablir l'affichage.
      if (xhr.status === 401) {
        $('[data-wall-alert-text]').text(t('js.wall.session'));
        setTimeout(function () { window.location.href = urls.login; }, 10000);
      }
    });
  }

  function tick() {
    var now = new Date();
    $('[data-wall-time]').text(pad(now.getHours()) + ':' + pad(now.getMinutes()));
    $('[data-wall-date]').text(now.toLocaleDateString(locale === 'en' ? 'en-GB' : 'fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }));
    status();
  }

  function rotate() {
    if (Date.now() < state.pausedUntil) return;
    $('[data-wall-paused]').prop('hidden', true);
    closeModal();
    window.PMReading.close();
    // Fin de pause : l'écran revient seul à sa vue par défaut (filtre et recherche effacés).
    if (state.filter || state.search) {
      state.filter = null;
      state.search = '';
      state.step = 0;
      $('[data-wall-search]').val('').trigger('blur');
      render();
      return;
    }
    var list = views();
    var cycle = pool(viewRows(list[state.view] === undefined ? null : list[state.view]));
    state.step++;
    // Fin de la liste : retour au début, sur le site suivant s'il y en a plusieurs.
    if (state.step >= Math.max(cycle.length, 1)) {
      state.step = 0;
      state.view = (state.view + 1) % list.length;
    }
    render();
  }

  // ---------------------------------------------------------------- Navigation à la souris
  /** Tout clic met la rotation en pause : l'opérateur garde la main, puis l'écran reprend seul. */
  function hold() {
    state.pausedUntil = Date.now() + PAUSE_MS;
    $('[data-wall-paused]').prop('hidden', false);
  }
  function currentRows() {
    var list = views();
    return viewRows(list[state.view] === undefined ? null : list[state.view]);
  }
  function selectRow(index) { state.step = index; hold(); render(); }
  /** Clic sur une ligne : l'engin est mis en avant et son détail s'ouvre. */
  function openRow(index) {
    selectRow(index);
    var row = listRows(currentRows())[index];
    if (row) openModal(row.id);
  }

  $(document).on('click', '[data-wall-row]', function () { openRow(Number(this.dataset.wallRow)); });
  $(document).on('keydown', '[data-wall-row]', function (e) {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openRow(Number(this.dataset.wallRow)); }
  });
  $(document).on('click', '[data-wall-kpi]', function () {
    state.filter = state.filter === this.dataset.wallKpi ? null : this.dataset.wallKpi;
    selectRow(0);
  });
  $(document).on('click', '[data-wall-page]', function () {
    var pages = Math.max(1, Math.ceil(listRows(currentRows()).length / PAGE_SIZE));
    selectRow(((Math.floor(state.step / PAGE_SIZE) + Number(this.dataset.wallPage) + pages) % pages) * PAGE_SIZE);
  });
  $(document).on('click', '[data-wall-chip]', function () {
    var id = Number(this.dataset.wallChip);
    state.filter = 'read';
    selectRow(Math.max(listRows(currentRows()).map(function (r) { return r.id; }).indexOf(id), 0));
    openModal(id);
  });
  $(document).on('click', '[data-wall-scope]', function () {
    var list = views();
    if (list.length < 2) return;
    state.view = (state.view + 1) % list.length;
    state.filter = null;
    selectRow(0);
  });
  // Recherche d'un engin : filtre la liste à la frappe ; Échap l'efface.
  $(document).on('input', '[data-wall-search]', function () {
    state.search = this.value.trim().toLowerCase();
    selectRow(0);
  });
  $(document).on('keydown', '[data-wall-search]', function (e) {
    if (e.key !== 'Escape' || this.value === '') return;
    e.stopPropagation();
    this.value = '';
    state.search = '';
    selectRow(0);
  });
  $(document).on('click', '[data-wall-resume]', function () {
    state.pausedUntil = 0;
    rotate();
  });

  // ---------------------------------------------------------------- Détail d'un engin (fenêtre)
  var modal = { id: null, days: 30, chart: null };

  function vital(icon, label, value, hint) {
    return '<div class="wall__vital"><span><i data-lucide="' + icon + '"></i>' + esc(label) + '</span><strong>' + value + '</strong>'
      + '<small>' + (hint ? esc(hint) : '&nbsp;') + '</small></div>';
  }

  function modalHtml(d) {
    var age = d.days_since === null ? t('js.meters.never') : (d.days_since === 0 ? t('js.meters.today') : t('js.meters.days_ago', { n: d.days_since }));
    var plans = d.plans.map(function (p) {
      var issue = M.issue(p, d);
      return '<tr class="wall__row--' + p.status + '"><td><strong>' + esc(p.title) + '</strong>' + (p.open_work_order_id ? '<small>' + esc(t('js.planned')) + '</small>' : '')
        + (issue ? '<small class="wall__issue">' + esc(issue) + '</small>' : '') + '</td>'
        + '<td class="num">' + esc(num(p.next_due, 0)) + ' ' + esc(p.unit) + '</td>'
        + '<td><span class="wall__progress"><span class="wall__progress-line"><span class="wall__bar"><span style="width:' + Math.min(100, p.pct === null ? 0 : p.pct) + '%"></span></span><b>' + (p.pct === null ? '—' : p.pct + ' %') + '</b></span>'
        + '<small>' + esc(M.lastDone(p)) + '</small></span></td>'
        + '<td class="num"><strong>' + esc(M.remaining(p)) + '</strong></td><td>' + esc(p.remaining === null || p.remaining <= 0 ? '—' : M.when(p)) + '</td>'
        + '<td><span class="wall__badge">' + esc(t('meter_status.' + p.status)) + '</span></td></tr>';
    }).join('');
    var readings = d.series.slice(-8).reverse().map(function (r) {
      return '<tr><td>' + esc(Core.formatDate(r.at, locale, true)) + '</td><td class="num">' + (r.hour_meter === null ? '—' : esc(num(r.hour_meter, 0)) + ' h')
        + '</td><td class="num">' + (r.odometer_km === null ? '—' : esc(num(r.odometer_km, 0)) + ' km') + '</td></tr>';
    }).join('');

    return '<header class="wall__modal-head wall__row--' + d.status + '"><span class="wall__badge">' + esc(t('meter_status.' + d.status)) + '</span>'
      + '<div><h2 id="wall-modal-title">' + esc(d.code) + '</h2><p>' + esc(d.name) + ' · ' + esc(d.site) + '</p></div>'
      + '<a class="wall__link" href="' + esc(Core.fillUrl(urls.equipment, { id: d.id })) + '">' + esc(t('js.log_open')) + '</a>'
      + '<button type="button" class="wall__nav" data-wall-modal-close aria-label="' + esc(t('js.close')) + '">×</button></header>'
      + '<div class="wall__vitals">'
      + vital('timer', t('fields.hour_meter'), d.hour_meter === null ? '—' : esc(num(d.hour_meter, 0)) + ' h')
      + vital('route', t('fields.odometer_km'), d.odometer_km === null ? '—' : esc(num(d.odometer_km, 0)) + ' km', d.rate_km === null ? '' : num(d.rate_km, 0) + ' km' + t('js.meters.per_day_short'))
      + vital('activity', t('js.meters.rate'), d.rate === null ? '—' : esc(rateText(d)), d.rate === null ? t('js.wall.no_rate') : '')
      + vital('gauge', t('js.wall.last_reading'), d.last_reading_at === null ? '—' : esc(Core.formatDate(d.last_reading_at, locale, true)), age)
      + '</div>'
      + '<div class="wall__modal-body"><section class="wall__modal-plans"><h3>' + esc(t('js.wall.plans')) + '</h3>'
      + (plans ? '<table class="wall__table"><thead><tr><th>' + esc(t('js.meters.next')) + '</th><th class="num">' + esc(t('js.wall.threshold_col')) + '</th><th>' + esc(t('js.wall.progress')) + '</th><th class="num">'
        + esc(t('js.wall.remaining')) + '</th><th>' + esc(t('js.meters.due_on')) + '</th><th></th></tr></thead><tbody>' + plans + '</tbody></table>'
        : '<p class="wall__muted">' + esc(t('js.meters.no_plan')) + '</p>')
      + '</section><section><h3>' + esc(t('js.wall.recent')) + '</h3>'
      + (readings ? '<table class="wall__table"><tbody>' + readings + '</tbody></table>' : '<p class="wall__muted">' + esc(t('js.wall.no_reading')) + '</p>')
      + '</section><section class="wall__modal-chart"><div class="wall__title"><h3>' + esc(t('js.wall.trend')) + '</h3><span class="wall__pager">'
      + [30, 90, 365, 548].map(function (n) { return '<button type="button" class="wall__nav wall__nav--text" data-wall-modal-days="' + n + '" aria-pressed="' + (n === modal.days) + '">' + esc(n === 548 ? t('js.wall.months18') : t('js.days', { n: n })) + '</button>'; }).join('')
      + '</span></div><div class="wall__chart"><canvas id="wall-modal-chart"></canvas>'
      + (d.series.some(function (r) { return r[d.metric] !== null; }) ? '' : '<p class="wall__chart-empty">' + esc(t('js.wall.no_reading')) + '</p>')
      + '</div></section></div>';
  }

  function loadModal() {
    var id = modal.id;
    $.getJSON(Core.fillUrl(urls.series, { id: id }), { days: modal.days }).then(function (d) {
      if (modal.id !== id) return; // fermée ou remplacée entre-temps
      if (modal.chart) { modal.chart.destroy(); modal.chart = null; }
      $('[data-wall-modal-content]').html(modalHtml(d));
      icons();
      modal.chart = buildChart(document.getElementById('wall-modal-chart'), d,
        seriesPoints(d));
    });
  }

  function openModal(id) {
    modal.id = id;
    modal.days = 30;
    $('[data-wall-modal-content]').html('<p class="wall__muted">' + esc(t('js.dt.loading')) + '</p>');
    $('[data-wall-modal]').prop('hidden', false);
    $('[data-wall-modal] .wall__modal').trigger('focus');
    loadModal();
  }

  function closeModal() {
    if (modal.id === null) return;
    modal.id = null;
    if (modal.chart) { modal.chart.destroy(); modal.chart = null; }
    $('[data-wall-modal]').prop('hidden', true);
  }

  $(document).on('click', '[data-wall-modal-close]', closeModal);
  $(document).on('click', '[data-wall-modal]', function (e) { if (e.target === this) closeModal(); });
  $(document).on('keydown', function (e) { if (e.key === 'Escape') closeModal(); });
  $(document).on('click', '[data-wall-modal-days]', function () {
    modal.days = Number(this.dataset.wallModalDays);
    hold();
    loadModal();
  });
  // Tant qu'on consulte le détail, la fenêtre reste ouverte ; elle se ferme seule à la reprise de la rotation.
  $(document).on('mousemove', '[data-wall-modal]', function () { if (modal.id !== null) hold(); });
  $(document).on('mousemove', '.reading-pop', hold);

  // ---------------------------------------------------------------- Plein écran, veille, curseur
  $(document).on('click', '[data-wall-fullscreen]', function () {
    if (document.fullscreenElement) document.exitFullscreen();
    else if (document.documentElement.requestFullscreen) document.documentElement.requestFullscreen();
  });
  function keepAwake() {
    if (navigator.wakeLock && document.visibilityState === 'visible') navigator.wakeLock.request('screen').catch(function () { /* refusé : sans effet */ });
  }
  document.addEventListener('visibilitychange', keepAwake);
  keepAwake();
  var idle;
  $(document).on('mousemove', function () {
    document.body.classList.remove('wall-idle');
    clearTimeout(idle);
    idle = setTimeout(function () { document.body.classList.add('wall-idle'); }, 4000);
  }).trigger('mousemove');
  $(window).on('resize', render);
  if (window.Chart) Chart.defaults.font.family = '"IBM Plex Sans", system-ui, sans-serif';

  var defaultTitle = $('[data-wall-list-title]').text();
  tick();
  render();
  setInterval(tick, 5000);
  setInterval(rotate, ROTATE_MS);
  setInterval(refresh, REFRESH_MS);
})(jQuery, window.PMCore);
