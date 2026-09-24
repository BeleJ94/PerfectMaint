<!doctype html>
<html lang="<?= e(\App\Core\I18n::current()->locale()) ?>">
<head>
    <?= $view->partial('partials/head', ['title' => $title ?? null]) ?>
</head>
<body>
<main class="auth">
    <?= $content ?>
</main>
<?= $view->partial('partials/scripts') ?>
</body>
</html>
