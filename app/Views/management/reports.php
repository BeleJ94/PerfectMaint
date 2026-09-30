<?php
/**
 * Rapport de direction.
 * @var array $sites  @var int|null $siteId  @var int $days  @var array $rows  @var array $totals  @var array $causes
 * @var array $top  @var array $operators  @var string $generatedAt
 */
$view->share('pageScripts', ['vendor/chart.umd.min.js', 'js/reports.js']);
$availability = $totals['fleet'] > 0 ? round($totals['available'] / $totals['fleet'] * 100, 1) : null;
$availClass = $availability === null ? '' : ($availability >= 85 ? 'good' : ($availability >= 70 ? 'warn' : 'bad'));
$q = static fn (array $extra) => url('/reports', array_filter($extra + ['days' => $days, 'site_id' => $siteId], static fn ($v) => $v !== null));
$sq = $siteId ? ['site_id' => $siteId] : [];
$causeChips = [['label' => t('common.all'), 'params' => []]];
foreach (\App\Services\WorkOrderService::FAILURE_CAUSES as $c) {
    $causeChips[] = ['label' => t('cause.' . $c), 'params' => ['cause' => $c]];
}
$causeTotal = array_sum(array_map(static fn ($c) => (int) $c['n'], $causes));
// Données de l'export exécutif (Excel / PDF), valeurs brutes + libellés traduits
$scopeName = t('reports.all_sites');
foreach ($sites as $site) {
    if ((int) $site['id'] === $siteId) {
        $scopeName = $site['name'];
    }
}
$exportPayload = [
    'labels' => [
        'title' => t('nav.reports'),
        'subtitle' => t('reports.export_subtitle', ['days' => $days, 'scope' => $scopeName, 'date' => format_date($generatedAt, true)]),
        'kpis' => t('reports.export_kpis'),
        'by_site' => t('reports.by_site'),
        'causes' => t('reports.causes'),
        'availability_chart' => t('reports.availability_by_site'),
        'top' => t('reports.top_equipment'),
        'operators' => t('reports.operators'),
        'share' => t('reports.share'),
        'count' => t('reports.count'),
        'cols_site' => [t('fields.site_id'), t('reports.fleet'), t('dashboard.availability'), t('reports.breakdowns'), t('status.breakdown'),
            t('reports.open'), t('reports.closed'), t('reports.downtime') . ' (h)', 'MTTR (h)', t('reports.consumption'), t('reports.lubricants')],
        'cols_top' => [t('fields.equipment_id'), t('fields.site_id'), t('reports.breakdowns'), t('reports.downtime') . ' (h)', t('reports.parts_cost')],
        'cols_ops' => [t('fields.personnel_id'), t('fields.site_id'), t('reports.breakdowns'), t('reports.usage_failures')],
        'total' => t('js.total'),
        'currency' => currency_symbol(),
        'confidential' => t('reports.confidential'),
        'contents' => t('reports.contents'),
        'lines' => t('reports.lines'),
    ],
    'kpis' => [
        ['label' => t('dashboard.availability'), 'value' => $availability, 'unit' => '%', 'level' => $availClass],
        ['label' => t('reports.breakdowns'), 'value' => (int) $totals['breakdowns'], 'unit' => '', 'hint' => t('reports.down_now', ['count' => (int) $totals['down']])],
        ['label' => t('reports.downtime'), 'value' => round((float) $totals['downtime_hours']), 'unit' => 'h', 'hint' => t('reports.downtime_hint')],
        ['label' => t('reports.consumption'), 'value' => round((float) $totals['consumption_value'], 2), 'unit' => currency_symbol(), 'money' => true],
        ['label' => t('reports.lubricants'), 'value' => round((float) $totals['lubricant_qty']), 'unit' => 'L/kg'],
        ['label' => t('reports.usage_failures'), 'value' => (int) $totals['usage_failures'], 'unit' => '', 'level' => $totals['usage_failures'] > 0 ? 'warn' : ''],
    ],
    'sites' => array_map(static fn (array $r) => [
        $r['name'], (int) $r['fleet'], (int) $r['fleet'] > 0 ? round($r['available'] / $r['fleet'] * 100, 1) : null, (int) $r['breakdowns'], (int) $r['down'],
        (int) $r['open_wo'], (int) $r['closed'], round((float) ($r['downtime_hours'] ?? 0), 1), $r['mttr_hours'] !== null ? (float) $r['mttr_hours'] : null,
        round((float) ($r['consumption_value'] ?? 0), 2), round((float) ($r['lubricant_qty'] ?? 0), 1),
    ], $rows),
    'totals' => [$availability, (int) $totals['fleet'], (int) $totals['breakdowns'], (int) $totals['down'], (int) $totals['open_wo'], (int) $totals['closed'],
        round((float) $totals['downtime_hours'], 1), round((float) $totals['consumption_value'], 2), round((float) $totals['lubricant_qty'], 1)],
    'causes' => array_map(static fn ($c) => [t('cause.' . $c['cause']), (int) $c['n'], $causeTotal > 0 ? round($c['n'] / $causeTotal * 100, 1) : 0], $causes),
    'top' => array_map(static fn ($eq) => [$eq['code'] . ' · ' . trim($eq['brand'] . ' ' . $eq['model']), $eq['site'], (int) $eq['work_orders'],
        round((float) ($eq['downtime_hours'] ?? 0), 1), round((float) ($eq['parts_cost'] ?? 0), 2)], $top),
    'operators' => array_map(static fn ($op) => [$op['operator_name'], $op['site'], (int) $op['breakdowns'], (int) $op['usage_related']], $operators),
];
$chart = [
    'labels' => array_map(static fn ($c) => t('cause.' . $c['cause']), $causes),
    'values' => array_map(static fn ($c) => (int) $c['n'], $causes),
    'keys' => array_column($causes, 'cause'),
    'sites' => array_column($rows, 'name'),
    'titles' => ['causes' => t('reports.causes'), 'fleet' => t('reports.fleet')],
    'site_ids' => array_map('intval', array_column($rows, 'id')),
    'days' => $days,
    'site_id' => $siteId,
    'urls' => ['work_orders' => url('/work-orders/data'), 'work_order' => url('/work-orders/{id}'), 'equipment' => url('/equipment/data'), 'equipment_row' => url('/equipment/{id}')],
    'availability' => array_map(static fn ($r) => (int) $r['fleet'] > 0 ? round($r['available'] / $r['fleet'] * 100, 1) : 0, $rows),
];
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="presentation"></i></span>
        <div>
            <h1><?= e(t('nav.reports')) ?></h1>
            <div class="page-title__meta"><span><?= e(t('reports.subtitle', ['days' => $days, 'date' => format_date($generatedAt, true)])) ?></span></div>
        </div>
    </div>
    <div class="page-header__actions">
        <div class="segmented" role="tablist" aria-label="<?= e(t('reports.period')) ?>">
            <?php foreach (\App\Controllers\ManagementController::PERIODS as $p): ?>
                <a class="segmented__link<?= $p === $days ? ' is-active' : '' ?>" href="<?= e($q(['days' => $p])) ?>"><?= e(t('reports.days', ['n' => $p])) ?></a>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn" data-report-export="excel"><i data-lucide="file-spreadsheet"></i><?= e(t('common.export_excel')) ?></button>
        <button type="button" class="btn" data-report-export="pdf"><i data-lucide="file-text"></i><?= e(t('common.export_pdf')) ?></button>
    </div>
