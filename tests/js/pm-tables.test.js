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
  documents: 'EquipmentDocumentRepository',
  work_orders: 'WorkOrderRepository',
  preventive: 'PreventivePlanRepository',
  parts: 'PartRepository',
  procurement: 'ProcurementRepository',
  personnel: 'PersonnelRepository',
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
    documents: { code: evil, equipment_name: evil, plate_number: evil, site: evil, type_label: evil, issuer: evil, reference: evil, state: 'expired', state_label: evil, id: 1, file_url: evil, renew_url: evil },
    work_orders: { reference: evil, title: evil, equipment_code: evil, equipment_name: evil, site: evil, type: 'corrective', priority: 'high', status: 'open', assignee: evil, immobilizing: 1, overdue: 1, due_date: '2026-01-01' },
    preventive: { title: evil, equipment_code: evil, equipment_name: evil, site: evil, is_active: 0 },
    parts: { reference: evil, name: evil, unit: evil, quantity: 1, sites_below_min: 1, manufacturer: evil, brands: evil, part_type: 'tyre', is_wear_part: 1 },
    procurement: { reference: evil, name: evil, manufacturer: evil, unit: evil, site: evil, part_type: 'lubricant', quantity: 1, min_quantity: 2, monthly: 3, coverage_days: 4, level: 'low', suggested: 5, order_value: 6 },
    personnel: { first_name: evil, last_name: evil, employee_no: evil, job: 'driver', site: evil, team: evil, shift: 'night', phone: evil, equipment: evil, is_active: 1 },
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

test('journal : libellés de champs et valeurs traduits selon le type d’élément', () => {
  const wo = { entity_type: 'work_order', before_data: '{"status":"open","priority":"normal"}', after_data: '{"status":"in_progress","priority":"high","foo":1}' };
  assert.deepEqual(Tables.activityDiff(wo, t), [
    { label: 'Statut', before: fr['wo_status.open'], after: fr['wo_status.in_progress'] },
    { label: 'Priorité', before: fr['priority.normal'], after: fr['priority.high'] },
    { label: 'foo', before: '∅', after: '1' }
  ]);
  const eq = { entity_type: 'equipment', before_data: '{"status":"available"}', after_data: '{"status":"breakdown"}' };
  assert.equal(Tables.activityDiff(eq, t)[0].after, fr['status.breakdown']);
  // Création : un seul côté, valeurs vides ignorées
  assert.deepEqual(Tables.activityDiff({ entity_type: 'part', before_data: null, after_data: '{"quantity":4,"comment":null}' }, t), [
    { label: 'Quantité', value: '4' }
  ]);
  assert.deepEqual(Tables.activityDiff({ entity_type: 'user', before_data: null, after_data: null }, t), []);
});

test('journal : auteur et famille d’action', () => {
  assert.equal(Tables.actorName({ user_id: 3, user_name: 'Marc R.' }, t), 'Marc R.');
  assert.equal(Tables.actorName({ user_id: null, user_name: 'system' }, t), fr['js.log_system']);
  assert.equal(Tables.actorName({ user_id: null, user_name: null }, t), fr['js.log_anonymous']);
  assert.equal(Tables.actionTone('login_failed'), 'alert');
  assert.equal(Tables.actionTone('create'), 'add');
  assert.equal(Tables.actionTone('transition'), 'change');
});

test('diff du journal', () => {
  assert.deepEqual(Tables.diffEntries('{"status":"available"}', '{"status":"breakdown","reason":"Fuite"}'), [
    { key: 'status', before: 'available', after: 'breakdown' },
    { key: 'reason', before: '∅', after: 'Fuite' }
  ]);
  assert.deepEqual(Tables.diffEntries(null, '{"a":1}'), []);
  assert.deepEqual(Tables.diffEntries('not json', '{"a":1}'), []);
});
