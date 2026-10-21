<?php
/** @var array $brands  @var array $models  @var array $categories */
$activeBrands = array_values(array_filter($brands, static fn (array $b) => (int) $b['is_active'] === 1));
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

<div class="grid grid--side-main">
    <section class="card">
        <div class="card__title"><h2><?= e(t('catalog.brands')) ?></h2></div>
        <form method="post" action="<?= e(url('/catalog/brands')) ?>" class="inline-add">
            <?= csrf_field() ?>
            <input name="name" class="input input--sm" required maxlength="60" placeholder="<?= e(t('catalog.new_brand')) ?>" aria-label="<?= e(t('catalog.new_brand')) ?>">
            <button type="submit" class="btn btn--primary btn--sm"><i data-lucide="plus"></i><?= e(t('common.add')) ?></button>
        </form>
        <ul class="list-rows">
            <?php foreach ($brands as $brand): ?>
                <li class="<?= (int) $brand['is_active'] ? '' : 'is-muted' ?>">
                    <button type="button" class="link-btn" data-filter-brand="<?= e($brand['id']) ?>"><strong><?= e($brand['name']) ?></strong></button>
                    <span class="muted small"><?= e(t('catalog.brand_counts', ['models' => $brand['model_count'], 'equipment' => $brand['equipment_count']])) ?></span>
                    <form method="post" action="<?= e(url('/catalog/brands/' . $brand['id'])) ?>" class="list-rows__action">
                        <?= csrf_field() ?>
                        <input type="hidden" name="name" value="<?= e($brand['name']) ?>">
                        <input type="hidden" name="is_active" value="<?= (int) $brand['is_active'] ? '0' : '1' ?>">
                        <button type="submit" class="switch" role="switch" aria-checked="<?= (int) $brand['is_active'] ? 'true' : 'false' ?>" title="<?= e(t((int) $brand['is_active'] ? 'catalog.deactivate' : 'catalog.activate')) ?>"><span></span></button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="card">
        <div class="card__title">
            <h2><?= e(t('catalog.models')) ?></h2>
            <div class="search-inline">
                <i data-lucide="search"></i>
                <input type="search" class="input input--sm" placeholder="<?= e(t('common.search')) ?>" data-table-search="#models-table" aria-label="<?= e(t('common.search')) ?>">
            </div>
        </div>
        <form method="post" action="<?= e(url('/catalog/models')) ?>" class="inline-add inline-add--wide">
            <?= csrf_field() ?>
            <select name="brand_id" class="input input--sm" required aria-label="<?= e(t('fields.brand_id')) ?>">
                <option value=""><?= e(t('fields.brand_id')) ?>…</option>
                <?php foreach ($activeBrands as $brand): ?><option value="<?= e($brand['id']) ?>"><?= e($brand['name']) ?></option><?php endforeach; ?>
            </select>
            <input name="name" class="input input--sm" required maxlength="80" placeholder="<?= e(t('catalog.new_model')) ?>" aria-label="<?= e(t('catalog.new_model')) ?>">
            <select name="category_id" class="input input--sm" aria-label="<?= e(t('fields.category_id')) ?>">
                <option value=""><?= e(t('fields.category_id')) ?>…</option>
                <?php foreach ($categories as $category): ?><option value="<?= e($category['id']) ?>"><?= e($category['name']) ?></option><?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn--primary btn--sm"><i data-lucide="plus"></i><?= e(t('common.add')) ?></button>
        </form>
        <div class="table-wrap">
            <table class="table table--compact" id="models-table">
                <thead><tr><th><?= e(t('fields.brand_id')) ?></th><th><?= e(t('fields.model_id')) ?></th><th><?= e(t('fields.category_id')) ?></th><th class="num"><?= e(t('nav.equipment')) ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($models as $model): ?>
                    <tr data-brand="<?= e($model['brand_id']) ?>" class="<?= (int) $model['is_active'] ? '' : 'is-muted' ?>">
                        <td><?= e($model['brand']) ?></td>
                        <td><strong><?= e($model['name']) ?></strong></td>
                        <td><?= e($model['category'] ?? '—') ?></td>
                        <td class="num"><?= e($model['equipment_count']) ?></td>
                        <td class="num">
                            <form method="post" action="<?= e(url('/catalog/models/' . $model['id'])) ?>">
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
    </section>
</div>

<section class="card">
    <div class="card__title"><h2><?= e(t('catalog.categories')) ?></h2></div>
    <p class="muted small"><?= e(t('catalog.categories_hint')) ?></p>
    <div class="table-wrap">
        <table class="table table--compact" data-no-dt>
            <thead><tr><th><?= e(t('fields.category_id')) ?></th><th><?= e(t('fields.meter_basis')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($categories as $category): ?>
                <tr>
                    <td><strong><?= e($category['name']) ?></strong></td>
                    <td>
                        <form method="post" action="<?= e(url('/catalog/categories/' . $category['id'])) ?>" class="inline-add">
                            <?= csrf_field() ?>
                            <select name="meter_basis" class="input input--sm" aria-label="<?= e(t('fields.meter_basis')) ?>">
                                <?php foreach (['hours', 'km'] as $basis): ?>
                                    <option value="<?= e($basis) ?>"<?= $category['meter_basis'] === $basis ? ' selected' : '' ?>><?= e(t('meter_basis.' . $basis)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn--sm"><i data-lucide="save"></i><?= e(t('common.save')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
