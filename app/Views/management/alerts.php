<?php
/** @var array $sites  @var int|null $siteId  @var array $wear  @var array $overuse  @var array $contracts  @var int $stockOut  @var int $stockLow */
$q = $siteId ? ['site_id' => $siteId] : [];
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon page-title__icon--alert"><i data-lucide="siren"></i></span>
        <div>
            <h1><?= e(t('nav.alerts')) ?></h1>
            <div class="page-title__meta"><span><?= e(t('alerts.subtitle')) ?></span></div>
        </div>
    </div>
</div>

<nav class="chips" aria-label="<?= e(t('fields.site_id')) ?>">
    <a class="chip-btn" aria-pressed="<?= $siteId === null ? 'true' : 'false' ?>" href="<?= e(url('/alerts')) ?>"><?= e(t('common.all')) ?></a>
    <?php foreach ($sites as $site): ?>
        <?php if ($site['activity'] !== 'office'): ?>
            <a class="chip-btn" aria-pressed="<?= $siteId === (int) $site['id'] ? 'true' : 'false' ?>" href="<?= e(url('/alerts', ['site_id' => $site['id']])) ?>"><?= e($site['name']) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>

<div class="kpis kpis--compact">
    <a class="kpi<?= $wear !== [] ? ' kpi--alert' : '' ?>" href="#wear">
        <div class="kpi__label"><?= e(t('alerts.wear')) ?></div>
        <div class="kpi__value"><?= e(count($wear)) ?></div>
        <div class="kpi__hint"><?= e(t('alerts.wear_hint', ['pct' => (int) (\App\Repositories\AlertRepository::WEAR_RATIO * 100)])) ?></div>
    </a>
    <a class="kpi<?= $overuse !== [] ? ' kpi--alert' : '' ?>" href="#overuse">
        <div class="kpi__label"><?= e(t('alerts.overuse')) ?></div>
        <div class="kpi__value"><?= e(count($overuse)) ?></div>
        <div class="kpi__hint"><?= e(t('alerts.overuse_hint', ['factor' => format_number(\App\Repositories\AlertRepository::OVERUSE_FACTOR, 1)])) ?></div>
    </a>
    <a class="kpi<?= $stockOut + $stockLow > 0 ? ' kpi--warn' : '' ?>" href="<?= e(url('/procurement', $q)) ?>">
        <div class="kpi__label"><?= e(t('alerts.stock')) ?></div>
        <div class="kpi__value"><?= e($stockOut + $stockLow) ?></div>
        <div class="kpi__hint"><?= e(t('alerts.stock_hint', ['out' => $stockOut, 'low' => $stockLow])) ?></div>
    </a>
    <a class="kpi<?= $contracts !== [] ? ' kpi--warn' : '' ?>" href="#contracts">
        <div class="kpi__label"><?= e(t('site.contracts_ending')) ?></div>
        <div class="kpi__value"><?= e(count($contracts)) ?></div>
        <div class="kpi__hint"><?= e(t('site.contracts_hint')) ?></div>
    </a>
</div>

