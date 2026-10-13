<?php
/** @var array $sites  @var int|null $siteId  @var array $overview  @var int|null $selected  @var array $rules */
$view->share('pageScripts', ['vendor/chart.umd.min.js', 'js/pm-reading.js', 'js/meters.js']);
$s = $overview['summary'];
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="gauge"></i></span>
        <div>
            <h1><?= e(t('nav.meters')) ?></h1>
            <div class="page-title__meta">
                <span><i data-lucide="refresh-cw"></i><span><?= e(t('meters.auto_refresh')) ?> · <span data-meters-updated><?= e(format_date($overview['generated_at'], true)) ?></span></span></span>
            </div>
        </div>
    </div>
    <div class="page-header__actions">
        <a class="btn" href="<?= e(url('/meters/screen', ['site_id' => $siteId ?? ''])) ?>" target="_blank" rel="noopener"><i data-lucide="monitor"></i><?= e(t('meters.screen')) ?></a>
    </div>
</div>

<?php if (count($sites) > 1): ?>
<nav class="chips" aria-label="<?= e(t('fields.site_id')) ?>">
    <a class="chip-btn" aria-pressed="<?= $siteId === null ? 'true' : 'false' ?>" href="<?= e(url('/meters', ['site_id' => ''])) ?>"><?= e(t('common.all')) ?></a>
    <?php foreach ($sites as $site): ?>
        <?php if ($site['activity'] !== 'office'): ?>
            <a class="chip-btn" aria-pressed="<?= $siteId === (int) $site['id'] ? 'true' : 'false' ?>" href="<?= e(url('/meters', ['site_id' => $site['id']])) ?>"><?= e($site['name']) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>
<?php endif; ?>

<div class="kpis kpis--compact">
    <button type="button" class="kpi kpi--button<?= $s['due'] > 0 ? ' kpi--alert' : '' ?>" data-meters-kpi="due">
        <div class="kpi__label"><?= e(t('meters.kpi_due')) ?></div>
        <div class="kpi__value" data-meters-value="due"><?= e($s['due']) ?></div>
        <div class="kpi__hint"><?= e(t('meters.kpi_due_hint')) ?></div>
    </button>
    <button type="button" class="kpi kpi--button<?= $s['soon'] > 0 ? ' kpi--warn' : '' ?>" data-meters-kpi="soon">
        <div class="kpi__label"><?= e(t('meters.kpi_soon')) ?></div>
        <div class="kpi__value" data-meters-value="soon"><?= e($s['soon']) ?></div>
        <div class="kpi__hint"><?= e(t('meters.kpi_soon_hint', $rules)) ?></div>
    </button>
    <button type="button" class="kpi kpi--button<?= $s['read_today'] < $s['equipment'] ? ' kpi--warn' : '' ?>" data-meters-kpi="unread">
        <div class="kpi__label"><?= e(t('meters.kpi_today')) ?></div>
        <div class="kpi__value"><span data-meters-value="read_today"><?= e($s['read_today']) ?></span><small>/ <span data-meters-value="equipment"><?= e($s['equipment']) ?></span></small></div>
        <div class="kpi__hint"><?= e(t('meters.kpi_today_hint')) ?></div>
    </button>
    <button type="button" class="kpi kpi--button<?= $s['stale'] > 0 ? ' kpi--alert' : '' ?>" data-meters-kpi="stale">
        <div class="kpi__label"><?= e(t('meters.kpi_stale')) ?></div>
        <div class="kpi__value" data-meters-value="stale"><?= e($s['stale']) ?></div>
        <div class="kpi__hint"><?= e(t('meters.kpi_stale_hint', $rules)) ?></div>
    </button>
</div>

