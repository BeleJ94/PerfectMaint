<?php
/**
 * Fenêtre site.
 * @var array $site  @var array<string,int> $counts  @var int $total  @var float|null $availability
 * @var array $fleet  @var array $workOrders  @var array $woKpis  @var int $preventiveDue  @var int $partsLow  @var array $contracts  @var array $teams
 */
$siteQuery = ['site_id' => $site['id']];
$activityIcons = ['office' => 'building-2', 'earthmoving' => 'mountain', 'transport' => 'bus'];
$groups = [];
foreach ($fleet as $row) {
    $groups[$row['category'] ?? t('site.uncategorized')][] = $row;
}
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
$stockChips = [
    ['label' => t('procurement.to_act'), 'params' => ['level' => 'action']],
    ['label' => t('level.out'), 'params' => ['level' => 'out']],
    ['label' => t('level.low'), 'params' => ['level' => 'low']],
    ['label' => t('level.watch'), 'params' => ['level' => 'watch']],
];
$availClass = $availability === null ? '' : ($availability >= 85 ? 'good' : ($availability >= 70 ? 'warn' : 'bad'));
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="<?= e($activityIcons[$site['activity']] ?? 'map-pin') ?>"></i></span>
        <div>
            <h1><?= e($site['name']) ?></h1>
            <div class="page-title__meta">
                <span class="chip"><?= e(t('site_activity.' . $site['activity'])) ?></span>
                <?php if ($site['city']): ?><span><i data-lucide="map-pin"></i><?= e($site['city']) ?></span><?php endif; ?>
                <span><?= e(t('site.fleet_count', ['count' => $total])) ?></span>
            </div>
        </div>
    </div>
    <div class="page-header__actions">
        <?php if ($currentUser->can('workorder.create')): ?>
            <a class="btn btn--danger" href="<?= e(url('/terrain/declarer')) ?>"><i data-lucide="alert-octagon"></i><?= e(t('equipment.declare_breakdown')) ?></a>
        <?php endif; ?>
        <?php if ($currentUser->can('equipment.manage')): ?>
            <a class="btn btn--primary" href="<?= e(url('/equipment/create', $siteQuery)) ?>"><i data-lucide="plus"></i><?= e(t('equipment.new')) ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="kpis kpis--compact">
    <button type="button" class="kpi kpi--button kpi--hero"<?= drill('equipment', '/equipment/data', $site['name'] . ' · ' . t('site.fleet'), $siteQuery, '/equipment', '/equipment/{id}', $eqChips) ?>>
        <div class="kpi__label"><?= e(t('dashboard.availability')) ?></div>
        <div class="kpi__value kpi__value--<?= e($availClass) ?>"><?= $availability === null ? '–' : e(format_number($availability, 1)) . '<small>%</small>' ?></div>
        <div class="meter meter--<?= e($availClass) ?>"><span style="width: <?= e((string) ($availability ?? 0)) ?>%"></span></div>
    </button>
    <button type="button" class="kpi kpi--button<?= $counts['breakdown'] > 0 ? ' kpi--alert' : '' ?>"<?= drill('equipment', '/equipment/data', $site['name'] . ' · ' . t('dashboard.breakdowns'), $siteQuery + ['status' => 'breakdown'], '/equipment', '/equipment/{id}') ?>>
        <div class="kpi__label"><?= e(t('dashboard.breakdowns')) ?></div>
        <div class="kpi__value"><?= e($counts['breakdown']) ?></div>
        <div class="kpi__hint"><?= e(t('dashboard.equipment_down')) ?></div>
    </button>
    <button type="button" class="kpi kpi--button"<?= drill('equipment', '/equipment/data', $site['name'] . ' · ' . t('status.maintenance'), $siteQuery, '/equipment', '/equipment/{id}', [['label' => t('status.maintenance'), 'params' => ['status' => 'maintenance']], ['label' => t('status.waiting_part'), 'params' => ['status' => 'waiting_part']]]) ?>>
        <div class="kpi__label"><?= e(t('status.maintenance')) ?></div>
        <div class="kpi__value"><?= e($counts['maintenance'] + $counts['waiting_part']) ?></div>
        <div class="kpi__hint"><?= e(t('site.waiting_part_count', ['count' => $counts['waiting_part']])) ?></div>
    </button>
    <button type="button" class="kpi kpi--button"<?= drill('work_orders', '/work-orders/data', $site['name'] . ' · ' . t('dashboard.open_work_orders'), $siteQuery + ['status' => 'active'], '/work-orders', '/work-orders/{id}', $woChips) ?>>
        <div class="kpi__label"><?= e(t('dashboard.open_work_orders')) ?></div>
        <div class="kpi__value"><?= e($woKpis['open']) ?></div>
        <div class="kpi__hint"><?= e(t('dashboard.critical_count', ['count' => $woKpis['critical']])) ?></div>
    </button>
    <button type="button" class="kpi kpi--button<?= $preventiveDue > 0 ? ' kpi--warn' : '' ?>"<?= drill('preventive', '/preventive/data', $site['name'] . ' · ' . t('dashboard.preventive_due'), $siteQuery + ['due' => '1'], '/preventive', $currentUser->can('preventive.manage') ? '/preventive/{id}/edit' : null) ?>>
        <div class="kpi__label"><?= e(t('dashboard.preventive_due')) ?></div>
        <div class="kpi__value"><?= e($preventiveDue) ?></div>
        <div class="kpi__hint"><?= e(t('dashboard.preventive_due_hint')) ?></div>
    </button>
    <button type="button" class="kpi kpi--button<?= $contracts !== [] ? ' kpi--warn' : '' ?>"<?= drill('equipment', '/equipment/data', $site['name'] . ' · ' . t('site.contracts_ending'), $siteQuery + ['contract_days' => 30], '/equipment', '/equipment/{id}') ?>>
        <div class="kpi__label"><?= e(t('site.contracts_ending')) ?></div>
        <div class="kpi__value"><?= e(count($contracts)) ?></div>
        <div class="kpi__hint"><?= $contracts !== [] ? e($contracts[0]['code'] . ' · ' . format_date($contracts[0]['contract_end'])) : e(t('site.contracts_hint')) ?></div>
    </button>
    <button type="button" class="kpi kpi--button<?= $partsLow > 0 ? ' kpi--alert' : '' ?>"<?= drill('procurement', '/procurement/data', $site['name'] . ' · ' . t('dashboard.parts_low'), $siteQuery + ['level' => 'action'], '/procurement', '/parts/{id}', $stockChips) ?>>
        <div class="kpi__label"><?= e(t('dashboard.parts_low')) ?></div>
        <div class="kpi__value"><?= e($partsLow) ?></div>
        <div class="kpi__hint"><?= e(t('dashboard.parts_low_hint')) ?></div>
    </button>
