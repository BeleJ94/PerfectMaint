/*
 * Recherche rapide (Ctrl + K, ou « / » hors d'un champ) : pages du menu, engins (code, nom, plaque, châssis)
 * et OT (référence, titre). Navigation au clavier : ↑ ↓ pour choisir, Entrée pour ouvrir, Échap pour fermer.
 */
(function ($, Core) {
  'use strict';

  var $open = $('[data-cmdk-open]');
  if (!$open.length || !window.PM) return;
  var t = window.PM.t;
  var esc = Core.escapeHtml;
  var url = $open.data('url');

  // Pages du menu, lues dans la barre latérale (respecte les droits de l'utilisateur).
  var pages = $('#sidebar .nav a[href]').map(function () {
    return { label: $(this).find('.nav__text').text().trim() || this.title, url: this.getAttribute('href'), icon: $(this).find('[data-lucide]').attr('data-lucide') || 'arrow-right' };
  }).get().filter(function (p) { return p.label; });

  var $root = $(
    '<div class="cmdk" data-cmdk hidden>'
    + '<div class="cmdk__box" role="dialog" aria-modal="true" aria-label="' + esc(t('js.cmdk.title')) + '">'
    + '<label class="cmdk__input"><i data-lucide="search"></i><input type="search" autocomplete="off" spellcheck="false" placeholder="' + esc(t('js.cmdk.placeholder')) + '" aria-controls="cmdk-results"><kbd>Échap</kbd></label>'
    + '<div class="cmdk__results" id="cmdk-results" role="listbox"></div>'
    + '<p class="cmdk__help">' + esc(t('js.cmdk.help')) + '</p>'
    + '</div></div>'
  ).appendTo(document.body);
  var $input = $root.find('input');
  var $results = $root.find('.cmdk__results');
  var entries = [];
  var active = 0;
  var timer = null;
  var request = null;

  function item(e, i) {
    return '<a class="cmdk__item' + (i === active ? ' is-active' : '') + '" role="option" href="' + esc(e.url) + '" data-cmdk-index="' + i + '"'
      + (e.blank ? ' target="_blank" rel="noopener"' : '') + '>'
      + '<i data-lucide="' + esc(e.icon) + '"></i><span><strong>' + esc(e.label) + '</strong>' + (e.sub ? '<small>' + esc(e.sub) + '</small>' : '') + '</span>'
      + (e.badge ? '<em class="badge badge--' + esc(e.badgeClass || 'neutral') + '">' + esc(e.badge) + '</em>' : '') + '</a>';
  }

  function render(groups) {
    entries = [];
    var html = groups.filter(function (g) { return g.items.length; }).map(function (g) {
      return '<div class="cmdk__group"><h3>' + esc(g.title) + '</h3>' + g.items.map(function (e) { entries.push(e); return item(e, entries.length - 1); }).join('') + '</div>';
    }).join('');
    $results.html(html || '<p class="cmdk__empty">' + esc(t('js.no_result')) + '</p>');
    if (window.lucide) window.lucide.createIcons();
  }

  function search() {
    var q = $input.val().trim().toLowerCase();
    active = 0;
    var matchPages = pages.filter(function (p) { return !q || p.label.toLowerCase().indexOf(q) !== -1; }).slice(0, q ? 6 : 8);
    var groups = [{ title: t('js.cmdk.pages'), items: matchPages.map(function (p) { return { label: p.label, url: p.url, icon: p.icon }; }) }];
    render(groups);
    clearTimeout(timer);
    if (q.length < 2) return;
    timer = setTimeout(function () {
      if (request) request.abort();
      request = $.getJSON(url, { q: q });
      request.done(function (json) {
        groups.push({ title: t('js.cmdk.equipment'), items: (json.equipment || []).map(function (e) {
          return { label: e.code + ' · ' + e.name, sub: e.site, url: e.url, icon: 'truck', badge: t('status.' + e.status), badgeClass: e.status };
        }) });
        groups.push({ title: t('js.cmdk.work_orders'), items: (json.work_orders || []).map(function (w) {
          return { label: w.reference + ' · ' + w.title, sub: w.equipment + ' · ' + w.site, url: w.url, icon: 'clipboard-list', badge: t('wo_status.' + w.status) };
        }) });
        render(groups);
      });
    }, 200);
  }

  function open() {
    $root.prop('hidden', false);
    document.body.classList.add('cmdk-open');
    $input.val('').trigger('focus');
    search();
  }
  function close() {
    $root.prop('hidden', true);
    document.body.classList.remove('cmdk-open');
  }
  function move(delta) {
    if (!entries.length) return;
    active = (active + delta + entries.length) % entries.length;
    $results.find('.cmdk__item').removeClass('is-active').eq(active).addClass('is-active')[0].scrollIntoView({ block: 'nearest' });
  }

  $open.on('click', open);
  $input.on('input', search);
  $input.on('keydown', function (e) {
    if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
    else if (e.key === 'Enter') {
      e.preventDefault();
      var link = $results.find('.cmdk__item').eq(active)[0];
      if (link) link.click();
    } else if (e.key === 'Escape') { close(); }
  });
  $root.on('mousemove', '.cmdk__item', function () {
    active = Number(this.dataset.cmdkIndex);
    $results.find('.cmdk__item').removeClass('is-active');
    this.classList.add('is-active');
  });
  $root.on('click', function (e) { if (e.target === this) close(); });
  $(document).on('keydown', function (e) {
    var typing = /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName) || e.target.isContentEditable;
    if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); if ($root.prop('hidden')) open(); else close(); }
    else if (e.key === '/' && !typing && $root.prop('hidden') && !(window.Swal && Swal.isVisible())) { e.preventDefault(); open(); }
  });
})(jQuery, window.PMCore);
