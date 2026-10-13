<?php /** Saisie des index (encodeurs) : barre compacte, grandes cibles tactiles, téléphone et tablette. @var string $content */ ?>
<!doctype html>
<html lang="<?= e(\App\Core\I18n::current()->locale()) ?>">
<head>
    <?= $view->partial('partials/head', ['title' => $title ?? null]) ?>
</head>
<body>
<div class="field-app entry-app">
    <header class="field-bar">
        <?php if ($currentUser->can('dashboard.view')): ?>
            <a href="<?= e(url('/')) ?>" aria-label="<?= e(t('nav.dashboard')) ?>"><i data-lucide="layout-dashboard"></i></a>
        <?php else: ?>
            <span class="entry-bar__icon"><i data-lucide="gauge"></i></span>
        <?php endif; ?>
        <h1><?= e($title ?? 'PerfectMaint') ?><small><?= e($currentUser->name()) ?></small></h1>
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
<?= $view->partial('partials/scripts', ['pageScripts' => $pageScripts ?? []]) ?>
</body>
</html>
