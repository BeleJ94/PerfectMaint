'use strict';
// Lecture des catalogues PHP (tableaux 'clé' => 'texte') sans exécuter PHP.
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.resolve(__dirname, '../..');

function loadCatalogue(locale) {
  const source = fs.readFileSync(path.join(ROOT, 'lang', locale + '.php'), 'utf8');
  const out = {};
  const re = /'([a-z_]+\.[a-z0-9_.]+)'\s*=>\s*(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")/g;
  let m;
  while ((m = re.exec(source))) {
    out[m[1]] = (m[2] !== undefined ? m[2] : m[3]).replace(/\\'/g, "'");
  }
  return out;
}

/** Colonnes triables déclarées côté PHP (constante SORTABLE d'un repository). */
function sortableKeys(repository) {
  const source = fs.readFileSync(path.join(ROOT, 'app/Repositories', repository + '.php'), 'utf8');
  const block = /SORTABLE = \[([\s\S]*?)\];/.exec(source);
  if (!block) return [];
  return [...block[1].matchAll(/'([a-z_]+)'\s*=>/g)].map((m) => m[1]);
}

module.exports = { ROOT, loadCatalogue, sortableKeys };
