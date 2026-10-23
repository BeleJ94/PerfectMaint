/*
 * Carburant lu sur l'indicateur : la graduation saisie (« 2 » sur 4 tirets) est convertie en direct
 * en litres et en pourcentage, comme le fera le serveur (capacité du réservoir connue : litres, sinon %).
 */
(function ($) {
  'use strict';

  var t = window.PM.t;
  var locale = window.PM.locale;
  function fmt(n) { return Number(n).toLocaleString(locale === 'en' ? 'en-GB' : 'fr-FR', { maximumFractionDigits: 1 }); }

  function preview(field) {
    var $field = $(field);
    var $box = $field.find('[data-fuel-preview]');
    var $text = $box.find('[data-fuel-preview-text]');
    var $out = $text.length ? $text : $box; // texte seul : l'icône éventuelle est conservée
    var marks = Number(field.dataset.marks) || 0;
    var capacity = Number(field.dataset.capacity) || 0;
    var raw = String($field.find('input[name=fuel_mark], input[name=fuel_level]').val() || '').replace(',', '.');
    $box.removeClass('is-error');
    if (raw === '') {
      $out.text(marks ? t('js.fuel.hint', { n: marks }) : t('js.fuel.no_gauge'));
      return;
    }
    var value = Number(raw);
    if (marks) {
      if (isNaN(value) || value < 0 || value > marks || (value * 2) % 1 !== 0) {
        $box.addClass('is-error'); $out.text(t('js.fuel.range', { n: marks }));
        return;
      }
      var pct = Math.round(value / marks * 100);
      $out.text(capacity
        ? t('js.fuel.result', { mark: fmt(value), n: marks, liters: fmt(Math.round(value / marks * capacity * 10) / 10), pct: pct })
        : t('js.fuel.result_pct', { mark: fmt(value), n: marks, pct: pct }));
      return;
    }
    if (isNaN(value) || value < 0 || value > 100) {
      $box.addClass('is-error'); $out.text(t('js.fuel.range_pct'));
      return;
    }
    $out.text(capacity ? t('js.fuel.result_liters', { liters: fmt(Math.round(value / 100 * capacity * 10) / 10), pct: value }) : t('js.fuel.no_gauge'));
  }

  $(document).on('input', '[data-fuel-field] input[name=fuel_mark], [data-fuel-field] input[name=fuel_level]', function () { preview($(this).closest('[data-fuel-field]')[0]); });
  $('[data-fuel-field]').each(function () { preview(this); });
})(jQuery);
