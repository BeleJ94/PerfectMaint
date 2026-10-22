<?php
/**
 * Référentiel : pilotage de ce qui reste à compléter, puis onglets Modèles / Marques / Catégories / Indicateurs.
 * Le tableau des modèles réunit tout ce qu'un modèle transmet à ses engins (catégorie, compteur, indicateur, réservoir)
 * et s'édite en ligne ; le filtre « À compléter » isole les modèles utilisés sans indicateur ou sans capacité.
 * @var array $brands  @var array $models  @var array $categories  @var array $gauges  @var array $completeness
 */
$view->share('pageScripts', ['js/catalog.js']);
$activeBrands = array_values(array_filter($brands, static fn (array $b) => (int) $b['is_active'] === 1));
$activeGauges = array_values(array_filter($gauges, static fn (array $g) => (int) $g['is_active'] === 1));
$basisByCategory = array_column($categories, 'meter_basis', 'id');
$c = $completeness;
$modelsUsed = max(1, $c['models_used']);
$tabs = [
    'models' => ['catalog.tab_models', 'boxes', count($models)],
    'brands' => ['catalog.brands', 'tag', count($brands)],
    'categories' => ['catalog.tab_categories', 'layers', count($categories)],
    'gauges' => ['catalog.gauges', 'fuel', count($gauges)],
];
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="tags"></i></span>
        <div>
            <h1><?= e(t('nav.catalog')) ?></h1>
            <div class="page-title__meta"><span><?= e(t('catalog.subtitle', ['brands' => count($brands), 'models' => count($models)])) ?></span></div>
        </div>
    </div>
</div>

<?php /* Pilotage : ce qui empêche le suivi d'être complet, chaque indicateur ouvre la liste à traiter. */ ?>
<div class="kpis kpis--compact catalog-kpis">
    <a class="kpi<?= $c['without_model'] > 0 ? ' kpi--warn' : '' ?>" href="<?= e(url('/equipment')) ?>">
        <div class="kpi__label"><?= e(t('catalog.kpi_without_model')) ?></div>
        <div class="kpi__value"><?= e($c['without_model']) ?><small>/ <?= e($c['equipment']) ?></small></div>
        <div class="kpi__hint"><?= e(t('catalog.kpi_without_model_hint')) ?></div>
    </a>
    <button type="button" class="kpi kpi--button<?= $c['models_incomplete'] > 0 ? ' kpi--warn' : '' ?>" data-catalog-goto="models" data-catalog-filter="incomplete">
        <div class="kpi__label"><?= e(t('catalog.kpi_fuel_missing')) ?></div>
        <div class="kpi__value"><?= e($c['models_incomplete']) ?><small>/ <?= e($c['models_used']) ?></small></div>
        <div class="kpi__hint"><?= e(t('catalog.kpi_fuel_missing_hint')) ?></div>
    </button>
    <div class="kpi">
        <div class="kpi__label"><?= e(t('catalog.kpi_fuel_ready')) ?></div>
        <div class="kpi__value"><?= e((int) round(($c['models_used'] - $c['models_incomplete']) / $modelsUsed * 100)) ?><small> %</small></div>
        <div class="meter meter--good"><span style="width: <?= e((int) round(($c['models_used'] - $c['models_incomplete']) / $modelsUsed * 100)) ?>%"></span></div>
    </div>
    <button type="button" class="kpi kpi--button" data-catalog-goto="gauges">
        <div class="kpi__label"><?= e(t('catalog.gauges')) ?></div>
        <div class="kpi__value"><?= e(count($activeGauges)) ?></div>
        <div class="kpi__hint"><?= e(t('catalog.kpi_gauges_hint')) ?></div>
    </button>
</div>

<nav class="catalog-tabs" role="tablist" aria-label="<?= e(t('nav.catalog')) ?>">
    <?php foreach ($tabs as $key => [$label, $icon, $count]): ?>
        <button type="button" role="tab" id="tab-<?= e($key) ?>" aria-controls="panel-<?= e($key) ?>" aria-selected="<?= $key === 'models' ? 'true' : 'false' ?>" data-catalog-tab="<?= e($key) ?>">
            <i data-lucide="<?= e($icon) ?>"></i><span><?= e(t($label)) ?></span><small><?= e($count) ?></small>
        </button>
    <?php endforeach; ?>
</nav>

