<?php /** Écrans terrain : barre compacte, une colonne, grandes cibles tactiles. */ ?>
<!doctype html>
<html lang="<?= e(\App\Core\I18n::current()->locale()) ?>">
<head>
    <?= $view->partial('partials/head', ['title' => $title ?? null]) ?>
</head>
<body>
<div class="field-app">
    <header class="field-bar">
        <?php if (($currentPath ?? '') !== '/terrain'): ?>
            <a href="<?= e(url(str_starts_with($currentPath ?? '', '/terrain/engins/') ? '/terrain/engins' : '/terrain')) ?>" aria-label="<?= e(t('common.back')) ?>"><i data-lucide="arrow-left"></i></a>
        <?php else: ?>
            <a href="<?= e(url('/')) ?>" aria-label="<?= e(t('nav.dashboard')) ?>"><i data-lucide="layout-dashboard"></i></a>
        <?php endif; ?>
        <h1><?= e($title ?? 'PerfectMaint') ?></h1>
        <form method="post" action="<?= e(url('/logout')) ?>">
            <?= csrf_field() ?>
            <button type="submit" aria-label="<?= e(t('auth.logout')) ?>"><i data-lucide="log-out"></i></button>
        </form>
    </header>
    <main id="main" class="field-main">
        <?= $view->partial('partials/flash', ['flashSuccess' => $flashSuccess ?? null, 'flashError' => $flashError ?? null]) ?>
        <?= $content ?>
    </main>
</div>
<?= $view->partial('partials/scripts') ?>
</body>
</html>
