<?php /** Affichage mural (salle de supervision) : plein écran, sans navigation. @var string $content */ ?>
<!doctype html>
<html lang="<?= e(\App\Core\I18n::current()->locale()) ?>">
<head>
    <?= $view->partial('partials/head', ['title' => $title ?? null]) ?>
</head>
<body class="wall-body">
<?= $content ?>
<?= $view->partial('partials/scripts', ['pageScripts' => $pageScripts ?? []]) ?>
</body>
</html>