</div>

<nav class="chips" aria-label="<?= e(t('fields.site_id')) ?>">
    <a class="chip-btn" aria-pressed="<?= $siteId === null ? 'true' : 'false' ?>" href="<?= e(url('/reports', ['days' => $days])) ?>"><?= e(t('reports.all_sites')) ?></a>
    <?php foreach ($sites as $site): ?>
        <?php if ($site['activity'] !== 'office'): ?>
            <a class="chip-btn" aria-pressed="<?= $siteId === (int) $site['id'] ? 'true' : 'false' ?>" href="<?= e(url('/reports', ['days' => $days, 'site_id' => $site['id']])) ?>"><?= e($site['name']) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>

<div class="kpis kpis--compact">
    <button type="button" class="kpi kpi--button kpi--hero"<?= drill('equipment', '/equipment/data', t('dashboard.availability'), $sq, '/equipment', '/equipment/{id}') ?>>
        <div class="kpi__label"><?= e(t('dashboard.availability')) ?></div>
        <div class="kpi__value kpi__value--<?= e($availClass) ?>"><?= $availability === null ? '–' : e(format_number($availability, 1)) . '<small>%</small>' ?></div>
        <div class="meter meter--<?= e($availClass) ?>"><span style="width: <?= e((string) ($availability ?? 0)) ?>%"></span></div>
    </button>
    <button type="button" class="kpi kpi--button"<?= drill('work_orders', '/work-orders/data', t('reports.breakdowns') . ' · ' . t('reports.days', ['n' => $days]), $sq + ['type' => 'corrective', 'reported_days' => $days], '/work-orders', '/work-orders/{id}', $causeChips) ?>><div class="kpi__label"><?= e(t('reports.breakdowns')) ?></div><div class="kpi__value"><?= e((int) $totals['breakdowns']) ?></div><div class="kpi__hint"><?= e(t('reports.down_now', ['count' => (int) $totals['down']])) ?></div></button>
    <button type="button" class="kpi kpi--button"<?= drill('work_orders', '/work-orders/data', t('reports.downtime') . ' · ' . t('reports.days', ['n' => $days]), $sq + ['immobilizing' => '1', 'closed_days' => $days], '/work-orders', '/work-orders/{id}') ?>><div class="kpi__label"><?= e(t('reports.downtime')) ?></div><div class="kpi__value"><?= e(format_number($totals['downtime_hours'], 0)) ?><small>h</small></div><div class="kpi__hint"><?= e(t('reports.downtime_hint')) ?></div></button>
    <div class="kpi"><div class="kpi__label"><?= e(t('reports.consumption')) ?></div><div class="kpi__value"><?= e(format_money($totals['consumption_value'])) ?></div><div class="kpi__hint"><?= e(t('reports.consumption_hint')) ?></div></div>
    <div class="kpi"><div class="kpi__label"><?= e(t('reports.lubricants')) ?></div><div class="kpi__value"><?= e(format_number($totals['lubricant_qty'], 0)) ?><small>L/kg</small></div><div class="kpi__hint"><?= e(t('reports.lubricants_hint')) ?></div></div>
    <button type="button" class="kpi kpi--button<?= $totals['usage_failures'] > 0 ? ' kpi--warn' : '' ?>"<?= drill('work_orders', '/work-orders/data', t('reports.usage_failures'), $sq + ['cause' => 'usage', 'closed_days' => $days], '/work-orders', '/work-orders/{id}', [['label' => t('common.all'), 'params' => []], ['label' => t('cause.misuse'), 'params' => ['cause' => 'misuse']], ['label' => t('cause.accident'), 'params' => ['cause' => 'accident']]]) ?>><div class="kpi__label"><?= e(t('reports.usage_failures')) ?></div><div class="kpi__value"><?= e((int) $totals['usage_failures']) ?></div><div class="kpi__hint"><?= e(t('reports.usage_hint')) ?></div></button>
