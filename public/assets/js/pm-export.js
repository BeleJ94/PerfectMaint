/*
 * Exports Excel (ExcelJS) et PDF (pdfmake). Les constructions de documents sont pures
 * (testées sous Node) ; seules exportExcel/exportPdf touchent au navigateur.
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory(require('./pm-core.js'), require('./pm-tables.js'));
  } else {
    root.PMExport = factory(root.PMCore, root.PMTables);
  }
})(typeof self !== 'undefined' ? self : this, function (Core, Tables) {
  'use strict';

  var ACCENT = '#1F4E79';

  /** { headers: [...], body: [[...], ...] } à partir des colonnes et des lignes JSON. */
  function tableData(columns, rows) {
    return {
      headers: columns.map(function (c) { return c.title; }),
      body: rows.map(function (row) {
        return columns.map(function (c) { return Tables.cellText(c, row); });
      })
    };
  }

  /** Classeur ExcelJS : en-tête à la couleur d'accent, filtre auto, volets figés, largeurs ajustées. */
  function buildWorkbook(ExcelJS, title, data, meta) {
    var wb = new ExcelJS.Workbook();
    wb.creator = 'PerfectMaint';
    wb.created = meta && meta.date ? meta.date : new Date();
    var sheet = wb.addWorksheet(String(title).slice(0, 31) || 'Export', {
      views: [{ state: 'frozen', ySplit: 1 }]
    });
    sheet.addRow(data.headers);
    data.body.forEach(function (r) { sheet.addRow(r); });
    var header = sheet.getRow(1);
    header.font = { bold: true, color: { argb: 'FFFFFFFF' } };
    header.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF1F4E79' } };
    header.alignment = { vertical: 'middle' };
    sheet.autoFilter = { from: { row: 1, column: 1 }, to: { row: 1, column: Math.max(1, data.headers.length) } };
    sheet.columns.forEach(function (col, i) {
      var max = String(data.headers[i] || '').length;
      data.body.forEach(function (r) { max = Math.max(max, String(r[i] || '').length); });
      col.width = Math.min(60, Math.max(10, max + 2));
    });
    return wb;
  }

  function pdfStyles() {
    return {
      title: { fontSize: 15, bold: true, color: ACCENT, margin: [0, 0, 0, 4] },
      meta: { fontSize: 8, color: '#5B6573', margin: [0, 0, 0, 10] },
      th: { bold: true, color: '#FFFFFF', fillColor: ACCENT, fontSize: 8 },
      h2: { fontSize: 11, bold: true, color: ACCENT, margin: [0, 12, 0, 6] },
      label: { color: '#5B6573' }
    };
  }

  /** Document pdfmake : tableau paysage avec en-tête répété et pagination. */
  function listDocDefinition(title, data, meta) {
    var widths = data.headers.map(function () { return '*'; });
    return {
      pageOrientation: 'landscape',
      pageSize: 'A4',
      pageMargins: [28, 32, 28, 36],
      defaultStyle: { fontSize: 8 },
      styles: pdfStyles(),
      footer: function (current, count) {
        return { text: current + ' / ' + count, alignment: 'right', fontSize: 7, color: '#5B6573', margin: [0, 8, 28, 0] };
      },
      content: [
        { text: title, style: 'title' },
        { text: (meta && meta.subtitle) || '', style: 'meta' },
        {
          table: {
            headerRows: 1,
            widths: widths,
            body: [data.headers.map(function (h) { return { text: h, style: 'th' }; })].concat(data.body)
          },
          layout: {
            hLineColor: function () { return '#DDE1E6'; },
            vLineWidth: function () { return 0; },
            fillColor: function (rowIndex) { return rowIndex > 0 && rowIndex % 2 === 0 ? '#F5F6F8' : null; }
          }
        }
      ]
    };
  }

  /** Bon de travail imprimable d'un OT. */
  function workOrderDocDefinition(wo, parts, t, locale) {
    var d = function (v, time) { return Core.formatDate(v, locale, time) || '—'; };
    var n = function (v, dec) { return Core.formatNumber(v, locale, dec); };
    var info = [
      [t('fields.equipment_id'), wo.equipment_code + ' – ' + wo.equipment_name],
      [t('fields.site_id'), wo.site],
      [t('fields.type'), t('wo_type.' + wo.type)],
      [t('fields.priority'), t('priority.' + wo.priority)],
      [t('fields.status'), t('wo_status.' + wo.status)],
      [t('fields.reported_by'), (wo.reporter || '—') + ' · ' + d(wo.reported_at, true)],
      [t('fields.assigned_to'), wo.assignee || '—'],
      [t('fields.due_date'), d(wo.due_date)],
      [t('fields.completed_at'), d(wo.completed_at, true)],
      [t('fields.labor_minutes'), wo.labor_minutes !== null && wo.labor_minutes !== undefined ? wo.labor_minutes + ' min' : '—']
    ].map(function (r) { return [{ text: r[0], style: 'label' }, String(r[1])]; });

    var total = 0;
    var partRows = (parts || []).map(function (p) {
      var line = Number(p.quantity) * Number(p.unit_cost);
      total += line;
      return [p.reference, p.name, n(p.quantity) + ' ' + p.unit, n(p.unit_cost, 2) + ' €', n(line, 2) + ' €'];
    });

    var content = [
      { text: t('js.work_order') + ' ' + wo.reference, style: 'title' },
      { text: wo.title, fontSize: 11, margin: [0, 0, 0, 10] },
      { table: { widths: [130, '*'], body: info }, layout: 'noBorders' },
      { text: t('fields.description'), style: 'h2' },
      { text: wo.description || '—' },
      { text: t('fields.resolution'), style: 'h2' },
      { text: wo.resolution || '\n\n\n' }
    ];
    content.push({ text: t('js.parts_used'), style: 'h2' });
    if (partRows.length) {
      content.push({
        table: {
          headerRows: 1,
          widths: [80, '*', 60, 60, 60],
          body: [[t('fields.reference'), t('fields.name'), t('fields.quantity'), t('fields.unit_cost'), t('js.total')]
            .map(function (h) { return { text: h, style: 'th' }; })]
            .concat(partRows)
            .concat([[{ text: t('js.total'), bold: true, colSpan: 4 }, '', '', '', { text: n(total, 2) + ' €', bold: true }]])
        },
        layout: 'lightHorizontalLines'
      });
    } else {
      content.push({ text: '—' });
    }
    content.push({
      margin: [0, 30, 0, 0],
      columns: [
        { text: t('js.signature_tech') + '\n\n\n______________________', fontSize: 9 },
        { text: t('js.signature_manager') + '\n\n\n______________________', fontSize: 9 }
      ]
    });

    return {
      pageSize: 'A4',
      pageMargins: [36, 36, 36, 40],
      defaultStyle: { fontSize: 9 },
      styles: pdfStyles(),
      footer: function () {
        return { text: 'PerfectMaint · ' + wo.reference, alignment: 'center', fontSize: 7, color: '#5B6573' };
      },
      content: content
    };
  }

  // --- Navigateur -----------------------------------------------------------

  function saveBlob(blob, filename) {
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 1000);
  }

  function exportExcel(ExcelJS, title, data, meta) {
    return buildWorkbook(ExcelJS, title, data, meta).xlsx.writeBuffer().then(function (buffer) {
      saveBlob(new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }),
        Core.exportFileName(title, 'xlsx'));
    });
  }

  function exportPdf(pdfMake, title, data, meta) {
    pdfMake.createPdf(listDocDefinition(title, data, meta)).download(Core.exportFileName(title, 'pdf'));
  }

  return {
    tableData: tableData,
    buildWorkbook: buildWorkbook,
    listDocDefinition: listDocDefinition,
    workOrderDocDefinition: workOrderDocDefinition,
    exportExcel: exportExcel,
    exportPdf: exportPdf
  };
});
