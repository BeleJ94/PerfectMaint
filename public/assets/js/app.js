/*
 * PerfectMaint — comportements d'interface (jQuery, DataTables, SweetAlert2, Lucide).
 * Aucun script inline : configuration et traductions sont lues depuis des blocs JSON.
 */
(function ($, Core, Tables, Exporter) {
  'use strict';

  function readJson(id) {
    var el = document.getElementById(id);
    if (!el) return {};
    try { return JSON.parse(el.textContent); } catch (e) { return {}; }
  }

  var config = readJson('pm-config');
  var t = Core.createTranslator(readJson('pm-i18n'));
  var locale = config.locale || 'fr';
  Core.setCurrency(config.currency);
  var csrf = $('meta[name="csrf-token"]').attr('content');

  window.PM = { t: t, locale: locale, config: config };

  // Tous les appels AJAX portent le jeton CSRF et s'identifient comme AJAX (réponses JSON).
  $.ajaxSetup({ headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' } });

  function icons() {
    if (window.lucide) window.lucide.createIcons();
  }

  function alertError(message) {
    return Swal.fire({ icon: 'error', title: t('js.error'), text: message || t('js.error_generic'), confirmButtonColor: '#1F4E79' });
  }

  function ajaxError(xhr) {
    var json = xhr && xhr.responseJSON;
    if (json && json.errors) {
      alertError(Object.keys(json.errors).map(function (k) { return json.errors[k]; }).join('\n'));
    } else {
      alertError(json && json.error);
    }
  }

  var loadedScripts = {};
  function loadScript(src) {
    if (!loadedScripts[src]) {
      loadedScripts[src] = new Promise(function (resolve, reject) {
        var s = document.createElement('script');
        s.src = src;
        s.onload = resolve;
        s.onerror = function () { reject(new Error('load ' + src)); };
        document.head.appendChild(s);
      });
    }
    return loadedScripts[src];
  }

  function loadPdfMake() {
    return loadScript(config.assets.pdfmake).then(function () { return loadScript(config.assets.vfs); })
      .then(function () { return window.pdfMake; });
  }

  // ---------------------------------------------------------------- Navigation
  $(document).on('click', '[data-toggle-sidebar]', function () {
    var open = $('#sidebar').toggleClass('is-open').hasClass('is-open');
    $(this).attr('aria-expanded', open ? 'true' : 'false');
  });
  $(document).on('keydown', function (e) {
    if (e.key === 'Escape') $('#sidebar').removeClass('is-open');
  });

  // Menu réductible en icônes (mémorisé sur ce poste)
  function setMini(on) {
    document.body.classList.toggle('sidebar-mini', on);
    $('[data-sidebar-mini]').attr('aria-pressed', on ? 'true' : 'false');
    try { localStorage.setItem('pm.sidebarMini', on ? '1' : '0'); } catch (e) { /* stockage indisponible */ }
  }
  try { if (localStorage.getItem('pm.sidebarMini') === '1' && window.innerWidth > 860) setMini(true); } catch (e) { /* ignore */ }
  $(document).on('click', '[data-sidebar-mini]', function () { setMini(!document.body.classList.contains('sidebar-mini')); });

  // Technicien sur téléphone : l'accueil ouvre directement le mode terrain (une fois par session)
  (function () {
    var target = document.body.dataset.fieldHome;
    if (!target || window.innerWidth > 700) return;
    try {
      if (sessionStorage.getItem('pm.fieldRedirect')) return;
      sessionStorage.setItem('pm.fieldRedirect', '1');
    } catch (e) { return; }
    window.location.replace(target);
  })();

  // ---------------------------------------------------------------- Confirmation de toute soumission
  /*
   * Avant d'envoyer une saisie : fenêtre sobre avec le titre de l'action et le résumé des champs renseignés
   * (libellé → valeur affichée, pas la valeur technique). « Modifier » revient au formulaire sans rien perdre.
   * Exclus : navigation (connexion, déconnexion, langue, site de travail), formulaires de recherche (GET),
   * et formulaires marqués data-no-confirm. Les actions de suppression / archivage ont un bouton rouge.
   */
  var NO_CONFIRM = /\/(login|logout|locale|context\/site)$/;
  var DANGER = /\/(delete|archive|end)$/;
  var MAX_ROWS = 12;

  function clean(text) { return String(text || '').replace(/\s+/g, ' ').trim(); }

  /** Libellé lisible d'un champ : son <label> (premier texte), sinon aria-label, placeholder ou titre de colonne. */
  function fieldLabel(el) {
    if (el.type === 'radio') {
      // Groupe de choix : libellé du champ (.label) ou du groupe (aria-label).
      var field = el.closest('.field');
      var group = el.closest('[role=radiogroup], [aria-label]');
      return clean((field && field.querySelector('.label') || {}).textContent)
        || clean(group ? group.getAttribute('aria-label') : '') || el.name;
    }
    var label = el.labels && el.labels[0];
    if (label) {
      if (el.type === 'checkbox') return clean(label.textContent) || el.name;
      // Libellé composé (texte + champ + aide) : on garde le premier texte, sans l'aide ni l'aperçu.
      var part = label.querySelector('span, .label');
      var text = clean(part && !part.contains(el) ? part.textContent : label.childNodes[0] && label.childNodes[0].textContent);
      if (text) return text;
    }
    return clean(el.getAttribute('aria-label') || el.getAttribute('placeholder') || el.title || el.name);
  }

  /** Valeur affichée : texte de l'option, « Oui » pour une case cochée, noms des fichiers… */
  function fieldValue(el) {
    if (el.tagName === 'SELECT') {
      return Array.prototype.filter.call(el.options, function (o) { return o.selected && o.value !== ''; }).map(function (o) { return clean(o.textContent); }).join(', ');
    }
    if (el.type === 'checkbox') return el.checked ? t('js.yes') : '';
    if (el.type === 'radio') return el.checked ? clean((el.closest('label') || {}).textContent || el.value) : '';
    if (el.type === 'file') return Array.prototype.map.call(el.files || [], function (f) { return f.name; }).join(', ');
    if (el.type === 'password') return el.value ? '••••••' : '';
    if (el.type === 'date' && el.value) return Core.formatDate(el.value, locale);
    var v = clean(el.value);
    return v.length > 140 ? v.slice(0, 137) + '…' : v;
  }

  function summaryRows(form) {
    var rows = [];
    Array.prototype.forEach.call(form.elements, function (el) {
      if (!el.name || el.disabled || el.name === '_csrf' || /^(hidden|submit|button|reset)$/.test(el.type) || el.tagName === 'BUTTON' || el.tagName === 'FIELDSET') return;
      if (el.closest('[hidden]') && !el.closest('[data-confirm-parked]')) return; // section masquée (ex. contrat d'un engin en propre)
      var value = fieldValue(el);
      if (value === '') return;
      rows.push([fieldLabel(el), value]);
    });
    return rows;
  }

  function actionTitle(form, submitter) {
    var text = form.dataset.confirmTitle || (submitter && clean(submitter.textContent || submitter.value || submitter.title || submitter.getAttribute('aria-label')));
    var page = clean($('.page-header h1').first().text());
    return text && page ? text + ' · ' + page : (text || page || t('js.confirm_title'));
  }

  /*
   * Nature de l'action, qui fixe le libellé, la couleur et l'icône du bouton de confirmation :
   * create (nouvel élément), save (modification), activate / deactivate (interrupteurs), danger (suppression…).
   * Déclarable sur le formulaire (data-confirm-kind), sinon déduite de l'adresse et des champs envoyés.
   */
  var KINDS = {
    create: { label: 'js.confirm_create', icon: 'plus', btn: 'btn--primary', back: 'js.confirm_back' },
    save: { label: 'js.confirm_save', icon: 'check', btn: 'btn--primary', back: 'js.confirm_back' },
    apply: { label: 'js.confirm_apply', icon: 'check', btn: 'btn--primary', back: 'js.confirm_cancel' },
    activate: { label: 'js.confirm_activate', icon: 'toggle-right', btn: 'btn--success', back: 'js.confirm_cancel' },
    deactivate: { label: 'js.confirm_deactivate', icon: 'toggle-left', btn: 'btn--warning', back: 'js.confirm_cancel' },
    danger: { label: 'js.confirm_danger', icon: 'trash-2', btn: 'btn--danger', back: 'js.confirm_cancel' }
  };
  function actionKind(form) {
    if (form.dataset.confirmKind && KINDS[form.dataset.confirmKind]) return form.dataset.confirmKind;
    var action = (form.getAttribute('action') || '').split('?')[0];
    if (DANGER.test(action)) return 'danger';
    // Interrupteur (seul champ : is_active) : activation ou désactivation.
    var active = form.querySelector('input[type=hidden][name=is_active]');
    var visible = Array.prototype.some.call(form.elements, function (el) { return el.name && el.name !== '_csrf' && el.type !== 'hidden' && !/^(submit|button)$/.test(el.type); });
    if (active && !visible) return active.value === '1' ? 'activate' : 'deactivate';
    return /\/\d+(\/[a-z-]+)?$/.test(action) ? 'save' : 'create';
  }

  /**
   * Fenêtre de confirmation : résout true (confirmé) ou false (retour au formulaire).
   * options : { title, message, kind, danger, rows, confirmLabel }
   */
  function confirmSubmit(form, options) {
    options = options || {};
    var rows = options.rows || summaryRows(form);
    var kind = options.kind || (options.danger ? 'danger' : (options.danger === false && actionKind(form) === 'danger' ? 'save' : actionKind(form)));
    var k = KINDS[kind];
    var list = rows.slice(0, MAX_ROWS).map(function (r) {
      return '<div><dt>' + Core.escapeHtml(r[0]) + '</dt><dd>' + Core.escapeHtml(r[1]) + '</dd></div>';
    }).join('');
    var more = rows.length > MAX_ROWS ? '<p class="confirm-more">' + Core.escapeHtml(t('js.confirm_more', { n: rows.length - MAX_ROWS })) + '</p>' : '';
    var message = options.message || form.dataset.confirm || '';
    var label = options.confirmLabel || form.dataset.confirmOk || t(k.label);
    return Swal.fire({
      title: options.title || actionTitle(form, options.submitter),
      html: (message ? '<p class="confirm-lead">' + Core.escapeHtml(message) + '</p>' : '')
        + (list ? '<dl class="confirm-summary">' + list + '</dl>' + more : (message ? '' : '<p class="confirm-lead">' + Core.escapeHtml(t('js.confirm_no_fields')) + '</p>')),
      customClass: {
        popup: 'confirm-popup confirm-popup--' + kind,
        actions: 'confirm-actions',
        confirmButton: 'btn ' + k.btn,
        cancelButton: 'btn btn--ghost confirm-back'
      },
      buttonsStyling: false,
      showCancelButton: true,
      confirmButtonText: '<i data-lucide="' + k.icon + '"></i>' + Core.escapeHtml(label),
      cancelButtonText: '<i data-lucide="arrow-left"></i>' + Core.escapeHtml(t(k.back)),
      reverseButtons: true,
      // Action irréversible : le focus est sur « Annuler », Entrée ne supprime pas par mégarde.
      focusConfirm: kind !== 'danger',
      focusCancel: kind === 'danger',
      returnFocus: false,
      didOpen: icons
    }).then(function (res) { return !!res.isConfirmed; });
  }
  window.PM.confirmSubmit = confirmSubmit;
  window.PM.summaryRows = summaryRows;

  /** Envoi après confirmation, en conservant le bouton utilisé (ex. transition d'OT choisie). */
  function submitConfirmed(form, submitter) {
    if (submitter && submitter.name) {
      $('<input type="hidden">').attr('name', submitter.name).val(submitter.value).appendTo(form);
    }
    form.dataset.confirmed = '1';
    $(form).trigger('pm:confirmed');
    form.submit();
  }

  $(document).on('submit', 'form', function (e) {
    var form = this;
    var method = (form.getAttribute('method') || 'get').toLowerCase();
    if (form.dataset.confirmed === '1' || method !== 'post' || form.hasAttribute('data-no-confirm')
      || NO_CONFIRM.test((form.getAttribute('action') || '').split('?')[0])) return;
    if ($(form).is('[data-entry-form], [data-backfill-form]')) return; // saisie de l'encodeur : confirmée par entry.js avant l'envoi AJAX
    e.preventDefault();
    var submitter = e.originalEvent && e.originalEvent.submitter;
    confirmSubmit(form, { submitter: submitter }).then(function (ok) {
      if (ok) submitConfirmed(form, submitter);
      else $(form).trigger('pm:cancelled');
    });
  });

  // Dialogues de saisie : le contenu vient d'un <template> contenant un formulaire.
  // Le formulaire est mis de côté pendant la confirmation ; « Modifier » le rouvre avec les valeurs saisies.
  var parking = $('<div data-confirm-parked hidden></div>').appendTo(document.body)[0];
  function openFormDialog(content) {
    Swal.fire({
      html: content,
      showCancelButton: true,
      confirmButtonText: t('js.save'),
      cancelButtonText: t('js.cancel'),
      confirmButtonColor: '#1F4E79',
      reverseButtons: true,
      focusConfirm: false,
      didOpen: function (popup) {
        icons();
        var first = popup.querySelector('input:not([type=hidden]), select, textarea');
        if (first) first.focus();
      },
      preConfirm: function () {
        var form = Swal.getHtmlContainer().querySelector('form');
        if (!form.reportValidity()) return false;
        var rows = summaryRows(form);
        var title = clean((Swal.getHtmlContainer().querySelector('.dialog-title, h2, h3') || {}).textContent);
        parking.appendChild(form);
        return { form: form, rows: rows, title: title };
      }
    }).then(function (res) {
      if (!res.isConfirmed || !res.value) return;
      var form = res.value.form;
      confirmSubmit(form, { rows: res.value.rows, title: res.value.title || undefined }).then(function (ok) {
        if (ok) { submitConfirmed(form); return; }
        openFormDialog(form);
      });
    });
  }
  $(document).on('click', '[data-open]', function () {
    var tpl = document.querySelector(this.dataset.open);
    if (tpl) openFormDialog(tpl.innerHTML);
  });

  // ---------------------------------------------------------------- DataTables
  function dtLanguage() {
    return {
      processing: t('js.dt.processing'),
      search: t('js.dt.search'),
      lengthMenu: t('js.dt.length'),
      info: t('js.dt.info'),
      infoEmpty: t('js.dt.info_empty'),
      infoFiltered: t('js.dt.info_filtered'),
      zeroRecords: t('js.dt.zero'),
      emptyTable: t('js.dt.empty'),
      loadingRecords: t('js.dt.loading'),
      paginate: { first: '«', last: '»', next: '›', previous: '‹' }
    };
  }

  function filtersFor(name) {
    var form = document.querySelector('form[data-filters-for="' + name + '"]');
    if (!form) return {};
    var entries = [];
    new FormData(form).forEach(function (value, key) { entries.push([key, value]); });
    return Core.filtersFromEntries(entries);
  }

  var tables = {};
  var definitions = Tables.definitions(t, locale);

  // Présentation commune : tableaux compacts, 15 lignes par défaut, recherche + longueur en tête.
  var DT_PAGE = 15;
  var DT_LENGTHS = [15, 25, 50, 100];
  var DT_LAYOUT = { topStart: 'search', topEnd: 'pageLength', bottomStart: 'info', bottomEnd: 'paging' };

  function dtSearchPlaceholder(api) {
    $(api.table().container()).find('.dt-search input').attr('placeholder', t('js.dt.search_placeholder'));
  }

  $('table[data-table]').each(function () {
    var $table = $(this);
    var name = $table.data('table');
    var def = definitions[name];
    if (!def) return;
    var rowLink = $table.data('row-link');

    $table.addClass('compact');
    tables[name] = $table.DataTable({
      serverSide: true,
      processing: true,
      searchDelay: 350,
      pageLength: DT_PAGE,
      lengthMenu: DT_LENGTHS,
      layout: DT_LAYOUT,
      autoWidth: false,
      order: def.order,
      language: dtLanguage(),
      ajax: {
        url: $table.data('source'),
        data: function (params) { return $.extend(params, filtersFor(name)); },
        error: ajaxError
      },
      columns: def.columns.map(function (col) {
        return {
          data: col.data,
          name: col.data,
          title: col.title,
          orderable: col.orderable !== false,
          className: col.className || '',
          defaultContent: '',
          render: function (data, type, row) {
            return type === 'display' ? Tables.cellHtml(col, row) : Tables.cellText(col, row);
          }
        };
      }),
      createdRow: function (row, data) {
        if (rowLink) {
          $(row).addClass('row-link').attr('data-href', Core.fillUrl(rowLink, data)).attr('tabindex', '0');
        }
      },
      drawCallback: function () { icons(); },
      initComplete: function () { dtSearchPlaceholder(this.api()); }
    });
  });

  // Tableaux statiques longs (fiches, alertes, rapports) : pagination / tri / recherche côté client.
  $('table.table').each(function () {
    var $table = $(this);
    var rows = $table.children('tbody').children('tr').length;
    if (!$table.children('thead').length || rows <= DT_PAGE || $table.is('[data-no-dt]')) return;
    var numeric = [];
    var unsortable = [];
    $table.find('thead th').each(function (i) {
      if ($(this).hasClass('num')) numeric.push(i);
      if (!$.trim($(this).text())) unsortable.push(i);
    });
    $table.addClass('compact').DataTable({
      pageLength: DT_PAGE,
      lengthMenu: DT_LENGTHS,
      layout: DT_LAYOUT,
      autoWidth: false,
      order: [],
      language: dtLanguage(),
      columnDefs: [
        { targets: numeric, className: 'dt-right' },
        { targets: unsortable, orderable: false, searchable: false }
      ],
      initComplete: function () { dtSearchPlaceholder(this.api()); }
    });
  });

  // Barres de filtres : compteur de filtres actifs + réinitialisation.
  function refreshFilterState($form) {
    var n = 0;
    $form.find('[data-filter]').each(function () {
      if (this.type === 'hidden') return;
      if (this.type === 'checkbox' ? this.checked : this.value !== '') n++;
    });
    $form.find('[data-filters-count]').text(n ? t('js.dt.active_filters', { n: n }) : '').prop('hidden', !n);
    $form.find('[data-filters-reset]').prop('hidden', !n);
  }

  $('form.filters').each(function () {
    var $form = $(this);
    if (!$form.find('[data-filter]').length) return;
    $form.addClass('filters--compact').append(
      '<div class="filters__tools">'
      + '<span class="filters__count" data-filters-count hidden></span>'
      + '<button type="button" class="btn btn--sm btn--ghost" data-filters-reset hidden><i data-lucide="rotate-ccw"></i>' + Core.escapeHtml(t('js.dt.reset')) + '</button>'
      + '</div>'
    );
    refreshFilterState($form);
  });
  icons();

  $(document).on('change', 'form.filters [data-filter]', function () { refreshFilterState($(this).closest('form')); });

  $(document).on('click', '[data-filters-reset]', function () {
    var $form = $(this).closest('form');
    $form.find('[data-filter]').each(function () {
      if (this.type === 'checkbox') this.checked = false;
      else if (this.type !== 'hidden') this.value = '';
    });
    refreshFilterState($form);
    var name = $form.data('filters-for');
    if (name && tables[name]) tables[name].ajax.reload();
    else $form.trigger('submit');
  });

  $(document).on('click keydown', 'tr.row-link', function (e) {
    if (e.type === 'keydown' && e.key !== 'Enter') return;
    if ($(e.target).closest('a, button, input, select').length) return;
    window.location.href = this.dataset.href;
  });

  // Filtres : rechargement de la table ou soumission (formulaires GET classiques).
  $(document).on('change', '[data-filter]', function () {
    var form = $(this).closest('form');
    var name = form.data('filters-for');
    if (name && tables[name]) {
      tables[name].ajax.reload();
    } else if (form.length) {
      form.trigger('submit');
    }
  });

  // ---------------------------------------------------------------- Exports
  $(document).on('click', '[data-export]', function () {
    var name = this.dataset.exportTable;
    if (!tables[name] || !definitions[name]) return;
    exportTable(tables[name], definitions[name], $('table[data-table="' + name + '"]').data('source'), this.dataset.exportTitle, this.dataset.export, this);
  });

  /** Export Excel / PDF de toutes les lignes correspondant aux filtres et au tri courants d'une DataTable. */
  function exportTable(table, def, source, title, kind, button) {
    var params = $.extend(table.ajax.params(), { start: 0, length: -1, draw: 0 });
    button.disabled = true;

    $.getJSON(source, params)
      .then(function (json) {
        // Colonnes d'interface (cases de sélection…) exclues des exports.
        var data = Exporter.tableData(def.columns.filter(function (c) { return c.export !== false; }), json.data || [], locale);
        var meta = { subtitle: t('js.export_meta', { count: json.recordsFiltered, date: Core.formatDate(new Date().toISOString().slice(0, 10), locale) }) };
        if (json.recordsFiltered > json.data.length) {
          meta.subtitle += ' — ' + t('js.export_truncated', { count: json.data.length });
        }
        if (kind === 'excel') {
          return loadScript(config.assets.exceljs).then(function () { return Exporter.exportExcel(window.ExcelJS, title, data, meta); });
        }
        return loadPdfMake().then(function (pdfMake) { Exporter.exportPdf(pdfMake, title, data, meta); });
      })
      .fail(ajaxError)
      .catch(function () { alertError(); })
      .always(function () { button.disabled = false; });
  }

  // ---------------------------------------------------------------- Fenêtre de détail (KPI, graphiques, notifications)
  // Spécification : { table, source, title, params, page, row, chips: [{label, params}] }
  var drillState = null;

  function closeDrill() {
    if (!drillState) return;
    drillState.table.destroy();
    drillState.$root.remove();
    $('body').removeClass('has-drill');
    if (drillState.opener) drillState.opener.focus();
    drillState = null;
  }

  function openDrill(spec, opener) {
    var def = definitions[spec.table];
    if (!def) return;
    closeDrill();
    var esc = Core.escapeHtml;
    var chips = (spec.chips || []).map(function (c, i) {
      return '<button type="button" class="chip-btn" data-drill-chip="' + i + '" aria-pressed="' + (i === 0 ? 'true' : 'false') + '">' + esc(c.label) + '</button>';
    }).join('');
    var sites = (config.sites || []);
    var siteSelect = !spec.params.site_id && sites.length > 1
      ? '<select class="input input--sm" data-drill-site aria-label="' + esc(t('js.site')) + '"><option value="">' + esc(t('js.all_sites_label')) + '</option>'
        + sites.map(function (s) { return '<option value="' + s.id + '">' + esc(s.name) + '</option>'; }).join('') + '</select>'
      : '';
    var html = '<div class="drill" role="dialog" aria-modal="true" aria-labelledby="drill-title">'
      + '<div class="drill__backdrop" data-drill-close></div>'
      + '<div class="drill__panel" tabindex="-1">'
      + '<header class="drill__head">'
      + '<div class="drill__title"><h2 id="drill-title">' + esc(spec.title) + '</h2><span class="drill__count" data-drill-count>…</span></div>'
      + '<div class="drill__actions">'
      + '<button type="button" class="btn btn--sm" data-drill-export="excel"><i data-lucide="file-spreadsheet"></i>Excel</button>'
      + '<button type="button" class="btn btn--sm" data-drill-export="pdf"><i data-lucide="file-text"></i>PDF</button>'
      + (spec.page ? '<a class="btn btn--sm btn--ghost" href="' + esc(spec.page) + '" title="' + esc(t('js.open_list')) + '"><i data-lucide="external-link"></i><span class="drill__hide-sm">' + esc(t('js.open_list')) + '</span></a>' : '')
      + '<button type="button" class="icon-btn icon-btn--lg" data-drill-close aria-label="' + esc(t('js.close')) + '"><i data-lucide="x"></i></button>'
      + '</div></header>'
      + ((chips || siteSelect) ? '<div class="drill__filters"><div class="chips">' + chips + '</div>' + siteSelect + '</div>' : '')
      + '<div class="drill__body"><table class="display compact drill__table" style="width:100%"></table></div>'
      + '</div></div>';
    var $root = $(html).appendTo('body');
    $('body').addClass('has-drill');
    icons();

    var state = { $root: $root, spec: spec, def: def, chip: (spec.chips && spec.chips[0]) ? spec.chips[0].params : {}, site: '', opener: opener || null };
    state.table = $root.find('table').DataTable({
      serverSide: true,
      processing: true,
      searchDelay: 300,
      pageLength: DT_PAGE,
      lengthMenu: DT_LENGTHS,
      order: def.order,
      language: dtLanguage(),
      layout: DT_LAYOUT,
      ajax: {
        url: spec.source,
        data: function (p) {
          var extra = $.extend({}, spec.params, state.chip);
          if (state.site) extra.site_id = state.site;
          return $.extend(p, extra);
        },
        error: ajaxError
      },
      columns: def.columns.map(function (col) {
        return {
          data: col.data, name: col.data, title: col.title, orderable: col.orderable !== false,
          className: col.className || '', defaultContent: '',
          render: function (data, type, row) { return type === 'display' ? Tables.cellHtml(col, row) : Tables.cellText(col, row); }
        };
      }),
      createdRow: function (row, data) {
        if (spec.row) $(row).addClass('row-link').attr('data-href', Core.fillUrl(spec.row, data)).attr('tabindex', '0');
      },
      drawCallback: function () {
        var info = this.api().page.info();
        $root.find('[data-drill-count]').text(t('js.rows_count', { n: Core.formatNumber(info.recordsDisplay, locale, 0) }));
      },
      initComplete: function () { $root.find('.dt-search input').attr('placeholder', t('js.search_in')).trigger('focus'); }
    });
    drillState = state;

    $root.on('click', '[data-drill-close]', closeDrill);
    $root.on('click', '[data-drill-chip]', function () {
      var chip = spec.chips[Number(this.dataset.drillChip)];
      state.chip = chip ? chip.params : {};
      $root.find('[data-drill-chip]').attr('aria-pressed', 'false');
      $(this).attr('aria-pressed', 'true');
      state.table.ajax.reload();
    });
    $root.on('change', '[data-drill-site]', function () { state.site = this.value; state.table.ajax.reload(); });
    $root.on('click', '[data-drill-export]', function () {
      var suffix = $root.find('[data-drill-chip][aria-pressed="true"]').text();
      var title = spec.title + (suffix && suffix !== t('js.all') ? ' — ' + suffix : '');
      exportTable(state.table, def, spec.source, title, this.dataset.drillExport, this);
    });
  }

  window.PM.drill = openDrill;
  $(document).on('click', '[data-drill]', function (e) {
    e.preventDefault();
    var spec;
    try { spec = JSON.parse(this.dataset.drill); } catch (err) { return; }
    openDrill(spec, this);
  });
  $(document).on('keydown', function (e) {
    if (e.key === 'Escape' && drillState && !Swal.isVisible()) closeDrill();
  });

  // ---------------------------------------------------------------- Planning : détail d'un événement et export matriciel
  var planning = readJson('planning-data');
  if (planning.events) {
    $(document).on('click', '[data-event]', function () {
      var ev = planning.events[Number(this.dataset.event)];
      if (!ev) return;
      var esc = Core.escapeHtml;
      var rows = ev.fields.map(function (f) {
        return '<div class="ev-modal__row"><dt>' + esc(f[0]) + '</dt><dd>' + esc(f[1]) + '</dd></div>';
      }).join('');
      var flags = (ev.overdue ? '<span class="badge badge--breakdown">' + esc(planning.labels.overdue) + '</span>' : '')
        + (ev.priority === 'critical' ? '<span class="badge badge--critical">' + esc(t('priority.critical')) + '</span>' : '');
      Swal.fire({
        html: '<div class="ev-modal ev-modal--' + esc(ev.kind) + '">'
          + '<div class="ev-modal__head"><span class="ev-modal__icon"><i data-lucide="' + esc(ev.icon) + '"></i></span>'
          + '<div><span class="ev-modal__kind">' + esc(ev.kindLabel) + '</span><h3>' + esc(ev.title) + '</h3>'
          + (flags ? '<div class="ev-modal__flags">' + flags + '</div>' : '') + '</div></div>'
          + '<dl class="ev-modal__grid">' + rows + '</dl></div>',
        showConfirmButton: !!ev.edit,
        confirmButtonText: '<i data-lucide="pencil"></i> ' + esc(ev.editLabel),
        showDenyButton: !!ev.view,
        denyButtonText: '<i data-lucide="external-link"></i> ' + esc(planning.labels.open),
        showCancelButton: true,
        cancelButtonText: esc(planning.labels.close),
        showCloseButton: true,
        width: 560,
        padding: 0,
        reverseButtons: true,
        focusConfirm: false,
        customClass: { popup: 'ev-popup', actions: 'ev-popup__actions', confirmButton: 'btn btn--primary', denyButton: 'btn', cancelButton: 'btn btn--ghost' },
        buttonsStyling: false,
        didOpen: icons
      }).then(function (res) {
        if (res.isConfirmed && ev.edit) window.location.href = ev.edit;
        else if (res.isDenied && ev.view) window.location.href = ev.view;
      });
    });

    $(document).on('click', '[data-planning-export]', function () {
      var button = this;
      button.disabled = true;
      var job = this.dataset.planningExport === 'excel'
        ? loadScript(config.assets.exceljs).then(function () { return Exporter.exportPlanningExcel(window.ExcelJS, planning); })
        : loadPdfMake().then(function (pdfMake) { Exporter.exportPlanningPdf(pdfMake, planning); });
      Promise.resolve(job).catch(function () { alertError(); }).then(function () { button.disabled = false; });
    });
  }

  // ---------------------------------------------------------------- Rapport de direction : export exécutif (graphiques inclus)
  var report = readJson('report-export');
  if (report.labels) {
    $(document).on('click', '[data-report-export]', function () {
      var button = this;
      var images = {};
      [['causes', 'chart-causes'], ['availability', 'chart-availability']].forEach(function (pair) {
        var canvas = document.getElementById(pair[1]);
        if (canvas && canvas.width) {
          // Fond blanc sous le graphique (canvas transparent)
          var copy = document.createElement('canvas');
          copy.width = canvas.width;
          copy.height = canvas.height;
          var ctx = copy.getContext('2d');
          ctx.fillStyle = '#FFFFFF';
          ctx.fillRect(0, 0, copy.width, copy.height);
          ctx.drawImage(canvas, 0, 0);
          images[pair[0]] = copy.toDataURL('image/png');
        }
      });
      button.disabled = true;
      var job = button.dataset.reportExport === 'excel'
        ? loadScript(config.assets.exceljs).then(function () { return Exporter.exportReportExcel(window.ExcelJS, report, images, locale); })
        : loadPdfMake().then(function (pdfMake) { Exporter.exportReportPdf(pdfMake, report, images, locale); });
      Promise.resolve(job).catch(function () { alertError(); }).then(function () { button.disabled = false; });
    });
  }

  // ---------------------------------------------------------------- Notifications (cloche) et pastilles du menu
  /*
   * - Couleur selon la gravité : rouge = bloquant (bad), orange = à prévoir (warn), gris = information (info).
   * - « Nouveau » : compteur en hausse depuis la dernière consultation (mémorisé par utilisateur, dans ce navigateur).
   * - Actualisation chaque minute (en pause onglet masqué) ; toast discret quand un point bloquant augmente.
   * - Panneau groupé : À traiter maintenant / À prévoir / Information.
   */
  $('[data-notifications]').each(function () {
    var $root = $(this);
    var $panel = $root.find('[data-notif-panel]');
    var $list = $root.find('[data-notif-list]');
    var $badge = $root.find('[data-notif-badge]');
    var RANK = { bad: 3, warn: 2, info: 1 };
    var GROUPS = [['bad', 'js.notif_group_bad'], ['warn', 'js.notif_group_warn'], ['info', 'js.notif_group_info']];
    var storeKey = 'pm.notif.seen.' + ($root.data('user') || '0');
    var items = [];
    var previous = null; // compteurs de l'actualisation précédente (toasts)

    function readSeen() { try { return JSON.parse(localStorage.getItem(storeKey) || '{}'); } catch (e) { return {}; } }
    function writeSeen(map) { try { localStorage.setItem(storeKey, JSON.stringify(map)); } catch (e) { /* stockage indisponible */ } }
    function isNew(it, seen) { return (seen[it.key] || 0) < it.count; }

    function navBadges(counts, levels) {
      $('[data-nav-badge]').each(function () {
        var keys = this.dataset.navBadge.split(' ');
        var total = keys.reduce(function (sum, key) { return sum + (counts[key] || 0); }, 0);
        var level = keys.reduce(function (best, key) { return counts[key] && RANK[levels[key]] > RANK[best] ? levels[key] : best; }, 'info');
        this.hidden = total === 0;
        this.textContent = total > 99 ? '99+' : String(total);
        this.className = 'nav__badge nav__badge--' + level;
        this.title = { bad: t('js.notif_level_bad'), warn: t('js.notif_level_warn'), info: t('js.notif_level_info') }[level];
      });
    }

    function render(json) {
      items = json.items || [];
      var seen = readSeen();
      var counts = {};
      var levels = {};
      items.forEach(function (it) { counts[it.key] = it.count; levels[it.key] = it.level; });
      navBadges(counts, levels);

      // Cloche : nombre de nouveautés ; sinon un point de la gravité la plus haute s'il reste des points ouverts.
      var fresh = items.filter(function (it) { return isNew(it, seen); });
      var top = items.reduce(function (best, it) { return RANK[it.level] > RANK[best] ? it.level : best; }, 'info');
      $badge.prop('hidden', !items.length)
        .text(fresh.length ? (fresh.length > 9 ? '9+' : fresh.length) : '')
        .attr('class', 'notif__badge notif__badge--' + top + (fresh.length ? '' : ' notif__badge--dot'));
      $root.toggleClass('has-items', items.length > 0);
      $root.find('[data-notif-scope]').text(' · ' + $root.data('scope'));

      if (!items.length) {
        $list.html('<p class="notif__empty"><i data-lucide="check-circle-2"></i>' + Core.escapeHtml(t('js.notif_none')) + '</p>');
      } else {
        $list.html(GROUPS.map(function (g) {
          var group = items.map(function (it, i) { return [it, i]; }).filter(function (p) { return p[0].level === g[0]; });
          if (!group.length) return '';
          return '<section class="notif__group notif__group--' + g[0] + '"><h3>' + Core.escapeHtml(t(g[1])) + '<span>' + group.length + '</span></h3><ul>'
            + group.map(function (p) {
              var it = p[0];
              return '<li><button type="button" class="notif__item notif__item--' + it.level + (isNew(it, seen) ? ' is-new' : '') + '" data-notif-item="' + p[1] + '">'
                + '<span class="notif__icon"><i data-lucide="' + Core.escapeHtml(it.icon) + '"></i></span>'
                + '<span class="notif__label">' + Core.escapeHtml(it.label) + (isNew(it, seen) ? '<em>' + Core.escapeHtml(t('js.notif_new')) + '</em>' : '') + '</span>'
                + '<i data-lucide="chevron-right" class="notif__chev"></i></button></li>';
            }).join('') + '</ul></section>';
        }).join(''));
      }
      icons();

      // Toast : point bloquant apparu ou en hausse depuis la dernière actualisation (pas au chargement de la page).
      if (previous) {
        var rising = items.filter(function (it) { return it.level === 'bad' && it.count > (previous[it.key] || 0); });
        // Une seule fenêtre SweetAlert à la fois : jamais de toast par-dessus une saisie ou une confirmation en cours.
        if (rising.length && Swal.isVisible()) return;
        if (rising.length) {
          toast.fire({
            icon: 'warning',
            title: rising[0].label + (rising.length > 1 ? ' (+' + (rising.length - 1) + ')' : ''),
            text: t('js.notif_toast_hint'),
            timer: 7000,
            didOpen: function (el) {
              el.style.cursor = 'pointer';
              el.addEventListener('click', function () { Swal.close(); openPanel(); });
            }
          });
        }
      }
      previous = counts;
    }

    function load() { return $.getJSON($root.data('url')).then(render); }
    function markSeen() {
      var map = {};
      items.forEach(function (it) { map[it.key] = it.count; });
      writeSeen(map);
      render({ items: items });
    }
    function openPanel() {
      $panel.prop('hidden', false);
      $root.find('[data-notif-toggle]').attr('aria-expanded', 'true');
    }

    setTimeout(load, 400);
    setInterval(function () { if (!document.hidden) load(); }, 60000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) load(); });

    $root.on('click', '[data-notif-toggle]', function (e) {
      e.stopPropagation();
      var open = $panel.prop('hidden');
      $panel.prop('hidden', !open);
      $(this).attr('aria-expanded', open ? 'true' : 'false');
      if (open) load();
      // Fermeture du panneau : ce qui a été vu n'est plus « nouveau ».
      else markSeen();
    });
    $root.on('click', '[data-notif-seen]', function (e) { e.stopPropagation(); markSeen(); });
    $root.on('click', '[data-notif-item]', function () {
      var it = items[Number(this.dataset.notifItem)];
      var seen = readSeen();
      seen[it.key] = it.count;
      writeSeen(seen);
      $panel.prop('hidden', true);
      render({ items: items });
      if (it.drill) openDrill(it.drill, $root.find('[data-notif-toggle]')[0]);
      else if (it.link) window.location.href = it.link;
    });
    $(document).on('click', function (e) {
      if (!$panel.prop('hidden') && !$(e.target).closest('[data-notifications]').length) {
        $panel.prop('hidden', true);
        markSeen();
      }
    });
    $(document).on('keydown', function (e) {
      if (e.key === 'Escape' && !$panel.prop('hidden')) { $panel.prop('hidden', true); markSeen(); }
    });
  });

  // Bon de travail PDF
  $(document).on('click', '[data-wo-pdf]', function () {
    var payload = readJson('wo-data');
    loadPdfMake().then(function (pdfMake) {
      Exporter.downloadPdf(pdfMake, Exporter.workOrderDocDefinition(payload.wo, payload.parts, t, locale, payload.technicians),
        Core.exportFileName(payload.wo.reference, 'pdf'));
    }).catch(function () { alertError(); });
  });

  // Aperçu du bon de travail : PDF affiché dans une modale, puis téléchargement ou impression.
  $(document).on('click', '[data-wo-preview]', function () {
    var payload = readJson('wo-data');
    var filename = Core.exportFileName(payload.wo.reference, 'pdf');
    Swal.fire({ title: t('js.wo_preview.loading'), allowOutsideClick: false, didOpen: function () { Swal.showLoading(); } });
    loadPdfMake().then(function (pdfMake) {
      return Exporter.pdfBlob(pdfMake, Exporter.workOrderDocDefinition(payload.wo, payload.parts, t, locale, payload.technicians));
    }).then(function (blob) {
      var url = URL.createObjectURL(blob);
      Swal.fire({
        title: t('js.wo_preview.title', { reference: payload.wo.reference }),
        html: '<iframe class="pdf-preview" src="' + Core.escapeHtml(url) + '" title="' + Core.escapeHtml(filename) + '"></iframe>',
        width: 'min(960px, 96vw)',
        customClass: { popup: 'pdf-preview-popup', actions: 'confirm-actions', confirmButton: 'btn btn--primary', denyButton: 'btn', cancelButton: 'btn btn--ghost' },
        buttonsStyling: false,
        showDenyButton: true,
        showCancelButton: true,
        showCloseButton: true,
        confirmButtonText: '<i data-lucide="download"></i>' + Core.escapeHtml(t('js.wo_preview.download')),
        denyButtonText: '<i data-lucide="printer"></i>' + Core.escapeHtml(t('js.wo_preview.print')),
        cancelButtonText: Core.escapeHtml(t('js.wo_preview.close')),
        reverseButtons: true,
        didOpen: icons,
        // Impression sans fermer l'aperçu.
        preDeny: function () {
          var frame = Swal.getHtmlContainer().querySelector('iframe');
          try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch (e) { window.open(url, '_blank'); }
          return false;
        },
        willClose: function () { setTimeout(function () { URL.revokeObjectURL(url); }, 1000); }
      }).then(function (res) {
        if (res.isConfirmed) {
          var a = document.createElement('a');
          a.href = URL.createObjectURL(blob);
          a.download = filename;
          document.body.appendChild(a);
          a.click();
          a.remove();
          setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
        }
      });
    }).catch(function () { alertError(); });
  });

  // ---------------------------------------------------------------- Sélecteur d'engin
  $('[data-equipment-picker]').each(function () {
    var $picker = $(this);
    var $value = $picker.find('[data-picker-value]');
    var $search = $picker.find('[data-picker-search]');
    var $selected = $picker.find('[data-picker-selected]');
    var $results = $picker.find('[data-picker-results]');
    var $input = $search.find('input[type=search]');
    var timer = null;
    var request = null;

    function select(item) {
      $value.val(item.id);
      $picker.find('[data-picker-label]').html('<strong>' + Core.escapeHtml(item.code) + '</strong> · ' + Core.escapeHtml(item.name));
      $selected.prop('hidden', false);
      $search.prop('hidden', true);
      $results.empty();
      if ($picker.data('with-assignees')) loadAssignees(item.id);
      $picker.trigger('pm:equipment-selected', [item]);
    }

    function loadAssignees(equipmentId) {
      var $select = $('[data-assignee-select]');
      if (!$select.length) return;
      $.getJSON($picker.data('assignees-url'), { equipment_id: equipmentId }).then(function (json) {
        var current = $select.val();
        $select.find('option:not(:first)').remove();
        json.results.forEach(function (u) {
          $select.append($('<option>').val(u.id).text(u.name));
        });
        $select.val(current);
      });
    }

    $picker.on('click', '[data-picker-clear]', function () {
      $value.val('');
      $selected.prop('hidden', true);
      $search.prop('hidden', false);
      $input.val('').trigger('focus');
    });

    $input.on('input', function () {
      clearTimeout(timer);
      var q = $input.val().trim();
      if (q.length < 1) { $results.empty(); return; }
      timer = setTimeout(function () {
        // On garde la requête jqXHR elle-même (abort), pas la promesse renvoyée par .then() qui n'en a pas :
        // sinon la deuxième frappe levait une erreur et la recherche restait figée.
        if (request) request.abort();
        request = $.getJSON($picker.data('search-url'), { q: q });
        request.done(function (json) {
          $results.empty();
          if (!json.results.length) {
            $results.append($('<li>').append($('<button type="button" disabled>').text(t('js.no_result'))));
            return;
          }
          json.results.forEach(function (item) {
            var $btn = $('<button type="button">')
              .append($('<span>').append($('<strong>').text(item.code)).append(document.createTextNode(' · ' + item.name + ' — ' + item.site)))
              .append(Core.badge('status', item.status, t))
              .on('click', function () { select(item); });
            $results.append($('<li>').append($btn));
          });
        });
      }, 250);
    });
  });

  // ---------------------------------------------------------------- Notifications discrètes
  var toast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 2600, timerProgressBar: true });

  // ---------------------------------------------------------------- Référentiel marque → modèle
  function filterModels($brand, $model) {
    var brandId = $brand.val();
    $model.find('option[data-brand]').each(function () {
      var match = !brandId || this.dataset.brand === brandId;
      this.hidden = !match;
      this.disabled = !match;
    });
    var selected = $model.find('option:selected')[0];
    if (selected && selected.disabled) $model.val('');
  }
  $('[data-model-select]').each(function () {
    var $model = $(this);
    var $form = $model.closest('form');
    var $brand = $form.find('[data-brand-select]');
    var $category = $($model.data('category-target'));
    filterModels($brand, $model);
    $brand.on('change', function () { filterModels($brand, $model); });
    $model.on('change', function () {
      var opt = this.options[this.selectedIndex];
      if (!opt || !opt.dataset.brand) return;
      if ($brand.val() !== opt.dataset.brand) { $brand.val(opt.dataset.brand); filterModels($brand, $model); }
      if (opt.dataset.category && !$category.val()) {
        $category.val(opt.dataset.category);
        $category.addClass('is-flash');
        setTimeout(function () { $category.removeClass('is-flash'); }, 900);
      }
    });
  });

  // Ajout rapide d'un modèle sans quitter le formulaire
  $(document).on('click', '[data-quick-model]', function () { quickModel($(this), {}); });

  /** Nouveau modèle : saisie, confirmation avec résumé, puis création ; « Modifier » rouvre la saisie. */
  function quickModel($btn, values) {
    var $form = $btn.closest('form');
    var $brand = $form.find('[data-brand-select]');
    var $model = $form.find('[data-model-select]');
    var $category = $($model.data('category-target'));
    if (!$brand.val()) {
      toast.fire({ icon: 'info', title: t('js.choose_brand_first') });
      $brand.trigger('focus');
      return;
    }
    var categories = $category.find('option').map(function () {
      return '<option value="' + Core.escapeHtml(this.value) + '">' + Core.escapeHtml(this.textContent) + '</option>';
    }).get().join('');
    var brandName = $brand.find('option:selected').text();
    Swal.fire({
      title: t('js.new_model_for', { brand: brandName }),
      html: '<div class="grid" style="text-align:left">'
        + '<div class="field"><label for="qm-name">' + Core.escapeHtml(t('js.model_name')) + '</label><input id="qm-name" class="input" maxlength="80" required></div>'
        + '<div class="field"><label for="qm-cat">' + Core.escapeHtml(t('js.category')) + '</label><select id="qm-cat" class="input">' + categories + '</select></div>'
        + '</div>',
      showCancelButton: true,
      confirmButtonText: t('js.save'),
      cancelButtonText: t('js.cancel'),
      confirmButtonColor: '#1F4E79',
      reverseButtons: true,
      focusConfirm: false,
      didOpen: function (popup) {
        popup.querySelector('#qm-cat').value = values.category !== undefined ? values.category : ($category.val() || '');
        popup.querySelector('#qm-name').value = values.name || '';
        popup.querySelector('#qm-name').focus();
        if (values.error) Swal.showValidationMessage(values.error);
      },
      preConfirm: function () {
        var name = document.getElementById('qm-name').value.trim();
        if (!name) { Swal.showValidationMessage(t('js.model_name')); return false; }
        var cat = document.getElementById('qm-cat');
        return { name: name, category: cat.value, categoryLabel: cat.value ? cat.options[cat.selectedIndex].textContent : '' };
      }
    }).then(function (res) {
      if (!res.isConfirmed || !res.value) return;
      var v = res.value;
      var rows = [[t('js.brand'), brandName], [t('js.model_name'), v.name]];
      if (v.categoryLabel) rows.push([t('js.category'), v.categoryLabel]);
      confirmSubmit($form[0], { title: t('js.new_model_for', { brand: brandName }), rows: rows, kind: 'create' }).then(function (ok) {
        if (!ok) { quickModel($btn, v); return; }
        $.post($btn.data('url'), { brand_id: $brand.val(), name: v.name, category_id: v.category }).then(function (json) {
          var m = json.model;
          $('<option>').val(m.id).text(m.name).attr({ 'data-brand': m.brand_id, 'data-category': m.category_id || '' }).appendTo($model);
          $model.val(String(m.id)).trigger('change');
          toast.fire({ icon: 'success', title: t('js.model_added', { name: m.name }) });
        }, function (xhr) {
          var json = xhr && xhr.responseJSON;
          quickModel($btn, $.extend({}, v, { error: json && json.errors ? Object.values(json.errors).join(' ') : ((json && json.error) || t('js.error_generic')) }));
        });
      });
    });
  }

  // ---------------------------------------------------------------- Fenêtre site : filtre du parc en direct
  $('[data-fleet]').each(function () {
    var $root = $(this);
    var status = '';
    var query = '';
    function apply() {
      var shown = 0;
      $root.find('[data-fleet-group]').each(function () {
        var n = 0;
        $(this).find('[data-fleet-item]').each(function () {
          var ok = (!status || this.dataset.status === status) && (!query || this.dataset.search.indexOf(query) !== -1);
          this.hidden = !ok;
          if (ok) n++;
        });
        this.hidden = n === 0;
        $(this).find('[data-fleet-group-count]').text(n);
        shown += n;
      });
      $root.find('[data-fleet-empty]').prop('hidden', shown !== 0);
    }
    $root.on('click', '[data-fleet-status]', function () {
      status = this.dataset.fleetStatus;
      $root.find('[data-fleet-status]').attr('aria-pressed', 'false');
      $(this).attr('aria-pressed', 'true');
      apply();
    });
    $root.on('input', '[data-fleet-search]', function () {
      query = this.value.trim().toLowerCase();
      apply();
    });
  });

  // ---------------------------------------------------------------- Recherche instantanée dans un tableau simple
  $(document).on('input', '[data-table-search]', function () {
    var q = this.value.trim().toLowerCase();
    $(this.dataset.tableSearch).find('tbody tr').each(function () {
      this.hidden = q !== '' && this.textContent.toLowerCase().indexOf(q) === -1;
    });
  });
  // Clic sur une marque : filtre les modèles de cette marque (second clic : tout afficher)
  // Filtre par marque du référentiel (compatible avec la pagination DataTables du tableau des modèles).
  var modelBrand = null;
  $.fn.dataTable.ext.search.push(function (settings, data, index) {
    if (modelBrand === null || settings.nTable.id !== 'models-table') return true;
    var row = settings.aoData[index];
    return !row || !row.nTr || row.nTr.dataset.brand === modelBrand;
  });

  $(document).on('click', '[data-filter-brand]', function () {
    var id = this.dataset.filterBrand;
    var active = $(this).attr('aria-pressed') === 'true';
    $('[data-filter-brand]').attr('aria-pressed', 'false');
    if (!active) $(this).attr('aria-pressed', 'true');
    var $models = $('#models-table');
    if ($models.length && $.fn.dataTable.isDataTable($models[0])) {
      modelBrand = active ? null : id;
      $models.DataTable().draw();
    } else {
      $models.find('tbody tr').each(function () { this.hidden = !active && this.dataset.brand !== id; });
    }
  });

  // ---------------------------------------------------------------- Listes dépendantes du site (équipe, chef d'équipe…)
  function filterBySite($form) {
    var siteId = $form.find('[data-site-select]').val();
    $form.find('select[data-site-filtered]').each(function () {
      var $select = $(this);
      $select.find('option[data-site]').each(function () {
        var ok = !siteId || this.dataset.site === siteId;
        this.hidden = !ok;
        this.disabled = !ok;
      });
      var selected = $select.find('option:selected')[0];
      if (selected && selected.disabled) $select.val('');
    });
  }
  $('[data-site-select]').each(function () {
    var $form = $(this).closest('form');
    filterBySite($form);
    $(this).on('change', function () { filterBySite($form); });
  });

  // ---------------------------------------------------------------- Compatibilité pièce ↔ modèles
  $('[data-compat]').each(function () {
    var $root = $(this);
    var $form = $root.closest('form');
    function sync() {
      $root.find('[data-compat-brand]').each(function () {
        var $boxes = $(this).find('[data-compat-item] input');
        var checked = $boxes.filter(':checked').length;
        var all = $(this).find('[data-compat-all]')[0];
        all.checked = checked > 0 && checked === $boxes.length;
        all.indeterminate = checked > 0 && checked < $boxes.length;
        $(this).toggleClass('has-selection', checked > 0);
      });
      $form.find('[data-compat-count]').text($root.find('[data-compat-item] input:checked').length);
    }
    $root.on('change', '[data-compat-item] input', sync);
    $root.on('change', '[data-compat-all]', function () {
      $(this).closest('[data-compat-brand]').find('[data-compat-item]:not([hidden]) input').prop('checked', this.checked);
      sync();
    });
    $root.on('input', '[data-compat-search]', function () {
      var q = this.value.trim().toLowerCase();
      $root.find('[data-compat-brand]').each(function () {
        var n = 0;
        $(this).find('[data-compat-item]').each(function () {
          var ok = !q || this.dataset.search.indexOf(q) !== -1;
          this.hidden = !ok;
          if (ok) n++;
        });
        this.hidden = n === 0;
      });
    });
    sync();
  });

  // ---------------------------------------------------------------- Indicateurs cliquables → filtre du tableau
  $(document).on('click', '[data-set-filter]', function () {
    var $select = $('form.filters [name="' + this.dataset.setFilter + '"]');
    $select.val(this.dataset.value).trigger('change');
    var table = document.querySelector('.card table[data-table]');
    if (table) table.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  // ---------------------------------------------------------------- Impression et envoi automatique des sélecteurs
  $(document).on('click', '[data-print]', function () { window.print(); });
  $(document).on('change', 'select[data-autosubmit]', function () { this.form.submit(); });

  // ---------------------------------------------------------------- Zones de texte extensibles (Ctrl+Entrée pour envoyer)
  $(document).on('input', 'textarea[data-autogrow]', function () {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight + 2, 320) + 'px';
  });
  $(document).on('keydown', 'textarea[data-autogrow]', function (e) {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && this.value.trim()) {
      e.preventDefault();
      $(this).closest('form').trigger('submit');
    }
  });

  // ---------------------------------------------------------------- Menus « … »
  $(document).on('click', '[data-menu-toggle]', function (e) {
    e.stopPropagation();
    var $list = $(this).siblings('.menu__list');
    var open = $list.prop('hidden');
    $('.menu__list').prop('hidden', true);
    $('[data-menu-toggle]').attr('aria-expanded', 'false');
    $list.prop('hidden', !open);
    $(this).attr('aria-expanded', open ? 'true' : 'false');
  });
  $(document).on('click', function () { $('.menu__list').prop('hidden', true); $('[data-menu-toggle]').attr('aria-expanded', 'false'); });
  $(document).on('keydown', function (e) { if (e.key === 'Escape') $('.menu__list').prop('hidden', true); });

  // ---------------------------------------------------------------- Curseurs : valeur affichée, champ envoyé seulement si touché
  $(document).on('input', '[data-range]', function () {
    if (this.dataset.name && !this.name) this.name = this.dataset.name;
    $('[data-range-output="#' + this.id + '"]').text(this.value + ' %');
  });

  // ---------------------------------------------------------------- Contrat : champs visibles hors « propriété »
  $(document).on('change', '[data-toggle-contract]', function () {
    $(this).closest('form').find('[data-contract-fields]').prop('hidden', this.value === 'owned');
  });

  // ---------------------------------------------------------------- Visionneuse photo
  $(document).on('click', '[data-lightbox]', function () {
    Swal.fire({
      imageUrl: this.dataset.lightbox,
      imageAlt: this.dataset.caption || '',
      text: this.dataset.caption || '',
      showConfirmButton: false,
      showCloseButton: true,
      width: 'min(1100px, 96vw)',
      padding: '0.75rem',
      customClass: { popup: 'lightbox' }
    });
  });

  // ---------------------------------------------------------------- Photos : compression dans le navigateur puis envoi
  function compressImage(file, maxSide, quality) {
    return new Promise(function (resolve) {
      if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { resolve(file); return; }
      var img = new Image();
      var url = URL.createObjectURL(file);
      img.onload = function () {
        var scale = Math.min(1, maxSide / Math.max(img.width, img.height));
        if (scale === 1 && file.size < 1.5 * 1024 * 1024) { URL.revokeObjectURL(url); resolve(file); return; }
        var canvas = document.createElement('canvas');
        canvas.width = Math.round(img.width * scale);
        canvas.height = Math.round(img.height * scale);
        canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
        URL.revokeObjectURL(url);
        canvas.toBlob(function (blob) {
          resolve(blob ? new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file);
        }, 'image/jpeg', quality);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }
  function uploadPhotos(url, files) {
    files = Array.prototype.filter.call(files, function (f) { return /^image\//.test(f.type); });
    if (!files.length) return;
    // Confirmation : photos qui vont être ajoutées (nom, poids).
    var rows = files.map(function (f, i) { return [t('js.photo_n', { n: i + 1 }), f.name + ' · ' + Core.formatNumber(f.size / 1048576, locale, 1) + ' Mo']; });
    confirmSubmit(document.createElement('form'), { title: t('js.photos_confirm', { n: files.length }), rows: rows, kind: 'create', confirmLabel: t('js.photos_send') }).then(function (ok) {
      if (ok) sendPhotos(url, files);
    });
  }
  function sendPhotos(url, files) {
    Swal.fire({ title: t('js.uploading', { n: files.length }), allowOutsideClick: false, didOpen: function () { Swal.showLoading(); } });
    files.reduce(function (chain, file) {
      return chain.then(function () {
        return compressImage(file, 1600, 0.82).then(function (blob) {
          var data = new FormData();
          data.append('photo', blob, blob.name || file.name);
          data.append('_csrf', csrf);
          return $.ajax({ url: url, method: 'POST', data: data, processData: false, contentType: false });
        });
      });
    }, Promise.resolve()).then(function () {
      window.location.reload();
    }).catch(function (xhr) { Swal.close(); ajaxError(xhr); });
  }
  $(document).on('change', '[data-photo-upload] input[type=file]', function () {
    uploadPhotos($(this).closest('[data-photo-upload]').data('url'), this.files);
    this.value = '';
  });
  $('[data-photo-drop]').each(function () {
    var $zone = $(this);
    var url = $('[data-photo-upload]').data('url');
    if (!url) return;
    $zone.on('dragover', function (e) { e.preventDefault(); $zone.addClass('is-dragover'); })
      .on('dragleave drop', function () { $zone.removeClass('is-dragover'); })
      .on('drop', function (e) { e.preventDefault(); uploadPhotos(url, e.originalEvent.dataTransfer.files); });
  });

  // Afficher / masquer un mot de passe
  $(document).on('click', '[data-toggle-password]', function () {
    var $btn = $(this);
    var input = document.getElementById($btn.data('toggle-password'));
    if (!input) return;
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    $btn.attr({ 'aria-pressed': String(show), 'aria-label': $btn.data(show ? 'label-hide' : 'label-show') });
    $btn.html('<i data-lucide="' + (show ? 'eye-off' : 'eye') + '"></i>');
    icons();
    input.focus();
  });

  icons();
})(window.jQuery, window.PMCore, window.PMTables, window.PMExport);
