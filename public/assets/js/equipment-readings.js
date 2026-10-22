/*
 * Fiche engin — relevés de compteurs : tableau compact (5 lignes par page, le plus récent en tête).
 * Colonnes : date, index du compteur de suivi de l'engin (heures ou km), écart avec le relevé précédent.
 * Un clic sur la ligne la déplie : les deux compteurs, rythme sur l'intervalle, carburant, auteur et note.
 */
(function ($, Core) {
  'use strict';

  var el = document.getElementById('readings-data');
  var $table = $('[data-readings-table]');
  if (!el || !$table.length || !$.fn.DataTable) return;
  var boot = JSON.parse(el.textContent);
  var t = window.PM.t;
  var locale = window.PM.locale;
  var esc = Core.escapeHtml;
  var DAY = 86400000;
  var MAIN = boot.basis === 'km' ? 'odometer_km' : 'hour_meter';
  var UNIT = { hour_meter: 'h', odometer_km: 'km' };

  function num(value, decimals) { return Core.formatNumber(value, locale, decimals || 0); }
  function ts(value) {
    var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(String(value));
    return m ? new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0)).getTime() : NaN;
  }

  /** Écart avec le relevé précédent du même compteur (les relevés arrivent du plus récent au plus ancien). */
  function withDeltas(readings) {
    return readings.map(function (r, i) {
      var row = $.extend({}, r, { deltas: {} });
      ['hour_meter', 'odometer_km'].forEach(function (metric) {
        if (r[metric] === null) return;
        for (var j = i + 1; j < readings.length; j++) {
          if (readings[j][metric] === null) continue;
          var days = (ts(r.at) - ts(readings[j].at)) / DAY;
          row.deltas[metric] = { value: r[metric] - readings[j][metric], days: days, since: readings[j].at };
          break;
        }
      });
      return row;
    });
  }

  function deltaText(d, unit) {
    if (!d) return '';
    return (d.value >= 0 ? '+' : '') + num(d.value) + ' ' + unit;
  }

  /** Détail d'une ligne dépliée. */
  function details(r) {
    var items = [];
    ['hour_meter', 'odometer_km'].forEach(function (metric) {
      if (r[metric] === null) return;
      var d = r.deltas[metric];
      var extra = '';
      if (d) {
        extra = ' <span class="muted">· ' + esc(deltaText(d, UNIT[metric])) + ' ' + esc(t('js.wall.since', { date: Core.formatDate(d.since, locale) }));
        if (d.days >= 1) extra += ' · ' + esc(num(d.value / d.days, metric === 'hour_meter' ? 1 : 0)) + ' ' + UNIT[metric] + esc(t('js.meters.per_day_short'));
        extra += '</span>';
      }
      items.push([t('fields.' + metric), esc(num(r[metric])) + ' ' + UNIT[metric] + extra]);
    });
    if (r.fuel_level !== null) {
      // Lecture sur l'indicateur : graduation saisie, litres déduits (selon la capacité du réservoir).
      var fuel = r.fuel_liters !== null ? num(r.fuel_liters, 1) + ' L · ' + r.fuel_level + ' %' : r.fuel_level + ' %';
      if (r.fuel_mark !== null) fuel += ' <span class="muted">(' + esc(num(r.fuel_mark, 1)) + ' / ' + esc(r.fuel_marks) + ')</span>';
      items.push([t('js.readings.fuel'), fuel]);
    }
    items.push([t('js.readings.by'), esc(r.by || '—') + ' <span class="muted">· ' + esc(Core.formatDate(r.at, locale, true)) + '</span>']);
    if (r.note) items.push([t('js.readings.note'), esc(r.note)]);
    return '<dl class="reading-details">' + items.map(function (i) { return '<dt>' + esc(i[0]) + '</dt><dd>' + i[1] + '</dd>'; }).join('') + '</dl>';
  }

  var rows = withDeltas(boot.readings);
  var table = $table.DataTable({
    data: rows,
    pageLength: 5,
    lengthMenu: [5, 10, 25, 50],
    order: [[1, 'desc']],
    autoWidth: false,
    searching: false,
    layout: { topStart: null, topEnd: null, bottomStart: 'info', bottomEnd: 'paging' },
    language: {
      lengthMenu: t('js.dt.length'), info: t('js.dt.info'), infoEmpty: t('js.dt.info_empty'),
      zeroRecords: t('js.dt.zero'), emptyTable: t('js.dt.empty'),
      paginate: { first: '«', last: '»', next: '›', previous: '‹' }
    },
    columns: [
      { data: null, orderable: false, className: 'readings-toggle', defaultContent: '<button type="button" class="link-btn" aria-expanded="false" aria-label="' + esc(t('js.readings.details')) + '"><i data-lucide="chevron-right"></i></button>' },
      { data: 'at', className: 'nowrap', render: function (v, type) { return type === 'display' ? esc(Core.formatDate(v, locale, true)) : v; } },
      { data: MAIN, className: 'dt-right nowrap', render: function (v, type, r) {
        if (type !== 'display') return v === null ? -1 : v;
        if (v !== null) return '<strong>' + esc(num(v)) + '</strong> ' + UNIT[MAIN];
        // Relevé sans le compteur de suivi (ex. carburant seul, ou l'autre compteur) : on l'indique en clair.
        var other = MAIN === 'hour_meter' ? 'odometer_km' : 'hour_meter';
        return r[other] !== null ? '<span class="muted">' + esc(num(r[other])) + ' ' + UNIT[other] + '</span>' : '<span class="muted">—</span>';
      } },
      { data: null, className: 'dt-right nowrap', orderable: false, render: function (v, type, r) {
        var d = r.deltas[MAIN];
        if (!d) return '<span class="muted">—</span>';
        return '<span class="' + (d.value === 0 ? 'reading-flat' : '') + '">' + esc(deltaText(d, UNIT[MAIN])) + '</span>';
      } }
    ],
    drawCallback: function () { if (window.lucide) window.lucide.createIcons(); }
  });

  // Dépliage : clic sur la ligne (ou sur le chevron), au clavier avec le bouton.
  $table.on('click', 'tbody tr', function (e) {
    var tr = this;
    if ($(tr).hasClass('reading-child') || $(e.target).closest('a').length) return; // clic dans le détail déplié
    var row = table.row(tr);
    if (!row.data()) return;
    var $btn = $(tr).find('.readings-toggle button');
    if (row.child.isShown()) {
      row.child.hide();
      $(tr).removeClass('is-open');
      $btn.attr('aria-expanded', 'false');
    } else {
      row.child(details(row.data()), 'reading-child').show();
      $(tr).addClass('is-open');
      $btn.attr('aria-expanded', 'true');
    }
  });
})(jQuery, window.PMCore);
