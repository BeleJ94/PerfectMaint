<?php
/** @var array $sites  @var array $summary  @var array $bySite  @var array $top  @var array $filters */
$maxSite = $bySite === [] ? 0 : max(array_map(static fn ($s) => (float) $s['value'], $bySite));
$typeIcons = ['part' => 'cog', 'tyre' => 'circle-dot', 'lubricant' => 'droplet', 'filter' => 'filter', 'consumable' => 'box', 'fuel' => 'fuel'];
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="shopping-cart"></i></span>
        <div>
            <h1><?= e(t('nav.procurement')) ?></h1>
            <div class="page-title__meta"><span><?= e(t('procurement.subtitle')) ?></span></div>
        </div>
    </div>
    <div class="page-header__actions">
        <?= $view->partial('partials/export_buttons', ['table' => 'procurement', 'exportTitle' => t('nav.procurement')]) ?>
    </div>
</div>

<div class="kpis kpis--compact">
    <button type="button" class="kpi kpi--button<?= (int) $summary['out_count'] > 0 ? ' kpi--alert' : '' ?>" data-set-filter="level" data-value="out">
        <div class="kpi__label"><?= e(t('level.out')) ?></div>
        <div class="kpi__value"><?= e((int) $summary['out_count']) ?></div>
        <div class="kpi__hint"><?= e(t('procurement.out_hint')) ?></div>
    </button>
    <button type="button" class="kpi kpi--button<?= (int) $summary['low_count'] > 0 ? ' kpi--warn' : '' ?>" data-set-filter="level" data-value="low">
        <div class="kpi__label"><?= e(t('level.low')) ?></div>
        <div class="kpi__value"><?= e((int) $summary['low_count']) ?></div>
        <div class="kpi__hint"><?= e(t('procurement.low_hint')) ?></div>
    </button>
    <button type="button" class="kpi kpi--button" data-set-filter="level" data-value="watch">
        <div class="kpi__label"><?= e(t('level.watch')) ?></div>
        <div class="kpi__value"><?= e((int) $summary['watch_count']) ?></div>
        <div class="kpi__hint"><?= e(t('procurement.watch_hint')) ?></div>
    </button>
    <div class="kpi kpi--hero">
        <div class="kpi__label"><?= e(t('procurement.order_value')) ?></div>
        <div class="kpi__value"><?= e(format_money($summary['order_value'])) ?></div>
        <div class="kpi__hint"><?= e(t('procurement.order_hint')) ?></div>
    </div>
</div>

<div class="grid grid--2">
    <section class="card">
        <div class="card__title"><h2><?= e(t('procurement.by_site')) ?></h2><span class="muted small"><?= e(t('procurement.last_30')) ?></span></div>
        <?php if ($bySite === []): ?>
            <p class="empty"><?= e(t('procurement.no_consumption')) ?></p>
        <?php else: ?>
            <ul class="bars">
                <?php foreach ($bySite as $s): ?>
                    <li><span class="bars__label"><?= e($s['site']) ?></span><span class="bars__track"><span style="width: <?= e(round((float) $s['value'] / max($maxSite, 0.01) * 100)) ?>%"></span></span><span class="bars__value"><?= e(format_money($s['value'])) ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <section class="card">
        <div class="card__title"><h2><?= e(t('procurement.top')) ?></h2><span class="muted small"><?= e(t('procurement.last_30')) ?></span></div>
        <?php if ($top === []): ?>
            <p class="empty"><?= e(t('procurement.no_consumption')) ?></p>
        <?php else: ?>
            <ul class="top-list">
                <?php foreach ($top as $i => $p): ?>
                    <li>
                        <span class="top-list__rank"><?= e($i + 1) ?></span>
                        <i data-lucide="<?= e($typeIcons[$p['part_type']] ?? 'package') ?>"></i>
                        <a href="<?= e(url('/parts/' . $p['id'])) ?>" class="top-list__name"><strong><?= e($p['reference']) ?></strong> <span class="muted"><?= e($p['name']) ?></span></a>
                        <span class="top-list__qty"><?= e(format_qty($p['quantity'])) ?> <?= e($p['unit']) ?></span>
                        <span class="top-list__value"><?= e(format_money($p['value'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<section class="card">
    <form class="filters" data-filters-for="procurement">
        <?= $view->partial('partials/site_filter', ['sites' => $sites, 'selected' => $filters['site_id']]) ?>
        <div class="field">
            <label for="filter-type"><?= e(t('fields.part_type')) ?></label>
            <select id="filter-type" name="part_type" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach (\App\Services\PartService::TYPES as $type): ?>
                    <option value="<?= e($type) ?>"<?= $filters['part_type'] === $type ? ' selected' : '' ?>><?= e(t('part_type.' . $type)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="filter-level"><?= e(t('procurement.level')) ?></label>
            <select id="filter-level" name="level" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <option value="action"<?= $filters['level'] === 'action' ? ' selected' : '' ?>><?= e(t('procurement.to_act')) ?></option>
                <?php foreach (\App\Repositories\ProcurementRepository::LEVELS as $level): ?>
                    <option value="<?= e($level) ?>"<?= $filters['level'] === $level ? ' selected' : '' ?>><?= e(t('level.' . $level)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
    <div class="table-wrap">
        <table class="display" style="width:100%" data-table="procurement" data-source="<?= e(url('/procurement/data')) ?>" data-row-link="<?= e(url('/parts/{id}')) ?>"></table>
    </div>
    <p class="hint small"><?= e(t('procurement.rule')) ?></p>
</section>
