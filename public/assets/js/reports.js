/*
 * Rapport de direction : graphiques (Chart.js). Données lues depuis le bloc JSON #report-data (CSP stricte).
 */
(function () {
  'use strict';

  var el = document.getElementById('report-data');
  if (!el || !window.Chart) return;
  var data;
  try { data = JSON.parse(el.textContent); } catch (e) { return; }

  var urls = data.urls || {};
  var t = window.PM ? window.PM.t : function (k) { return k; };
  function drill(spec) {
    if (window.PM && window.PM.drill) window.PM.drill(spec);
  }
  function pointer(evt, elements) {
    evt.native.target.style.cursor = elements.length ? 'pointer' : 'default';
  }
  var siteParams = data.site_id ? { site_id: data.site_id } : {};

  var font = { family: getComputedStyle(document.body).fontFamily, size: 12 };
  Chart.defaults.font = font;
  Chart.defaults.color = '#5B6573';

  // Couleurs par cause : les causes liées à l'utilisation ressortent en rouge.
  var CAUSE_COLORS = {
    wear: '#1E7A4C', misuse: '#B42318', maintenance_lapse: '#1F4E79', accident: '#E04F39',
    component_failure: '#B54708', external: '#8D9098', unknown: '#C4CAD2'
  };

  var causes = document.getElementById('chart-causes');
  if (causes && data.values.length) {
    new Chart(causes, {
      type: 'doughnut',
      data: {
        labels: data.labels,
        datasets: [{ data: data.values, backgroundColor: data.keys.map(function (k) { return CAUSE_COLORS[k] || '#C4CAD2'; }), borderWidth: 2, borderColor: '#fff' }]
      },
      options: {
        maintainAspectRatio: false,
        cutout: '62%',
        onHover: pointer,
        onClick: function (evt, elements) {
          if (!elements.length) return;
          var i = elements[0].index;
          drill({
            table: 'work_orders', source: urls.work_orders, row: urls.work_order, page: null, chips: [],
            title: data.titles.causes + ' · ' + data.labels[i],
            params: Object.assign({ cause: data.keys[i], type: 'corrective', closed_days: data.days }, siteParams)
          });
        },
        plugins: { legend: { position: 'right', labels: { boxWidth: 12, padding: 12 } } }
      }
    });
  }

  var availability = document.getElementById('chart-availability');
  if (availability && data.sites.length) {
    new Chart(availability, {
      type: 'bar',
      data: {
        labels: data.sites,
        datasets: [{
          data: data.availability,
          backgroundColor: data.availability.map(function (v) { return v >= 85 ? '#1E7A4C' : (v >= 70 ? '#B54708' : '#B42318'); }),
          borderRadius: 6,
          maxBarThickness: 36
        }]
      },
      options: {
        maintainAspectRatio: false,
        indexAxis: 'y',
        onHover: pointer,
        onClick: function (evt, elements) {
          if (!elements.length) return;
          var i = elements[0].index;
          drill({
            table: 'equipment', source: urls.equipment, row: urls.equipment_row, page: null,
            title: data.sites[i] + ' · ' + data.titles.fleet, params: { site_id: data.site_ids[i] },
            chips: [
              { label: t('js.all'), params: {} },
              { label: t('status.breakdown'), params: { status: 'breakdown' } },
              { label: t('status.maintenance'), params: { status: 'maintenance' } },
              { label: t('status.waiting_part'), params: { status: 'waiting_part' } }
            ]
          });
        },
        scales: {
          x: { min: 0, max: 100, ticks: { callback: function (v) { return v + ' %'; } }, grid: { color: '#EEF0F3' } },
          y: { grid: { display: false } }
        },
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return c.parsed.x + ' %'; } } } }
      }
    });
  }
})();