</div>

<section class="card">
    <div class="card__title"><h2><?= e(t('reports.by_site')) ?></h2></div>
    <div class="table-wrap"><table class="table table--compact table--report">
        <thead><tr>
            <th><?= e(t('fields.site_id')) ?></th><th class="num"><?= e(t('reports.fleet')) ?></th><th><?= e(t('dashboard.availability')) ?></th>
            <th class="num"><?= e(t('reports.breakdowns')) ?></th><th class="num"><?= e(t('reports.open')) ?></th><th class="num"><?= e(t('reports.closed')) ?></th>
            <th class="num"><?= e(t('reports.downtime')) ?></th><th class="num" title="<?= e(t('dashboard.mttr')) ?>">MTTR</th><th class="num"><?= e(t('reports.consumption')) ?></th><th class="num"><?= e(t('reports.lubricants')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <?php $avail = (int) $r['fleet'] > 0 ? round($r['available'] / $r['fleet'] * 100, 1) : null; $cls = $avail === null ? '' : ($avail >= 85 ? 'good' : ($avail >= 70 ? 'warn' : 'bad')); ?>
            <tr>
                <td><a href="<?= e(url('/sites/' . $r['id'])) ?>"><strong><?= e($r['name']) ?></strong></a><br><span class="muted small"><?= e(t('site_activity.' . $r['activity'])) ?></span></td>
                <td class="num"><button type="button" class="cell-link"<?= drill('equipment', '/equipment/data', $r['name'] . ' · ' . t('reports.fleet'), ['site_id' => $r['id']], '/equipment', '/equipment/{id}') ?>><?= e($r['fleet']) ?></button></td>
                <td><div class="life"><span class="life__bar life__bar--<?= e($cls) ?>"><span style="width: <?= e($avail ?? 0) ?>%"></span></span><strong><?= $avail === null ? '–' : e(format_number($avail, 1)) . ' %' ?></strong></div></td>
                <td class="num"><button type="button" class="cell-link"<?= drill('work_orders', '/work-orders/data', $r['name'] . ' · ' . t('reports.breakdowns'), ['site_id' => $r['id'], 'type' => 'corrective', 'reported_days' => $days], '/work-orders', '/work-orders/{id}', $causeChips) ?>><?= e($r['breakdowns']) ?></button><?php if ((int) $r['down'] > 0): ?> <button type="button" class="badge badge--breakdown"<?= drill('equipment', '/equipment/data', $r['name'] . ' · ' . t('status.breakdown'), ['site_id' => $r['id'], 'status' => 'breakdown'], '/equipment', '/equipment/{id}') ?>><?= e($r['down']) ?></button><?php endif; ?></td>
                <td class="num"><button type="button" class="cell-link"<?= drill('work_orders', '/work-orders/data', $r['name'] . ' · ' . t('reports.open'), ['site_id' => $r['id'], 'status' => 'active'], '/work-orders', '/work-orders/{id}') ?>><?= e($r['open_wo']) ?></button></td>
                <td class="num"><button type="button" class="cell-link"<?= drill('work_orders', '/work-orders/data', $r['name'] . ' · ' . t('reports.closed'), ['site_id' => $r['id'], 'closed_days' => $days], '/work-orders', '/work-orders/{id}', $causeChips) ?>><?= e($r['closed']) ?></button></td>
                <td class="num"><?= e(format_number($r['downtime_hours'] ?? 0, 0)) ?> h</td>
                <td class="num"><?= $r['mttr_hours'] !== null ? e(format_number($r['mttr_hours'], 1)) . ' h' : '—' ?></td>
                <td class="num"><?= e(format_money($r['consumption_value'] ?? 0)) ?></td>
                <td class="num"><?= e(format_number($r['lubricant_qty'] ?? 0, 0)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>

<div class="grid grid--2">
    <section class="card">
        <div class="card__title"><h2><?= e(t('reports.causes')) ?></h2><span class="muted small"><?= e(t('reports.closed_count', ['count' => $causeTotal])) ?></span></div>
        <?php if ($causes === []): ?>
            <p class="empty"><?= e(t('reports.no_closed')) ?></p>
        <?php else: ?>
            <div class="chart-box chart-box--sm"><canvas id="chart-causes" role="img" aria-label="<?= e(t('reports.causes')) ?>"></canvas></div>
        <?php endif; ?>
    </section>
    <section class="card">
        <div class="card__title"><h2><?= e(t('reports.availability_by_site')) ?></h2></div>
        <?php if ($rows === []): ?>
            <p class="empty"><?= e(t('common.none')) ?></p>
        <?php else: ?>
            <div class="chart-box chart-box--sm"><canvas id="chart-availability" role="img" aria-label="<?= e(t('reports.availability_by_site')) ?>"></canvas></div>
        <?php endif; ?>
    </section>
</div>

<div class="grid grid--main-side">
    <section class="card">
        <div class="card__title"><h2><?= e(t('reports.top_equipment')) ?></h2></div>
        <?php if ($top === []): ?>
            <p class="empty"><?= e(t('common.none')) ?></p>
        <?php else: ?>
            <div class="table-wrap"><table class="table table--compact">
                <thead><tr><th><?= e(t('fields.equipment_id')) ?></th><th class="num"><?= e(t('reports.breakdowns')) ?></th><th class="num"><?= e(t('reports.downtime')) ?></th><th class="num"><?= e(t('reports.parts_cost')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($top as $eq): ?>
                    <tr>
                        <td><a href="<?= e(url('/equipment/' . $eq['id'])) ?>"><strong><?= e($eq['code']) ?></strong></a> <?= status_badge($eq['status']) ?><br><span class="muted small"><?= e(trim($eq['brand'] . ' ' . $eq['model'])) ?> · <?= e($eq['site']) ?></span></td>
                        <td class="num"><?= e($eq['work_orders']) ?></td>
                        <td class="num"><?= e(format_number($eq['downtime_hours'] ?? 0, 0)) ?> h</td>
                        <td class="num"><?= e(format_money($eq['parts_cost'] ?? 0)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>
    <section class="card">
        <div class="card__title"><h2><?= e(t('reports.operators')) ?></h2></div>
        <?php if ($operators === []): ?>
            <p class="empty"><?= e(t('reports.no_operator')) ?></p>
        <?php else: ?>
            <ul class="top-list">
                <?php foreach ($operators as $i => $op): ?>
                    <li>
                        <span class="top-list__rank"><?= e($i + 1) ?></span>
                        <span class="top-list__name"><strong><?= e($op['operator_name']) ?></strong> <span class="muted"><?= e($op['site']) ?></span></span>
                        <span class="top-list__qty"><?= e(t('reports.breakdowns_n', ['n' => $op['breakdowns']])) ?></span>
                        <?php if ((int) $op['usage_related'] > 0): ?><span class="badge badge--breakdown"><?= e(t('reports.usage_n', ['n' => $op['usage_related']])) ?></span><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p class="hint small"><?= e(t('reports.operators_hint')) ?></p>
    </section>
</div>

<script type="application/json" id="report-data"><?= json_for_html($chart) ?></script>
<script type="application/json" id="report-export"><?= json_for_html($exportPayload) ?></script>
