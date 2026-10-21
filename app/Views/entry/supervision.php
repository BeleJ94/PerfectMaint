<?php
/**
 * Supervision des index pour l'encodeur : échéances au compteur, relevés manquants et évolution par engin.
 * Même lecture que l'écran mural (/meters/screen), en cartes : téléphone en une colonne, ordinateur en grille.
 * @var array $overview  @var array $sites  @var int|null $siteId  @var array $rules
 */
$view->share('pageScripts', ['vendor/chart.umd.min.js', 'js/pm-reading.js', 'js/pm-meters.js', 'js/entry-supervision.js']);
$kpis = [
    'due' => ['meters.kpi_due', 'alert-octagon'],
    'soon' => ['meters.kpi_soon', 'wrench'],
    'check' => ['meters.kpi_check', 'help-circle'],
    'ok' => ['meter_status.ok', 'check-circle-2'],
    'unread' => ['js.sup.unread', 'gauge'],
    'stale' => ['meters.kpi_stale', 'timer-off'],
];
?>
<?= $view->partial('entry/_nav', ['sites' => $sites, 'siteId' => $siteId, 'tab' => 'supervision']) ?>

<div class="sup" data-sup>
    <div class="sup-kpis" role="group" aria-label="<?= e(t('js.sup.filter_hint')) ?>">
        <?php foreach ($kpis as $key => [$label, $icon]): ?>
            <button type="button" class="sup-kpi sup-kpi--<?= e($key) ?>" data-sup-kpi="<?= e($key) ?>" aria-pressed="false">
                <span class="sup-kpi__label"><i data-lucide="<?= e($icon) ?>"></i><?= e(t($label)) ?></span>
                <strong class="sup-kpi__value" data-sup-value="<?= e($key) ?>">–</strong>
            </button>
        <?php endforeach; ?>
    </div>

    <div class="sup-tools">
        <input type="search" class="input" data-sup-search autocomplete="off" placeholder="<?= e(t('entry.search')) ?>" aria-label="<?= e(t('entry.search')) ?>">
        <span class="sup-status" data-sup-status aria-live="polite"></span>
    </div>

    <h2 class="entry-section"><span data-sup-title><?= e(t('js.sup.all')) ?></span> · <span data-sup-count>0</span></h2>
    <div class="sup-list" data-sup-list></div>
    <p class="empty" data-sup-empty hidden><?= e(t('js.sup.empty')) ?></p>
    <p class="muted small sup-rule"><?= e(t('meters.rule', $rules)) ?></p>
</div>

<script type="application/json" id="sup-data"><?= json_for_html([
    'overview' => $overview,
    'urls' => [
        'data' => url('/saisie/supervision/data'),
        'series' => url('/saisie/supervision/{id}/series'),
        'entry' => url('/saisie'),
    ],
]) ?></script>
