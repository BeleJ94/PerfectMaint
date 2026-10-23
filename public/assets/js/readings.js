/*
 * Répertoire des index : filtre par engin avec recherche dynamique.
 * Saisie libre (code, nom, plaque, châssis) → suggestions limitées au site choisi, ↑ ↓ pour naviguer,
 * Entrée pour retenir, Échap pour fermer. Le choix recharge le tableau (champ caché data-filter).
 */
(function ($, Core) {
  'use strict';

  var $box = $('[data-eq-filter]');
  if (!$box.length) return;
  var t = window.PM.t;
  var $form = $box.closest('form');
  var $value = $box.find('[data-eq-value]');
  var $input = $box.find('[data-eq-input]');
  var $list = $box.find('[data-eq-list]');
  var $clear = $box.find('[data-eq-clear]');
  var items = [];
  var active = -1;
  var timer = null;
  var request = null;
  var chosen = $value.val() ? { label: $input.val(), site_id: String($value.data('site') || '') } : null;

  function close() {
    $list.prop('hidden', true).empty();
    $input.attr('aria-expanded', 'false').removeAttr('aria-activedescendant');
    items = [];
    active = -1;
  }

  /** Met en gras la partie saisie dans le libellé (texte échappé). */
  function highlight(text, term) {
    var i = term ? text.toLowerCase().indexOf(term.toLowerCase()) : -1;
    if (i < 0) return Core.escapeHtml(text);
    return Core.escapeHtml(text.slice(0, i)) + '<mark>' + Core.escapeHtml(text.slice(i, i + term.length)) + '</mark>'
      + Core.escapeHtml(text.slice(i + term.length));
  }

  function setActive(index) {
    active = index;
    $list.children().each(function (i) { $(this).toggleClass('is-active', i === index).attr('aria-selected', i === index ? 'true' : 'false'); });
    if (index >= 0) {
      var el = $list.children()[index];
      $input.attr('aria-activedescendant', el.id);
      el.scrollIntoView({ block: 'nearest' });
    }
  }

  function render(term) {
    $list.empty();
    if (!items.length) {
      $list.append($('<li class="eq-filter__empty">').text(t('js.no_result')));
    }
    items.forEach(function (item, i) {
      $list.append(
        '<li id="eq-opt-' + i + '" role="option" data-index="' + i + '">'
        + '<span><strong>' + highlight(item.code, term) + '</strong> · ' + highlight(item.name, term)
        + '<small>' + Core.escapeHtml(item.site) + '</small></span>'
        + Core.badge('status', item.status, t) + '</li>'
      );
    });
    $list.prop('hidden', false);
    $input.attr('aria-expanded', 'true');
    setActive(items.length ? 0 : -1);
  }

  function apply(id) {
    if ($value.val() === String(id)) return;
    $value.val(id).trigger('change'); // recharge le tableau et met à jour le compteur de filtres
  }

  function select(item) {
    chosen = { label: item.code + ' · ' + item.name, site_id: String(item.site_id) };
    $input.val(chosen.label);
    $clear.prop('hidden', false);
    close();
    apply(item.id);
  }

  function reset() {
    chosen = null;
    $input.val('');
    $clear.prop('hidden', true);
    close();
    apply('');
  }

  function search() {
    var q = $input.val().trim();
    if (!q) { close(); return; }
    if (request) request.abort();
    var params = { q: q };
    var site = $form.find('[name=site_id]').val();
    if (site) params.site_id = site;
    request = $.getJSON($box.data('search-url'), params);
    request.done(function (json) {
      items = json.results || [];
      render(q);
    });
  }

  $input.on('input', function () {
    // Le texte ne correspond plus à l'engin retenu : on attend un nouveau choix.
    if (chosen && $input.val() !== chosen.label) {
      chosen = null;
      apply('');
    }
    $clear.prop('hidden', $input.val() === '');
    clearTimeout(timer);
    timer = setTimeout(search, 200);
  });

  $input.on('focus', function () { if (!chosen && $input.val().trim()) search(); else if (chosen) this.select(); });

  $input.on('keydown', function (e) {
    var open = !$list.prop('hidden');
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      if (!open) { search(); return; }
      if (items.length) setActive((active + 1) % items.length);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      if (open && items.length) setActive((active - 1 + items.length) % items.length);
    } else if (e.key === 'Enter') {
      e.preventDefault(); // pas de soumission du formulaire de filtres
      if (open && items[active]) select(items[active]);
    } else if (e.key === 'Escape') {
      if (open) { e.preventDefault(); close(); }
    }
  });

  $list.on('mousedown', '[data-index]', function (e) {
    e.preventDefault(); // garde le focus dans le champ jusqu'au choix
    select(items[Number(this.dataset.index)]);
  });
  $list.on('mousemove', '[data-index]', function () { setActive(Number(this.dataset.index)); });

  $input.on('blur', function () { setTimeout(close, 120); });
  $clear.on('click', function () { reset(); $input.trigger('focus'); });

  // Changement de site : l'engin retenu d'un autre site est retiré du filtre.
  $form.on('change', '[name=site_id]', function () {
    if (chosen && chosen.site_id && this.value && chosen.site_id !== this.value) reset();
  });

  // « Réinitialiser » (traité ensuite par app.js, qui recharge le tableau) ne touche pas aux champs cachés : on vide le nôtre.
  $form.on('click', '[data-filters-reset]', function () {
    $value.val('');
    chosen = null;
    $input.val('');
    $clear.prop('hidden', true);
    close();
  });
})(jQuery, window.PMCore);

