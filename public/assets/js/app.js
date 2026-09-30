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

  // ---------------------------------------------------------------- Confirmations
  $(document).on('submit', 'form[data-confirm]', function (e) {
    var form = this;
    if (form.dataset.confirmed === '1') return;
    e.preventDefault();
    Swal.fire({
      icon: 'question',
      text: form.dataset.confirm,
      showCancelButton: true,
      confirmButtonText: t('js.confirm'),
      cancelButtonText: t('js.cancel'),
      confirmButtonColor: '#1F4E79',
      reverseButtons: true
    }).then(function (res) {
      if (res.isConfirmed) {
        form.dataset.confirmed = '1';
        form.submit();
      }
    });
  });

  // Dialogues de saisie : le contenu vient d'un <template> contenant un formulaire.
  $(document).on('click', '[data-open]', function () {
    var tpl = document.querySelector(this.dataset.open);
    if (!tpl) return;
    Swal.fire({
      html: tpl.innerHTML,
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
        form.submit();
        return true;
      }
    });
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
        var data = Exporter.tableData(def.columns, json.data || [], locale);
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

  // ---------------------------------------------------------------- Notifications (cloche)
  $('[data-notifications]').each(function () {
    var $root = $(this);
    var $panel = $root.find('[data-notif-panel]');
    var $list = $root.find('[data-notif-list]');
    var $badge = $root.find('[data-notif-badge]');
    var items = [];
    function render(json) {
      items = json.items || [];
      // Pastilles du menu : mêmes compteurs que les notifications
      var counts = {};
      items.forEach(function (it) { counts[it.key] = it.count; });
      $('[data-nav-badge]').each(function () {
        var total = this.dataset.navBadge.split(' ').reduce(function (sum, key) { return sum + (counts[key] || 0); }, 0);
        this.hidden = total === 0;
        this.textContent = total > 99 ? '99+' : String(total);
      });
      $badge.prop('hidden', !json.count).text(json.count > 9 ? '9+' : json.count);
      $root.toggleClass('has-items', items.length > 0);
      if (!items.length) {
        $list.html('<li class="notif__empty"><i data-lucide="check-circle-2"></i>' + Core.escapeHtml(t('js.notif_none')) + '</li>');
      } else {
        $list.html(items.map(function (it, i) {
          return '<li><button type="button" class="notif__item notif__item--' + it.level + '" data-notif-item="' + i + '">'
            + '<span class="notif__icon"><i data-lucide="' + Core.escapeHtml(it.icon) + '"></i></span>'
            + '<span class="notif__label">' + Core.escapeHtml(it.label) + '</span>'
            + '<i data-lucide="chevron-right" class="notif__chev"></i></button></li>';
        }).join(''));
      }
      icons();
    }
    function load() {
      return $.getJSON($root.data('url')).then(render);
    }
    setTimeout(load, 400);
    $root.on('click', '[data-notif-toggle]', function (e) {
      e.stopPropagation();
      var open = $panel.prop('hidden');
      $panel.prop('hidden', !open);
      $(this).attr('aria-expanded', open ? 'true' : 'false');
      if (open) load();
    });
    $root.on('click', '[data-notif-item]', function () {
      var it = items[Number(this.dataset.notifItem)];
      $panel.prop('hidden', true);
      if (it.drill) openDrill(it.drill, $root.find('[data-notif-toggle]')[0]);
      else if (it.link) window.location.href = it.link;
    });
    $(document).on('click', function (e) {
      if (!$(e.target).closest('[data-notifications]').length) $panel.prop('hidden', true);
    });
  });

  // Bon de travail PDF
  $(document).on('click', '[data-wo-pdf]', function () {
    var payload = readJson('wo-data');
    loadPdfMake().then(function (pdfMake) {
      Exporter.downloadPdf(pdfMake, Exporter.workOrderDocDefinition(payload.wo, payload.parts, t, locale),
        Core.exportFileName(payload.wo.reference, 'pdf'));
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
        if (request) request.abort();
        request = $.getJSON($picker.data('search-url'), { q: q }).then(function (json) {
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
  $(document).on('click', '[data-quick-model]', function () {
    var $btn = $(this);
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
    Swal.fire({
      title: t('js.new_model_for', { brand: $brand.find('option:selected').text() }),
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
        popup.querySelector('#qm-cat').value = $category.val() || '';
        popup.querySelector('#qm-name').focus();
      },
      preConfirm: function () {
        var name = document.getElementById('qm-name').value.trim();
        if (!name) { Swal.showValidationMessage(t('js.model_name')); return false; }
        return $.post($btn.data('url'), { brand_id: $brand.val(), name: name, category_id: document.getElementById('qm-cat').value })
          .then(function (json) { return json.model; })
          .catch(function (xhr) {
            var json = xhr && xhr.responseJSON;
            Swal.showValidationMessage(json && json.errors ? Object.values(json.errors).join(' ') : ((json && json.error) || t('js.error_generic')));
          });
      }
    }).then(function (res) {
      if (!res.isConfirmed || !res.value) return;
      var m = res.value;
      $('<option>').val(m.id).text(m.name).attr({ 'data-brand': m.brand_id, 'data-category': m.category_id || '' }).appendTo($model);
      $model.val(String(m.id)).trigger('change');
      toast.fire({ icon: 'success', title: t('js.model_added', { name: m.name }) });
    });
  });

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
