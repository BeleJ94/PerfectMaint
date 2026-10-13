<?php
/** @var array $stats  @var array $sites  @var int|null $siteId  @var array $siteCards */
$view->share('pageScripts', ['vendor/chart.umd.min.js', 'js/dashboard.js']);
$eq = $stats['equipment'];
$wo = $stats['work_orders'];
$siteQuery = $siteId ? ['site_id' => $siteId] : [];
$sp = static fn (array $p) => $p + $siteQuery;
$woChips = [
    ['label' => t('common.all'), 'params' => []],
    ['label' => t('priority.critical'), 'params' => ['priority' => 'critical']],
    ['label' => t('priority.high'), 'params' => ['priority' => 'high']],
    ['label' => t('wo_type.corrective'), 'params' => ['type' => 'corrective']],
    ['label' => t('wo_type.preventive'), 'params' => ['type' => 'preventive']],
];
$eqChips = [['label' => t('common.all'), 'params' => []]];
foreach (\App\Domain\EquipmentStatus::cases() as $st) {
    $eqChips[] = ['label' => t($st->labelKey()), 'params' => ['status' => $st->value]];
}
$availClass = $eq['availability'] === null ? '' : ($eq['availability'] >= 85 ? 'good' : ($eq['availability'] >= 70 ? 'warn' : 'bad'));
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="layout-dashboard"></i></span>
        <div>
            <h1><?= e(t('nav.dashboard')) ?></h1>
            <div class="page-title__meta"><span><i data-lucide="mouse-pointer-click"></i><?= e(t('dashboard.drill_hint')) ?></span></div>
        </div>
    </div>
    <form method="get" action="<?= e(url('/')) ?>" class="filters" style="margin:0">
        <?= $view->partial('partials/site_filter', ['sites' => $sites, 'selected' => $siteId]) ?>
    </form>
</div>

<div class="kpis kpis--compact kpis--8">
    <button type="button" class="kpi kpi--button kpi--hero"<?= drill('equipment', '/equipment/data', t('dashboard.availability'), $sp([]), '/equipment', '/equipment/{id}', $eqChips) ?>>
        <div class="kpi__label"><?= e(t('dashboard.availability')) ?></div>
        <div class="kpi__value kpi__value--<?= e($availClass) ?>"><?= $eq['availability'] === null ? '–' : e(format_number($eq['availability'], 1)) . '<small>%</small>' ?></div>
        <div class="meter meter--<?= e($availClass) ?>"><span style="width: <?= e((string) ($eq['availability'] ?? 0)) ?>%"></span></div>
        <div class="kpi__hint"><?= e(t('dashboard.available_of', ['available' => $eq['by_status']['available'], 'total' => $eq['total']])) ?></div>
    </button>
    <button type="button" class="kpi kpi--button<?= $eq['by_status']['breakdown'] > 0 ? ' kpi--alert' : '' ?>"<?= drill('equipment', '/equipment/data', t('dashboard.breakdowns'), $sp(['status' => 'breakdown']), '/equipment', '/equipment/{id}') ?>>
        <div class="kpi__label"><?= e(t('dashboard.breakdowns')) ?></div>
        <div class="kpi__value"><?= e($eq['by_status']['breakdown']) ?></div>
        <div class="kpi__hint"><?= e(t('dashboard.equipment_down')) ?></div>
    </button>
    <button type="button" class="kpi kpi--button"<?= drill('work_orders', '/work-orders/data', t('dashboard.open_work_orders'), $sp(['status' => 'active']), '/work-orders', '/work-orders/{id}', $woChips) ?>>
        <div class="kpi__label"><?= e(t('dashboard.open_work_orders')) ?></div>
        <div class="kpi__value"><?= e($wo['open']) ?></div>
        <div class="kpi__hint"><?= e(t('dashboard.critical_count', ['count' => $wo['critical']])) ?></div>
    </button>
    <button type="button" class="kpi kpi--button<?= $wo['overdue'] > 0 ? ' kpi--alert' : '' ?>"<?= drill('work_orders', '/work-orders/data', t('dashboard.overdue'), $sp(['status' => 'overdue']), '/work-orders', '/work-orders/{id}', $woChips) ?>>
        <div class="kpi__label"><?= e(t('dashboard.overdue')) ?></div>
        <div class="kpi__value"><?= e($wo['overdue']) ?></div>
        <div class="kpi__hint"><?= e(t('dashboard.overdue_hint')) ?></div>
    </button>
    <button type="button" class="kpi kpi--button<?= $stats['preventive_due'] > 0 ? ' kpi--warn' : '' ?>"<?= drill('preventive', '/preventive/data', t('dashboard.preventive_due'), $sp(['due' => '1']), '/preventive', $currentUser->can('preventive.manage') ? '/preventive/{id}/edit' : null) ?>>
        <div class="kpi__label"><?= e(t('dashboard.preventive_due')) ?></div>
        <div class="kpi__value"><?= e($stats['preventive_due']) ?></div>
        <div class="kpi__hint"><?= e(t('dashboard.preventive_due_hint')) ?></div>
    </button>
    <a class="kpi<?= $stats['meters']['due'] > 0 ? ' kpi--alert' : ($stats['meters']['soon'] > 0 ? ' kpi--warn' : '') ?>" href="<?= e(url('/meters', $siteQuery)) ?>">
        <div class="kpi__label"><?= e(t('dashboard.meters')) ?></div>
        <div class="kpi__value"><?= e($stats['meters']['due'] + $stats['meters']['soon']) ?></div>
        <div class="kpi__hint"><?= e(t('dashboard.meters_hint', ['read' => $stats['meters']['read_today'], 'total' => $stats['meters']['equipment']])) ?></div>
    </a>
    <button type="button" class="kpi kpi--button<?= $stats['parts_below_min'] > 0 ? ' kpi--alert' : '' ?>"<?= drill('procurement', '/procurement/data', t('dashboard.parts_low'), $sp(['level' => 'action']), '/procurement', '/parts/{id}', [
        ['label' => t('procurement.to_act'), 'params' => ['level' => 'action']],
        ['label' => t('level.out'), 'params' => ['level' => 'out']],
        ['label' => t('level.low'), 'params' => ['level' => 'low']],
        ['label' => t('level.watch'), 'params' => ['level' => 'watch']],
    ]) ?>>
        <div class="kpi__label"><?= e(t('dashboard.parts_low')) ?></div>
        <div class="kpi__value"><?= e($stats['parts_below_min']) ?></div>
        <div class="kpi__hint"><?= e(t('dashboard.parts_low_hint')) ?></div>
    </button>
    <button type="button" class="kpi kpi--button"<?= drill('work_orders', '/work-orders/data', t('dashboard.mttr_drill'), $sp(['closed_days' => 90, 'type' => 'corrective']), '/work-orders', '/work-orders/{id}') ?>>
        <div class="kpi__label"><?= e(t('dashboard.mttr')) ?></div>
        <div class="kpi__value"><?= $wo['mttr_hours'] === null ? '–' : e(format_number($wo['mttr_hours'], 1)) . '<small>h</small>' ?></div>
        <div class="kpi__hint"><?= e(t('dashboard.mttr_hint')) ?></div>
    </button>
