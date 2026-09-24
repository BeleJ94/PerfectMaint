<?php
/** @var array $stats  @var array $sites  @var int|null $siteId */
$view->share('pageScripts', ['vendor/chart.umd.min.js', 'js/dashboard.js']);
$eq = $stats['equipment'];
$wo = $stats['work_orders'];
$siteQuery = $siteId ? ['site_id' => $siteId] : [];
?>
<div class="page-header">
    <h1><?= e(t('nav.dashboard')) ?></h1>
    <form method="get" action="<?= e(url('/')) ?>" class="filters" style="margin:0">
        <?= $view->partial('partials/site_filter', ['sites' => $sites, 'selected' => $siteId]) ?>
    </form>
</div>

<div class="kpis">
    <div class="kpi">
        <div class="kpi__label"><?= e(t('dashboard.availability')) ?></div>
        <div class="kpi__value"><?= $eq['availability'] === null ? '–' : e(format_number($eq['availability'], 1)) . ' %' ?></div>
        <div class="kpi__hint"><?= e(t('dashboard.available_of', ['available' => $eq['by_status']['available'], 'total' => $eq['total']])) ?></div>
    </div>
    <a class="kpi<?= $eq['by_status']['breakdown'] > 0 ? ' kpi--alert' : '' ?>" href="<?= e(url('/equipment', $siteQuery + ['status' => 'breakdown'])) ?>">
        <div class="kpi__label"><?= e(t('dashboard.breakdowns')) ?></div>
        <div class="kpi__value"><?= e($eq['by_status']['breakdown']) ?></div>
        <div class="kpi__hint"><?= e(t('dashboard.equipment_down')) ?></div>
    </a>
    <a class="kpi" href="<?= e(url('/work-orders', $siteQuery + ['status' => 'active'])) ?>">
        <div class="kpi__label"><?= e(t('dashboard.open_work_orders')) ?></div>
        <div class="kpi__value"><?= e($wo['open']) ?></div>
        <div class="kpi__hint"><?= e(t('dashboard.critical_count', ['count' => $wo['critical']])) ?></div>
    </a>
    <a class="kpi<?= $wo['overdue'] > 0 ? ' kpi--alert' : '' ?>" href="<?= e(url('/work-orders', $siteQuery + ['status' => 'overdue'])) ?>">
        <div class="kpi__label"><?= e(t('dashboard.overdue')) ?></div>
        <div class="kpi__value"><?= e($wo['overdue']) ?></div>
        <div class="kpi__hint"><?= e(t('dashboard.overdue_hint')) ?></div>
    </a>
    <a class="kpi" href="<?= e(url('/preventive', $siteQuery + ['due' => '1'])) ?>">
        <div class="kpi__label"><?= e(t('dashboard.preventive_due')) ?></div>
        <div class="kpi__value"><?= e($stats['preventive_due']) ?></div>
        <div class="kpi__hint"><?= e(t('dashboard.preventive_due_hint')) ?></div>
    </a>
    <a class="kpi<?= $stats['parts_below_min'] > 0 ? ' kpi--alert' : '' ?>" href="<?= e(url('/parts', $siteQuery + ['low' => '1'])) ?>">
        <div class="kpi__label"><?= e(t('dashboard.parts_low')) ?></div>
        <div class="kpi__value"><?= e($stats['parts_below_min']) ?></div>
        <div class="kpi__hint"><?= e(t('dashboard.parts_low_hint')) ?></div>
    </a>
    <div class="kpi">
        <div class="kpi__label"><?= e(t('dashboard.mttr')) ?></div>
        <div class="kpi__value"><?= $wo['mttr_hours'] === null ? '–' : e(format_number($wo['mttr_hours'], 1)) . ' h' ?></div>
        <div class="kpi__hint"><?= e(t('dashboard.mttr_hint')) ?></div>
    </div>
</div>

<div class="grid grid--main-side">
    <section class="card">
        <div class="card__title"><h2><?= e(t('dashboard.weekly_title')) ?></h2></div>
        <div class="chart-box"><canvas id="chart-weekly" role="img" aria-label="<?= e(t('dashboard.weekly_title')) ?>"></canvas></div>
        <details style="margin-top: 12px">
            <summary class="muted"><?= e(t('dashboard.table_view')) ?></summary>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th><?= e(t('dashboard.week')) ?></th><th class="num"><?= e(t('wo_type.corrective')) ?></th><th class="num"><?= e(t('wo_type.preventive')) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($stats['weekly']['labels'] as $i => $weekLabel): ?>
                        <tr><td><?= e($weekLabel) ?></td><td class="num"><?= e($stats['weekly']['corrective'][$i]) ?></td><td class="num"><?= e($stats['weekly']['preventive'][$i]) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
    </section>
    <section class="card">
        <div class="card__title"><h2><?= e(t('dashboard.fleet_status')) ?></h2></div>
        <div class="chart-box" style="height: 220px"><canvas id="chart-status" role="img" aria-label="<?= e(t('dashboard.fleet_status')) ?>"></canvas></div>
        <table class="table" style="margin-top: 12px">
            <tbody>
            <?php foreach ($eq['by_status'] as $status => $count): ?>
                <tr>
                    <td><a href="<?= e(url('/equipment', $siteQuery + ['status' => $status])) ?>"><?= status_badge($status) ?></a></td>
                    <td class="num"><?= e($count) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</div>

<script type="application/json" id="dashboard-data"><?= json_for_html($stats) ?></script>
