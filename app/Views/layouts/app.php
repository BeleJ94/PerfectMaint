<?php
/** @var \App\Security\CurrentUser $currentUser  @var string $content  @var string $currentPath */
$nav = [
    ['section' => 'nav.section_operations'],
    ['/', 'layout-dashboard', 'nav.dashboard', 'dashboard.view'],
    ['/terrain', 'smartphone', 'nav.field', 'field.use'],
    ['/work-orders', 'clipboard-list', 'nav.work_orders', 'workorder.view'],
    ['/equipment', 'forklift', 'nav.equipment', 'equipment.view'],
    ['/preventive', 'calendar-clock', 'nav.preventive', 'preventive.view'],
    ['/parts', 'package', 'nav.parts', 'parts.view'],
    ['section' => 'nav.section_admin', 'permission' => 'activity.view'],
    ['/activity', 'history', 'nav.activity', 'activity.view'],
    ['/sites', 'building-2', 'nav.sites', 'sites.manage'],
    ['/users', 'users', 'nav.users', 'users.manage'],
];
?>
<!doctype html>
<html lang="<?= e(\App\Core\I18n::current()->locale()) ?>">
<head>
    <?= $view->partial('partials/head', ['title' => $title ?? null]) ?>
</head>
<body>
<a class="skip-link" href="#main"><?= e(t('nav.skip')) ?></a>
<div class="layout">
    <aside class="sidebar" id="sidebar" aria-label="<?= e(t('nav.main')) ?>">
        <div class="sidebar__brand">
            <i data-lucide="wrench"></i>
            <div>PerfectMaint<small>Perfect Logistics</small></div>
        </div>
        <ul class="nav">
            <?php foreach ($nav as $item): ?>
                <?php if (isset($item['section'])): ?>
                    <?php if (!isset($item['permission']) || $currentUser->can($item['permission'])): ?>
                        <li class="nav__section"><?= e(t($item['section'])) ?></li>
                    <?php endif; ?>
                <?php elseif ($currentUser->can($item[3])): ?>
                    <li><a href="<?= e(url($item[0])) ?>"<?= nav_current($currentPath, $item[0]) ?>><i data-lucide="<?= e($item[1]) ?>"></i><?= e(t($item[2])) ?></a></li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </aside>
    <div class="main">
        <header class="topbar">
            <button type="button" class="btn btn--ghost topbar__menu" data-toggle-sidebar aria-controls="sidebar" aria-expanded="false" aria-label="<?= e(t('nav.menu')) ?>">
                <i data-lucide="menu"></i>
            </button>
            <p class="topbar__title"><?= e($title ?? '') ?></p>
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
