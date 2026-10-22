/*
 * Fiche engin (création / modification) :
 * - valeurs héritées affichées là où elles s'appliquent : « Selon la catégorie (Kilométrage) »,
 *   « Selon le modèle (Aiguille 4 graduations) », capacité du modèle en indication ;
 * - encadré « ce que le modèle apporte » sous le choix du modèle ;
 * - synthèse fixe (ce que l'engin héritera réellement et d'où), sections complètes ou non, section courante ;
 * - avertissement si l'on quitte la page avec des modifications non enregistrées.
 */
(function ($) {
  'use strict';

  var $form = $('[data-equipment-form]');
  if (!$form.length) return;
  var t = window.PM.t;
  var Core = window.PMCore;
  var form = $form[0];
  var $category = $('#f-category_id');
  var $model = $('#f-model_id');
  var $brand = $('#f-brand_id');
  var $basis = $form.find('[data-inherit-basis]');
  var $gauge = $form.find('[data-inherit-gauge]');
  var $capacity = $form.find('[data-inherit-capacity]');
  var basisLabel = function (b) { return b === 'km' ? t('js.eqf.km') : t('js.eqf.hours'); };
  function selected($select) { var o = $select.find('option:selected')[0]; return o && o.value ? o : null; }
  function text($select) { var o = selected($select); return o ? o.textContent.trim() : ''; }

  function source(label, from) { return label + ' <span class="summary-source">' + Core.escapeHtml(from) + '</span>'; }

  function refresh() {
    var cat = selected($category);
    var mdl = selected($model);
    var catBasis = cat ? cat.dataset.basis || 'hours' : 'hours';
    var modelGauge = mdl ? mdl.dataset.gauge : '';
    var modelCapacity = mdl ? mdl.dataset.capacity : '';

    // Valeurs héritées affichées dans les listes elles-mêmes.
    $basis.find('option[value=""]').text(t('js.eqf.basis_inherit', { value: basisLabel(catBasis) }));
    $gauge.find('option[value=""]').text(mdl
      ? t('js.eqf.gauge_inherit', { value: modelGauge || t('js.eqf.not_set') })
      : t('js.eqf.gauge_no_model'));
    $capacity.attr('placeholder', mdl && modelCapacity ? t('js.eqf.capacity_inherit', { n: modelCapacity }) : '—');

    // Ce que le modèle apporte.
    var $brings = $form.find('[data-model-brings]');
    if (mdl) {
      var catName = mdl.dataset.category ? $category.find('option[value="' + mdl.dataset.category + '"]').text() : '';
      var parts = [];
      if (catName) parts.push(t('js.eqf.brings_category', { value: catName }));
      parts.push(t('js.eqf.brings_gauge', { value: modelGauge || t('js.eqf.not_set') }));
      parts.push(t('js.eqf.brings_capacity', { value: modelCapacity ? modelCapacity + ' L' : t('js.eqf.not_set') }));
      $brings.html('<i data-lucide="sparkles"></i><span>' + Core.escapeHtml(t('js.eqf.brings')) + ' ' + Core.escapeHtml(parts.join(' · ')) + '</span>')
        .toggleClass('is-incomplete', !modelGauge || !modelCapacity).prop('hidden', false);
    } else {
      $brings.prop('hidden', true);
    }

    // Synthèse : valeur effective et origine (catégorie, modèle, engin).
    var out = function (key, html) { $form.find('[data-summary-out="' + key + '"]').html(html); };
    out('code', Core.escapeHtml($form.find('[name=code]').val() || '—'));
    out('name', Core.escapeHtml($form.find('[name=name]').val() || ''));
    out('site', Core.escapeHtml(text($form.find('[name=site_id]'))));
    out('model', mdl ? Core.escapeHtml((text($brand) + ' ' + mdl.textContent).trim()) : '<span class="summary-missing">' + Core.escapeHtml(t('js.eqf.no_model')) + '</span>');
    var ownBasis = $basis.val();
    out('basis', ownBasis ? source(basisLabel(ownBasis), t('js.eqf.from_equipment')) : source(basisLabel(catBasis), cat ? t('js.eqf.from_category') : t('js.eqf.by_default')));
    var ownGauge = $gauge.val();
    out('gauge', ownGauge ? source(Core.escapeHtml(text($gauge)), t('js.eqf.from_equipment'))
      : (modelGauge ? source(Core.escapeHtml(modelGauge), t('js.eqf.from_model')) : '<span class="summary-missing">' + Core.escapeHtml(t('js.eqf.not_set')) + '</span>'));
    var ownCapacity = $capacity.val();
    out('capacity', ownCapacity ? source(Core.escapeHtml(ownCapacity) + ' L', t('js.eqf.from_equipment'))
      : (modelCapacity ? source(Core.escapeHtml(modelCapacity) + ' L', t('js.eqf.from_model')) : '<span class="summary-missing">' + Core.escapeHtml(t('js.eqf.not_set')) + '</span>'));
    var $own = $form.find('[name=ownership]:checked');
    var end = $form.find('[name=contract_end]').val();
    out('ownership', Core.escapeHtml($own.next('span').text() || '—') + ($own.val() !== 'owned' && end ? ' <span class="summary-source">' + Core.escapeHtml(t('js.eqf.until', { date: Core.formatDate(end, window.PM.locale) })) + '</span>' : ''));

    // Sections : complète (✓) ou à compléter (!).
    var state = function (key, ok) {
      $form.find('[data-section-state="' + key + '"]').attr('class', 'summary-nav__state ' + (ok ? 'is-ok' : 'is-todo')).text(ok ? '✓' : '!');
    };
    state('identity', !!($form.find('[name=code]').val() && $form.find('[name=name]').val() && $form.find('[name=site_id]').val()));
    state('model', !!mdl);
    state('meters', ownBasis || cat ? !!(ownGauge || modelGauge) : false);
  }

  $form.on('change input', 'input, select, textarea', refresh);
  // Le choix d'un modèle peut remplir la catégorie et la marque (script commun) : synthèse recalculée ensuite.
  $model.on('change', function () { setTimeout(refresh, 0); });
  refresh();

  // ---------------------------------------------------------------- Section courante dans la navigation
  if ('IntersectionObserver' in window) {
    var links = {};
    $form.find('[data-section-link]').each(function () { links['sec-' + this.dataset.sectionLink] = this; });
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting || !links[entry.target.id]) return;
        $form.find('[data-section-link]').removeClass('is-current');
        links[entry.target.id].classList.add('is-current');
      });
    }, { rootMargin: '-30% 0px -60% 0px' });
    $form.find('.form-section[id]').each(function () { observer.observe(this); });
  }

  // ---------------------------------------------------------------- Modifications non enregistrées
  var initial = $form.serialize();
  var submitting = false;
  $form.on('change input', function () { $form.find('[data-dirty-note]').prop('hidden', $form.serialize() === initial); });
  $form.on('pm:confirmed', function () { submitting = true; });
  window.addEventListener('beforeunload', function (e) {
    if (submitting || $form.serialize() === initial) return;
    e.preventDefault();
    e.returnValue = t('js.unsaved_changes');
  });
  if (form.querySelector('.error')) form.querySelector('.error').closest('.form-section').scrollIntoView({ block: 'start' });
})(jQuery);
