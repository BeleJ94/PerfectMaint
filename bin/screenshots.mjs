/*
 * Captures d'écran du guide utilisateur (Playwright, lecture seule : aucun formulaire n'est soumis).
 *
 *   npm run screenshots                          # FR + EN, vers docs/guide/images/{fr,en}/
 *   npm run screenshots -- --lang=fr --only=20,21 # sous-ensemble (préfixes d'identifiant)
 *
 * Variables : PM_URL (défaut http://localhost/perfectMaint), PM_PASSWORD (défaut PerfectMaint2026!).
 * Prérequis : base peuplée (composer seed && composer seed:mining), application servie par Apache.
 */
import { chromium } from 'playwright';
import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const BASE = (process.env.PM_URL || 'http://localhost/perfectMaint').replace(/\/$/, '');
const PASSWORD = process.env.PM_PASSWORD || 'PerfectMaint2026!';
const arg = (name) => (process.argv.find((a) => a.startsWith(`--${name}=`)) || '').split('=')[1];
const LANGS = arg('lang') ? arg('lang').split(',') : ['fr', 'en'];
const ONLY = arg('only') ? arg('only').split(',') : null;

const ACCOUNTS = {
  admin: process.env.PM_ADMIN || 'admin@perfect-logistics.test',
  manager: 'marc.responsable@perfect-logistics.test',
  technician: 'sofia.tech@perfect-logistics.test',
  viewer: 'lea.lecture@perfect-logistics.test',
};
const DESKTOP = { width: 1440, height: 900 };
const MOBILE = { width: 390, height: 844 };

/** Chemin applicatif (« /equipment ») ou lien produit par l'application (« /perfectMaint/equipment/3 ») → URL absolue. */
const BASE_PATH = new URL(BASE).pathname.replace(/\/$/, '');
const toUrl = (target) => {
  if (/^https?:/.test(target)) return target;
  if (BASE_PATH && target.startsWith(BASE_PATH + '/')) return new URL(target, BASE).href;
  return BASE + target;
};

/* Helpers d'action (page déjà chargée) */
const firstRowHref = async (page, listPath) => {
  await page.goto(toUrl(listPath));
  const row = page.locator('tr.row-link').first();
  await row.waitFor({ timeout: 15000 });
  return row.getAttribute('data-href');
};
const openDialog = (selector) => async (page) => {
  const trigger = page.locator(`[data-open="${selector}"]`).first();
  if (!(await trigger.isVisible())) {
    // Action rangée dans un menu « … » : l'ouvrir d'abord
    await trigger.locator('xpath=ancestor::*[@data-menu][1]').locator('[data-menu-toggle]').click();
  }
  await trigger.click();
  await page.locator('.swal2-popup').waitFor();
  await page.waitForTimeout(400);
};
const openDrill = async (page) => {
  await page.locator('[data-drill]').first().click();
  await page.locator('.drill tbody tr').first().waitFor({ timeout: 15000 });
  await page.waitForTimeout(400);
};
const highlight = (selector) => async (page) => {
  await page.addStyleTag({ content: `${selector}{outline:3px solid #E0662B !important;outline-offset:3px;border-radius:6px}` });
};

/*
 * Liste des captures (identifiants repris dans docs/guide/PLAN.md).
 * path : chemin fixe, ou fonction (page) => chemin (découverte d'un identifiant réel).
 */
const SHOTS = [
  { id: '01-login', role: null, path: '/login' },
  { id: '02-dashboard', role: 'manager', path: '/' },
  { id: '03-dashboard-drill', role: 'manager', path: '/', action: openDrill },
  { id: '04-list-filters', role: 'manager', path: '/work-orders', action: highlight('.filters') },

  { id: '10-field-queue', role: 'technician', path: '/terrain', mobile: true },
  { id: '11-field-declare', role: 'technician', path: '/terrain/declarer', mobile: true },
  { id: '12-field-wo', role: 'technician', mobile: true, path: async (page) => {
    await page.goto(BASE + '/terrain');
    const link = page.locator('a[href*="/terrain/ot/"]').first();
    await link.waitFor({ timeout: 10000 });
    return link.getAttribute('href');
  } },

  { id: '20-equipment-list', role: 'manager', path: '/equipment' },
  { id: '21-equipment-show', role: 'manager', path: (p) => firstRowHref(p, '/equipment'), fullPage: true },
  { id: '22-equipment-reading', role: 'manager', path: (p) => firstRowHref(p, '/equipment'), action: openDialog('#reading-dialog') },
  { id: '23-equipment-status', role: 'manager', path: (p) => firstRowHref(p, '/equipment'), action: openDialog('#status-dialog') },
  { id: '24-equipment-assign', role: 'manager', path: (p) => firstRowHref(p, '/equipment'), action: openDialog('#assign-dialog') },
  { id: '25-equipment-form', role: 'manager', path: '/equipment/create', fullPage: true },

  { id: '30-wo-list', role: 'manager', path: '/work-orders' },
  { id: '31-wo-create', role: 'manager', path: '/work-orders/create', fullPage: true },
  { id: '32-wo-show', role: 'manager', path: (p) => firstRowHref(p, '/work-orders'), fullPage: true },
  { id: '33-wo-done', role: 'manager', path: (p) => firstRowHref(p, '/work-orders'), action: openDialog('#done-dialog') },

  { id: '40-preventive-list', role: 'manager', path: '/preventive' },
  { id: '41-preventive-form', role: 'manager', path: '/preventive/create', fullPage: true },

  { id: '50-parts-list', role: 'manager', path: '/parts' },
  { id: '51-parts-show', role: 'manager', path: (p) => firstRowHref(p, '/parts'), fullPage: true },
  { id: '52-procurement', role: 'manager', path: '/procurement' },

  { id: '60-alerts', role: 'manager', path: '/alerts', fullPage: true },
  { id: '61-planning', role: 'manager', path: '/planning?view=week' },
  { id: '62-site-overview', role: 'manager', fullPage: true, path: async (page) => {
    await page.goto(BASE + '/');
    const id = await page.locator('#site-switch option[value]:not([value=""])').first().getAttribute('value');
    return `/sites/${id}`;
  } },

  { id: '70-teams', role: 'manager', path: '/teams' },
  { id: '71-personnel', role: 'manager', path: '/personnel' },
  { id: '72-catalog', role: 'admin', path: '/catalog' },

  { id: '80-reports', role: 'manager', path: '/reports', fullPage: true },
  { id: '81-reports-drill', role: 'manager', path: '/reports', action: openDrill },
  { id: '82-viewer-dashboard', role: 'viewer', path: '/' },

  { id: '90-sites', role: 'admin', path: '/sites' },
  { id: '91-users', role: 'admin', path: '/users' },
  { id: '92-user-form', role: 'admin', path: (p) => firstRowHref(p, '/users'), fullPage: true },
  { id: '93-activity', role: 'admin', path: '/activity' },
];