</div>

<?php if ($total > 0): ?>
<div class="status-bar" role="img" aria-label="<?= e(t('site.status_split')) ?>">
    <?php foreach ($counts as $status => $n): ?>
        <?php if ($n > 0): ?><button type="button" class="status-bar__seg status-bar__seg--<?= e($status) ?>" style="flex: <?= e($n) ?>" title="<?= e(t('status.' . $status)) ?> : <?= e($n) ?>" aria-label="<?= e(t('status.' . $status)) ?> : <?= e($n) ?>"<?= drill('equipment', '/equipment/data', $site['name'] . ' · ' . t('status.' . $status), $siteQuery + ['status' => $status], '/equipment', '/equipment/{id}') ?>></button><?php endif; ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="grid grid--main-side">
    <section class="card" data-fleet>
        <div class="card__title">
            <h2><?= e(t('site.fleet')) ?></h2>
            <div class="search-inline">
                <i data-lucide="search"></i>
                <input type="search" class="input input--sm" placeholder="<?= e(t('site.search_fleet')) ?>" data-fleet-search aria-label="<?= e(t('site.search_fleet')) ?>">
            </div>
        </div>
        <div class="chips" role="group" aria-label="<?= e(t('fields.status')) ?>">
            <button type="button" class="chip-btn" aria-pressed="true" data-fleet-status=""><?= e(t('common.all')) ?> <b><?= e($total) ?></b></button>
            <?php foreach ($counts as $status => $n): ?>
                <?php if ($n > 0): ?>
                    <button type="button" class="chip-btn chip-btn--<?= e($status) ?>" aria-pressed="false" data-fleet-status="<?= e($status) ?>"><?= e(t('status.' . $status)) ?> <b><?= e($n) ?></b></button>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <?php if ($fleet === []): ?>
            <div class="empty-state">
                <i data-lucide="truck"></i>
                <p><?= e(t('site.no_fleet')) ?></p>
                <?php if ($currentUser->can('equipment.manage')): ?>
                    <a class="btn btn--primary btn--sm" href="<?= e(url('/equipment/create', $siteQuery)) ?>"><i data-lucide="plus"></i><?= e(t('equipment.new')) ?></a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php foreach ($groups as $category => $rows): ?>
                <div class="fleet-group" data-fleet-group>
                    <h3 class="fleet-group__title"><?= e($category) ?> <span class="muted" data-fleet-group-count><?= e(count($rows)) ?></span></h3>
                    <div class="fleet-grid">
                        <?php foreach ($rows as $eq): ?>
                            <a class="fleet-tile fleet-tile--<?= e($eq['status']) ?>" href="<?= e(url('/equipment/' . $eq['id'])) ?>"
                               data-fleet-item data-status="<?= e($eq['status']) ?>"
                               data-search="<?= e(mb_strtolower($eq['code'] . ' ' . $eq['name'] . ' ' . $eq['brand'] . ' ' . $eq['model'] . ' ' . $eq['plate_number'])) ?>">
                                <?php if ($eq['photo_id']): ?><img class="fleet-tile__thumb" src="<?= e(url('/equipment/' . $eq['id'] . '/photos/' . $eq['photo_id'])) ?>" alt="" loading="lazy"><?php endif; ?>
                                <span class="fleet-tile__head">
                                    <strong><?= e($eq['code']) ?></strong>
                                    <?php if ((int) $eq['open_work_orders'] > 0): ?>
                                        <span class="count-pill" title="<?= e(t('js.open_wo')) ?>"><i data-lucide="wrench"></i><?= e($eq['open_work_orders']) ?></span>
                                    <?php endif; ?>
                                </span>
                                <span class="fleet-tile__name"><?= e(trim(($eq['brand'] ?? '') . ' ' . ($eq['model'] ?? '')) ?: $eq['name']) ?><?= $eq['plate_number'] ? ' · ' . e($eq['plate_number']) : '' ?></span>
                                <span class="fleet-tile__foot">
                                    <span class="dot dot--<?= e($eq['status']) ?>"></span><?= e(t('status.' . $eq['status'])) ?>
                                    <?php if ($eq['hour_meter'] !== null): ?><span class="fleet-tile__meter"><?= e(format_number($eq['hour_meter'])) ?> h</span><?php endif; ?>
                                </span>
                                <?php if ($eq['fuel_level'] !== null): ?><span class="fleet-tile__fuel fleet-tile__fuel--<?= (int) $eq['fuel_level'] <= 15 ? 'bad' : ((int) $eq['fuel_level'] <= 35 ? 'warn' : 'good') ?>" title="<?= e(t('fields.fuel_level')) ?> : <?= e($eq['fuel_level']) ?> %"><span style="width: <?= e($eq['fuel_level']) ?>%"></span></span><?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <p class="empty" data-fleet-empty hidden><?= e(t('js.no_result')) ?></p>
        <?php endif; ?>
    </section>

    <div>
    <section class="card">
        <div class="card__title">
            <h2><?= e(t('site.ongoing')) ?></h2>
            <a href="<?= e(url('/work-orders', $siteQuery + ['status' => 'active'])) ?>"><?= e(t('common.see_all')) ?></a>
        </div>
        <?php if ($workOrders === []): ?>
            <div class="empty-state empty-state--ok">
                <i data-lucide="check-circle-2"></i>
                <p><?= e(t('site.no_ongoing')) ?></p>
            </div>
        <?php else: ?>
            <ul class="wo-list">
                <?php foreach ($workOrders as $wo): ?>
                    <li>
                        <a href="<?= e(url('/work-orders/' . $wo['id'])) ?>" class="wo-list__item wo-list__item--<?= e($wo['priority']) ?>">
                            <span class="wo-list__top">
                                <strong><?= e($wo['equipment_code']) ?></strong>
                                <?= wo_status_badge($wo['status']) ?>
                            </span>
                            <span class="wo-list__title"><?= e($wo['title']) ?></span>
                            <span class="wo-list__meta">
                                <?= e($wo['reference']) ?>
                                <?php if ($wo['assignee']): ?> · <?= e($wo['assignee']) ?><?php endif; ?>
                                <?php if ((int) $wo['overdue']): ?> · <span class="overdue"><?= e(t('site.overdue')) ?></span><?php endif; ?>
                                <?php if (in_array($wo['priority'], ['critical', 'high'], true)): ?> <?= priority_badge($wo['priority']) ?><?php endif; ?>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="card__title">
            <h2><?= e(t('site.teams')) ?></h2>
            <a href="<?= e(url('/teams', $siteQuery)) ?>"><?= e(t('common.see_all')) ?></a>
        </div>
        <?php if ($teams === []): ?>
            <p class="empty"><?= e(t('teams.none')) ?></p>
        <?php else: ?>
            <ul class="team-mini">
                <?php foreach ($teams as $team): ?>
                    <li>
                        <span class="shift-badge shift-badge--<?= e($team['shift']) ?>"><i data-lucide="<?= e(['day' => 'sun', 'night' => 'moon', 'rotation' => 'repeat'][$team['shift']]) ?>"></i></span>
                        <span class="team-mini__name"><strong><?= e($team['name']) ?></strong><small><?= e($team['leader'] ?? '—') ?> · <?= e(t('teams.member_count', ['count' => $team['member_count']])) ?></small></span>
                        <?php if ($team['dispatch_phone']): ?>
                            <a class="phone-btn phone-btn--strong" href="tel:<?= e(preg_replace('/[^\d+]/', '', $team['dispatch_phone'])) ?>" title="<?= e(t('teams.dispatch')) ?>"><i data-lucide="radio"></i><?= e($team['dispatch_phone']) ?></a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    </div>
</div>
