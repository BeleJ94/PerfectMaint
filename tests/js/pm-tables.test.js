'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const Core = require('../../public/assets/js/pm-core.js');
const Tables = require('../../public/assets/js/pm-tables.js');
const { loadCatalogue, sortableKeys } = require('./helpers.js');

const fr = loadCatalogue('fr');
const t = Core.createTranslator(fr);
const defs = Tables.definitions(t, 'fr');

const REPOSITORIES = {
  equipment: 'EquipmentRepository',
  work_orders: 'WorkOrderRepository',
  preventive: 'PreventivePlanRepository',
  parts: 'PartRepository',
  sites: 'SiteRepository',
  users: 'UserRepository',
  activity: 'ActivityLogRepository'
};

test('chaque tableau a un repository et chaque colonne triable est dans sa liste blanche PHP', () => {
  assert.deepEqual(Object.keys(defs).sort(), Object.keys(REPOSITORIES).sort());
  for (const [name, def] of Object.entries(defs)) {
    const allowed = sortableKeys(REPOSITORIES[name]);
    assert.ok(allowed.length > 0, name);
    for (const col of def.columns) {
      if (col.orderable !== false) {
        assert.ok(allowed.includes(col.data), `${name}.${col.data} triable côté JS mais absent de ${REPOSITORIES[name]}::SORTABLE`);
      }
    }
    const [index] = def.order[0];
    assert.notEqual(def.columns[index].orderable, false, `${name} : tri initial sur une colonne triable`);
  }
});

test('tous les titres de colonnes sont traduits', () => {
  for (const [name, def] of Object.entries(defs)) {
    for (const col of def.columns) {
      assert.ok(col.title && !/^[a-z_]+\.[a-z0-9_.]+$/.test(col.title), `${name}.${col.data} : "${col.title}"`);
    }
  }
});

test('le rendu HTML échappe les données utilisateur', () => {
  const evil = '<script>alert(1)</script>';
  const rows = {
    equipment: { code: evil, name: evil, brand: evil, status: 'breakdown', site: evil, category: evil },
    work_orders: { reference: evil, title: evil, equipment_code: evil, equipment_name: evil, site: evil, type: 'corrective', priority: 'high', status: 'open', assignee: evil, immobilizing: 1, overdue: 1, due_date: '2026-01-01' },
    preventive: { title: evil, equipment_code: evil, equipment_name: evil, site: evil, is_active: 0 },
    parts: { reference: evil, name: evil, unit: evil, quantity: 1, sites_below_min: 1 },
    sites: { code: evil, name: evil, city: evil },
    users: { name: evil, email: evil, role: 'technician', sites: evil },
    activity: { user_name: evil, site: evil, entity_type: 'equipment', action: 'update', summary: evil,
      before_data: JSON.stringify({ name: evil }), after_data: JSON.stringify({ name: 'ok' }) }
  };
  for (const [name, def] of Object.entries(defs)) {
    for (const col of def.columns) {
      const html = Tables.cellHtml(col, rows[name]);
      assert.ok(!html.includes('<script>'), `${name}.${col.data} n'échappe pas : ${html}`);
    }
  }
});

test('textes d’export lisibles (libellés traduits, dates françaises)', () => {
  const wo = defs.work_orders.columns;
  const row = { reference: 'OT-2026-00001', title: 'Fuite', equipment_code: 'CH-01', equipment_name: 'Chariot', status: 'waiting_part', priority: 'critical', type: 'preventive', reported_at: '2026-09-24 08:00:00', due_date: null };
  const text = Object.fromEntries(wo.map((c) => [c.data, Tables.cellText(c, row)]));
  assert.equal(text.status, 'Attente pièce');
  assert.equal(text.priority, 'Critique');
  assert.equal(text.type, 'Préventif');
  assert.equal(text.equipment, 'CH-01 – Chariot');
  assert.equal(text.reported_at, '24/09/2026');
  assert.equal(text.due_date, '');
  assert.equal(text.assignee, '');
});

test('diff du journal', () => {
  assert.deepEqual(Tables.diffEntries('{"status":"available"}', '{"status":"breakdown","reason":"Fuite"}'), [
    { key: 'status', before: 'available', after: 'breakdown' },
    { key: 'reason', before: '∅', after: 'Fuite' }
  ]);
  assert.deepEqual(Tables.diffEntries(null, '{"a":1}'), []);
  assert.deepEqual(Tables.diffEntries('not json', '{"a":1}'), []);
});