<section class="card">
    <div class="card__title">
        <h2><i data-lucide="list-checks" class="title-icon"></i><?= e(t('meters.table_title')) ?></h2>
    </div>
    <div class="chips" role="group" aria-label="<?= e(t('fields.status')) ?>">
        <?php foreach (['all', 'due', 'soon', 'unread', 'stale'] as $i => $filter): ?>
            <button type="button" class="chip-btn" data-meters-filter="<?= e($filter) ?>" aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>"><?= e(t('meter_filter.' . $filter)) ?></button>
        <?php endforeach; ?>
    </div>
    <div class="table-wrap">
        <table class="display compact meters-table" style="width:100%" data-meters-table>
            <thead><tr>
                <th><?= e(t('fields.equipment_id')) ?></th>
                <th><?= e(t('meters.col_index')) ?></th>
                <th><?= e(t('meters.col_last_reading')) ?></th>
                <th><?= e(t('meters.col_next')) ?></th>
                <th><?= e(t('meters.col_progress')) ?></th>
                <th><?= e(t('meters.col_remaining')) ?></th>
                <th><?= e(t('fields.status')) ?></th>
                <th aria-label="<?= e(t('js.action')) ?>"></th>
            </tr></thead>
        </table>
    </div>
    <p class="hint small"><?= e(t('meters.rule', $rules)) ?></p>
</section>

<?php /* Détail et évolution d'un engin : contenu de la fenêtre ouverte par le bouton « Évolution » d'une ligne. */ ?>
<template id="meters-detail-tpl">
<div class="meters-modal" data-meters-detail>
    <div class="meters-modal__head">
        <span data-meters-detail-status></span>
        <div>
            <h2 data-meters-detail-title>…</h2>
            <p class="muted small" data-meters-detail-sub></p>
        </div>
        <a class="btn btn--sm" data-meters-detail-link href="#"><i data-lucide="external-link"></i><?= e(t('meters.open_equipment')) ?></a>
    </div>
    <div class="vitals" data-meters-detail-vitals></div>
    <h3><?= e(t('js.wall.plans')) ?></h3>
    <div class="table-wrap">
        <table class="table table--compact" data-no-dt>
            <thead><tr>
                <th><?= e(t('meters.col_next')) ?></th><th class="num"><?= e(t('js.wall.threshold_col')) ?></th><th><?= e(t('meters.col_progress')) ?></th>
                <th><?= e(t('meters.col_remaining')) ?></th><th><?= e(t('fields.status')) ?></th>
            </tr></thead>
            <tbody data-meters-detail-plans></tbody>
        </table>
    </div>
    <h3><?= e(t('meters.evolution')) ?></h3>
    <div class="meters-toolbar">
        <div class="chips" role="group" aria-label="<?= e(t('meters.period')) ?>">
            <?php foreach (\App\Services\MeterMonitorService::PERIODS as $i => $days): ?>
                <button type="button" class="chip-btn" data-meters-days="<?= e($days) ?>" aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>"><?= e($days === 548 ? t('js.wall.months18') : t('meters.days', ['n' => $days])) ?></button>
            <?php endforeach; ?>
        </div>
        <div class="chips" role="group" aria-label="<?= e(t('meters.col_index')) ?>">
            <button type="button" class="chip-btn" data-meters-metric="hour_meter" aria-pressed="true"><?= e(t('fields.hour_meter')) ?></button>
            <button type="button" class="chip-btn" data-meters-metric="odometer_km" aria-pressed="false"><?= e(t('fields.odometer_km')) ?></button>
        </div>
    </div>
    <div class="chart-box chart-box--sm"><canvas id="chart-meter" role="img" aria-label="<?= e(t('meters.evolution')) ?>"></canvas></div>
    <p class="empty" data-meters-detail-empty hidden><?= e(t('meters.no_readings')) ?></p>
    <details>
        <summary class="muted"><?= e(t('js.wall.recent')) ?></summary>
        <div class="table-wrap">
            <table class="table table--compact" data-no-dt>
                <thead><tr><th><?= e(t('js.date')) ?></th><th class="num"><?= e(t('fields.hour_meter')) ?></th><th class="num"><?= e(t('fields.odometer_km')) ?></th></tr></thead>
                <tbody data-meters-detail-rows></tbody>
            </table>
        </div>
    </details>
</div>
</template>

<script type="application/json" id="meters-data"><?= json_for_html([
    'overview' => $overview,
    'selected' => $selected,
    'can_read' => $currentUser->can('equipment.reading'),
    'can_plan' => $currentUser->can('preventive.manage'),
    'urls' => [
        'data' => url('/meters/data', ['site_id' => $siteId ?? '']),
        'series' => url('/meters/{id}/series'),
        'reading' => url('/equipment/{id}/readings'),
        'equipment' => url('/equipment/{id}'),
        'work_order' => url('/work-orders/{id}'),
        'plan' => url('/preventive/{id}/edit'),
    ],
]) ?></script>