/*
 * Répertoire des index : un clic (ou Entrée) sur une ligne ouvre une modale avec le détail du relevé
 * et son formulaire. Enregistrer / Supprimer → fenêtre de confirmation → envoi AJAX → notification,
 * et le tableau se recharge à la même page. Une erreur de validation rouvre la modale avec la saisie.
 */
(function ($, Core) {
  'use strict';

  var $table = $('table[data-table="readings"]');
  if (!$table.length || !$.fn.dataTable.isDataTable($table)) return;
  var t = window.PM.t;
  var locale = document.documentElement.lang || 'fr';
  var api = $table.DataTable();
  var esc = Core.escapeHtml;
  var num = function (v, d) { return Core.formatNumber(v, locale, d || 0); };
  var fill = function (pattern, id) { return String(pattern).replace('{id}', id); };

  function markRows() { $table.find('tbody tr').addClass('row-modal').attr('tabindex', '0'); }
  api.on('draw', markRows);
  markRows();

  function toast(message) {
    Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: message, showConfirmButton: false, timer: 2200, timerProgressBar: true });
  }

  function field(name, label, type, value, attrs) {
    var extra = Object.keys(attrs || {}).map(function (k) { return ' ' + k + '="' + esc(attrs[k]) + '"'; }).join('');
    return '<div class="field"><label for="rd-' + name + '">' + esc(label) + '</label>'
      + '<input id="rd-' + name + '" name="' + name + '" type="' + type + '" class="input" value="'
      + esc(value === null || value === undefined ? '' : value) + '"' + extra + '></div>';
  }

  /** Contenu de la modale : fiche du relevé puis formulaire (valeurs du relevé, ou saisie à reprendre). */
  function content(r, values) {
    var v = values || {
      recorded_at: String(r.recorded_at).slice(0, 16).replace(' ', 'T'),
      hour_meter: r.hour_meter, odometer_km: r.odometer_km, fuel_level: r.fuel_level, note: r.note
    };
    var current = [];
    if (r.current_hours !== null) current.push(num(r.current_hours) + ' h');
    if (r.current_km !== null) current.push(num(r.current_km) + ' km');
    var fuel = r.fuel_level === null ? '—'
      : (r.fuel_liters !== null ? num(r.fuel_liters, 1) + ' L (' + r.fuel_level + ' %)' : r.fuel_level + ' %')
        + (r.fuel_mark !== null ? ' · ' + t('js.readings.mark', { mark: num(r.fuel_mark, 1), n: r.fuel_marks }) : '');
    var details = [
      [t('fields.equipment_id'), '<a href="' + esc(fill($table.data('equipment-url'), r.equipment_id)) + '"><strong>' + esc(r.code) + '</strong></a> · ' + esc(r.equipment_name)],
      [t('fields.site_id'), esc(r.site)],
      [t('js.user'), esc(r.recorded_by_name || '—')],
      [t('fields.fuel_level'), esc(fuel)],
      [t('js.readings.current'), esc(current.join(' · ') || '—')]
    ].map(function (d) { return '<div><dt>' + esc(d[0]) + '</dt><dd>' + d[1] + '</dd></div>'; }).join('');

    return '<h2 class="dialog-title">' + esc(t('js.readings.title', { code: r.code })) + '</h2>'
      + '<dl class="reading-details">' + details + '</dl>'
      + '<form class="form reading-form" novalidate>'
      + '<div class="form-grid">'
      + field('recorded_at', t('fields.recorded_at'), 'datetime-local', v.recorded_at, { required: 'required', max: $table.data('now') })
      + field('hour_meter', t('fields.hour_meter'), 'number', v.hour_meter, { min: 0, step: 1, inputmode: 'numeric' })
      + field('odometer_km', t('fields.odometer_km'), 'number', v.odometer_km, { min: 0, step: 1, inputmode: 'numeric' })
      + field('fuel_level', t('fields.fuel_level') + ' (%)', 'number', v.fuel_level, { min: 0, max: 100, step: 1, inputmode: 'numeric' })
      + '</div>'
      + field('note', t('fields.note'), 'text', v.note, { maxlength: 255 })
      + '<p class="hint">' + esc(t('js.readings.bounds')) + '</p>'
      + '</form>';
  }

  function formValues(form) {
    var out = {};
    $(form).serializeArray().forEach(function (f) { out[f.name] = f.value; });
    return out;
  }

  function errorText(xhr) {
    var json = xhr.responseJSON || {};
    if (json.errors) return Object.keys(json.errors).map(function (k) { return json.errors[k]; }).join('\n');
    return json.error || t('js.entry.offline');
  }

  function failed(xhr, retry) {
    if (xhr.status === 401) { window.location.reload(); return; }
    Swal.fire({ icon: 'error', title: t('js.readings.error'), text: errorText(xhr), confirmButtonText: t('js.readings.back'), confirmButtonColor: '#1F4E79' })
      .then(retry);
  }

  function open(r, values) {
    Swal.fire({
      html: content(r, values),
      width: 640,
      customClass: { popup: 'reading-popup', actions: 'confirm-actions', confirmButton: 'btn btn--primary', denyButton: 'btn btn--danger', cancelButton: 'btn btn--ghost' },
      buttonsStyling: false,
      showCancelButton: true,
      showDenyButton: true,
      confirmButtonText: '<i data-lucide="save"></i>' + esc(t('js.save')),
      denyButtonText: '<i data-lucide="trash-2"></i>' + esc(t('js.readings.delete')),
      cancelButtonText: esc(t('js.cancel')),
      reverseButtons: true,
      focusConfirm: false,
      didOpen: function (popup) {
        if (window.lucide) window.lucide.createIcons();
        var first = popup.querySelector('input[name=hour_meter]');
        if (first) first.focus();
      },
      preConfirm: function () {
        var form = Swal.getHtmlContainer().querySelector('form');
        var values = formValues(form);
        if (!form.reportValidity()) return false;
        if (values.hour_meter === '' && values.odometer_km === '') {
          Swal.showValidationMessage(t('js.readings.index_required'));
          return false;
        }
        return { form: form, values: values, rows: window.PM.summaryRows(form) };
      },
      preDeny: function () {
        return { values: formValues(Swal.getHtmlContainer().querySelector('form')) };
      }
    }).then(function (res) {
      if (res.isConfirmed) save(r, res.value);
      else if (res.isDenied) remove(r, res.value.values);
    });
  }

  function save(r, payload) {
    window.PM.confirmSubmit(payload.form, {
      title: t('js.readings.confirm_save', { code: r.code }), kind: 'save', rows: payload.rows
    }).then(function (ok) {
      if (!ok) { open(r, payload.values); return; }
      Swal.fire({ title: t('js.dt.processing'), allowOutsideClick: false, didOpen: function () { Swal.showLoading(); } });
      $.post(fill($table.data('reading-url'), r.id), payload.values)
        .done(function (json) { toast(json.message); api.ajax.reload(null, false); })
        .fail(function (xhr) { failed(xhr, function () { open(r, payload.values); }); });
    });
  }

  function remove(r, values) {
    window.PM.confirmSubmit(document.createElement('form'), {
      title: t('js.readings.confirm_delete', { code: r.code }),
      message: t('js.readings.delete_message', { code: r.code, date: Core.formatDate(r.recorded_at, locale, true) }),
      kind: 'danger', rows: []
    }).then(function (ok) {
      if (!ok) { open(r, values); return; }
      Swal.fire({ title: t('js.dt.processing'), allowOutsideClick: false, didOpen: function () { Swal.showLoading(); } });
      $.post(fill($table.data('reading-url'), r.id) + '/delete')
        .done(function (json) { toast(json.message); api.ajax.reload(null, false); })
        .fail(function (xhr) { failed(xhr, function () { open(r, values); }); });
    });
  }

  $table.on('click keydown', 'tbody tr', function (e) {
    if (e.type === 'keydown' && e.key !== 'Enter') return;
    if ($(e.target).closest('a, button, input, select, .select-cell').length) return;
    var r = api.row(this).data();
    if (r) open(r);
  });
})(jQuery, window.PMCore);