async function login(context, role, lang) {
  const page = await context.newPage();
  await page.goto(BASE + '/login');
  await page.fill('#email', ACCOUNTS[role]);
  await page.fill('#password', PASSWORD);
  await Promise.all([page.waitForURL((u) => !u.pathname.endsWith('/login')), page.click('form[action$="/login"] button[type=submit]')]);
  await setLocale(page, lang);
  return page;
}

/** Change la langue via le sélecteur de l'application (préférence enregistrée sur le compte). */
async function setLocale(page, lang) {
  await page.evaluate(async ({ base, lang }) => {
    const token = document.querySelector('meta[name="csrf-token"]')?.content || document.querySelector('input[name="_csrf"]')?.value || '';
    await fetch(base + '/locale', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': token },
      body: new URLSearchParams({ locale: lang, _csrf: token }),
    });
  }, { base: BASE, lang });
}

/** Attend la fin des chargements (DataTables, graphiques, icônes) et fige les animations. */
async function settle(page) {
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.addStyleTag({ content: '*,*::before,*::after{animation:none !important;transition:none !important;caret-color:transparent !important}' });
  await page.locator('.dt-processing:visible').waitFor({ state: 'hidden', timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(700);
}

async function run() {
  const browser = await chromium.launch();
  const failures = [];
  let count = 0;
  const shots = SHOTS.filter((s) => !ONLY || ONLY.some((p) => s.id.startsWith(p)));

  for (const lang of LANGS) {
    const outDir = path.join(ROOT, 'docs', 'guide', 'images', lang);
    await mkdir(outDir, { recursive: true });
    const byRole = new Map();
    for (const s of shots) {
      const key = `${s.role}|${s.mobile ? 'm' : 'd'}`;
      if (!byRole.has(key)) byRole.set(key, []);
      byRole.get(key).push(s);
    }

    for (const [key, group] of byRole) {
      const [role, device] = key.split('|');
      const context = await browser.newContext({
        viewport: device === 'm' ? MOBILE : DESKTOP,
        deviceScaleFactor: 2,
        locale: lang === 'en' ? 'en-GB' : 'fr-FR',
        timezoneId: 'Africa/Lubumbashi',
        isMobile: device === 'm',
        hasTouch: device === 'm',
      });
      try {
        let page;
        if (role === 'null') {
          page = await context.newPage();
          await page.goto(BASE + '/login');
          await setLocale(page, lang);
        } else {
          page = await login(context, role, lang);
        }
        for (const shot of group) {
          try {
            const target = typeof shot.path === 'function' ? await shot.path(page) : shot.path;
            await page.goto(toUrl(target));
            await settle(page);
            if (shot.action) await shot.action(page);
            await page.screenshot({ path: path.join(outDir, `${shot.id}.png`), fullPage: !!shot.fullPage && !shot.action });
            count++;
            console.log(`  ✓ ${lang}/${shot.id}`);
          } catch (e) {
            failures.push(`${lang}/${shot.id} : ${e.message.split('\n')[0]}`);
            console.log(`  ✗ ${lang}/${shot.id}`);
          }
        }
        if (role !== 'null') await setLocale(page, 'fr'); // la langue par défaut des comptes de démonstration
      } finally {
        await context.close();
      }
    }
  }
  await browser.close();
  console.log(`\n${count} capture(s) dans docs/guide/images/`);
  if (failures.length) {
    console.log(`${failures.length} échec(s) :\n  ` + failures.join('\n  '));
    process.exitCode = 1;
  }
}

run().catch((e) => { console.error(e); process.exit(1); });
