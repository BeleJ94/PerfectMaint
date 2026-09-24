<?php
/** Données de configuration et catalogue i18n passés au JS sans script inline (CSP stricte). */
$pmConfig = [
    'base' => \App\Core\Url::basePath(),
    'locale' => \App\Core\I18n::current()->locale(),
    'assets' => [
        'exceljs' => asset('vendor/exceljs.min.js'),
        'pdfmake' => asset('vendor/pdfmake.min.js'),
        'vfs' => asset('vendor/vfs_fonts.js'),
    ],
];
?>
<script type="application/json" id="pm-config"><?= json_for_html($pmConfig) ?></script>
<script type="application/json" id="pm-i18n"><?= json_for_html(\App\Core\I18n::current()->jsCatalogue()) ?></script>
<script src="<?= e(asset('vendor/jquery.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/dataTables.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/sweetalert2.all.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/lucide.min.js')) ?>"></script>
<script src="<?= e(asset('js/pm-core.js')) ?>"></script>
<script src="<?= e(asset('js/pm-tables.js')) ?>"></script>
<script src="<?= e(asset('js/pm-export.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php foreach ($pageScripts ?? [] as $script): ?>
<script src="<?= e(asset($script)) ?>"></script>
<?php endforeach; ?>
