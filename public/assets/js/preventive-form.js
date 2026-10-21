/*
 * Formulaire de plan préventif, selon les règles du suivi des index :
 * - seuls les champs du compteur de suivi de l'engin sont affichés (l'autre compteur sur demande) ;
 * - à la création, le point de départ est proposé (index actuel, date du jour), visible et modifiable ;
 * - aperçu en direct : dernière réalisation → échéance, index actuel, avancement, et incohérences.
 */
(function ($, Core) {
  'use strict';

  var $form = $('[data-preventive-form]');
  if (!$form.length) return;
  var t = window.PM.t;
  var locale = window.PM.locale;
  var esc = Core.escapeHtml;
  var boot = JSON.parse(document.getElementById('preventive-data').textContent);
  var isNew = $form.data('new') === 1;
  var METERS = {
    hours: { unit: 'h', current: 'hour_meter', interval: 'interval_hours', done: 'last_done_hours', label: t('fields.hour_meter') },
    km: { unit: 'km', current: 'odometer_km', interval: 'interval_km', done: 'last_done_km', label: t('fields.odometer_km') }
  };
  var state = { equipment: boot.equipment, showOther: false };

  function num(value) { return Core.formatNumber(value, locale, 0); }
  function field(name) { return $form.find('[name="' + name + '"]'); }
  function intVal(name) { var v = field(name).val(); return v === '' || v === undefined ? null : parseInt(v, 10); }
  function basis() { return state.equipment && state.equipment.meter_basis ? state.equipment.meter_basis : null; }
  function other(b) { return b === 'km' ? 'hours' : 'km'; }
  function today() {
    var d = new Date(); // date locale, pas UTC
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }
  function hasValues(key) { var m = METERS[key]; return intVal(m.interval) !== null || intVal(m.done) !== null; }

  /** Champs visibles : compteur de suivi, plus l'autre s'il est déjà renseigné ou demandé. */
  function visibleMeters() {
    var b = basis();
    if (!b) return ['hours', 'km'];
    var o = other(b);
    return state.showOther || hasValues(o) ? [b, o] : [b];
  }

  function applyBasis() {
    var b = basis();
    var shown = visibleMeters();
    Object.keys(METERS).forEach(function (key) {
      $form.find('[data-meter="' + key + '"]').prop('hidden', shown.indexOf(key) === -1);
    });
    var $hint = $form.find('[data-preventive-basis-hint]');
    $hint.text($hint.data(b === 'km' ? 'km' : (b ? 'hours' : 'none')));
    var $toggle = $form.find('[data-preventive-other]');
    $toggle.prop('hidden', !b || shown.length > 1);
    if (b) $toggle.find('span').text($toggle.data(other(b) === 'km' ? 'km' : 'hours'));

    var e = state.equipment;
    $form.find('[data-preventive-meter]').prop('hidden', !e);
    if (e) {
      $form.find('[data-preventive-basis-label]').text(t(b === 'km' ? 'js.prev.basis_km' : 'js.prev.basis_hours'));
      var parts = [];
      if (e.hour_meter !== null) parts.push(num(e.hour_meter) + ' h');
      if (e.odometer_km !== null) parts.push(num(e.odometer_km) + ' km');
      $form.find('[data-preventive-current]').text(t('js.prev.current', { value: parts.length ? parts.join(' · ') : '—' }));
    }
    preview();
  }

  /** Point de départ proposé à la création : index actuel et date du jour, dans les champs encore vides. */
  function prefill() {
    if (!isNew || !state.equipment) return;
    (basis() ? [basis()] : Object.keys(METERS)).forEach(function (key) { // compteur de suivi seulement
      var $input = field(METERS[key].done);
      var current = state.equipment[METERS[key].current];
      if ($input.val() === '' && current !== null && current !== undefined) $input.val(current);
    });
    if (field('last_done_at').val() === '') field('last_done_at').val(today());
  }

  function addDays(date, days) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date || '');
    if (!m) return null;
    var d = new Date(+m[1], +m[2] - 1, +m[3] + days);
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  function previewMeter(key) {
    var m = METERS[key];
    var interval = intVal(m.interval);
    if (interval === null) return '';
    var start = intVal(m.done);
    var current = state.equipment ? state.equipment[m.current] : null;
    var head = '<div class="plan-preview__row"><strong>' + esc(m.label) + '</strong>';
    if (start === null) {
      return head + '<span class="meter-issue"><i data-lucide="alert-triangle"></i>' + esc(t('js.prev.start_missing', { unit: m.unit })) + '</span></div>';
    }
    var next = start + interval;
    var html = head + '<span>' + esc(t('js.meters.last_done', { value: num(start) + ' ' + m.unit })) + ' → '
      + esc(t('js.meters.next_at', { value: num(next) + ' ' + m.unit })) + '</span>';
    if (current === null || current === undefined) {
      return html + '<span class="muted small">' + esc(t('js.prev.no_current')) + '</span></div>';
    }
    if (start > current) {
      return html + '<span class="meter-issue"><i data-lucide="alert-triangle"></i>' + esc(t('js.prev.start_future', { value: num(current) + ' ' + m.unit })) + '</span></div>';
    }
    var pct = Math.round(Math.max(0, current - start) / interval * 100);
    var remaining = next - current;
    var cls = remaining <= 0 ? '' : (pct >= 90 ? ' life__bar--warn' : ' life__bar--good');
    return html + '<div class="life"><span class="life__bar' + cls + '"><span style="width:' + Math.min(100, pct) + '%"></span></span><strong>' + pct + ' %</strong></div>'
      + '<span class="small ' + (remaining <= 0 ? 'overdue' : 'muted') + '">' + esc(t('js.prev.current_short', { value: num(current) + ' ' + m.unit })) + ' · '
      + esc(remaining <= 0 ? t('js.meters.over', { n: num(-remaining), unit: m.unit }) : t('js.prev.remaining', { value: num(remaining) + ' ' + m.unit })) + '</span></div>';
  }

  function preview() {
    var rows = visibleMeters().map(previewMeter).filter(Boolean);
    var days = intVal('interval_days');
    if (days !== null) {
      var date = field('next_due_date').val() || addDays(field('last_done_at').val() || today(), days);
      rows.push('<div class="plan-preview__row"><strong>' + esc(t('js.prev.calendar')) + '</strong><span>'
        + esc(t('js.prev.next_date', { date: date ? Core.formatDate(date, locale) : '—' })) + '</span></div>');
    }
    if (basis() && intVal(METERS[basis()].interval) === null && intVal(METERS[other(basis())].interval) !== null) {
      rows.unshift('<div class="meter-issue"><i data-lucide="alert-triangle"></i>' + esc(t(basis() === 'km' ? 'js.prev.wrong_meter_km' : 'js.prev.wrong_meter_hours')) + '</div>');
    }
    $form.find('[data-preventive-preview]').html(rows.length ? rows.join('') : '<p class="muted">' + esc(t('js.prev.empty')) + '</p>');
    if (window.lucide) window.lucide.createIcons();
  }

  $form.on('pm:equipment-selected', '[data-equipment-picker]', function (e, item) {
    state.equipment = { hour_meter: item.hour_meter === null ? null : Number(item.hour_meter),
      odometer_km: item.odometer_km === null ? null : Number(item.odometer_km), meter_basis: item.meter_basis || 'hours' };
    state.showOther = false;
    prefill();
    applyBasis();
  });
  $form.on('click', '[data-picker-clear]', function () { state.equipment = null; applyBasis(); });
  $form.on('click', '[data-preventive-other]', function () { state.showOther = true; applyBasis(); });
  $form.on('input change', 'input', preview);

  prefill();
  applyBasis();
})(jQuery, window.PMCore);
