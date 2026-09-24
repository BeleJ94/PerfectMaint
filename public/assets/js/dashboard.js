/*
 * Tableau de bord : graphiques Chart.js.
 * - OT créés par semaine : barres empilées, 2 séries (correctif = accent, préventif = accent clair),
 *   palette validée (séparation daltonisme ΔE ≥ 19, contraste ≥ 3:1), légende + infobulle + vue tableau.
 * - État du parc : barres horizontales aux couleurs d'état imposées, libellés sur l'axe (jamais la couleur seule).
 */
(function (Core) {
  'use strict';
  if (!window.Chart) return;

  var el = document.getElementById('dashboard-data');
  if (!el) return;
  var data = JSON.parse(el.textContent);
  var t = window.PM.t;
  var locale = window.PM.locale;

  var INK = '#1B2430';
  var MUTED = '#5B6573';
  var GRID = '#EEF0F3';
  var SURFACE = '#FFFFFF';
  var SERIES = { corrective: '#1F4E79', preventive: '#4F86C0' };

  Chart.defaults.font.family = '"IBM Plex Sans", system-ui, sans-serif';
  Chart.defaults.font.size = 12;
  Chart.defaults.color = MUTED;

  var tooltip = {
    backgroundColor: SURFACE,
    titleColor: INK,
    bodyColor: INK,
    borderColor: '#DDE1E6',
    borderWidth: 1,
    padding: 10,
    boxPadding: 4,
    usePointStyle: true
  };

  var weekly = document.getElementById('chart-weekly');
  if (weekly) {
    new Chart(weekly, {
      type: 'bar',
      data: {
        labels: data.weekly.labels,
        datasets: ['corrective', 'preventive'].map(function (key, i) {
          return {
            label: t('wo_type.' + key),
            data: data.weekly[key],
            backgroundColor: SERIES[key],
            borderColor: SURFACE,
            borderWidth: { top: i === 1 ? 0 : 2 }, // espace de 2 px entre segments empilés
            borderRadius: i === 1 ? { topLeft: 4, topRight: 4 } : 0,
            borderSkipped: false,
            maxBarThickness: 36
          };
        })
      },
      options: {
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: {
          x: { stacked: true, grid: { display: false }, border: { color: '#DDE1E6' } },
          y: {
            stacked: true,
            beginAtZero: true,
            ticks: { precision: 0 },
            grid: { color: GRID },
            border: { display: false }
          }
        },
        plugins: {
          legend: { position: 'top', align: 'end', labels: { usePointStyle: true, pointStyle: 'rectRounded', color: INK } },
          tooltip: $.extend({}, tooltip, {
            callbacks: {
              footer: function (items) {
                var sum = items.reduce(function (s, it) { return s + it.parsed.y; }, 0);
                return t('js.total') + ' : ' + Core.formatNumber(sum, locale, 0);
              }
            }
          })
        }
      }
    });
  }

  var status = document.getElementById('chart-status');
  if (status) {
    var keys = Core.STATUS_ORDER;
    new Chart(status, {
      type: 'bar',
      data: {
        labels: keys.map(function (k) { return t('status.' + k); }),
        datasets: [{
          label: t('js.equipment_count'),
          data: keys.map(function (k) { return data.equipment.by_status[k] || 0; }),
          backgroundColor: keys.map(function (k) { return Core.STATUS_COLORS[k]; }),
          borderRadius: 4,
          borderSkipped: 'start',
          maxBarThickness: 22
        }]
      },
      options: {
        indexAxis: 'y',
        maintainAspectRatio: false,
        scales: {
          x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: GRID }, border: { display: false } },
          y: { grid: { display: false }, ticks: { color: INK } }
        },
        plugins: { legend: { display: false }, tooltip: tooltip }
      }
    });
  }
})(window.PMCore);
