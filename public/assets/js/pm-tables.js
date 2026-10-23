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

    var defs = {
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

      // Documents de bord : une ligne par engin × type de document (libellés traduits fournis par le serveur)
      documents: {
        order: [[5, 'asc']],
        columns: [
          { data: 'code', title: t('fields.equipment_id'), html: function (r) {
            var sub = [r.equipment_name, r.plate_number].filter(Boolean).join(' · ');
            return '<strong>' + esc(r.code) + '</strong>' + (sub ? '<br><span class="muted">' + esc(sub) + '</span>' : '');
          }, text: function (r) { return r.code + ' – ' + r.equipment_name; } },
          { data: 'site', title: t('fields.site_id') },
          { data: 'type', title: t('fields.document_type_id'), text: function (r) { return r.type_label; } },
          { data: 'issuer', title: t('fields.issuer'), html: function (r) {
            return esc(r.issuer || '') + (r.reference ? '<br><span class="muted mono">' + esc(r.reference) + '</span>' : '');
          }, text: function (r) { return [r.issuer, r.reference].filter(Boolean).join(' – '); } },
          { data: 'expires_at', title: t('fields.expires_at'), text: function (r) { return date(r.expires_at); } },
          { data: 'state', title: t('fields.status'), html: function (r) {
            var cls = { expired: 'breakdown', soon: 'waiting_part', valid: 'available' }[r.state] || 'neutral';
            return '<span class="badge badge--' + cls + '">' + esc(r.state_label) + '</span>';
          }, text: function (r) { return r.state_label; } },
          { data: 'file_url', title: t('fields.document_file'), orderable: false, className: 'dt-right nowrap', html: function (r) {
            var out = '';
            if (r.file_url) {
              out += '<a class="icon-btn" href="' + esc(r.file_url) + '" target="_blank" rel="noopener" title="' + esc(t('js.open_file')) + '"><i data-lucide="paperclip"></i></a>';
            }
            if (r.renew_url) {
              out += ' <a class="btn btn--sm" href="' + esc(r.renew_url) + '"><i data-lucide="' + (r.id ? 'refresh-cw' : 'plus') + '"></i>'
                + esc(t(r.id ? 'js.renew' : 'js.add')) + '</a>';
            }
            return out;
          }, text: function (r) { return r.file_url ? '✓' : ''; } }
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
            var parts = [];
            if (r.next_due_hours !== null) parts.push(num(r.next_due_hours, 0) + ' h (' + num(r.hour_meter, 0) + ')');
            if (r.next_due_km !== null) parts.push(num(r.next_due_km, 0) + ' km (' + num(r.odometer_km, 0) + ')');
            return parts.join(' / ');
          } },
          { data: 'interval', title: t('js.interval'), orderable: false, text: function (r) {
            var parts = [];
            if (r.interval_days) parts.push(t('js.every_days', { n: r.interval_days }));
            if (r.interval_hours) parts.push(t('js.every_hours', { n: r.interval_hours }));
            if (r.interval_km) parts.push(t('js.every_km', { n: r.interval_km }));
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
          { data: 'name', title: t('fields.name'), html: function (r) {
            return esc(r.name) + (Number(r.is_wear_part) ? ' <span class="chip chip--warn">' + esc(t('js.wear')) + '</span>' : '')
              + (r.manufacturer ? '<br><span class="muted">' + esc(r.manufacturer) + '</span>' : '');
          }, text: function (r) { return r.name; } },
          { data: 'part_type', title: t('fields.part_type'), text: function (r) { return r.part_type ? t('part_type.' + r.part_type) : ''; } },
          { data: 'brands', title: t('js.compatible_brands'), orderable: false, text: function (r) { return r.brands || ''; } },
          { data: 'quantity', title: t('fields.quantity'), className: 'dt-right', html: function (r) {
            var low = Number(r.sites_below_min) > 0;
            return '<span class="' + (low ? 'overdue' : '') + '">' + esc(num(r.quantity)) + ' ' + esc(r.unit) + '</span>'
              + (low ? ' <span class="badge badge--waiting_part">' + esc(t('js.below_min')) + '</span>' : '');
          }, text: function (r) { return num(r.quantity) + ' ' + r.unit; } },
          { data: 'min_quantity', title: t('fields.min_quantity'), orderable: false, className: 'dt-right', text: function (r) { return num(r.min_quantity); } },
          { data: 'unit_cost', title: t('fields.unit_cost'), className: 'dt-right', text: function (r) { return Core.formatMoney(r.unit_cost, locale); } }
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

      procurement: {
        order: [[7, 'asc']],
        columns: [
          { data: 'reference', title: t('fields.reference'), html: function (r) { return '<strong>' + esc(r.reference) + '</strong>'; } },
          { data: 'name', title: t('fields.name'), html: function (r) {
            return esc(r.name) + (r.manufacturer ? '<br><span class="muted">' + esc(r.manufacturer) + '</span>' : '');
          }, text: function (r) { return r.name; } },
          { data: 'part_type', title: t('fields.part_type'), text: function (r) { return r.part_type ? t('part_type.' + r.part_type) : ''; } },
          { data: 'site', title: t('fields.site_id') },
          { data: 'quantity', title: t('fields.quantity'), className: 'dt-right', html: function (r) {
            return esc(num(r.quantity)) + ' ' + esc(r.unit) + '<br><span class="muted">' + esc(t('js.min_short')) + ' ' + esc(num(r.min_quantity)) + '</span>';
          }, text: function (r) { return num(r.quantity) + ' ' + r.unit; } },
          { data: 'monthly', title: t('js.monthly_use'), className: 'dt-right', text: function (r) { return Number(r.monthly) ? num(r.monthly) + ' ' + r.unit : '—'; } },
          { data: 'coverage_days', title: t('js.coverage'), className: 'dt-right', text: function (r) {
            return r.coverage_days === null || r.coverage_days === undefined ? '—' : t('js.days', { n: r.coverage_days });
          } },
          { data: 'level', title: t('js.level'), html: function (r) {
            var cls = { out: 'breakdown', low: 'waiting_part', watch: 'maintenance', ok: 'available' }[r.level] || 'neutral';
            return '<span class="badge badge--' + cls + '">' + esc(t('level.' + r.level)) + '</span>';
          }, text: function (r) { return t('level.' + r.level); } },
          { data: 'suggested', title: t('js.to_order'), className: 'dt-right', html: function (r) {
            return Number(r.suggested) ? '<strong>' + esc(num(r.suggested)) + ' ' + esc(r.unit) + '</strong>' : '<span class="muted">—</span>';
          }, text: function (r) { return Number(r.suggested) ? num(r.suggested) + ' ' + r.unit : ''; } },
          { data: 'order_value', title: t('js.order_value'), className: 'dt-right', text: function (r) { return Number(r.order_value) ? Core.formatMoney(r.order_value, locale) : ''; } }
        ]
      },

      personnel: {
        order: [[0, 'asc']],
        columns: [
          { data: 'name', title: t('fields.name'), html: function (r) {
            return '<strong>' + esc(r.last_name) + '</strong> ' + esc(r.first_name)
              + (Number(r.is_active) ? '' : ' <span class="badge badge--neutral">' + esc(t('js.inactive')) + '</span>');
          }, text: function (r) { return r.last_name + ' ' + r.first_name; } },
          { data: 'employee_no', title: t('fields.employee_no'), text: function (r) { return r.employee_no || ''; } },
          { data: 'job', title: t('fields.job'), text: function (r) { return r.job ? t('job.' + r.job) : ''; } },
          { data: 'site', title: t('fields.site_id') },
          { data: 'team', title: t('fields.team_id'), html: function (r) {
            return r.team ? esc(r.team) + ' <span class="muted">· ' + esc(t('shift.' + r.shift)) + '</span>' : '<span class="muted">—</span>';
          }, text: function (r) { return r.team ? r.team + ' (' + t('shift.' + r.shift) + ')' : ''; } },
          { data: 'phone', title: t('fields.phone'), orderable: false, html: function (r) {
            return r.phone ? '<a href="tel:' + esc(String(r.phone).replace(/[^\d+]/g, '')) + '" class="nowrap">' + esc(r.phone) + '</a>' : '';
          }, text: function (r) { return r.phone || ''; } },
          { data: 'equipment', title: t('js.assigned_equipment'), orderable: false, text: function (r) { return r.equipment || ''; } }
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

      // Répertoire général des index (administrateur) : un clic sur la ligne ouvre la modification
      readings: {
        order: [[0, 'desc']],
        columns: [
          { data: 'recorded_at', title: t('js.date'), className: 'nowrap', text: function (r) { return dateTime(r.recorded_at); } },
          { data: 'code', title: t('fields.equipment_id'), html: function (r) {
            return '<strong>' + esc(r.code) + '</strong><br><span class="muted">' + esc(r.equipment_name) + '</span>';
          }, text: function (r) { return r.code + ' – ' + r.equipment_name; } },
          { data: 'site', title: t('fields.site_id') },
          { data: 'hour_meter', title: t('fields.hour_meter'), className: 'dt-right',
            text: function (r) { return r.hour_meter === null ? '' : num(r.hour_meter, 0); } },
          { data: 'odometer_km', title: t('fields.odometer_km'), className: 'dt-right',
            text: function (r) { return r.odometer_km === null ? '' : num(r.odometer_km, 0); } },
          { data: 'fuel_level', title: t('fields.fuel_level'), className: 'dt-right', text: function (r) {
            if (r.fuel_level === null) return '';
            return r.fuel_liters !== null ? num(r.fuel_liters, 1) + ' L (' + r.fuel_level + ' %)' : r.fuel_level + ' %';
          } },
          { data: 'recorded_by_name', title: t('js.user'), text: function (r) { return r.recorded_by_name || ''; } },
          { data: 'note', title: t('fields.note'), orderable: false, text: function (r) { return r.note || ''; } }
        ]
      },

      activity: {
        order: [[0, 'desc']],
        columns: [
          { data: 'created_at', title: t('js.date'), className: 'nowrap', text: function (r) { return dateTime(r.created_at); } },
          { data: 'user_name', title: t('js.user'), html: function (r) {
            return '<strong>' + esc(actorName(r, t)) + '</strong>';
          }, text: function (r) { return actorName(r, t); } },
          { data: 'site', title: t('fields.site_id'), orderable: false },
          { data: 'entity_type', title: t('fields.entity_type'), text: function (r) { return t('entity.' + r.entity_type) + (r.entity_id ? ' #' + r.entity_id : ''); } },
          { data: 'action', title: t('js.action'), html: function (r) {
            return '<span class="badge badge--plain log-action log-action--' + actionTone(r.action) + '">' + esc(t('action.' + r.action)) + '</span>';
          }, text: function (r) { return t('action.' + r.action); } },
          { data: 'summary', title: t('js.summary'), orderable: false, html: function (r) {
            return esc(r.summary) + diffHtml(activityDiff(r, t));
          }, text: function (r) { return (r.summary || '') + diffText(activityDiff(r, t)); } }
        ]
      }
    };

    // Types Excel : les exports écrivent de vrais nombres / dates (tri, filtres, sommes) avec un format adapté.
    var days = String(t('js.days', { n: '' })).trim();
    var XL = {
      equipment: { hour_meter: { type: 'int', unit: 'h' }, open_work_orders: { type: 'int' } },
      documents: { expires_at: { type: 'date' } },
      work_orders: { reported_at: { type: 'date' }, due_date: { type: 'date' } },
      preventive: { next_due_date: { type: 'date' }, last_done_at: { type: 'date' } },
      parts: { quantity: { type: 'qty' }, min_quantity: { type: 'qty' }, unit_cost: { type: 'money' } },
      sites: { equipment_count: { type: 'int' } },
      procurement: { quantity: { type: 'qty' }, monthly: { type: 'qty', blankZero: true }, coverage_days: { type: 'int', unit: days },
        suggested: { type: 'qty', blankZero: true }, order_value: { type: 'money', blankZero: true } },
      users: { last_login_at: { type: 'datetime' } },
      readings: { recorded_at: { type: 'datetime' }, hour_meter: { type: 'int', unit: 'h' }, odometer_km: { type: 'int', unit: 'km' } },
      activity: { created_at: { type: 'datetime' } }
    };
    Object.keys(XL).forEach(function (name) {
      defs[name].columns.forEach(function (col) {
        if (XL[name][col.data]) col.xl = XL[name][col.data];
      });
    });
    return defs;
  }

  /** Date / date-heure SQL → Date UTC (Excel l'affiche telle quelle, sans décalage de fuseau). */
  function sqlDate(v) {
    var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(String(v || ''));
    return m ? new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0))) : null;
  }

  function unitFormat(base, unit) {
    return unit ? base + '" ' + String(unit).replace(/"/g, '') + '"' : base;
  }

  /**
   * Valeur native d'une cellule pour Excel : { v: nombre | Date | null, fmt: code de format } ;
   * null si la colonne est textuelle (l'export utilise alors le texte affiché).
   */
  function cellValue(column, row, locale) {
    var xl = column.xl;
    if (!xl) return null;
    var raw = row[column.data];
    if (xl.type === 'date' || xl.type === 'datetime') {
      var d = sqlDate(raw);
      var dfmt = locale === 'en' ? 'yyyy-mm-dd' : 'dd/mm/yyyy';
      return { v: d, fmt: xl.type === 'datetime' ? dfmt + ' hh:mm' : dfmt };
    }
    if (raw === null || raw === undefined || raw === '' || !isFinite(Number(raw))) return { v: null, fmt: null };
    var n = Number(raw);
    if (xl.blankZero && n === 0) return { v: null, fmt: null };
    switch (xl.type) {
      case 'money': return { v: n, fmt: '#,##0.00" ' + Core.currency().replace(/"/g, '') + '"' };
      case 'qty': return { v: n, fmt: unitFormat(Math.round(n) === n ? '#,##0' : '#,##0.00', row.unit) };
      case 'dec': return { v: n, fmt: unitFormat('#,##0.0', xl.unit) };
      default: return { v: n, fmt: unitFormat('#,##0', xl.unit) };
    }
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

  /** Auteur d'une entrée : compte, tâche planifiée (« system ») ou identifiant saisi sans session. */
  function actorName(row, t) {
    if (!row.user_name) return t('js.log_anonymous');
    return row.user_name === 'system' && !row.user_id ? t('js.log_system') : String(row.user_name);
  }

  /** Famille d'une action, pour la couleur de sa pastille. */
  function actionTone(action) {
    if (action === 'create' || action === 'photo_add' || action === 'stock_in' || action === 'assign') return 'add';
    if (action === 'archive' || action === 'photo_delete' || action === 'technician_remove' || action === 'unassign') return 'remove';
    if (action === 'login_failed') return 'alert';
    if (action === 'login' || action === 'logout') return 'session';
    return 'change';
  }

  // Champs dont la valeur est un code traduit : champ => groupe du catalogue (selon le type d'élément si besoin).
  var VALUE_GROUPS = {
    priority: 'priority', role: 'role', job: 'job', shift: 'shift', failure_cause: 'cause', part_type: 'part_type',
    status: { work_order: 'wo_status', equipment: 'status' },
    type: { work_order: 'wo_type' }
  };

  function fieldLabel(key, t) {
    var label = t('fields.' + key);
    return label === 'fields.' + key ? key : label;
  }

  function valueLabel(key, value, entityType, t) {
    var group = VALUE_GROUPS[key];
    if (group && typeof group === 'object') group = group[entityType];
    if (!group || value === null || value === undefined || value === '' || typeof value === 'object') return scalar(value);
    var label = t(group + '.' + value);
    return label === group + '.' + value ? scalar(value) : label;
  }

  /**
   * Détail lisible d'une entrée du journal : libellés de champs et valeurs traduits.
   * Modification → { label, before, after } ; création / événement (un seul côté) → { label, value }.
   */
  function activityDiff(row, t) {
    var before = parseJson(row.before_data);
    var after = parseJson(row.after_data);
    var type = row.entity_type;
    if (before && after) {
      return Object.keys(after).map(function (key) {
        return { label: fieldLabel(key, t), before: valueLabel(key, before[key], type, t), after: valueLabel(key, after[key], type, t) };
      });
    }
    var only = after || before;
    if (!only || typeof only !== 'object') return [];
    return Object.keys(only).filter(function (key) {
      return only[key] !== null && only[key] !== '';
    }).map(function (key) {
      return { label: fieldLabel(key, t), value: valueLabel(key, only[key], type, t) };
    });
  }

  function changes(entries) {
    return entries.filter(function (e) { return e.before !== undefined; });
  }

  function diffHtml(entries) {
    entries = changes(entries);
    if (!entries.length) return '';
    return '<div class="diff">' + entries.map(function (e) {
      return '<div>' + esc(e.label) + ' : <del>' + esc(e.before) + '</del> → <ins>' + esc(e.after) + '</ins></div>';
    }).join('') + '</div>';
  }

  function diffText(entries) {
    entries = changes(entries);
    return entries.length ? ' [' + entries.map(function (e) { return e.label + ': ' + e.before + ' → ' + e.after; }).join('; ') + ']' : '';
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
    cellValue: cellValue,
    definitions: definitions,
    diffEntries: diffEntries,
    activityDiff: activityDiff,
    actorName: actorName,
    actionTone: actionTone,
    cellText: cellText,
    cellHtml: cellHtml
  };
});
