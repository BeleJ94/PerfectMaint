<?php
/** @var \App\Security\CurrentUser $currentUser  @var string $content  @var string $currentPath  @var array $navSites  @var int|null $siteContext */
$siteIcons = ['office' => 'building-2', 'earthmoving' => 'mountain', 'transport' => 'bus'];
$siteContext ??= null;
$contextSite = null;
foreach ($navSites ?? [] as $navSite) {
    if ((int) $navSite['id'] === $siteContext) {
        $contextSite = $navSite;
    }
}
// Sections métier : [clé, libellé, liens [chemin, icône, libellé, permission, pastille]]
$sections = [
    'steering' => ['nav.section_steering', [
        [$contextSite ? '/sites/' . $contextSite['id'] : '/', 'layout-dashboard', $contextSite ? 'nav.site_home' : 'nav.dashboard', 'dashboard.view', null],
        ['/reports', 'presentation', 'nav.reports', 'reports.view', null],
        ['/alerts', 'siren', 'nav.alerts', 'dashboard.view', 'wear overuse'],
        ['/planning', 'calendar-range', 'nav.planning', 'dashboard.view', null],
    ]],
    'maintenance' => ['nav.section_maintenance', [
        ['/work-orders', 'clipboard-list', 'nav.work_orders', 'workorder.view', 'overdue'],
        ['/preventive', 'calendar-clock', 'nav.preventive', 'preventive.view', 'preventive'],
        ['/meters', 'gauge', 'nav.meters', 'equipment.view', 'meter_soon meter_stale'],
        ['/meters/screen', 'monitor', 'nav.meters_screen', 'equipment.view', null, true],
        ['/saisie', 'pencil', 'nav.meter_entry', 'meters.entry', null],
    ]],
    'fleet' => ['nav.section_fleet', [
        ['/equipment', 'truck', 'nav.equipment', 'equipment.view', 'down'],
        ['/documents', 'file-badge', 'nav.documents', 'equipment.view', 'documents_expired documents_soon'],
        ['/teams', 'users-round', 'nav.teams', 'personnel.view', null],
    ]],
    'stores' => ['nav.section_stores', [
        ['/parts', 'package', 'nav.parts', 'parts.view', null],
        ['/procurement', 'shopping-cart', 'nav.procurement', 'parts.view', 'stock_out stock_low'],
    ]],
];
// Ordre adapté au rôle : le technicien commence par la maintenance.
$order = $currentUser->role->value === 'technician'
    ? ['maintenance', 'fleet', 'steering', 'stores']
    : ['steering', 'maintenance', 'fleet', 'stores'];
$settings = [
    ['/sites', 'building-2', 'nav.sites_admin', 'sites.manage'],
    ['/users', 'users', 'nav.users', 'users.manage'],
    ['/catalog', 'tags', 'nav.catalog', 'equipment.manage'],
    ['/settings/mail', 'mail', 'nav.mail_settings', 'settings.manage'],
    ['/activity', 'history', 'nav.activity', 'activity.view'],
];
$settings = array_values(array_filter($settings, static fn (array $l) => $currentUser->can($l[3])));
$navLink = static function (array $l) use ($currentPath): string {
    $isSiteWindow = $l[0] === '/sites' && preg_match('#^/sites/\d+$#', $currentPath);
    $current = $isSiteWindow ? '' : nav_current($currentPath, $l[0]);
    // Sous-lien (ex. écran mural du suivi des index) : en retrait, ouvert dans un nouvel onglet.
    $sub = !empty($l[5]);
    return '<li' . ($sub ? ' class="nav__sub"' : '') . '><a href="' . e(url($l[0])) . '"' . $current . ($sub ? ' target="_blank" rel="noopener"' : '') . ' title="' . e(t($l[2])) . '"><i data-lucide="' . e($l[1]) . '"></i>'
        . '<span class="nav__text">' . e(t($l[2])) . '</span>'
        . (!empty($l[4]) ? '<span class="nav__badge" data-nav-badge="' . e($l[4]) . '" hidden></span>' : '') . '</a></li>';
};
?>
<!doctype html>
<html lang="<?= e(\App\Core\I18n::current()->locale()) ?>">
<head>
    <?= $view->partial('partials/head', ['title' => $title ?? null]) ?>
