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

  /**
   * { headers, body, cells } à partir des colonnes et des lignes JSON.
   * body : textes affichés (PDF) ; cells : valeurs natives pour Excel ({v, fmt} ou null = texte).
   */
  function tableData(columns, rows, locale) {
    return {
      headers: columns.map(function (c) { return c.title; }),
      body: rows.map(function (row) {
        return columns.map(function (c) { return Tables.cellText(c, row); });
      }),
      cells: rows.map(function (row) {
        return columns.map(function (c) { return Tables.cellValue(c, row, locale); });
      }),
      numeric: columns.map(function (c) { return !!c.xl; })
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
    data.body.forEach(function (r, i) {
      var row = sheet.addRow(r.map(function (text, c) {
        var native = data.cells && data.cells[i][c];
        return native ? native.v : text;
      }));
      (data.cells ? data.cells[i] : []).forEach(function (native, c) {
        if (!native) return;
        var cell = row.getCell(c + 1);
        if (native.fmt) cell.numFmt = native.fmt;
        cell.alignment = { horizontal: 'right' };
      });
    });
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

  /**
   * Bon de travail imprimable d'un OT : en-tête avec référence et statut, fiches engin / intervention,
   * description, travaux réalisés, intervenants, pièces chiffrées, signatures, pied de page paginé.
   * @param {Array} [technicians] intervenants (nom, métier, minutes)
   */
  function workOrderDocDefinition(wo, parts, t, locale, technicians) {
    var d = function (v, time) { return Core.formatDate(v, locale, time) || '—'; };
    var n = function (v, dec) { return Core.formatNumber(v, locale, dec); };
    var GREY = '#5B6573';
    var LINE = '#D5DBE3';
    var SOFT = '#F3F6F9';
    var STATUS_COLORS = { open: '#B54708', in_progress: '#1F4E79', waiting_part: '#B54708', done: '#067647', cancelled: '#5E6166' };
    var PRIORITY_COLORS = { critical: '#B42318', high: '#B54708', medium: '#1F4E79', low: '#5E6166' };

    // Tableau sans bordures verticales, filets fins, en-tête accentué, lignes alternées.
    var tableLayout = {
      hLineWidth: function (i, node) { return i === 0 || i === node.table.body.length ? 0 : 0.5; },
      vLineWidth: function () { return 0; },
      hLineColor: function () { return LINE; },
      fillColor: function (row, node) { return row > 0 && row % 2 === 0 && row < node.table.body.length ? SOFT : null; },
      paddingTop: function () { return 4; },
      paddingBottom: function () { return 4; }
    };
    var boxLayout = {
      hLineWidth: function () { return 0.5; }, vLineWidth: function () { return 0.5; },
      hLineColor: function () { return LINE; }, vLineColor: function () { return LINE; },
      paddingLeft: function () { return 8; }, paddingRight: function () { return 8; },
      paddingTop: function () { return 6; }, paddingBottom: function () { return 6; }
    };

    function section(title) {
      return {
        margin: [0, 14, 0, 6],
        table: { widths: ['*'], body: [[{ text: title.toUpperCase(), bold: true, fontSize: 8.5, color: '#FFFFFF', fillColor: ACCENT, characterSpacing: 0.6 }]] },
        layout: { hLineWidth: function () { return 0; }, vLineWidth: function () { return 0; }, paddingLeft: function () { return 6; }, paddingTop: function () { return 3; }, paddingBottom: function () { return 3; } }
      };
    }

    function card(title, rows) {
      return {
        table: {
          widths: [78, '*'],
          body: [[{ text: title.toUpperCase(), colSpan: 2, bold: true, fontSize: 8, color: ACCENT, characterSpacing: 0.6, fillColor: SOFT }, '']]
            .concat(rows.map(function (r) { return [{ text: r[0], color: GREY, fontSize: 8 }, { text: String(r[1]), bold: !!r[2], color: r[3] || '#1D2733' }]; }))
        },
        layout: boxLayout
      };
    }

    // Zone à remplir à la main si l'information manque (bon imprimé avant intervention).
    function textOrLines(text, lines) {
      if (text) return { text: text, lineHeight: 1.25 };
      return { stack: [{ text: '—', color: GREY }].concat(Array.apply(null, Array(lines)).map(function () {
        return { canvas: [{ type: 'line', x1: 0, y1: 0, x2: 523, y2: 0, lineWidth: 0.5, lineColor: LINE }], margin: [0, 16, 0, 0] };
      })) };
    }

    var meters = [];
    if (wo.hour_meter !== null && wo.hour_meter !== undefined) meters.push(n(wo.hour_meter, 0) + ' h');
    else if (wo.equipment_hour_meter !== null && wo.equipment_hour_meter !== undefined) meters.push(n(wo.equipment_hour_meter, 0) + ' h');
    if (wo.odometer_km !== null && wo.odometer_km !== undefined) meters.push(n(wo.odometer_km, 0) + ' km');
    else if (wo.equipment_odometer !== null && wo.equipment_odometer !== undefined) meters.push(n(wo.equipment_odometer, 0) + ' km');

    var labor = wo.labor_minutes !== null && wo.labor_minutes !== undefined ? wo.labor_minutes + ' min' : '—';

    var header = {
      columns: [
        {
          width: '*',
          stack: [
            { text: 'PERFECT LOGISTICS', fontSize: 8, bold: true, color: GREY, characterSpacing: 1.2 },
            { text: t('js.work_order') + ' ' + wo.reference, fontSize: 17, bold: true, color: ACCENT, margin: [0, 3, 0, 2] },
            { text: wo.title, fontSize: 11, color: '#1D2733' }
          ]
        },
        {
          width: 150,
          table: {
            widths: ['*'],
            body: [
              [{ text: t('wo_status.' + wo.status), alignment: 'center', bold: true, characterSpacing: 0.4, color: '#FFFFFF', fillColor: STATUS_COLORS[wo.status] || ACCENT, fontSize: 9, margin: [0, 2, 0, 2] }],
              [{ text: t('fields.priority') + ' : ' + t('priority.' + wo.priority), alignment: 'center', bold: true, color: PRIORITY_COLORS[wo.priority] || ACCENT, fontSize: 8.5 }],
              [{ text: t('js.wo_doc.issued', { date: d(new Date().toISOString().slice(0, 10)) }), alignment: 'center', color: GREY, fontSize: 7.5 }]
            ]
          },
          layout: boxLayout
        }
      ]
    };

    var content = [
      header,
      { canvas: [{ type: 'line', x1: 0, y1: 0, x2: 523, y2: 0, lineWidth: 2, lineColor: ACCENT }], margin: [0, 10, 0, 12] },
      {
        columns: [
          card(t('js.wo_doc.equipment'), [
            [t('fields.code'), wo.equipment_code, true],
            [t('fields.name'), wo.equipment_name],
            [t('fields.site_id'), wo.site],
            [t('js.wo_doc.meters'), meters.join(' · ') || '—']
          ]),
          card(t('js.wo_doc.intervention'), [
            [t('fields.type'), t('wo_type.' + wo.type), true],
            [t('fields.reported_by'), (wo.reporter || '—') + ' · ' + d(wo.reported_at, true)],
            [t('fields.assigned_to'), wo.assignee || '—'],
            [t('fields.due_date'), d(wo.due_date)],
            [t('fields.completed_at'), d(wo.completed_at, true)],
            [t('fields.labor_minutes'), labor, true]
          ])
        ],
        columnGap: 12
      },
      section(t('fields.description')),
      textOrLines(wo.description, 2),
      section(t('fields.resolution')),
      textOrLines(wo.resolution, 4)
    ];

    if (wo.failure_cause) {
      content.push({ text: [{ text: t('fields.failure_cause') + ' : ', color: GREY }, { text: t('cause.' + wo.failure_cause), bold: true }], margin: [0, 6, 0, 0] });
    }

    // Intervenants
    if (technicians && technicians.length) {
      var minutes = 0;
      content.push(section(t('js.wo_doc.technicians')));
      content.push({
        table: {
          headerRows: 1,
          widths: ['*', 120, 70],
          body: [[t('fields.name'), t('js.wo_doc.job'), t('fields.minutes')].map(function (h, i) { return { text: h, style: 'th', alignment: i === 2 ? 'right' : 'left' }; })]
            .concat(technicians.map(function (p) {
              minutes += Number(p.minutes) || 0;
              return [p.name, p.job ? t('job.' + p.job) : '—', { text: p.minutes ? n(p.minutes, 0) + ' min' : '—', alignment: 'right' }];
            }))
            .concat([[{ text: t('js.total'), bold: true, colSpan: 2 }, '', { text: n(minutes, 0) + ' min', bold: true, alignment: 'right' }]])
        },
        layout: tableLayout
      });
    }

    // Pièces et consommables
    var total = 0;
    var partRows = (parts || []).map(function (p) {
      var line = Number(p.quantity) * Number(p.unit_cost);
      total += line;
      return [
        { text: p.reference, color: GREY },
        p.name,
        { text: n(p.quantity) + ' ' + p.unit, alignment: 'right' },
        { text: Core.formatMoney(p.unit_cost, locale), alignment: 'right' },
        { text: Core.formatMoney(line, locale), alignment: 'right' }
      ];
    });
    content.push(section(t('js.parts_used')));
    if (partRows.length) {
      content.push({
        table: {
          headerRows: 1,
          widths: [80, '*', 60, 70, 75],
          body: [[t('fields.reference'), t('fields.name'), t('fields.quantity'), t('fields.unit_cost'), t('js.total')]
            .map(function (h, i) { return { text: h, style: 'th', alignment: i >= 2 ? 'right' : 'left' }; })]
            .concat(partRows)
            .concat([[{ text: t('js.wo_doc.parts_total'), bold: true, colSpan: 4, alignment: 'right' }, '', '', '',
              { text: Core.formatMoney(total, locale), bold: true, alignment: 'right', color: ACCENT }]])
        },
        layout: tableLayout
      });
    } else {
      content.push({ text: '—', color: GREY });
    }

    // Signatures : nom, date et visa de chaque partie.
    function signature(title) {
      return {
        table: {
          widths: ['*'],
          heights: [12, 14, 14, 46],
          body: [
            [{ text: title.toUpperCase(), bold: true, fontSize: 7.5, color: ACCENT, characterSpacing: 0.5, fillColor: SOFT }],
            [{ text: t('js.wo_doc.sign_name'), fontSize: 7.5, color: GREY }],
            [{ text: t('js.wo_doc.sign_date'), fontSize: 7.5, color: GREY }],
            [{ text: t('js.wo_doc.sign_visa'), fontSize: 7.5, color: GREY }]
          ]
        },
        layout: boxLayout
      };
    }
    content.push({
      margin: [0, 20, 0, 0],
      unbreakable: true,
      columns: [signature(t('js.signature_tech')), signature(t('js.signature_manager')), signature(t('js.wo_doc.sign_reception'))],
      columnGap: 10
    });

    return {
      pageSize: 'A4',
      pageMargins: [36, 36, 36, 46],
      info: { title: t('js.work_order') + ' ' + wo.reference, author: 'PerfectMaint', subject: wo.title },
      defaultStyle: { fontSize: 9, color: '#1D2733' },
      styles: pdfStyles(),
      footer: function (current, count) {
        return {
          margin: [36, 12, 36, 0],
          columns: [
            { text: 'PerfectMaint · ' + wo.reference + ' · ' + wo.site, fontSize: 7, color: GREY },
            { text: t('js.wo_doc.editor'), fontSize: 7, color: GREY, alignment: 'center' },
            { text: current + ' / ' + count, fontSize: 7, color: GREY, alignment: 'right' }
          ]
        };
      },
      content: content
    };
  }

  // --- Planning : matrice engins × jours --------------------------------------

  var PLAN_COLORS = {
    preventive: { fg: '5B3FA6', bg: 'F3F0FB' },
    preventive_planned: { fg: '1F4E79', bg: 'E8EEF4' },
    work_order: { fg: 'B54708', bg: 'FCEFE3' },
    contract: { fg: '5E6166', bg: 'EEEFF0' },
    document: { fg: '0E7490', bg: 'E6F4F7' }
  };

  /**
   * Matrice exécutive : une ligne par engin (groupée par site), une colonne par jour.
   * Cellule = libellés courts des événements du jour ; « ! » signale un retard.
   * @return {{days: Array, groups: Array<{site: string, rows: Array}>, dayTotals: number[], total: number, counts: Object}}
   */
  function planningMatrix(payload) {
    var days = payload.days;
    var index = {};
    days.forEach(function (d, i) { index[d.key] = i; });
    var rowsByKey = {};
    var counts = {};
    var dayTotals = days.map(function () { return 0; });
    payload.events.forEach(function (ev) {
      var col = index[ev.date];
      if (col === undefined) return;
      var key = ev.site + '\u0000' + ev.code;
      if (!rowsByKey[key]) {
        rowsByKey[key] = { site: ev.site, code: ev.code, name: ev.equipment_name || '', cells: days.map(function () { return []; }), total: 0 };
      }
      var row = rowsByKey[key];
      row.cells[col].push({ kind: ev.kind, overdue: !!ev.overdue, text: payload.labels.short[ev.kind] + (ev.overdue ? ' !' : ''), title: ev.title });
      row.total++;
      dayTotals[col]++;
      counts[ev.kind] = (counts[ev.kind] || 0) + 1;
    });
    var groups = {};
    Object.keys(rowsByKey).sort(function (a, b) { return a.localeCompare(b); }).forEach(function (k) {
      var row = rowsByKey[k];
      (groups[row.site] = groups[row.site] || []).push(row);
    });
    return {
      days: days,
      groups: Object.keys(groups).map(function (site) { return { site: site, rows: groups[site] }; }),
      dayTotals: dayTotals,
      total: dayTotals.reduce(function (s, n) { return s + n; }, 0),
      counts: counts
    };
  }

  function cellText(cell) {
    return cell.map(function (c) { return c.text; }).join('\n');
  }

  /** Classeur Excel : titre, périmètre, matrice colorée, totaux, légende ; volets figés sur engins et jours. */
  function buildPlanningWorkbook(ExcelJS, payload, matrix) {
    var L = payload.labels;
    var wb = new ExcelJS.Workbook();
    wb.creator = 'PerfectMaint';
    var sheet = wb.addWorksheet('Planning', {
      views: [{ state: 'frozen', xSplit: 2, ySplit: 4 }],
      pageSetup: { orientation: 'landscape', fitToPage: true, fitToWidth: 1, fitToHeight: 0, paperSize: 9 }
    });
    var lastCol = 3 + matrix.days.length;
    var thin = { style: 'thin', color: { argb: 'FFDDE1E6' } };
    var border = { top: thin, left: thin, bottom: thin, right: thin };

    sheet.mergeCells(1, 1, 1, lastCol);
    sheet.getCell(1, 1).value = L.title;
    sheet.getCell(1, 1).font = { bold: true, size: 15, color: { argb: 'FF1F4E79' } };
    sheet.mergeCells(2, 1, 2, lastCol);
    sheet.getCell(2, 1).value = L.scope + ' · ' + L.generated + ' · ' + matrix.total + ' ' + L.legend.toLowerCase();
    sheet.getCell(2, 1).font = { size: 9, color: { argb: 'FF5B6573' } };
    sheet.getRow(1).height = 24;

    var header = sheet.getRow(4);
    header.values = [L.equipment, ''].concat(matrix.days.map(function (d) { return d.label; })).concat([L.total]);
    header.height = 30;
    header.eachCell(function (cell, col) {
      var day = matrix.days[col - 3];
      cell.font = { bold: true, color: { argb: 'FFFFFFFF' }, size: 9 };
      cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: day && day.weekend ? 'FF3B5F85' : 'FF1F4E79' } };
      cell.alignment = { horizontal: col <= 2 ? 'left' : 'center', vertical: 'middle', wrapText: true };
      cell.border = border;
    });
    sheet.mergeCells(4, 1, 4, 2);

    var r = 5;
    matrix.groups.forEach(function (group) {
      sheet.mergeCells(r, 1, r, lastCol);
      var g = sheet.getCell(r, 1);
      g.value = group.site + ' — ' + group.rows.length;
      g.font = { bold: true, color: { argb: 'FF1F4E79' } };
      g.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFE8EEF4' } };
      r++;
      group.rows.forEach(function (row) {
        var excelRow = sheet.getRow(r);
        excelRow.values = [row.code, row.name].concat(row.cells.map(cellText)).concat([row.total]);
        excelRow.eachCell({ includeEmpty: true }, function (cell, col) {
          cell.border = border;
          cell.alignment = { vertical: 'middle', horizontal: col <= 2 ? 'left' : 'center', wrapText: true };
          cell.font = { size: 9, bold: col === 1 || col === lastCol };
          var dayCell = row.cells[col - 3];
          if (dayCell && dayCell.length) {
            var first = dayCell[0];
            var c = PLAN_COLORS[first.kind];
            cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF' + c.bg } };
            cell.font = { size: 8, bold: true, color: { argb: dayCell.some(function (x) { return x.overdue; }) ? 'FFB42318' : 'FF' + c.fg } };
            cell.note = dayCell.map(function (x) { return x.title; }).join('\n');
          } else if (matrix.days[col - 3] && matrix.days[col - 3].weekend) {
            cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFF7F8F9' } };
          }
        });
        r++;
      });
    });
    var totalRow = sheet.getRow(r);
    totalRow.values = [L.total, ''].concat(matrix.dayTotals).concat([matrix.total]);
    totalRow.eachCell({ includeEmpty: true }, function (cell, col) {
      cell.font = { bold: true, size: 9 };
      cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFEEF0F3' } };
      cell.alignment = { horizontal: col <= 2 ? 'left' : 'center' };
      cell.border = border;
    });
    sheet.mergeCells(r, 1, r, 2);

    r += 2;
    sheet.getCell(r, 1).value = L.legend;
    sheet.getCell(r, 1).font = { bold: true, size: 9 };
    Object.keys(PLAN_COLORS).forEach(function (kind) {
      r++;
      var codeCell = sheet.getCell(r, 1);
      codeCell.value = L.short[kind];
      codeCell.font = { bold: true, size: 8, color: { argb: 'FF' + PLAN_COLORS[kind].fg } };
      codeCell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF' + PLAN_COLORS[kind].bg } };
      codeCell.alignment = { horizontal: 'center' };
      sheet.getCell(r, 2).value = L.kinds[kind] + ' (' + (matrix.counts[kind] || 0) + ')';
      sheet.getCell(r, 2).font = { size: 9 };
    });
    r++;
    sheet.getCell(r, 1).value = '!';
    sheet.getCell(r, 1).font = { bold: true, color: { argb: 'FFB42318' } };
    sheet.getCell(r, 1).alignment = { horizontal: 'center' };
    sheet.getCell(r, 2).value = L.overdue;
    sheet.getCell(r, 2).font = { size: 9 };

    sheet.getColumn(1).width = 16;
    sheet.getColumn(2).width = 26;
    matrix.days.forEach(function (d, i) { sheet.getColumn(3 + i).width = matrix.days.length > 10 ? 9 : 14; });
    sheet.getColumn(lastCol).width = 8;
    return wb;
  }

  /** PDF paysage (A3 au-delà de 10 jours) : matrice colorée, en-tête répété, légende. */
  function planningDocDefinition(payload, matrix) {
    var L = payload.labels;
    var many = matrix.days.length > 10;
    var head = [{ text: L.equipment, style: 'th' }].concat(matrix.days.map(function (d) {
      return { text: d.label.replace(' ', '\n'), style: 'th', alignment: 'center', fillColor: d.weekend ? '#3B5F85' : '#1F4E79' };
    })).concat([{ text: L.total, style: 'th', alignment: 'center' }]);
    var body = [head];
    matrix.groups.forEach(function (group) {
      body.push([{ text: group.site + ' — ' + group.rows.length, colSpan: matrix.days.length + 2, bold: true, color: ACCENT, fillColor: '#E8EEF4' }]
        .concat(matrix.days.map(function () { return ''; })).concat(['']));
      group.rows.forEach(function (row) {
        body.push([{ text: [{ text: row.code, bold: true }, { text: '\n' + row.name, color: '#5B6573', fontSize: many ? 5.5 : 6.5 }] }]
          .concat(row.cells.map(function (cell, i) {
            if (!cell.length) return { text: '', fillColor: matrix.days[i].weekend ? '#F7F8F9' : null };
            var c = PLAN_COLORS[cell[0].kind];
            return {
              text: cellText(cell), alignment: 'center', bold: true, fontSize: many ? 5.5 : 7,
              color: cell.some(function (x) { return x.overdue; }) ? '#B42318' : '#' + c.fg, fillColor: '#' + c.bg
            };
          }))
          .concat([{ text: String(row.total), alignment: 'center', bold: true }]));
      });
    });
    body.push([{ text: L.total, bold: true, fillColor: '#EEF0F3' }]
      .concat(matrix.dayTotals.map(function (n) { return { text: n ? String(n) : '', alignment: 'center', bold: true, fillColor: '#EEF0F3' }; }))
      .concat([{ text: String(matrix.total), alignment: 'center', bold: true, fillColor: '#EEF0F3' }]));

    var legend = Object.keys(PLAN_COLORS).map(function (kind) {
      return { columns: [
        { width: 38, table: { body: [[{ text: L.short[kind], bold: true, fontSize: 7, alignment: 'center', color: '#' + PLAN_COLORS[kind].fg, fillColor: '#' + PLAN_COLORS[kind].bg }]] }, layout: 'noBorders' },
        { text: L.kinds[kind] + ' (' + (matrix.counts[kind] || 0) + ')', fontSize: 8, margin: [4, 2, 0, 0] }
      ], columnGap: 4, width: 'auto' };
    });
    legend.push({ text: [{ text: '!  ', bold: true, color: '#B42318' }, L.overdue], fontSize: 8, margin: [0, 2, 0, 0], width: 'auto' });

    return {
      pageOrientation: 'landscape',
      pageSize: many ? 'A3' : 'A4',
      pageMargins: [24, 28, 24, 34],
      defaultStyle: { fontSize: many ? 6 : 7.5 },
      styles: pdfStyles(),
      footer: function (current, count) {
        return { columns: [
          { text: 'PerfectMaint · ' + L.title, fontSize: 7, color: '#5B6573', margin: [24, 10, 0, 0] },
          { text: current + ' / ' + count, alignment: 'right', fontSize: 7, color: '#5B6573', margin: [0, 10, 24, 0] }
        ] };
      },
      content: [
        { text: L.title, style: 'title' },
        { text: L.scope + ' · ' + L.generated + ' · ' + matrix.total, style: 'meta' },
        {
          table: {
            headerRows: 1,
            dontBreakRows: true,
            widths: [many ? 70 : 110].concat(matrix.days.map(function () { return '*'; })).concat([many ? 22 : 30]),
            body: body
          },
          layout: {
            hLineColor: function () { return '#DDE1E6'; },
            vLineColor: function () { return '#DDE1E6'; },
            hLineWidth: function () { return 0.5; },
            vLineWidth: function () { return 0.5; },
            paddingTop: function () { return 3; },
            paddingBottom: function () { return 3; }
          }
        },
        { text: L.legend, bold: true, fontSize: 8, margin: [0, 12, 0, 4] },
        { columns: legend, columnGap: 14 }
      ]
    };
  }

  // --- Rapport de direction ----------------------------------------------------

  var LEVEL_HEX = { good: '1E7A4C', warn: 'B54708', bad: 'B42318' };
  function availabilityLevel(v) {
    return v === null || v === undefined ? '' : (v >= 85 ? 'good' : (v >= 70 ? 'warn' : 'bad'));
  }
  function kpiText(k, locale) {
    if (k.value === null || k.value === undefined) return '–';
    if (k.money) return Core.formatMoney(k.value, locale);
    return Core.formatNumber(k.value, locale, k.unit === '%' ? 1 : 0) + (k.unit ? ' ' + k.unit : '');
  }

  /** Format Excel d'une tuile d'indicateur (valeur numérique conservée). */
  function kpiFormat(k, currency) {
    if (k.value === null || k.value === undefined) return null;
    if (k.money) return '#,##0.00" ' + String(currency).replace(/"/g, '') + '"';
    if (k.unit === '%') return '0.0" %"';
    return k.unit ? '#,##0" ' + k.unit + '"' : '#,##0';
  }

  /** Nom de feuille Excel valide (31 caractères, sans : \ / ? * [ ]). */
  function sheetName(text) {
    return String(text).replace(/[:\\/?*[\]]/g, ' ').slice(0, 31).trim() || 'Feuille';
  }

  /**
   * Classeur exécutif multi-feuilles :
   * Synthèse (indicateurs, graphiques, sommaire) · Par site · Causes · Engins pénalisants · Conducteurs.
   * Chaque feuille : bandeau, tableau filtrable à en-tête figé, lignes alternées, totaux, mise en page d'impression.
   */
  function buildReportWorkbook(ExcelJS, p, images, locale) {
    var L = p.labels;
    var wb = new ExcelJS.Workbook();
    wb.creator = 'PerfectMaint';
    wb.title = L.title;
    var thin = { style: 'thin', color: { argb: 'FFDDE1E6' } };
    var border = { top: thin, left: thin, bottom: thin, right: thin };
    var money = '#,##0.00 "' + L.currency + '"';
    var names = {
      summary: sheetName(L.kpis), sites: sheetName(L.by_site), causes: sheetName(L.causes),
      top: sheetName(L.top), operators: sheetName(L.operators)
    };

    function newSheet(name, tab, landscape) {
      return wb.addWorksheet(name, {
        properties: { tabColor: { argb: 'FF' + tab } },
        views: [{ showGridLines: false }],
        pageSetup: { orientation: landscape ? 'landscape' : 'portrait', fitToPage: true, fitToWidth: 1, fitToHeight: 0, paperSize: 9,
          margins: { left: 0.4, right: 0.4, top: 0.5, bottom: 0.5, header: 0.2, footer: 0.2 } },
        headerFooter: { oddFooter: '&8PerfectMaint · ' + L.confidential + '&R&8&P / &N' }
      });
    }
    /** Bandeau titre + sous-titre + lien retour vers la synthèse. */
    function banner(ws, title, lastCol, back) {
      ws.mergeCells(1, 1, 1, lastCol);
      var c = ws.getCell(1, 1);
      c.value = title;
      c.font = { bold: true, size: 16, color: { argb: 'FFFFFFFF' } };
      c.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF1F4E79' } };
      c.alignment = { vertical: 'middle', indent: 1 };
      ws.getRow(1).height = 32;
      ws.mergeCells(2, 1, 2, lastCol);
      ws.getCell(2, 1).value = L.subtitle;
      ws.getCell(2, 1).font = { size: 9, color: { argb: 'FF5B6573' } };
      ws.getCell(2, 1).alignment = { indent: 1 };
      if (back) {
        var b = ws.getCell(3, 1);
        b.value = { text: '← ' + names.summary, hyperlink: "#'" + names.summary + "'!A1" };
        b.font = { size: 9, color: { argb: 'FF1F4E79' }, underline: true };
      }
    }
    /** Tableau : en-tête, lignes alternées, formats, filtre auto, volets figés. */
    function table(ws, top, headers, rows, formats, opts) {
      opts = opts || {};
      var h = ws.getRow(top);
      h.values = headers;
      h.height = 30;
      h.eachCell(function (c, col) {
        c.font = { bold: true, size: 9, color: { argb: 'FFFFFFFF' } };
        c.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF1F4E79' } };
        c.alignment = { vertical: 'middle', horizontal: col === 1 || (opts.textCols || []).indexOf(col) !== -1 ? 'left' : 'center', wrapText: true };
        c.border = border;
      });
      rows.forEach(function (values, i) {
        var r = ws.getRow(top + 1 + i);
        r.values = values;
        for (var col = 1; col <= headers.length; col++) {
          var c = r.getCell(col);
          var text = col === 1 || (opts.textCols || []).indexOf(col) !== -1;
          c.border = border;
          c.font = { size: 9, bold: col === 1 };
          c.alignment = { vertical: 'middle', horizontal: text ? 'left' : 'right' };
          if (formats[col - 1]) c.numFmt = formats[col - 1];
          if (i % 2 === 1) c.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFF7F8FA' } };
        }
        if (opts.style) opts.style(r, values);
      });
      var last = top + rows.length;
      if (opts.total) {
        last++;
        var t = ws.getRow(last);
        t.values = opts.total;
        for (var col2 = 1; col2 <= headers.length; col2++) {
          var tc = t.getCell(col2);
          tc.font = { size: 9, bold: true };
          tc.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFE8EEF4' } };
          tc.border = { top: { style: 'medium', color: { argb: 'FF1F4E79' } }, bottom: thin, left: thin, right: thin };
          tc.alignment = { horizontal: col2 === 1 ? 'left' : 'right' };
          if (formats[col2 - 1]) tc.numFmt = formats[col2 - 1];
        }
      }
      if (rows.length) {
        ws.autoFilter = { from: { row: top, column: 1 }, to: { row: top + rows.length, column: headers.length } };
      } else {
        ws.getCell(top + 1, 1).value = '—';
      }
      ws.views = [{ state: 'frozen', ySplit: top, xSplit: 1, showGridLines: false }];
      ws.pageSetup.printTitlesRow = top + ':' + top;
      return last;
    }
    function widths(ws, list) {
      list.forEach(function (w, i) { ws.getColumn(i + 1).width = w; });
    }
    function image(ws, dataUrl, col, row, w, h) {
      if (dataUrl) ws.addImage(wb.addImage({ base64: dataUrl, extension: 'png' }), { tl: { col: col, row: row }, ext: { width: w, height: h } });
    }

    // ---- 1. Synthèse : tuiles d'indicateurs, sommaire, graphiques
    var ws = newSheet(names.summary, '1F4E79', true);
    banner(ws, L.title, 12, false);
    ws.mergeCells(4, 1, 4, 12);
    ws.getCell(4, 1).value = L.kpis;
    ws.getCell(4, 1).font = { bold: true, size: 11, color: { argb: 'FF1F4E79' } };
    ws.getCell(4, 1).border = { bottom: { style: 'medium', color: { argb: 'FF1F4E79' } } };
    p.kpis.forEach(function (k, i) {
      var col = 1 + i * 2;
      [[5, k.label, { size: 8, bold: true, color: { argb: 'FF5B6573' } }],
        [6, k.value === null || k.value === undefined ? '–' : k.value, { size: 18, bold: true, color: { argb: 'FF' + (LEVEL_HEX[k.level] || '1B2430') } }, kpiFormat(k, L.currency)],
        [7, k.hint || '', { size: 8, color: { argb: 'FF5B6573' } }]].forEach(function (spec) {
        ws.mergeCells(spec[0], col, spec[0], col + 1);
        var c = ws.getCell(spec[0], col);
        c.value = spec[1];
        c.font = spec[2];
        if (spec[3]) c.numFmt = spec[3];
        c.alignment = { horizontal: 'center', vertical: 'middle', wrapText: true };
        c.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFF1F5F9' } };
        c.border = { left: { style: 'thick', color: { argb: 'FFFFFFFF' } }, right: { style: 'thick', color: { argb: 'FFFFFFFF' } },
          top: spec[0] === 5 ? { style: 'thick', color: { argb: 'FF' + (LEVEL_HEX[k.level] || '1F4E79') } } : undefined };
      });
    });
    ws.getRow(5).height = 28; ws.getRow(6).height = 34; ws.getRow(7).height = 26;

    // Sommaire (liens vers les feuilles)
    ws.getCell(9, 1).value = L.contents;
    ws.getCell(9, 1).font = { bold: true, size: 11, color: { argb: 'FF1F4E79' } };
    [[names.sites, p.sites.length], [names.causes, p.causes.length], [names.top, p.top.length], [names.operators, p.operators.length]].forEach(function (item, i) {
      var c = ws.getCell(10 + i, 1);
      c.value = { text: '→ ' + item[0], hyperlink: "#'" + item[0] + "'!A1" };
      c.font = { size: 10, color: { argb: 'FF1F4E79' }, underline: true };
      ws.mergeCells(10 + i, 1, 10 + i, 3);
      var n = ws.getCell(10 + i, 4);
      n.value = item[1];
      n.font = { size: 9, color: { argb: 'FF5B6573' } };
      n.numFmt = '0" ' + L.lines + '"';
    });
    image(ws, images && images.causes, 4.2, 8.5, 360, 205);
    image(ws, images && images.availability, 8.2, 8.5, 360, 205);
    ws.getCell(21, 1).value = L.confidential;
    ws.getCell(21, 1).font = { italic: true, size: 8, color: { argb: 'FF8D9098' } };
    widths(ws, [15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15, 15]);
    ws.views = [{ showGridLines: false }];

    // ---- 2. Par site
    var sites = newSheet(names.sites, '1E7A4C', true);
    banner(sites, L.by_site, L.cols_site.length, true);
    var siteFormats = [null, '0', '0.0" %"', '0', '0', '0', '0', '0.0', '0.0', money, '0.0'];
    var t = p.totals;
    var endSites = table(sites, 5, L.cols_site, p.sites, siteFormats, {
      total: [L.total, t[1], t[0], t[2], t[3], t[4], t[5], t[6], null, t[7], t[8]],
      style: function (row, values) {
        var lvl = availabilityLevel(values[2]);
        if (lvl) row.getCell(3).font = { size: 9, bold: true, color: { argb: 'FF' + LEVEL_HEX[lvl] } };
        if (values[4] > 0) row.getCell(5).font = { size: 9, bold: true, color: { argb: 'FFB42318' } };
      }
    });
    // Barre de données sur la disponibilité (lecture visuelle immédiate)
    if (p.sites.length) {
      sites.addConditionalFormatting({
        ref: 'C6:C' + (5 + p.sites.length),
        rules: [{ type: 'dataBar', minLength: 0, maxLength: 100, cfvo: [{ type: 'num', value: 0 }, { type: 'num', value: 100 }], color: { argb: 'FF8FB8DE' } }]
      });
    }
    image(sites, images && images.availability, 0, endSites + 1.5, 520, 250);
    widths(sites, [26, 9, 14, 10, 11, 11, 11, 15, 10, 18, 13]);

    // ---- 3. Causes des pannes
    var causes = newSheet(names.causes, 'B42318', false);
    banner(causes, L.causes, 3, true);
    var sum = p.causes.reduce(function (s, c) { return s + c[1]; }, 0);
    var endCauses = table(causes, 5, [L.causes, L.count, L.share], p.causes.map(function (c) { return [c[0], c[1], c[2] / 100]; }), [null, '0', '0.0%'], {
      total: p.causes.length ? [L.total, sum, 1] : null
    });
    if (p.causes.length) {
      causes.addConditionalFormatting({
        ref: 'C6:C' + (5 + p.causes.length),
        rules: [{ type: 'dataBar', cfvo: [{ type: 'num', value: 0 }, { type: 'num', value: 1 }], color: { argb: 'FFE59A92' } }]
      });
    }
    image(causes, images && images.causes, 0, endCauses + 1.5, 460, 260);
    widths(causes, [34, 12, 12]);

    // ---- 4. Engins pénalisants
    var top = newSheet(names.top, 'B54708', true);
    banner(top, L.top, L.cols_top.length, true);
    var topSum = p.top.reduce(function (acc, r) { return [acc[0] + r[2], acc[1] + r[3], acc[2] + r[4]]; }, [0, 0, 0]);
    table(top, 5, L.cols_top, p.top, [null, null, '0', '0.0', money], {
      textCols: [2],
      total: p.top.length ? [L.total, '', topSum[0], topSum[1], topSum[2]] : null
    });
    if (p.top.length) {
      top.addConditionalFormatting({
        ref: 'D6:D' + (5 + p.top.length),
        rules: [{ type: 'dataBar', cfvo: [{ type: 'min' }, { type: 'max' }], color: { argb: 'FFF2B77E' } }]
      });
    }
    widths(top, [40, 18, 12, 16, 18]);

    // ---- 5. Conducteurs
    var ops = newSheet(names.operators, '5B3FA6', false);
    banner(ops, L.operators, L.cols_ops.length, true);
    table(ops, 5, L.cols_ops, p.operators, [null, null, '0', '0'], {
      textCols: [2],
      style: function (row, values) {
        if (values[3] > 0) row.getCell(4).font = { size: 9, bold: true, color: { argb: 'FFB42318' } };
      }
    });
    widths(ops, [30, 18, 12, 22]);

    return wb;
  }

  /** PDF exécutif A4 paysage : bandeau, tuiles d'indicateurs, tableaux, graphiques. */
  function reportDocDefinition(p, images, locale) {
    var L = p.labels;
    var n = function (v, d) { return v === null || v === undefined ? '—' : Core.formatNumber(v, locale, d); };
    var tableLayout = {
      hLineColor: function () { return '#DDE1E6'; },
      vLineWidth: function () { return 0; },
      hLineWidth: function (i) { return i === 1 ? 1.2 : 0.5; },
      fillColor: function (i) { return i > 0 && i % 2 === 0 ? '#F7F8FA' : null; },
      paddingTop: function () { return 4; },
      paddingBottom: function () { return 4; }
    };
    function th(list, alignFirstLeft) {
      return list.map(function (h, i) { return { text: h, style: 'th', alignment: i === 0 && alignFirstLeft !== false ? 'left' : 'right' }; });
    }
    function section(text) {
      return { text: text, fontSize: 11, bold: true, color: ACCENT, margin: [0, 14, 0, 6] };
    }

    var kpiCells = p.kpis.map(function (k) {
      return {
        stack: [
          { text: k.label.toUpperCase(), fontSize: 7, bold: true, color: '#5B6573' },
          { text: kpiText(k, locale), fontSize: 17, bold: true, color: '#' + (LEVEL_HEX[k.level] || '1B2430'), margin: [0, 3, 0, 2] },
          { text: k.hint || ' ', fontSize: 7, color: '#5B6573' }
        ],
        fillColor: '#F1F5F9', margin: [8, 7, 8, 7]
      };
    });

    var siteBody = [th(L.cols_site)];
    p.sites.forEach(function (s) {
      var lvl = availabilityLevel(s[2]);
      siteBody.push([
        { text: s[0], bold: true }, { text: n(s[1], 0), alignment: 'right' },
        { text: s[2] === null ? '—' : n(s[2], 1) + ' %', alignment: 'right', bold: true, color: lvl ? '#' + LEVEL_HEX[lvl] : null },
        { text: n(s[3], 0), alignment: 'right' }, { text: n(s[4], 0), alignment: 'right', color: s[4] > 0 ? '#B42318' : null, bold: s[4] > 0 },
        { text: n(s[5], 0), alignment: 'right' }, { text: n(s[6], 0), alignment: 'right' }, { text: n(s[7], 1), alignment: 'right' },
        { text: n(s[8], 1), alignment: 'right' }, { text: Core.formatMoney(s[9], locale), alignment: 'right' }, { text: n(s[10], 0), alignment: 'right' }
      ]);
    });
    var t = p.totals;
    siteBody.push([L.total, n(t[1], 0), t[0] === null ? '—' : n(t[0], 1) + ' %', n(t[2], 0), n(t[3], 0), n(t[4], 0), n(t[5], 0), n(t[6], 1), '', Core.formatMoney(t[7], locale), n(t[8], 0)]
      .map(function (v, i) { return { text: v, bold: true, alignment: i === 0 ? 'left' : 'right', fillColor: '#E8EEF4' }; }));

    var causeRows = [th([L.causes, L.count, L.share])].concat(p.causes.map(function (c) {
      return [c[0], { text: n(c[1], 0), alignment: 'right' }, { text: n(c[2], 1) + ' %', alignment: 'right' }];
    }));
    var topRows = [th(L.cols_top)].concat(p.top.map(function (r) {
      return [{ text: r[0], bold: true }, r[1], { text: n(r[2], 0), alignment: 'right' }, { text: n(r[3], 1), alignment: 'right' }, { text: Core.formatMoney(r[4], locale), alignment: 'right' }];
    }));
    var opRows = [th(L.cols_ops)].concat(p.operators.map(function (r) {
      return [{ text: r[0], bold: true }, r[1], { text: n(r[2], 0), alignment: 'right' }, { text: n(r[3], 0), alignment: 'right', color: r[3] > 0 ? '#B42318' : null, bold: r[3] > 0 }];
    }));
    function block(title, rows, widths) {
      return rows.length > 1
        ? [section(title), { table: { headerRows: 1, widths: widths, body: rows }, layout: tableLayout }]
        : [section(title), { text: '—', color: '#5B6573' }];
    }

    var charts = [];
    if (images && images.causes) charts.push({ stack: [section(L.causes), { image: images.causes, fit: [360, 170] }], unbreakable: true });
    if (images && images.availability) charts.push({ stack: [section(L.availability_chart), { image: images.availability, fit: [360, 170] }], unbreakable: true });

    return {
      pageOrientation: 'landscape',
      pageSize: 'A4',
      pageMargins: [28, 28, 28, 36],
      defaultStyle: { fontSize: 8 },
      styles: pdfStyles(),
      footer: function (current, count) {
        return { columns: [
          { text: 'PerfectMaint · ' + L.confidential, fontSize: 7, color: '#8D9098', margin: [28, 12, 0, 0] },
          { text: current + ' / ' + count, alignment: 'right', fontSize: 7, color: '#5B6573', margin: [0, 12, 28, 0] }
        ] };
      },
      content: [
        {
          table: { widths: ['*'], body: [[{ stack: [
            { text: L.title, fontSize: 18, bold: true, color: '#FFFFFF' },
            { text: L.subtitle, fontSize: 8.5, color: '#D6E2EE', margin: [0, 3, 0, 0] }
          ], fillColor: ACCENT, margin: [12, 10, 12, 10] }]] },
          layout: 'noBorders'
        },
        section(L.kpis),
        { table: { widths: p.kpis.map(function () { return '*'; }), body: [kpiCells] }, layout: { hLineWidth: function () { return 0; }, vLineWidth: function () { return 4; }, vLineColor: function () { return '#FFFFFF'; } } },
        section(L.by_site),
        { table: { headerRows: 1, widths: ['*', 32, 56, 44, 38, 44, 44, 58, 40, 74, 50], body: siteBody }, layout: tableLayout },
        charts.length ? { columns: charts, columnGap: 20 } : { text: '' },
        { columns: [
          { width: '*', stack: block(images && images.causes ? L.causes + ' — ' + L.count : L.causes, causeRows, ['*', 40, 44]) },
          { width: '*', stack: block(L.operators, opRows, ['*', 70, 44, 60]) }
        ], columnGap: 20 },
        { stack: block(L.top, topRows, ['*', 90, 50, 70, 80]), unbreakable: true }
      ]
    };
  }

  function exportReportExcel(ExcelJS, payload, images, locale) {
    return buildReportWorkbook(ExcelJS, payload, images, locale).xlsx.writeBuffer().then(function (buffer) {
      saveBlob(new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }),
        Core.exportFileName(payload.labels.title, 'xlsx'));
    });
  }

  function exportReportPdf(pdfMake, payload, images, locale) {
    downloadPdf(pdfMake, reportDocDefinition(payload, images, locale), Core.exportFileName(payload.labels.title, 'pdf'));
  }

  /**
   * La police Roboto embarquée par pdfmake ne contient pas les espaces fines (U+202F séparateur de milliers
   * français, U+2009) : elles s'affichent en caractères parasites. On les remplace par l'espace insécable U+00A0
   * dans tout le document (textes, cellules, pieds de page générés).
   */
  var PDF_UNSUPPORTED_SPACES = /[\u202F\u2009\u2007]/g;
  function pdfSafe(node) {
    if (typeof node === 'string') return node.replace(PDF_UNSUPPORTED_SPACES, '\u00A0');
    if (typeof node === 'function') {
      return function () { return pdfSafe(node.apply(this, arguments)); };
    }
    if (Array.isArray(node)) return node.map(pdfSafe);
    if (node && typeof node === 'object') {
      var out = {};
      Object.keys(node).forEach(function (k) {
        // Images (data URL) et couleurs : inchangées
        out[k] = k === 'image' ? node[k] : pdfSafe(node[k]);
      });
      return out;
    }
    return node;
  }

  /** Génère et télécharge un PDF après normalisation des caractères non pris en charge par la police. */
  function downloadPdf(pdfMake, doc, filename) {
    pdfMake.createPdf(pdfSafe(doc)).download(filename);
  }

  /** PDF généré en Blob (aperçu dans une modale avant téléchargement ou impression). */
  function pdfBlob(pdfMake, doc) {
    return new Promise(function (resolve) { pdfMake.createPdf(pdfSafe(doc)).getBlob(resolve); });
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

  function exportPlanningExcel(ExcelJS, payload) {
    return buildPlanningWorkbook(ExcelJS, payload, planningMatrix(payload)).xlsx.writeBuffer().then(function (buffer) {
      saveBlob(new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }),
        Core.exportFileName(payload.labels.title, 'xlsx'));
    });
  }

  function exportPlanningPdf(pdfMake, payload) {
    downloadPdf(pdfMake, planningDocDefinition(payload, planningMatrix(payload)), Core.exportFileName(payload.labels.title, 'pdf'));
  }

  function exportPdf(pdfMake, title, data, meta) {
    downloadPdf(pdfMake, listDocDefinition(title, data, meta), Core.exportFileName(title, 'pdf'));
  }

  return {
    tableData: tableData,
    buildWorkbook: buildWorkbook,
    listDocDefinition: listDocDefinition,
    workOrderDocDefinition: workOrderDocDefinition,
    exportExcel: exportExcel,
    exportPdf: exportPdf,
    pdfSafe: pdfSafe,
    downloadPdf: downloadPdf,
    pdfBlob: pdfBlob,
    planningMatrix: planningMatrix,
    buildPlanningWorkbook: buildPlanningWorkbook,
    planningDocDefinition: planningDocDefinition,
    exportPlanningExcel: exportPlanningExcel,
    exportPlanningPdf: exportPlanningPdf,
    buildReportWorkbook: buildReportWorkbook,
    reportDocDefinition: reportDocDefinition,
    exportReportExcel: exportReportExcel,
    exportReportPdf: exportReportPdf
  };
});