<?php /* ------------------------------------------------------------ Modèles */ ?>
<section class="card catalog-panel" role="tabpanel" id="panel-models" aria-labelledby="tab-models" data-catalog-panel="models">
    <div class="card__title">
        <h2><?= e(t('catalog.tab_models')) ?></h2>
        <div class="catalog-filters">
            <select class="input input--sm" data-models-brand aria-label="<?= e(t('fields.brand_id')) ?>">
                <option value=""><?= e(t('catalog.all_brands')) ?></option>
                <?php foreach ($brands as $brand): ?><option value="<?= e($brand['id']) ?>"><?= e($brand['name']) ?></option><?php endforeach; ?>
            </select>
            <label class="check small"><input type="checkbox" data-models-incomplete> <?= e(t('catalog.only_incomplete')) ?></label>
            <div class="search-inline">
                <i data-lucide="search"></i>
                <input type="search" class="input input--sm" placeholder="<?= e(t('common.search')) ?>" data-models-search aria-label="<?= e(t('common.search')) ?>">
            </div>
        </div>
    </div>

    <form method="post" action="<?= e(url('/catalog/models')) ?>" class="catalog-add" data-confirm-title="<?= e(t('catalog.new_model')) ?>">
        <?= csrf_field() ?>
        <span class="catalog-add__label"><i data-lucide="plus-circle"></i><?= e(t('catalog.new_model')) ?></span>
        <select name="brand_id" class="input input--sm" required aria-label="<?= e(t('fields.brand_id')) ?>">
            <option value=""><?= e(t('fields.brand_id')) ?>…</option>
            <?php foreach ($activeBrands as $brand): ?><option value="<?= e($brand['id']) ?>"><?= e($brand['name']) ?></option><?php endforeach; ?>
        </select>
        <input name="name" class="input input--sm" required maxlength="80" placeholder="<?= e(t('catalog.model_placeholder')) ?>" aria-label="<?= e(t('fields.model_id')) ?>">
        <select name="category_id" class="input input--sm" aria-label="<?= e(t('fields.category_id')) ?>">
            <option value=""><?= e(t('fields.category_id')) ?>…</option>
            <?php foreach ($categories as $category): ?><option value="<?= e($category['id']) ?>"><?= e($category['name']) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn--primary btn--sm"><?= e(t('common.add')) ?></button>
    </form>

    <div class="table-wrap">
        <table class="table table--compact catalog-models" data-no-dt data-models-table>
            <thead><tr>
                <th><?= e(t('fields.model_id')) ?></th>
                <th><?= e(t('fields.category_id')) ?></th>
                <th class="num"><?= e(t('nav.equipment')) ?></th>
                <th><?= e(t('catalog.gauge')) ?></th>
                <th><?= e(t('catalog.tank_short')) ?></th>
                <th class="num"><?= e(t('catalog.active')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($models as $model): ?>
                <?php
                $formId = 'model-fuel-' . $model['id'];
                $used = (int) $model['equipment_count'] > 0;
                $incomplete = $used && ($model['fuel_gauge_id'] === null || $model['tank_capacity_l'] === null);
                $basis = $model['category_id'] !== null ? ($basisByCategory[$model['category_id']] ?? null) : null;
                ?>
                <tr class="<?= (int) $model['is_active'] ? '' : 'is-muted' ?><?= $incomplete ? ' is-incomplete' : '' ?>" data-model-row
                    data-brand="<?= e($model['brand_id']) ?>" data-incomplete="<?= $incomplete ? '1' : '0' ?>"
                    data-search="<?= e(mb_strtolower($model['brand'] . ' ' . $model['name'] . ' ' . ($model['category'] ?? ''))) ?>">
                    <td>
                        <strong><?= e($model['brand']) ?> <?= e($model['name']) ?></strong>
                        <?php if ($incomplete): ?><br><span class="meter-issue"><i data-lucide="alert-triangle"></i><?= e(t('catalog.fuel_to_complete')) ?></span><?php endif; ?>
                    </td>
                    <td>
                        <?= e($model['category'] ?? '—') ?>
                        <?php if ($basis): ?><br><span class="muted small"><?= e($basis === 'km' ? t('meter_basis.km') : t('meter_basis.hours')) ?></span><?php endif; ?>
                    </td>
                    <td class="num"><?= $used ? '<strong>' . e($model['equipment_count']) . '</strong>' : '<span class="muted">0</span>' ?></td>
                    <td>
                        <select name="fuel_gauge_id" form="<?= e($formId) ?>" class="input input--sm" data-dirty-watch aria-label="<?= e(t('catalog.gauge')) ?>">
                            <option value=""><?= e(t('catalog.gauge_none')) ?></option>
                            <?php foreach ($gauges as $gauge): ?>
                                <?php if ((int) $gauge['is_active'] || (int) $gauge['id'] === (int) $model['fuel_gauge_id']): ?>
                                    <option value="<?= e($gauge['id']) ?>"<?= (int) $gauge['id'] === (int) $model['fuel_gauge_id'] ? ' selected' : '' ?>><?= e($gauge['name']) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <div class="catalog-tank">
                            <input type="number" name="tank_capacity_l" form="<?= e($formId) ?>" class="input input--sm input--num" min="1" max="20000" value="<?= e($model['tank_capacity_l']) ?>" placeholder="—" data-dirty-watch aria-label="<?= e(t('fields.tank_capacity_l')) ?>">
                            <span class="muted small">L</span>
                            <form method="post" action="<?= e(url('/catalog/models/' . $model['id'] . '/fuel')) ?>" id="<?= e($formId) ?>" data-confirm-title="<?= e(t('catalog.confirm_model_fuel', ['model' => $model['brand'] . ' ' . $model['name']])) ?>" data-confirm="<?= e(t('catalog.confirm_model_fuel_lead', ['n' => $model['equipment_count']])) ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn--sm btn--primary" data-dirty-save hidden><i data-lucide="check"></i><?= e(t('common.save')) ?></button>
                            </form>
                        </div>
                    </td>
                    <td class="num">
                        <form method="post" action="<?= e(url('/catalog/models/' . $model['id'])) ?>" data-confirm-title="<?= e(t((int) $model['is_active'] ? 'catalog.deactivate' : 'catalog.activate') . ' · ' . $model['brand'] . ' ' . $model['name']) ?>" data-confirm="<?= e(t((int) $model['is_active'] ? 'catalog.confirm_model_off' : 'catalog.confirm_model_on', ['n' => $model['equipment_count']])) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="name" value="<?= e($model['name']) ?>">
                            <input type="hidden" name="category_id" value="<?= e($model['category_id']) ?>">
                            <input type="hidden" name="is_active" value="<?= (int) $model['is_active'] ? '0' : '1' ?>">
                            <button type="submit" class="switch" role="switch" aria-checked="<?= (int) $model['is_active'] ? 'true' : 'false' ?>" title="<?= e(t((int) $model['is_active'] ? 'catalog.deactivate' : 'catalog.activate')) ?>"><span></span></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="empty" data-models-empty<?= $models === [] ? '' : ' hidden' ?>><?= e($models === [] ? t('catalog.no_models') : t('js.no_result')) ?></p>
</section>

<?php /* ------------------------------------------------------------ Marques */ ?>
<section class="card catalog-panel" role="tabpanel" id="panel-brands" aria-labelledby="tab-brands" data-catalog-panel="brands" hidden>
    <div class="card__title"><h2><?= e(t('catalog.brands')) ?></h2></div>
    <form method="post" action="<?= e(url('/catalog/brands')) ?>" class="catalog-add" data-confirm-title="<?= e(t('catalog.new_brand')) ?>">
        <?= csrf_field() ?>
        <span class="catalog-add__label"><i data-lucide="plus-circle"></i><?= e(t('catalog.new_brand')) ?></span>
        <input name="name" class="input input--sm" required maxlength="60" placeholder="<?= e(t('catalog.new_brand')) ?>" aria-label="<?= e(t('catalog.new_brand')) ?>">
        <button type="submit" class="btn btn--primary btn--sm"><?= e(t('common.add')) ?></button>
    </form>
    <ul class="list-rows catalog-brands">
        <?php foreach ($brands as $brand): ?>
            <li class="<?= (int) $brand['is_active'] ? '' : 'is-muted' ?>">
                <button type="button" class="link-btn" data-catalog-goto="models" data-catalog-brand="<?= e($brand['id']) ?>" title="<?= e(t('catalog.show_models')) ?>"><strong><?= e($brand['name']) ?></strong></button>
                <span class="muted small"><?= e(t('catalog.brand_counts', ['models' => $brand['model_count'], 'equipment' => $brand['equipment_count']])) ?></span>
                <form method="post" action="<?= e(url('/catalog/brands/' . $brand['id'])) ?>" class="list-rows__action" data-confirm-title="<?= e(t((int) $brand['is_active'] ? 'catalog.deactivate' : 'catalog.activate') . ' · ' . $brand['name']) ?>" data-confirm="<?= e(t((int) $brand['is_active'] ? 'catalog.confirm_brand_off' : 'catalog.confirm_brand_on', ['n' => $brand['equipment_count']])) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="name" value="<?= e($brand['name']) ?>">
                    <input type="hidden" name="is_active" value="<?= (int) $brand['is_active'] ? '0' : '1' ?>">
                    <button type="submit" class="switch" role="switch" aria-checked="<?= (int) $brand['is_active'] ? 'true' : 'false' ?>" title="<?= e(t((int) $brand['is_active'] ? 'catalog.deactivate' : 'catalog.activate')) ?>"><span></span></button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>
</section>

<?php /* ------------------------------------------------------------ Catégories : compteur de suivi (enregistré dès le choix) */ ?>
<section class="card catalog-panel" role="tabpanel" id="panel-categories" aria-labelledby="tab-categories" data-catalog-panel="categories" hidden>
    <div class="card__title"><h2><?= e(t('catalog.categories')) ?></h2></div>
    <p class="muted small"><?= e(t('catalog.categories_hint')) ?></p>
    <div class="table-wrap">
        <table class="table table--compact" data-no-dt>
            <thead><tr><th><?= e(t('fields.category_id')) ?></th><th class="num"><?= e(t('nav.equipment')) ?></th><th><?= e(t('fields.meter_basis')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($categories as $category): ?>
                <tr>
                    <td><strong><?= e($category['name']) ?></strong></td>
                    <td class="num"><?= e($category['equipment_count']) ?></td>
                    <td>
                        <form method="post" action="<?= e(url('/catalog/categories/' . $category['id'])) ?>" class="segmented segmented--sm" aria-label="<?= e(t('fields.meter_basis')) ?>" data-autosubmit-form data-confirm-title="<?= e(t('catalog.confirm_category', ['category' => $category['name']])) ?>" data-confirm-kind="apply" data-confirm="<?= e(t('catalog.confirm_category_lead', ['n' => $category['equipment_count']])) ?>">
                            <?= csrf_field() ?>
                            <?php foreach (['hours', 'km'] as $basis): ?>
                                <label><input type="radio" name="meter_basis" value="<?= e($basis) ?>"<?= $category['meter_basis'] === $basis ? ' checked' : '' ?>><span><?= e(t('meter_basis.' . $basis)) ?></span></label>
                            <?php endforeach; ?>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php /* ------------------------------------------------------------ Indicateurs de carburant */ ?>
<section class="card catalog-panel" role="tabpanel" id="panel-gauges" aria-labelledby="tab-gauges" data-catalog-panel="gauges" hidden>
    <div class="card__title"><h2><?= e(t('catalog.gauges')) ?></h2></div>
    <p class="muted small"><?= e(t('catalog.gauges_hint')) ?></p>
    <form method="post" action="<?= e(url('/catalog/gauges')) ?>" class="catalog-add" data-confirm-title="<?= e(t('catalog.new_gauge')) ?>">
        <?= csrf_field() ?>
        <span class="catalog-add__label"><i data-lucide="plus-circle"></i><?= e(t('catalog.new_gauge')) ?></span>
        <input name="name" class="input input--sm" required maxlength="80" placeholder="<?= e(t('catalog.gauge_name')) ?>" aria-label="<?= e(t('catalog.gauge_name')) ?>">
        <select name="kind" class="input input--sm" data-gauge-kind aria-label="<?= e(t('catalog.gauge_kind')) ?>">
            <option value="marks"><?= e(t('fuel_kind.marks')) ?></option>
            <option value="percent"><?= e(t('fuel_kind.percent')) ?></option>
        </select>
        <input name="marks" type="number" min="1" max="20" class="input input--sm input--num" placeholder="<?= e(t('catalog.gauge_marks')) ?>" data-gauge-marks aria-label="<?= e(t('catalog.gauge_marks')) ?>">
        <button type="submit" class="btn btn--primary btn--sm"><?= e(t('common.add')) ?></button>
    </form>
    <ul class="list-rows">
        <?php foreach ($gauges as $gauge): ?>
            <li class="<?= (int) $gauge['is_active'] ? '' : 'is-muted' ?>">
                <span class="gauge-chip" aria-hidden="true"><?= $gauge['kind'] === 'marks' ? e($gauge['marks']) : '%' ?></span>
                <strong><?= e($gauge['name']) ?></strong>
                <span class="muted small">
                    <?= e($gauge['kind'] === 'marks' ? t('catalog.gauge_marks_n', ['n' => $gauge['marks']]) : t('fuel_kind.percent')) ?>
                    · <?= e(t('catalog.gauge_usage', ['models' => $gauge['model_count'], 'equipment' => $gauge['equipment_count']])) ?>
                </span>
                <form method="post" action="<?= e(url('/catalog/gauges/' . $gauge['id'])) ?>" class="list-rows__action" data-confirm-title="<?= e(t((int) $gauge['is_active'] ? 'catalog.deactivate' : 'catalog.activate') . ' · ' . $gauge['name']) ?>" data-confirm="<?= e(t((int) $gauge['is_active'] ? 'catalog.confirm_gauge_off' : 'catalog.confirm_gauge_on', ['n' => $gauge['model_count']])) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="name" value="<?= e($gauge['name']) ?>">
                    <input type="hidden" name="kind" value="<?= e($gauge['kind']) ?>">
                    <input type="hidden" name="marks" value="<?= e($gauge['marks']) ?>">
                    <input type="hidden" name="is_active" value="<?= (int) $gauge['is_active'] ? '0' : '1' ?>">
                    <button type="submit" class="switch" role="switch" aria-checked="<?= (int) $gauge['is_active'] ? 'true' : 'false' ?>" title="<?= e(t((int) $gauge['is_active'] ? 'catalog.deactivate' : 'catalog.activate')) ?>"><span></span></button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
