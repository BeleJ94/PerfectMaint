/*
 * Saisie des index (encodeurs) : chaque carte s'enregistre seule, sans recharger la page.
 * Un écart anormal demande confirmation ; en cas de coupure réseau la saisie reste dans le champ.
 */
(function ($) {
  'use strict';

  var t = window.PM.t;

  // Rapport de saisie : le choix d'une date recharge la page (les listes déroulantes le font déjà).
  $(document).on('change', 'input[data-autosubmit]', function () { if (this.value) this.form.submit(); });

  // Rapport d'un jour passé : rattrapage d'un engin non relevé ; la page est rechargée une fois le relevé enregistré.
  function backfill($form, confirmed) {
    var $button = $form.find('button[type=submit]');
    $form.find('[data-entry-error]').prop('hidden', true);
    $button.prop('disabled', true);
    $.post($form.attr('action'), $form.serialize() + (confirmed ? '&confirm=1' : ''))
      .done(function (json) {
        Swal.fire({ toast: true, position: 'top', icon: 'success', title: json.message, showConfirmButton: false, timer: 1200 })
          .then(function () { window.location.reload(); });
      })
      .fail(function (xhr) {
        $button.prop('disabled', false);
        var json = xhr.responseJSON || {};
        if (xhr.status === 409 && json.confirm) {
          Swal.fire({
            icon: 'warning', title: t('js.entry.check'), text: json.confirm, showCancelButton: true, reverseButtons: true,
            confirmButtonText: t('js.entry.confirm'), cancelButtonText: t('js.entry.fix'), confirmButtonColor: '#1F4E79'
          }).then(function (res) { if (res.isConfirmed) backfill($form, true); });
        } else if (xhr.status === 401) {
          window.location.reload();
        } else {
          var message = json.errors ? Object.keys(json.errors).map(function (k) { return json.errors[k]; }).join(' ') : (json.error || t('js.entry.offline'));
          $form.find('[data-entry-error]').text(message).prop('hidden', false);
        }
      });
  }
  $(document).on('submit', '[data-backfill-form]', function (e) {
    e.preventDefault();
    var $form = $(this);
    window.PM.confirmSubmit(this, {
      title: t('js.entry.backfill_title', { code: $form.closest('[data-entry-card]').find('.entry-card__head strong').first().text() }),
      kind: 'save',
      confirmLabel: t('js.entry.confirm_ok')
    }).then(function (ok) { if (ok) backfill($form, false); });
  });

  // Carte repliée : le formulaire ne s'ouvre qu'à la demande (correction, nouveau relevé ou rattrapage).
  $(document).on('click', '[data-entry-edit]', function () {
    var $form = $(this).closest('[data-entry-card]').find('[data-entry-form], [data-backfill-form]');
    $(this).prop('hidden', true);
    $form.prop('hidden', false).find('input[type=number]').first().trigger('focus').trigger('select');
  });

  var $todo = $('[data-entry-todo]');
  var $done = $('[data-entry-done-list]');
  if (!$todo.length) return;

  function icons() { if (window.lucide) window.lucide.createIcons(); }

  function refreshCounts() {
    var todo = $todo.children('[data-entry-card]').length;
    var done = $done.children('[data-entry-card]').length;
    var total = todo + done;
    $('[data-entry-done], [data-entry-done-count]').text(done);
    $('[data-entry-total]').text(total);
    $('[data-entry-todo-count]').text(todo);
    $('[data-entry-bar]').css('width', (total ? Math.round(done / total * 100) : 0) + '%');
    $('[data-entry-todo-title]').prop('hidden', todo === 0);
    $('[data-entry-done-title]').prop('hidden', done === 0);
    $('[data-entry-all-done]').prop('hidden', todo !== 0 || total === 0);
  }

  function showError($form, message) {
    $form.find('[data-entry-error]').text(message).prop('hidden', false);
  }

  function submit($form, confirmed) {
    var $card = $form.closest('[data-entry-card]');
    var $button = $form.find('button[type=submit]');
    $form.find('[data-entry-error]').prop('hidden', true);
    $button.prop('disabled', true);
    $.post($form.attr('action'), $form.serialize() + (confirmed ? '&confirm=1' : ''))
      .done(function (json) {
        var wasTodo = $card.attr('data-done') === '0';
        var $next = wasTodo ? $card.nextAll('[data-entry-card]:visible').first() : $();
        $card.remove();
        $done.prepend(json.html);
        icons();
        refreshCounts();
        applySearch();
        Swal.fire({ toast: true, position: 'top', icon: 'success', title: json.message, showConfirmButton: false, timer: 1800 });
        // Engin suivant : le curseur passe directement dans son premier champ.
        if ($next.length) $next.find('input[type=number]').first().trigger('focus');
      })
      .fail(function (xhr) {
        $button.prop('disabled', false);
        var json = xhr.responseJSON || {};
        if (xhr.status === 409 && json.confirm) {
          Swal.fire({
            icon: 'warning', title: t('js.entry.check'), text: json.confirm, showCancelButton: true, reverseButtons: true,
            confirmButtonText: t('js.entry.confirm'), cancelButtonText: t('js.entry.fix'), confirmButtonColor: '#1F4E79'
          }).then(function (res) {
            if (res.isConfirmed) submit($form, true);
            else $form.find('input[type=number]').first().trigger('focus');
          });
        } else if (json.errors) {
          showError($form, Object.keys(json.errors).map(function (k) { return json.errors[k]; }).join(' '));
        } else if (xhr.status === 401) {
          window.location.reload(); // session expirée : retour à la connexion
        } else {
          showError($form, json.error || t('js.entry.offline'));
        }
      });
  }

  // Confirmation avec résumé avant l'envoi : engin, index saisis, carburant converti en litres.
  $(document).on('submit', '[data-entry-form]', function (e) {
    e.preventDefault();
    var $form = $(this);
    var $card = $form.closest('[data-entry-card]');
    var $fuel = $form.find('[data-fuel-field]');
    var fuelInput = $fuel.find('input[name=fuel_mark], input[name=fuel_level]')[0];
    var rows = window.PM.summaryRows(this).map(function (row) {
      // Carburant : la graduation seule ne parle pas, on affiche la conversion (« 2 / 4 → 40 L (50 %) »).
      return fuelInput && fuelInput.value !== '' && row[1] === fuelInput.value ? [row[0], $fuel.find('[data-fuel-preview]').text().trim()] : row;
    });
    window.PM.confirmSubmit(this, {
      title: t('js.entry.confirm_title', { code: $card.find('.entry-card__head strong').first().text() }),
      kind: 'save',
      confirmLabel: t('js.entry.confirm_ok'),
      rows: rows
    }).then(function (ok) {
      if (ok) submit($form, false);
      else $form.find('input[type=number]').first().trigger('focus');
    });
  });

  function applySearch() {
    var term = ($('[data-entry-search]').val() || '').trim().toLowerCase();
    var shown = 0;
    $('[data-entry-card]').each(function () {
      var match = term === '' || this.dataset.search.indexOf(term) !== -1;
      this.hidden = !match;
      if (match) shown++;
    });
    $('[data-entry-no-match]').prop('hidden', shown > 0 || term === '');
  }
  $(document).on('input', '[data-entry-search]', applySearch);
})(jQuery);
