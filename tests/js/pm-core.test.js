'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const Core = require('../../public/assets/js/pm-core.js');

test('escapeHtml neutralise les caractères HTML', () => {
  assert.equal(Core.escapeHtml('<img src=x onerror="alert(\'1\')">&'), '&lt;img src=x onerror=&quot;alert(&#039;1&#039;)&quot;&gt;&amp;');
  assert.equal(Core.escapeHtml(null), '');
  assert.equal(Core.escapeHtml(0), '0');
});

test('traducteur : paramètres, repli sur la clé', () => {
  const t = Core.createTranslator({ 'a.b': ':count ligne(s) le :date', 'x.y': 'OK' });
  assert.equal(t('a.b', { count: 3, date: '24/09/2026' }), '3 ligne(s) le 24/09/2026');
  assert.equal(t('x.y'), 'OK');
  assert.equal(t('missing.key'), 'missing.key');
});

test('formatDate sans conversion de fuseau', () => {
  assert.equal(Core.formatDate('2026-09-24', 'fr'), '24/09/2026');
  assert.equal(Core.formatDate('2026-09-24 07:05:00', 'fr', true), '24/09/2026 07:05');
  assert.equal(Core.formatDate('2026-09-24 07:05:00', 'en', true), '2026-09-24 07:05');
  assert.equal(Core.formatDate('2026-09-24 07:05:00', 'fr', false), '24/09/2026');
  assert.equal(Core.formatDate(null, 'fr'), '');
  assert.equal(Core.formatDate('n/a', 'fr'), '');
});

test('formatNumber : séparateurs français et anglais', () => {
  assert.equal(Core.formatNumber(1234567.5, 'fr', 2), '1 234 567,50');
  assert.equal(Core.formatNumber(1234.5, 'en', 1), '1,234.5');
  assert.equal(Core.formatNumber('12.00', 'fr'), '12');
  assert.equal(Core.formatNumber(-3.25, 'fr'), '-3,25');
  assert.equal(Core.formatNumber('', 'fr'), '');
  assert.equal(Core.formatNumber('abc', 'fr'), '');
});

test('badge : classes de la charte et libellé échappé', () => {
  const t = (k) => (k === 'status.breakdown' ? 'En <panne>' : k);
  assert.equal(Core.badge('status', 'breakdown', t), '<span class="badge badge--breakdown">En &lt;panne&gt;</span>');
  assert.match(Core.badge('wo_status', 'in_progress', t), /badge--maintenance/);
  assert.match(Core.badge('wo_status', 'cancelled', t), /badge--neutral/);
  assert.match(Core.badge('priority', 'critical', t), /badge--critical/);
  assert.match(Core.badge('priority', 'low', t), /badge--neutral/);
  assert.equal(Core.badge('status', '', t), '');
});

test('couleurs d’état imposées', () => {
  assert.deepEqual(Core.STATUS_COLORS, {
    available: '#1E7A4C', breakdown: '#B42318', maintenance: '#1F4E79', waiting_part: '#B54708', immobilized: '#8D9098'
  });
  assert.deepEqual(Core.STATUS_ORDER, Object.keys(Core.STATUS_COLORS));
});

test('fillUrl encode les valeurs', () => {
  assert.equal(Core.fillUrl('/equipment/{id}', { id: 12 }), '/equipment/12');
  assert.equal(Core.fillUrl('/x/{id}/edit', { id: '1/../2?a' }), '/x/1%2F..%2F2%3Fa/edit');
  assert.equal(Core.fillUrl('/x/{missing}', {}), '/x/');
});

test('filtersFromEntries ignore les valeurs vides', () => {
  assert.deepEqual(Core.filtersFromEntries([['site_id', ''], ['status', 'breakdown'], ['mine', '1']]), { status: 'breakdown', mine: '1' });
});

test('exportFileName : nom sûr et daté', () => {
  const d = new Date(2026, 8, 4);
  assert.equal(Core.exportFileName('Ordres de travail', 'xlsx', d), 'perfectmaint-ordres-de-travail-20260904.xlsx');
  assert.equal(Core.exportFileName('../../etc/passwd', 'pdf', d), 'perfectmaint-etc-passwd-20260904.pdf');
  assert.equal(Core.exportFileName('', 'pdf', d), 'perfectmaint-export-20260904.pdf');
});
