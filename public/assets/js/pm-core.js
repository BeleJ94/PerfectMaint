/*
 * PerfectMaint — fonctions utilitaires pures (navigateur + Node pour les tests).
 * Aucune dépendance au DOM ici.
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.PMCore = factory();
  }
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  /** Couleurs d'état imposées par la charte. */
  var STATUS_COLORS = {
    available: '#1E7A4C',
    breakdown: '#B42318',
    maintenance: '#1F4E79',
    waiting_part: '#B54708',
    immobilized: '#8D9098'
  };
  var STATUS_ORDER = ['available', 'breakdown', 'maintenance', 'waiting_part', 'immobilized'];

  /** Couleur de pastille d'un statut d'OT (reprend l'état d'engin correspondant). */
  var WO_STATUS_CLASS = {
    open: 'breakdown',
    in_progress: 'maintenance',
    waiting_part: 'waiting_part',
    done: 'available',
    cancelled: 'neutral'
  };

  function escapeHtml(value) {
    if (value === null || value === undefined) return '';
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  /** Traducteur sur un catalogue à plat ; paramètres :nom. Repli sur la clé. */
  function createTranslator(catalogue) {
    var dict = catalogue || {};
    return function t(key, params) {
      var text = Object.prototype.hasOwnProperty.call(dict, key) ? dict[key] : key;
      if (params) {
        Object.keys(params).forEach(function (name) {
          text = text.split(':' + name).join(String(params[name]));
        });
      }
      return text;
    };
  }

  function pad(n) {
    return n < 10 ? '0' + n : String(n);
  }

  /**
   * Formate une date SQL (« AAAA-MM-JJ » ou « AAAA-MM-JJ HH:MM:SS ») sans conversion de fuseau.
   */
  function formatDate(value, locale, withTime) {
    if (!value) return '';
    var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(String(value));
    if (!m) return '';
    var date = locale === 'en' ? m[1] + '-' + m[2] + '-' + m[3] : m[3] + '/' + m[2] + '/' + m[1];
    if (withTime && m[4] !== undefined) {
      date += ' ' + m[4] + ':' + m[5];
    }
    return date;
  }

  var currencySymbol = '$';

  /** Symbole monétaire (configuration APP_CURRENCY, transmis par la page). */
  function setCurrency(symbol) {
    if (symbol) currencySymbol = String(symbol);
  }

  function currency() {
    return currencySymbol;
  }

  function formatMoney(value, locale) {
    var out = formatNumber(value, locale, 2);
    return out === '' ? '' : out + ' ' + currencySymbol;
  }

  function formatNumber(value, locale, decimals) {
    if (value === null || value === undefined || value === '') return '';
    var num = Number(value);
    if (!isFinite(num)) return '';
    var d = decimals === undefined ? (Math.round(num) === num ? 0 : 2) : decimals;
    var fixed = Math.abs(num).toFixed(d);
    var parts = fixed.split('.');
    var sep = locale === 'en' ? ',' : ' ';
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, sep);
    var out = parts.join(locale === 'en' ? '.' : ',');
    return (num < 0 ? '-' : '') + out;
  }

  /** Pastille HTML (échappée) : kind = status | wo_status | priority. */
  function badge(kind, value, t) {
    if (!value) return '';
    var cls;
    if (kind === 'status') cls = value;
    else if (kind === 'wo_status') cls = WO_STATUS_CLASS[value] || 'neutral';
    else cls = value === 'critical' || value === 'high' ? value : 'neutral';
    return '<span class="badge badge--' + escapeHtml(cls) + '">' + escapeHtml(t(kind + '.' + value)) + '</span>';
  }

  /** Remplace {clé} dans un gabarit d'URL par les valeurs (encodées) de la ligne. */
  function fillUrl(template, row) {
    return String(template).replace(/\{(\w+)\}/g, function (_, key) {
      return encodeURIComponent(row[key] === undefined || row[key] === null ? '' : row[key]);
    });
  }

  /** Paramètres de filtres à partir de paires [nom, valeur] (FormData), valeurs vides ignorées. */
  function filtersFromEntries(entries) {
    var out = {};
    (entries || []).forEach(function (pair) {
      var name = pair[0];
      var value = pair[1];
      if (value === '' || value === null || value === undefined) return;
      out[name] = value;
    });
    return out;
  }

  /** Nom de fichier sûr et daté pour les exports. */
  function exportFileName(title, extension, now) {
    var d = now || new Date();
    var slug = String(title || 'export')
      .normalize('NFD').replace(/[̀-ͯ]/g, '')
      .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'export';
    return 'perfectmaint-' + slug + '-' + d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate()) + '.' + extension;
  }

  return {
    STATUS_COLORS: STATUS_COLORS,
    STATUS_ORDER: STATUS_ORDER,
    WO_STATUS_CLASS: WO_STATUS_CLASS,
    escapeHtml: escapeHtml,
    createTranslator: createTranslator,
    formatDate: formatDate,
    formatNumber: formatNumber,
    formatMoney: formatMoney,
    setCurrency: setCurrency,
    currency: currency,
    badge: badge,
    fillUrl: fillUrl,
    filtersFromEntries: filtersFromEntries,
    exportFileName: exportFileName
  };
});
