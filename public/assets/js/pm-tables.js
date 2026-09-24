/*
 * Définitions des colonnes des tableaux DataTables (server-side).
 * Chaque colonne : data (nom côté serveur, sert au tri), title, html(row) pour l'affichage,
 * text(row) pour les exports Excel/PDF. Module pur, testable sous Node.
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory(require('./pm-core.js'));
  } else {
    root.PMTables = factory(root.PMCore);
  }
})(typeof self !== 'undefined' ? self : this, function (Core) {
  'use strict';

  var esc = Core.escapeHtml;

  function definitions(t, locale) {
    var date = function (v) { return Core.formatDate(v, locale, false); };
    var dateTime = function (v) { return Core.formatDate(v, locale, true); };
    var num = function (v, d) { return Core.formatNumber(v, locale, d); };
    var label = function (group) {
      return function (row, key) { return row[key] ? t(group + '.' + row[key]) : ''; };
    };

    return {
      equipment: {
        order: [[0, 'asc']],
        columns: [
          { data: 'code', title: t('fields.code'), html: function (r) { return '<strong>' + esc(r.code) + '</strong>'; } },
          { data: 'name', title: t('fields.name'), html: function (r) {
            var sub = [r.brand, r.model].filter(Boolean).join(' ');
            return esc(r.name) + (sub ? '<br><span class="muted">' + esc(sub) + '</span>' : '');
          }, text: function (r) { return r.name; } },
          { data: 'category', title: t('fields.category_id') },
          { data: 'site', title: t('fields.site_id') },
          { data: 'status', title: t('fields.status'),
            html: function (r) { return Core.badge('status', r.status, t); },
            text: function (r) { return label('status')(r, 'status'); } },
          { data: 'hour_meter', title: t('fields.hour_meter'), className: 'dt-right',
            text: function (r) { return r.hour_meter === null ? '' : num(r.hour_meter, 0); } },
          { data: 'open_work_orders', title: t('js.open_wo'), orderable: false, className: 'dt-right' }
        ]
      },

      work_orders: {
        order: [[0, 'desc']],
        columns: [
          { data: 'reference', title: t('fields.reference'), html: function (r) { return '<strong class="nowrap">' + esc(r.reference) + '</strong>'; } },
          { data: 'title', title: t('fields.title'), html: function (r) {
            return esc(r.title) + (Number(r.immobilizing) ? ' <span class="badge badge--breakdown">' + esc(t('js.immobilizing')) + '</span>' : '');
          }, text: function (r) { return r.title; } },
          { data: 'equipment', title: t('fields.equipment_id'), html: function (r) {
            return '<span class="nowrap">' + esc(r.equipment_code) + '</span><br><span class="muted">' + esc(r.equipment_name) + '</span>';
          }, text: function (r) { return r.equipment_code + ' – ' + r.equipment_name; } },
          { data: 'site', title: t('fields.site_id') },
          { data: 'type', title: t('fields.type'), text: function (r) { return label('wo_type')(r, 'type'); } },
          { data: 'priority', title: t('fields.priority'),
            html: function (r) { return Core.badge('priority', r.priority, t); },
            text: function (r) { return label('priority')(r, 'priority'); } },
          { data: 'status', title: t('fields.status'),
            html: function (r) { return Core.badge('wo_status', r.status, t); },
            text: function (r) { return label('wo_status')(r, 'status'); } },
          { data: 'assignee', title: t('fields.assigned_to'), text: function (r) { return r.assignee || ''; } },
          { data: 'reported_at', title: t('fields.reported_at'), text: function (r) { return date(r.reported_at); } },
          { data: 'due_date', title: t('fields.due_date'),
            html: function (r) { return '<span class="' + (Number(r.overdue) ? 'overdue' : '') + '">' + esc(date(r.due_date)) + '</span>'; },
            text: function (r) { return date(r.due_date); } }
        ]
      },

      preventive: {
        order: [[3, 'asc']],
        columns: [
          { data: 'title', title: t('fields.title'), html: function (r) {
            return esc(r.title) + (Number(r.is_active) ? '' : ' <span class="badge badge--neutral">' + esc(t('js.inactive')) + '</span>');
          }, text: function (r) { return r.title; } },
          { data: 'equipment', title: t('fields.equipment_id'), text: function (r) { return r.equipment_code + ' – ' + r.equipment_name; } },
          { data: 'site', title: t('fields.site_id') },
          { data: 'next_due_date', title: t('js.next_due'), html: function (r) {
            var overdue = r.next_due_date && r.next_due_date < new Date().toISOString().slice(0, 10);
            return '<span class="' + (overdue ? 'overdue' : '') + '">' + esc(date(r.next_due_date)) + '</span>';
          }, text: function (r) { return date(r.next_due_date); } },
          { data: 'next_due_hours', title: t('js.next_due_hours'), className: 'dt-right', text: function (r) {
            if (r.next_due_hours === null) return '';
            return num(r.next_due_hours, 0) + ' h (' + num(r.hour_meter, 0) + ')';
          } },
          { data: 'interval', title: t('js.interval'), orderable: false, text: function (r) {
            var parts = [];
            if (r.interval_days) parts.push(t('js.every_days', { n: r.interval_days }));
            if (r.interval_hours) parts.push(t('js.every_hours', { n: r.interval_hours }));
            return parts.join(' / ');
          } },
          { data: 'last_done_at', title: t('fields.last_done_at'), text: function (r) { return date(r.last_done_at); } },
          { data: 'open_work_order_id', title: t('js.open_wo'), orderable: false, html: function (r) {
            return r.open_work_order_id ? '<span class="badge badge--maintenance">' + esc(t('js.planned')) + '</span>' : '';
          }, text: function (r) { return r.open_work_order_id ? t('js.planned') : ''; } }
        ]
      },

      parts: {
        order: [[0, 'asc']],
        columns: [
          { data: 'reference', title: t('fields.reference'), html: function (r) { return '<strong>' + esc(r.reference) + '</strong>'; } },
          { data: 'name', title: t('fields.name') },
          { data: 'quantity', title: t('fields.quantity'), className: 'dt-right', html: function (r) {
            var low = Number(r.sites_below_min) > 0;
            return '<span class="' + (low ? 'overdue' : '') + '">' + esc(num(r.quantity)) + ' ' + esc(r.unit) + '</span>'
              + (low ? ' <span class="badge badge--waiting_part">' + esc(t('js.below_min')) + '</span>' : '');
          }, text: function (r) { return num(r.quantity) + ' ' + r.unit; } },
          { data: 'min_quantity', title: t('fields.min_quantity'), orderable: false, className: 'dt-right', text: function (r) { return num(r.min_quantity); } },
          { data: 'unit_cost', title: t('fields.unit_cost'), className: 'dt-right', text: function (r) { return num(r.unit_cost, 2) + ' €'; } }
        ]
      },

      sites: {
        order: [[1, 'asc']],
        columns: [
          { data: 'code', title: t('fields.code'), html: function (r) { return '<strong>' + esc(r.code) + '</strong>'; } },
          { data: 'name', title: t('fields.name') },
          { data: 'city', title: t('fields.city') },
          { data: 'equipment_count', title: t('js.equipment_count'), orderable: false, className: 'dt-right' },
          { data: 'is_active', title: t('fields.is_active'), text: function (r) { return Number(r.is_active) ? t('js.yes') : t('js.no'); } }
        ]
      },

      users: {
        order: [[0, 'asc']],
        columns: [
          { data: 'name', title: t('fields.name') },
          { data: 'email', title: t('fields.email') },
          { data: 'role', title: t('fields.role'), text: function (r) { return t('role.' + r.role); } },
          { data: 'sites', title: t('fields.site_ids'), orderable: false, text: function (r) { return Number(r.all_sites) || r.role === 'admin' ? t('js.all_sites') : (r.sites || ''); } },
          { data: 'is_active', title: t('fields.is_active'), text: function (r) { return Number(r.is_active) ? t('js.yes') : t('js.no'); } },
          { data: 'last_login_at', title: t('js.last_login'), text: function (r) { return dateTime(r.last_login_at); } }
        ]
      },

      activity: {
        order: [[0, 'desc']],
        columns: [
          { data: 'created_at', title: t('js.date'), className: 'nowrap', text: function (r) { return dateTime(r.created_at); } },
          { data: 'user_name', title: t('js.user') },
          { data: 'site', title: t('fields.site_id'), orderable: false },
          { data: 'entity_type', title: t('fields.entity_type'), text: function (r) { return t('entity.' + r.entity_type) + (r.entity_id ? ' #' + r.entity_id : ''); } },
          { data: 'action', title: t('js.action'), text: function (r) { return t('action.' + r.action); } },
          { data: 'summary', title: t('js.summary'), orderable: false, html: function (r) {
            return esc(r.summary) + diffHtml(r.before_data, r.after_data);
          }, text: function (r) { return (r.summary || '') + diffText(r.before_data, r.after_data); } }
        ]
      }
    };
  }

  function parseJson(value) {
    if (!value) return null;
    try { return JSON.parse(value); } catch (e) { return null; }
  }

  function scalar(v) {
    if (v === null || v === undefined || v === '') return '∅';
    return typeof v === 'object' ? JSON.stringify(v) : String(v);
  }

  /** Liste « champ : avant → après » à partir des JSON du journal. */
  function diffEntries(beforeJson, afterJson) {
    var before = parseJson(beforeJson);
    var after = parseJson(afterJson);
    if (!after || !before) return [];
    return Object.keys(after).map(function (key) {
      return { key: key, before: scalar(before[key]), after: scalar(after[key]) };
    });
  }

  function diffHtml(beforeJson, afterJson) {
    var entries = diffEntries(beforeJson, afterJson);
    if (!entries.length) return '';
    return '<div class="diff">' + entries.map(function (e) {
      return '<div>' + esc(e.key) + ' : <del>' + esc(e.before) + '</del> → <ins>' + esc(e.after) + '</ins></div>';
    }).join('') + '</div>';
  }

  function diffText(beforeJson, afterJson) {
    var entries = diffEntries(beforeJson, afterJson);
    return entries.length ? ' [' + entries.map(function (e) { return e.key + ': ' + e.before + ' → ' + e.after; }).join('; ') + ']' : '';
  }

  /** Valeur texte d'une cellule (exports), à partir de la définition de colonne. */
  function cellText(column, row) {
    if (typeof column.text === 'function') return column.text(row);
    var v = row[column.data];
    return v === null || v === undefined ? '' : String(v);
  }

  /** Valeur HTML d'une cellule (affichage), toujours échappée. */
  function cellHtml(column, row) {
    if (typeof column.html === 'function') return column.html(row);
    return esc(cellText(column, row));
  }

  return {
    definitions: definitions,
    diffEntries: diffEntries,
    cellText: cellText,
    cellHtml: cellHtml
  };
});
