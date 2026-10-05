/*
 * Journal d'activité : synthèse « qui a fait quoi » (suit les filtres du tableau)
 * et fenêtre de détail d'une entrée (auteur, origine, valeurs avant / après).
 */
(function ($, Core, Tables) {
  'use strict';

  var $table = $('table[data-table="activity"]');
  var $summary = $('[data-activity-summary]');
  if (!$table.length || !$summary.length) return;

  var t = window.PM.t;
  var locale = window.PM.locale;
  var esc = Core.escapeHtml;
  var $form = $('form[data-filters-for="activity"]');
  var $user = $form.find('[name="user_id"]');
  var $users = $summary.find('[data-activity-users]');
  var links = {};
  try { links = JSON.parse(document.getElementById('activity-data').textContent).links || {}; } catch (e) { links = {}; }

  function icons() {
    if (window.lucide) window.lucide.createIcons();
  }

  function filters() {
    var entries = [];
    new FormData($form[0]).forEach(function (value, key) { entries.push([key, value]); });
    return Core.filtersFromEntries(entries);
  }

  function initials(name) {
    return String(name || '?').split(/\s+/).slice(0, 2).map(function (p) { return p.charAt(0).toUpperCase(); }).join('');
  }

  // ---------------------------------------------------------------- Synthèse par utilisateur
  function renderUsers(users) {
    if (!users.length) {
      $users.html('<p class="empty">' + esc(t('js.log_none')) + '</p>');
      return;
    }
    var selected = $user.val();
    $users.html(users.map(function (u) {
      var id = u.user_id === null ? '0' : String(u.user_id);
      var name = u.user_id === null ? t('js.log_no_user') : u.user_name;
      return '<button type="button" class="log-user" data-activity-user="' + esc(id) + '" title="' + esc(u.email || name) + '" aria-pressed="' + (selected === id ? 'true' : 'false') + '">'
        + '<span class="avatar avatar--sm" aria-hidden="true">' + esc(u.user_id === null ? '–' : initials(name)) + '</span>'
        + '<span class="log-user__body"><strong>' + esc(name) + '</strong>'
        + '<span class="muted small">' + esc(u.role ? t('role.' + u.role) + ' · ' : '') + esc(Core.formatDate(u.last_at, locale, true)) + '</span></span>'
        + '<span class="log-user__count">' + esc(Core.formatNumber(u.actions, locale, 0)) + '<small>' + esc(t('js.log_actions')) + '</small></span>'
        + '</button>';
    }).join(''));
  }

  var lastQuery = null;
  function loadSummary() {
    var params = filters();
    delete params.user_id;
    var query = JSON.stringify(params);
    if (query === lastQuery) {
      $users.find('[data-activity-user]').each(function () {
        this.setAttribute('aria-pressed', this.dataset.activityUser === $user.val() ? 'true' : 'false');
      });
      return;
    }
    lastQuery = query;
    $.getJSON($summary.data('url'), params).then(function (json) {
      if (query === lastQuery) renderUsers(json.users || []);
    }).catch(function () {
      lastQuery = null;
      $users.html('<p class="empty">' + esc(t('js.error_generic')) + '</p>');
    });
  }

  // Chaque rechargement du tableau (filtre, réinitialisation) rafraîchit la synthèse si les filtres ont changé.
  $table.on('preXhr.dt', loadSummary);
  loadSummary();

  $users.on('click', '[data-activity-user]', function () {
    var id = this.dataset.activityUser;
    $user.val($user.val() === id ? '' : id).trigger('change');
  });

  // ---------------------------------------------------------------- Détail d'une entrée
  function row(label, value) {
    return value ? '<dt>' + esc(label) + '</dt><dd>' + value + '</dd>' : '';
  }

  function detailHtml(r) {
    var entries = Tables.activityDiff(r, t);
    var isDiff = entries.length && entries[0].before !== undefined;
    var link = r.entity_id && links[r.entity_type] ? Core.fillUrl(links[r.entity_type], { id: r.entity_id }) : null;
    var html = '<div class="log-detail">'
      + '<p class="log-detail__head"><span class="badge badge--plain log-action log-action--' + Tables.actionTone(r.action) + '">' + esc(t('action.' + r.action)) + '</span> '
      + '<strong>' + esc(t('entity.' + r.entity_type)) + (r.entity_id ? ' #' + esc(r.entity_id) : '') + '</strong></p>'
      + (r.summary ? '<p class="log-detail__summary">' + esc(r.summary) + '</p>' : '')
      + '<dl class="dl">'
      + row(t('js.date'), esc(Core.formatDate(r.created_at, locale, true)))
      + row(t('js.user'), esc(Tables.actorName(r, t)))
      + row(t('js.site'), esc(r.site || ''))
      + row(t('js.log_ip'), esc(r.ip_address || ''))
      + row(t('js.log_device'), r.user_agent ? '<span class="small muted">' + esc(r.user_agent) + '</span>' : '')
      + '</dl>';
    if (entries.length) {
      html += '<table class="table log-detail__table"><thead><tr><th>' + esc(t('js.log_field')) + '</th>'
        + (isDiff ? '<th>' + esc(t('js.log_before')) + '</th><th>' + esc(t('js.log_after')) + '</th>' : '<th>' + esc(t('js.log_value')) + '</th>')
        + '</tr></thead><tbody>' + entries.map(function (e) {
          return '<tr><td>' + esc(e.label) + '</td>' + (isDiff
            ? '<td><del>' + esc(e.before) + '</del></td><td><ins>' + esc(e.after) + '</ins></td>'
            : '<td>' + esc(e.value) + '</td>') + '</tr>';
        }).join('') + '</tbody></table>';
    }
    html += '<div class="log-detail__actions">'
      + (link ? '<a class="btn btn--sm" href="' + esc(link) + '"><i data-lucide="external-link"></i>' + esc(t('js.log_open')) + '</a>' : '')
      + (r.user_id ? '<button type="button" class="btn btn--sm" data-log-user="' + esc(r.user_id) + '"><i data-lucide="user-search"></i>' + esc(t('js.log_filter_user')) + '</button>' : '')
      + '</div></div>';
    return html;
  }

  function openDetail(tr) {
    var data = $table.DataTable().row(tr).data();
    if (!data) return;
    window.Swal.fire({
      title: t('js.log_detail'),
      html: detailHtml(data),
      width: 680,
      showCloseButton: true,
      confirmButtonText: t('js.close'),
      confirmButtonColor: '#1F4E79',
      customClass: { htmlContainer: 'log-detail__container' },
      didOpen: function (popup) {
        icons();
        $(popup).on('click', '[data-log-user]', function () {
          var id = String(this.dataset.logUser);
          window.Swal.close();
          // Compte absent de la liste (périmètre) : le filtre reste applicable par son identifiant.
          if (!$user.find('option[value="' + id + '"]').length) {
            $user.append($('<option>').val(id).text(Tables.actorName(data, t)));
          }
          $user.val(id).trigger('change');
        });
      }
    });
  }

  $table.on('draw.dt', function () {
    $table.find('tbody tr').each(function () {
      if ($table.DataTable().row(this).data()) $(this).attr({ tabindex: '0', role: 'button' });
    });
  });
  $table.on('click keydown', 'tbody tr', function (e) {
    if (e.type === 'keydown' && e.key !== 'Enter') return;
    if ($(e.target).closest('a, button').length) return;
    openDetail(this);
  });
})(window.jQuery, window.PMCore, window.PMTables);