</div>

<?php if (!empty($siteCards)): ?>
<div class="site-cards">
    <?php foreach ($siteCards as $sc): ?>
        <?php $avail = (int) $sc['fleet'] > 0 ? round($sc['available'] / $sc['fleet'] * 100) : null; $cls = $avail === null ? '' : ($avail >= 85 ? 'good' : ($avail >= 70 ? 'warn' : 'bad')); ?>
        <a class="site-card" href="<?= e(url('/sites/' . $sc['id'])) ?>" title="<?= e(t('dashboard.open_site')) ?>">
            <span class="site-card__head"><i data-lucide="<?= $sc['activity'] === 'transport' ? 'bus' : 'mountain' ?>"></i><strong><?= e($sc['name']) ?></strong><span class="site-card__pct site-card__pct--<?= e($cls) ?>"><?= $avail === null ? '–' : e($avail) . ' %' ?></span></span>
            <span class="meter meter--<?= e($cls) ?>"><span style="width: <?= e($avail ?? 0) ?>%"></span></span>
            <span class="site-card__meta">
                <span><?= e(t('site.fleet_count', ['count' => $sc['fleet']])) ?></span>
                <?php if ((int) $sc['down'] > 0): ?><span class="site-card__down"><span class="dot dot--breakdown"></span><?= e(t('dashboard.down_count', ['count' => $sc['down']])) ?></span><?php endif; ?>
                <span><?= e(t('dashboard.open_count', ['count' => $sc['open_wo']])) ?></span>
            </span>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

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
                <tr class="row-hover">
                    <td><button type="button" class="link-btn"<?= drill('equipment', '/equipment/data', t('status.' . $status), $siteQuery + ['status' => $status], '/equipment', '/equipment/{id}') ?>><?= status_badge($status) ?></button></td>
                    <td class="num"><?= e($count) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</div>

<script type="application/json" id="dashboard-data"><?= json_for_html($stats + ['site_id' => $siteId, 'urls' => ['work_orders' => url('/work-orders/data'), 'work_orders_page' => url('/work-orders'), 'work_order' => url('/work-orders/{id}'), 'equipment' => url('/equipment/data'), 'equipment_page' => url('/equipment'), 'equipment_row' => url('/equipment/{id}')]]) ?></script>