</head>
<body<?= $currentUser->role->value === 'technician' && $currentPath === '/' ? ' data-field-home="' . e(url('/terrain')) . '"' : '' ?>>
<a class="skip-link" href="#main"><?= e(t('nav.skip')) ?></a>
<div class="layout">
    <aside class="sidebar" id="sidebar" aria-label="<?= e(t('nav.main')) ?>">
        <div class="sidebar__brand">
            <i data-lucide="wrench"></i>
            <div class="nav__text">PerfectMaint<small>Perfect Logistics</small></div>
            <button type="button" class="sidebar__collapse" data-sidebar-mini aria-pressed="false" title="<?= e(t('nav.collapse')) ?>" aria-label="<?= e(t('nav.collapse')) ?>"><i data-lucide="panel-left-close"></i></button>
        </div>

        <?php if (count($navSites ?? []) > 1): ?>
        <form method="post" action="<?= e(url('/context/site')) ?>" class="site-switch">
            <?= csrf_field() ?>
            <input type="hidden" name="back" value="<?= e($currentPath) ?>">
            <label for="site-switch" class="site-switch__label"><?= e(t('nav.work_site')) ?></label>
            <div class="site-switch__control" title="<?= e($contextSite['name'] ?? t('common.all_sites')) ?>">
                <i data-lucide="<?= e($contextSite ? ($siteIcons[$contextSite['activity']] ?? 'map-pin') : 'globe-2') ?>"></i>
                <select id="site-switch" name="site_id" data-autosubmit>
                    <option value=""><?= e(t('common.all_sites')) ?></option>
                    <?php foreach ($navSites as $navSite): ?>
                        <option value="<?= e($navSite['id']) ?>"<?= (int) $navSite['id'] === $siteContext ? ' selected' : '' ?>><?= e($navSite['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <i data-lucide="chevrons-up-down" class="site-switch__chevron"></i>
            </div>
        </form>
        <?php endif; ?>

        <ul class="nav">
            <?php foreach ($order as $key): ?>
                <?php [$sectionLabel, $links] = $sections[$key]; $links = array_filter($links, static fn (array $l) => $currentUser->can($l[3])); ?>
                <?php if ($links !== []): ?>
                    <li class="nav__section"><span class="nav__text"><?= e(t($sectionLabel)) ?></span></li>
                    <?php foreach ($links as $link): ?><?= $navLink($link) ?><?php endforeach; ?>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if ($settings !== []): /* Administration toujours visible : référentiel, utilisateurs, journal… */ ?>
                <li class="nav__section"><span class="nav__text"><?= e(t('nav.section_admin')) ?></span></li>
                <?php foreach ($settings as $link): ?><?= $navLink($link) ?><?php endforeach; ?>
            <?php endif; ?>
        </ul>

        <?php if ($currentUser->can('field.use')): ?>
            <div class="sidebar__foot">
                <a class="sidebar__field" href="<?= e(url('/terrain')) ?>" title="<?= e(t('nav.field_mode')) ?>"><i data-lucide="smartphone"></i><span class="nav__text"><?= e(t('nav.field_mode')) ?></span></a>
            </div>
        <?php endif; ?>
    </aside>
    <div class="main">
        <header class="topbar">
            <button type="button" class="btn btn--ghost topbar__menu" data-toggle-sidebar aria-controls="sidebar" aria-expanded="false" aria-label="<?= e(t('nav.menu')) ?>">
                <i data-lucide="menu"></i>
            </button>
            <p class="topbar__title"><?= e($title ?? '') ?></p>
            <?php if ($currentUser->can('dashboard.view')): ?>
            <?php /* Recherche rapide : engins, OT et pages du menu (Ctrl + K) — js/pm-command.js */ ?>
            <button type="button" class="topbar__search" data-cmdk-open data-url="<?= e(url('/search')) ?>" aria-label="<?= e(t('search.open')) ?>">
                <i data-lucide="search"></i><span><?= e(t('search.placeholder')) ?></span><kbd>Ctrl K</kbd>
            </button>
            <div class="notif" data-notifications data-url="<?= e(url('/notifications')) ?>" data-user="<?= e($currentUser->id) ?>"
                 data-scope="<?= e($contextSite['name'] ?? t('notify.all_my_sites')) ?>">
                <button type="button" class="btn btn--ghost notif__btn" data-notif-toggle aria-haspopup="true" aria-expanded="false" aria-label="<?= e(t('notify.title')) ?>">
                    <i data-lucide="bell"></i><span class="notif__badge" data-notif-badge hidden></span>
                </button>
                <div class="notif__panel" data-notif-panel hidden>
                    <div class="notif__head">
                        <div><strong><?= e(t('notify.title')) ?></strong><small class="muted" data-notif-scope></small></div>
                        <button type="button" class="link-btn small" data-notif-seen><?= e(t('notify.mark_seen')) ?></button>
                    </div>
                    <div class="notif__body" data-notif-list><p class="notif__empty"><?= e(t('js.dt.loading')) ?></p></div>
                    <a class="notif__foot" href="<?= e(url('/alerts')) ?>"><?= e(t('notify.see_all')) ?><i data-lucide="arrow-right"></i></a>
                </div>
            </div>
            <?php endif; ?>
            <?= $view->partial('partials/lang_switch', ['currentPath' => $currentPath]) ?>
            <div class="topbar__user">
                <span class="avatar" aria-hidden="true"><?= e($currentUser->initials()) ?></span>
                <span class="name"><?= e($currentUser->name()) ?><br><small class="muted"><?= e(t($currentUser->role->labelKey())) ?></small></span>
                <form method="post" action="<?= e(url('/logout')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn--ghost" title="<?= e(t('auth.logout')) ?>" aria-label="<?= e(t('auth.logout')) ?>"><i data-lucide="log-out"></i></button>
                </form>
            </div>
        </header>
        <main id="main" class="content">
            <?= $view->partial('partials/flash', ['flashSuccess' => $flashSuccess ?? null, 'flashError' => $flashError ?? null]) ?>
            <?= $content ?>
        </main>
    </div>
</div>
<?= $view->partial('partials/scripts', ['pageScripts' => $pageScripts ?? []]) ?>
</body>
</html>
