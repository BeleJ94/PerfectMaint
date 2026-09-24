'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const Core = require('../../public/assets/js/pm-core.js');
const Tables = require('../../public/assets/js/pm-tables.js');
const Exporter = require('../../public/assets/js/pm-export.js');
const { loadCatalogue } = require('./helpers.js');

const t = Core.createTranslator(loadCatalogue('fr'));
const columns = Tables.definitions(t, 'fr').equipment.columns;
const rows = [
  { code: 'CH-01', name: 'Chariot', status: 'available', site: 'Lyon', category: 'Frontal', hour_meter: 1520, open_work_orders: 0 },
  { code: 'TR-02', name: 'Transpalette', status: 'breakdown', site: 'Lille', category: null, hour_meter: null, open_work_orders: 2 }
];

test('tableData produit en-têtes et lignes texte', () => {
  const data = Exporter.tableData(columns, rows);
  assert.deepEqual(data.headers, ['Code', 'Nom', 'Catégorie', 'Site', 'Statut', 'Compteur horaire', 'OT ouverts']);
  assert.deepEqual(data.body[0], ['CH-01', 'Chariot', 'Frontal', 'Lyon', 'Disponible', '1 520', '0']);
  assert.deepEqual(data.body[1], ['TR-02', 'Transpalette', '', 'Lille', 'En panne', '', '2']);
});

test('document PDF de liste : paysage, en-tête répété, pagination', () => {
  const data = Exporter.tableData(columns, rows);
  const doc = Exporter.listDocDefinition('Engins', data, { subtitle: '2 lignes' });
  assert.equal(doc.pageOrientation, 'landscape');
  const table = doc.content[2].table;
  assert.equal(table.headerRows, 1);
  assert.equal(table.body.length, 3);
  assert.equal(table.body[0][0].text, 'Code');
  assert.equal(table.widths.length, 7);
  assert.equal(doc.footer(2, 5).text, '2 / 5');
  assert.equal(doc.styles.th.fillColor, '#1F4E79');
});

test('bon de travail PDF : informations et total des pièces', () => {
  const wo = {
    reference: 'OT-2026-00042', title: 'Fuite hydraulique', equipment_code: 'CH-01', equipment_name: 'Chariot', site: 'Lyon',
    type: 'corrective', priority: 'high', status: 'done', reporter: 'Sofia', reported_at: '2026-09-20 08:00:00',
    assignee: 'Sofia', due_date: '2026-09-21', completed_at: '2026-09-21 10:30:00', labor_minutes: 90,
    description: 'Fuite au vérin', resolution: 'Joint remplacé'
  };
  const parts = [
    { reference: 'JNT-1', name: 'Joint', quantity: '2.00', unit: 'u', unit_cost: '12.50' },
    { reference: 'HUI-1', name: 'Huile', quantity: '1.5', unit: 'L', unit_cost: '6.20' }
  ];
  const doc = Exporter.workOrderDocDefinition(wo, parts, t, 'fr');
  const flat = JSON.stringify(doc.content);
  assert.match(flat, /Ordre de travail OT-2026-00042/);
  assert.match(flat, /Correctif/);
  assert.match(flat, /Terminé/);
  assert.match(flat, /90 min/);
  assert.match(flat, /Joint remplacé/);
  assert.match(flat, /34,30 €/, 'Total = 2 × 12,50 + 1,5 × 6,20');

  const empty = Exporter.workOrderDocDefinition({ ...wo, labor_minutes: null, resolution: null }, [], t, 'fr');
  assert.match(JSON.stringify(empty.content), /"—"/);
});

test('classeur Excel : en-tête stylé, filtre automatique, largeurs bornées', () => {
  // Double minimal d'ExcelJS : on vérifie ce que le module configure.
  class Sheet {
    constructor() { this.rows = []; this.columnsList = []; }
    addRow(values) {
      const row = { values, font: null, fill: null, alignment: null };
      this.rows.push(row);
      values.forEach((_, i) => { this.columnsList[i] = this.columnsList[i] || { width: undefined }; });
      return row;
    }
    getRow(n) { return this.rows[n - 1]; }
    get columns() { return this.columnsList; }
  }
  class Workbook {
    constructor() { this.sheets = []; }
    addWorksheet(name, opts) { const s = new Sheet(); s.name = name; s.opts = opts; this.sheets.push(s); return s; }
  }
  const data = Exporter.tableData(columns, rows);
  data.body[0][1] = 'x'.repeat(200);
  const wb = Exporter.buildWorkbook({ Workbook }, 'Ordres de travail et engins du parc', data);
  const sheet = wb.sheets[0];

  assert.equal(sheet.name.length <= 31, true, 'Nom de feuille ≤ 31 caractères (limite Excel)');
  assert.equal(sheet.rows.length, 3);
  assert.equal(sheet.rows[0].font.bold, true);
  assert.equal(sheet.rows[0].fill.fgColor.argb, 'FF1F4E79');
  assert.deepEqual(sheet.autoFilter, { from: { row: 1, column: 1 }, to: { row: 1, column: 7 } });
  assert.equal(sheet.opts.views[0].ySplit, 1);
  assert.equal(sheet.columns[1].width, 60);
  assert.ok(sheet.columns[0].width >= 10);
});
