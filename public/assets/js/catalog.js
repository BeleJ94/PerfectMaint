/*
 * Référentiel : onglets (mémorisés dans l'adresse, #models, #brands…), filtres du tableau des modèles
 * (marque, « à compléter », recherche), bouton Enregistrer qui n'apparaît que sur une ligne modifiée,
 * compteur de suivi d'une catégorie enregistré dès le choix.
 */
(function ($) {
  'use strict';

  var $tabs = $('[data-catalog-tab]');
  if (!$tabs.length) return;
  var t = window.PM.t;

  // ---------------------------------------------------------------- Onglets
  function show(name) {
    if (!$('[data-catalog-panel="' + name + '"]').length) name = 'models';
    $tabs.each(function () { this.setAttribute('aria-selected', String(this.dataset.catalogTab === name)); });
    $('[data-catalog-panel]').each(function () { this.hidden = this.dataset.catalogPanel !== name; });
    if (window.history.replaceState) window.history.replaceState(null, '', '#' + name);
  }
  $tabs.on('click', function () { show(this.dataset.catalogTab); });
  // Flèches gauche / droite entre onglets (clavier).
  $tabs.on('keydown', function (e) {
    if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
    var i = $tabs.index(this) + (e.key === 'ArrowRight' ? 1 : -1);
    var next = $tabs.get((i + $tabs.length) % $tabs.length);
    next.focus();
    show(next.dataset.catalogTab);
  });
  show((window.location.hash || '#models').slice(1));

  // ---------------------------------------------------------------- Filtres des modèles
  var $rows = $('[data-model-row]');
  function filter() {
    var brand = $('[data-models-brand]').val();
    var onlyIncomplete = $('[data-models-incomplete]').prop('checked');
    var q = String($('[data-models-search]').val() || '').trim().toLowerCase();
    var shown = 0;
    $rows.each(function () {
      var ok = (!brand || this.dataset.brand === brand)
        && (!onlyIncomplete || this.dataset.incomplete === '1')
        && (!q || this.dataset.search.indexOf(q) !== -1);
      this.hidden = !ok;
      if (ok) shown++;
    });
    if ($rows.length) $('[data-models-empty]').prop('hidden', shown > 0);
  }
  $(document).on('change input', '[data-models-brand], [data-models-incomplete], [data-models-search]', filter);

  // Raccourcis : indicateur de pilotage, nom de marque → onglet Modèles filtré.
  $(document).on('click', '[data-catalog-goto]', function () {
    if (this.dataset.catalogFilter === 'incomplete') $('[data-models-incomplete]').prop('checked', true);
    if (this.dataset.catalogBrand) $('[data-models-brand]').val(this.dataset.catalogBrand);
    show(this.dataset.catalogGoto);
    filter();
    var panel = document.getElementById('panel-' + this.dataset.catalogGoto);
    if (panel) panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  // ---------------------------------------------------------------- Ligne modifiée : bouton Enregistrer visible
  $('[data-dirty-watch]').each(function () { this.dataset.initial = $(this).val(); });
  $(document).on('change input', '[data-dirty-watch]', function () {
    var $row = $(this).closest('tr');
    var dirty = $row.find('[data-dirty-watch]').toArray().some(function (el) { return $(el).val() !== el.dataset.initial; });
    $row.toggleClass('is-dirty', dirty).find('[data-dirty-save]').prop('hidden', !dirty);
  });
  window.addEventListener('beforeunload', function (e) {
    if (!$('tr.is-dirty').length) return;
    e.preventDefault();
    e.returnValue = t('js.unsaved_changes');
  });
  $(document).on('pm:confirmed', 'form', function () { $('tr.is-dirty').removeClass('is-dirty'); });

  // ---------------------------------------------------------------- Catégories : enregistrement dès le choix
  $('[data-autosubmit-form]').each(function () { this.dataset.initial = $(this).find('input:checked').val() || ''; });
  $(document).on('change', '[data-autosubmit-form] input[type=radio]', function () {
    if (this.form.requestSubmit) this.form.requestSubmit(); else $(this.form).trigger('submit');
  });
  // Confirmation annulée : le choix revient à la valeur enregistrée.
  $(document).on('pm:cancelled', '[data-autosubmit-form]', function () {
    $(this).find('input[value="' + this.dataset.initial + '"]').prop('checked', true);
  });

  // Nouvel indicateur : le nombre de graduations n'a de sens que pour un indicateur à graduations.
  $(document).on('change', '[data-gauge-kind]', function () {
    var marks = $(this).val() === 'marks';
    $(this.form).find('[data-gauge-marks]').prop({ disabled: !marks, required: marks }).val(marks ? $(this.form).find('[data-gauge-marks]').val() : '');
  });
  $('[data-gauge-kind]').trigger('change');
})(jQuery);
