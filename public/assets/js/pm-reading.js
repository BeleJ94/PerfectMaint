/*
 * Détail d'un relevé d'index, ouvert au clic sur un point d'une courbe d'évolution.
 * Partagé par la page Supervision des index et par l'écran mural : la fenêtre se superpose
 * à celle déjà ouverte (détail de l'engin) sans la fermer.
 */
(function ($, Core) {
  'use strict';

  var CLICK_RADIUS = 24; // distance maximale (px) entre le clic et le point : les points serrés restent cliquables

  function t(key, params) { return window.PM.t(key, params); }
  function num(value) { return Core.formatNumber(value, window.PM.locale, 0); }
  function esc(value) { return Core.escapeHtml(value); }

  /** Dernier relevé antérieur qui porte la mesure demandée (heures ou km). */
  function previous(series, index, metric) {
    for (var i = index - 1; i >= 0; i--) {
      if (series[i][metric] !== null) return series[i];
    }
    return null;
  }

  function row(label, value, hint) {
    return '<div class="reading-pop__row"><dt>' + esc(label) + '</dt><dd>' + value + (hint ? '<small>' + esc(hint) + '</small>' : '') + '</dd></div>';
  }

  function measure(series, index, metric, unit) {
    var reading = series[index];
    if (reading[metric] === null) return '';
    var before = previous(series, index, metric);
    var hint = '';
    if (before) {
      var delta = reading[metric] - before[metric];
      hint = t('js.reading.delta', { delta: (delta >= 0 ? '+' : '−') + num(Math.abs(delta)) + ' ' + unit, date: Core.formatDate(before.at, window.PM.locale, true) });
    }
    return row(t('fields.' + metric), '<strong>' + esc(num(reading[metric])) + ' ' + unit + '</strong>', hint);
  }

  function close() {
    $('.reading-pop').remove();
    document.removeEventListener('keydown', onKey, true);
  }

  // Échap ferme d'abord cette fenêtre, sans fermer celle de l'engin qui est dessous.
  function onKey(e) {
    if (e.key !== 'Escape') return;
    e.stopPropagation();
    e.preventDefault();
    close();
  }

  /**
   * @param {{code: string, name: string}} equipment
   * @param {Array} series relevés de la période (ordre chronologique)
   * @param {number} index position du relevé cliqué dans la série
   */
  function open(equipment, series, index) {
    var reading = series[index];
    if (!reading) return;
    close();
    var html = '<div class="reading-pop" role="dialog" aria-modal="true" aria-labelledby="reading-pop-title"><div class="reading-pop__card" tabindex="-1">'
      + '<header><div><h3 id="reading-pop-title">' + esc(t('js.reading.title', { date: Core.formatDate(reading.at, window.PM.locale, true) })) + '</h3>'
      + '<p>' + esc(equipment.code) + ' · ' + esc(equipment.name) + '</p></div>'
      + '<button type="button" class="reading-pop__close" data-reading-close aria-label="' + esc(t('js.close')) + '">×</button></header><dl>'
      + measure(series, index, 'hour_meter', 'h')
      + measure(series, index, 'odometer_km', 'km')
      + (reading.fuel_level === null ? '' : row(t('fields.fuel_level'), '<strong>' + esc(reading.fuel_level) + ' %</strong>'))
      + row(t('js.reading.by'), esc(reading.by || t('js.reading.unknown')))
      + (reading.note ? row(t('fields.note'), esc(reading.note)) : '')
      + '</dl></div></div>';
    $(document.body).append(html);
    $('.reading-pop__card').trigger('focus');
    document.addEventListener('keydown', onKey, true);
  }

  $(document).on('click', '.reading-pop', function (e) {
    if (e.target === this || $(e.target).is('[data-reading-close]')) close();
  });

  /**
   * Options Chart.js (onClick, onHover) pour une courbe dont la première série est celle des relevés
   * et dont chaque point porte « i », sa position dans la série de l'engin.
   *
   * @param {function(): {equipment: Object, series: Array}} context données de la courbe au moment du clic
   */
  function chartOptions(context) {
    function nearest(evt, chart) {
      var hits = chart.getElementsAtEventForMode(evt.native || evt, 'nearest', { intersect: false }, false)
        .filter(function (hit) { return hit.datasetIndex === 0; });
      if (!hits.length) return null;
      var point = hits[0].element;
      var position = Chart.helpers.getRelativePosition(evt.native || evt, chart);
      var distance = Math.sqrt(Math.pow(point.x - position.x, 2) + Math.pow(point.y - position.y, 2));
      return distance <= CLICK_RADIUS ? chart.data.datasets[0].data[hits[0].index] : null;
    }
    return {
      onClick: function (evt, elements, chart) {
        var point = nearest(evt, chart);
        if (!point) return;
        var ctx = context();
        open(ctx.equipment, ctx.series, point.i);
      },
      onHover: function (evt, elements, chart) {
        chart.canvas.style.cursor = nearest(evt, chart) ? 'pointer' : 'default';
      }
    };
  }

  window.PMReading = { open: open, close: close, chartOptions: chartOptions };
})(jQuery, window.PMCore);
