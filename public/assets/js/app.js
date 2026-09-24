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

  $('table[data-table]').each(function () {
    var $table = $(this);
    var name = $table.data('table');
    var def = definitions[name];
    if (!def) return;
    var rowLink = $table.data('row-link');

    tables[name] = $table.DataTable({
      serverSide: true,
      processing: true,
      searchDelay: 350,
      pageLength: 25,
      lengthMenu: [10, 25, 50, 100],
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
      }
    });
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
    var button = this;
    var name = button.dataset.exportTable;
    var table = tables[name];
    var def = definitions[name];
    if (!table || !def) return;
    var title = button.dataset.exportTitle;
    var params = $.extend(table.ajax.params(), { start: 0, length: -1, draw: 0 });
    button.disabled = true;

    $.getJSON($('table[data-table="' + name + '"]').data('source'), params)
      .then(function (json) {
        var data = Exporter.tableData(def.columns, json.data || []);
        var meta = { subtitle: t('js.export_meta', { count: json.recordsFiltered, date: Core.formatDate(new Date().toISOString().slice(0, 10), locale) }) };
        if (json.recordsFiltered > json.data.length) {
          meta.subtitle += ' — ' + t('js.export_truncated', { count: json.data.length });
        }
        if (button.dataset.export === 'excel') {
          return loadScript(config.assets.exceljs).then(function () { return Exporter.exportExcel(window.ExcelJS, title, data, meta); });
        }
        return loadPdfMake().then(function (pdfMake) { Exporter.exportPdf(pdfMake, title, data, meta); });
      })
      .fail(ajaxError)
      .catch(function () { alertError(); })
      .always(function () { button.disabled = false; });
  });

  // Bon de travail PDF
  $(document).on('click', '[data-wo-pdf]', function () {
    var payload = readJson('wo-data');
    loadPdfMake().then(function (pdfMake) {
      pdfMake.createPdf(Exporter.workOrderDocDefinition(payload.wo, payload.parts, t, locale))
        .download(Core.exportFileName(payload.wo.reference, 'pdf'));
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

  icons();
})(window.jQuery, window.PMCore, window.PMTables, window.PMExport);