/*
 * Répertoire des index : suppression en lot. Les cases cochées sont mémorisées d'une page à l'autre
 * (vidées si les filtres changent) ; la case d'en-tête coche / décoche la page affichée.
 * Confirmation récapitulative → envoi AJAX → notification → rechargement du tableau.
 */
(function ($, Core) {
  'use strict';

  var $table = $('table[data-table="readings"]');
  var $bar = $('[data-bulk-bar]');
  if (!$table.length || !$bar.length || !$.fn.dataTable.isDataTable($table)) return;
  var t = window.PM.t;
  var locale = document.documentElement.lang || 'fr';
  var api = $table.DataTable();
  var selected = {}; // id → libellé (engin · date) pour le récapitulatif

  function ids() { return Object.keys(selected); }

  function refresh() {
    var n = ids().length;
    $bar.prop('hidden', n === 0);
    $bar.find('[data-bulk-count]').text(t('js.readings.selected', { n: n }));
    var $rows = $table.find('[data-select-row]');
    $rows.each(function () {
      this.checked = !!selected[this.value];
      $(this).closest('tr').toggleClass('is-selected', this.checked);
    });
    var checked = $rows.filter(':checked').length;
    var head = $(api.table().header()).find('[data-select-page]')[0];
    if (head) {
      head.checked = $rows.length > 0 && checked === $rows.length;
      head.indeterminate = checked > 0 && checked < $rows.length;
    }
  }

  function label(row) { return row.code + ' · ' + Core.formatDate(row.recorded_at, locale, true); }

  api.on('draw', refresh);

  $table.on('change', '[data-select-row]', function () {
    var row = api.row($(this).closest('tr')).data();
    if (this.checked && row) selected[this.value] = label(row); else delete selected[this.value];
    refresh();
  });

  $(api.table().header()).on('change', '[data-select-page]', function () {
    var on = this.checked;
    api.rows({ page: 'current' }).data().each(function (row) {
      if (on) selected[row.id] = label(row); else delete selected[row.id];
    });
    refresh();
  });
  // La case d'en-tête ne trie pas la colonne.
  $(api.table().header()).on('click', '[data-select-page]', function (e) { e.stopPropagation(); });

  // Clic dans la cellule de sélection : coche la case, sans ouvrir la modale du relevé.
  $table.on('click', 'td.select-cell', function (e) {
    e.stopPropagation();
    if (e.target.tagName !== 'INPUT') $(this).find('input').prop('checked', function (i, v) { return !v; }).trigger('change');
  });

  // Filtres modifiés : la sélection ne correspond plus à ce qui est affiché.
  $(document).on('change', 'form[data-filters-for="readings"] [data-filter]', function () { selected = {}; refresh(); });
  $(document).on('click', 'form[data-filters-for="readings"] [data-filters-reset]', function () { selected = {}; refresh(); });

  $bar.on('click', '[data-bulk-clear]', function () { selected = {}; refresh(); });

  $bar.on('click', '[data-bulk-delete]', function () {
    var list = ids();
    if (!list.length) return;
    var rows = list.map(function (id) { return [t('js.readings.reading'), selected[id]]; });
    window.PM.confirmSubmit(document.createElement('form'), {
      title: t('js.readings.bulk_title', { n: list.length }),
      message: t('js.readings.bulk_message', { n: list.length }),
      kind: 'danger',
      confirmLabel: t('js.readings.bulk_ok', { n: list.length }),
      rows: rows
    }).then(function (ok) {
      if (!ok) return;
      Swal.fire({ title: t('js.dt.processing'), allowOutsideClick: false, didOpen: function () { Swal.showLoading(); } });
      $.post($bar.data('url'), { ids: list })
        .done(function (json) {
          selected = {};
          Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: json.message, showConfirmButton: false, timer: 2600, timerProgressBar: true });
          api.ajax.reload(null, false);
        })
        .fail(function (xhr) {
          if (xhr.status === 401) { window.location.reload(); return; }
          var json = xhr.responseJSON || {};
          var text = json.errors ? Object.keys(json.errors).map(function (k) { return json.errors[k]; }).join('\n') : (json.error || t('js.entry.offline'));
          Swal.fire({ icon: 'error', title: t('js.readings.error'), text: text, confirmButtonColor: '#1F4E79' });
        });
    });
  });

  refresh();
})(jQuery, window.PMCore);