<section class="card" id="wear">
    <div class="card__title">
        <h2><i data-lucide="trending-down" class="title-icon title-icon--bad"></i><?= e(t('alerts.wear')) ?></h2>
        <span class="muted small"><?= e(t('alerts.last_12m')) ?></span>
    </div>
    <?php if ($wear === []): ?>
        <div class="empty-state empty-state--ok"><i data-lucide="shield-check"></i><p><?= e(t('alerts.no_wear')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap"><table class="table table--compact">
            <thead><tr>
                <th><?= e(t('fields.equipment_id')) ?></th><th><?= e(t('fields.part_id')) ?></th><th><?= e(t('alerts.replaced_on')) ?></th>
                <th class="num"><?= e(t('alerts.interval')) ?></th><th class="num"><?= e(t('alerts.expected')) ?></th><th><?= e(t('alerts.life_used')) ?></th><th><?= e(t('fields.failure_cause')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($wear as $w): ?>
                <?php $useKm = $w['interval_hours'] === null || $w['expected_life_hours'] === null; ?>
                <tr>
                    <td><a href="<?= e(url('/equipment/' . $w['equipment_id'])) ?>"><strong><?= e($w['equipment_code']) ?></strong></a><br><span class="muted small"><?= e(trim($w['brand'] . ' ' . $w['model'])) ?> · <?= e($w['site']) ?></span></td>
                    <td><a href="<?= e(url('/parts/' . $w['part_id'])) ?>"><?= e($w['reference']) ?></a><br><span class="muted small"><?= e($w['part_name']) ?></span></td>
                    <td class="nowrap"><?= e(format_date($w['created_at'])) ?><br><a class="small" href="<?= e(url('/work-orders/' . $w['work_order_id'])) ?>"><?= e($w['work_order_ref']) ?></a></td>
                    <td class="num nowrap"><?= $useKm ? e(format_number($w['interval_km'])) . ' km' : e(format_number($w['interval_hours'])) . ' h' ?></td>
                    <td class="num nowrap"><?= $useKm ? e(format_number($w['expected_life_km'])) . ' km' : e(format_number($w['expected_life_hours'])) . ' h' ?></td>
                    <td><div class="life"><span class="life__bar"><span style="width: <?= e(min(100, (int) $w['life_pct'])) ?>%"></span></span><strong><?= e((int) $w['life_pct']) ?> %</strong></div></td>
                    <td><?= $w['failure_cause'] ? '<span class="badge badge--cause badge--cause-' . e($w['failure_cause']) . '">' . e(t('cause.' . $w['failure_cause'])) . '</span>' : '<span class="muted">—</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>

<section class="card" id="overuse">
    <div class="card__title">
        <h2><i data-lucide="droplets" class="title-icon title-icon--warn"></i><?= e(t('alerts.overuse')) ?></h2>
        <span class="muted small"><?= e(t('alerts.last_90d')) ?></span>
    </div>
    <?php if ($overuse === []): ?>
        <div class="empty-state empty-state--ok"><i data-lucide="shield-check"></i><p><?= e(t('alerts.no_overuse')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap"><table class="table table--compact">
            <thead><tr>
                <th><?= e(t('fields.equipment_id')) ?></th><th><?= e(t('fields.part_id')) ?></th><th class="num"><?= e(t('alerts.consumed')) ?></th>
                <th class="num"><?= e(t('alerts.rate')) ?></th><th class="num"><?= e(t('alerts.fleet_rate')) ?></th><th class="num"><?= e(t('alerts.excess')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($overuse as $o): ?>
                <tr>
                    <td><a href="<?= e(url('/equipment/' . $o['equipment_id'])) ?>"><strong><?= e($o['equipment_code']) ?></strong></a><br><span class="muted small"><?= e(trim($o['brand'] . ' ' . $o['model'])) ?> · <?= e($o['site']) ?></span></td>
                    <td><?= e($o['part_name']) ?><br><span class="muted small"><?= e($o['reference']) ?></span></td>
                    <td class="num nowrap"><?= e(format_qty($o['quantity'])) ?> <?= e($o['unit']) ?><br><span class="muted small"><?= e(format_number($o['hours'])) ?> h</span></td>
                    <td class="num nowrap"><strong><?= e(format_number($o['rate'], 1)) ?></strong> <span class="muted small"><?= e($o['unit']) ?>/100 h</span></td>
                    <td class="num nowrap"><?= e(format_number($o['fleet_rate'], 1)) ?> <span class="muted small">(<?= e(t('alerts.peers', ['n' => $o['peers']])) ?>)</span></td>
                    <td class="num"><span class="badge badge--breakdown">+<?= e($o['excess_pct']) ?> %</span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
    <p class="hint small"><?= e(t('alerts.overuse_rule')) ?></p>
</section>

<section class="card" id="contracts">
    <div class="card__title"><h2><i data-lucide="file-clock" class="title-icon"></i><?= e(t('site.contracts_ending')) ?></h2></div>
    <?php if ($contracts === []): ?>
        <p class="empty"><?= e(t('common.none')) ?></p>
    <?php else: ?>
        <ul class="wo-list">
            <?php foreach ($contracts as $ct): ?>
                <li><a class="wo-list__item<?= (int) $ct['days_left'] < 0 ? ' wo-list__item--critical' : ' wo-list__item--high' ?>" href="<?= e(url('/equipment/' . $ct['id'])) ?>">
                    <span class="wo-list__top"><strong><?= e($ct['code']) ?></strong><span class="badge badge--<?= (int) $ct['days_left'] < 0 ? 'breakdown' : 'waiting_part' ?>"><?= e((int) $ct['days_left'] < 0 ? t('equipment.contract_expired', ['days' => -(int) $ct['days_left']]) : t('equipment.contract_days_left', ['days' => (int) $ct['days_left']])) ?></span></span>
                    <span class="wo-list__meta"><?= e(t('ownership.' . $ct['ownership'])) ?> · <?= e($ct['contract_ref'] ?? '—') ?> · <?= e($ct['contract_party'] ?? '') ?> · <?= e($ct['site']) ?> · <?= e(format_date($ct['contract_end'])) ?></span>
                </a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
