<?php
/** @var array $overview  @var string|null $siteName  @var bool $rotate  @var array $rules */
$view->share('pageScripts', ['vendor/chart.umd.min.js', 'js/pm-reading.js', 'js/meters-screen.js']);
$kpis = [
    'due' => ['meters.kpi_due', 'alert-octagon'],
    'soon' => ['meters.kpi_soon', 'wrench'],
    'ok' => ['meter_status.ok', 'check-circle-2'],
    'read' => ['meters.kpi_today', 'gauge'],
    'stale' => ['meters.kpi_stale', 'timer-off'],
];
?>
<div class="wall" data-wall>
    <header class="wall__head">
        <div class="wall__brand">
            <span class="wall__logo"><i data-lucide="gauge"></i></span>
            <div>
                <h1><?= e(t('nav.meters')) ?></h1>
                <p data-wall-scope><?= e($siteName ?? t('common.all_sites')) ?></p>
            </div>
        </div>
        <div class="wall__status" data-wall-status>
            <span class="wall__dot"></span><span data-wall-updated></span>
        </div>
        <div class="wall__clock">
            <strong data-wall-time></strong>
            <span data-wall-date></span>
        </div>
    </header>

    <div class="wall__alert" data-wall-alert role="alert" hidden><i data-lucide="wifi-off"></i><span data-wall-alert-text></span></div>

    <section class="wall__kpis">
        <?php foreach ($kpis as $key => [$label, $icon]): ?>
            <button type="button" class="wall__kpi wall__kpi--<?= e($key) ?>" data-wall-kpi="<?= e($key) ?>" data-title="<?= e($key === 'read' ? t('meter_filter.unread') : t($label)) ?>" title="<?= e(t('meters.wall_filter_hint')) ?>">
                <span class="wall__kpi-label"><i data-lucide="<?= e($icon) ?>"></i><?= e(t($label)) ?></span>
                <span class="wall__kpi-value" data-wall-value="<?= e($key) ?>">–</span>
            </button>
        <?php endforeach; ?>
    </section>

    <section class="wall__main">
        <div class="wall__panel wall__panel--list">
            <div class="wall__title"><h2 data-wall-list-title><?= e(t('meters.wall_list')) ?></h2>
                <span class="wall__title-tools">
                    <label class="wall__search"><i data-lucide="search"></i><input type="search" data-wall-search autocomplete="off" placeholder="<?= e(t('entry.search')) ?>" aria-label="<?= e(t('entry.search')) ?>"></label>
                    <span class="wall__pager" data-wall-pages></span>
                </span>
            </div>
            <div class="wall__rows" data-wall-rows></div>
        </div>
        <div class="wall__side">
            <div class="wall__panel">
                <div class="wall__title"><h2><?= e(t('meter_filter.unread')) ?></h2><span data-wall-unread-count></span></div>
                <div class="wall__chips" data-wall-unread></div>
            </div>
            <div class="wall__panel wall__panel--chart">
                <div class="wall__title"><h2 data-wall-chart-title><?= e(t('meters.evolution')) ?></h2><span><span data-wall-chart-rate></span> <a class="wall__link" data-wall-equipment href="#" hidden><?= e(t('meters.open_equipment')) ?></a></span></div>
                <div class="wall__chart"><canvas id="wall-chart" role="img" aria-label="<?= e(t('meters.evolution')) ?>"></canvas><p class="wall__chart-empty" data-wall-chart-empty hidden><?= e(t('meters.no_readings')) ?></p></div>
            </div>
        </div>
    </section>

    <footer class="wall__foot">
        <span><?= e(t('meters.rule', $rules)) ?></span>
        <span class="wall__paused" data-wall-paused hidden><i data-lucide="pause"></i><?= e(t('meters.wall_paused')) ?> <button type="button" class="wall__link" data-wall-resume><?= e(t('meters.wall_resume')) ?></button></span>
        <span>PerfectMaint</span>
    </footer>

    <div class="wall__overlay" data-wall-modal hidden>
        <div class="wall__modal" role="dialog" aria-modal="true" aria-labelledby="wall-modal-title" tabindex="-1" data-wall-modal-content></div>
    </div>

    <div class="wall__tools">
        <button type="button" class="btn btn--sm" data-wall-fullscreen><i data-lucide="maximize"></i><?= e(t('meters.fullscreen')) ?></button>
        <a class="btn btn--sm" href="<?= e(url('/meters')) ?>"><i data-lucide="x"></i><?= e(t('js.close')) ?></a>
    </div>
</div>

<script type="application/json" id="wall-data"><?= json_for_html([
    'overview' => $overview,
    'rotate' => $rotate,
    'scope' => $siteName ?? t('common.all_sites'),
    'urls' => [
        'data' => url('/meters/data', ['site_id' => $siteId ?? '']),
        'series' => url('/meters/{id}/series'),
        'equipment' => url('/equipment/{id}'),
        'login' => url('/login'),
    ],
]) ?></script>
