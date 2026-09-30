<?php /** @var array $sites  @var array $filters */ ?>
<div class="page-header">
    <h1><?= e(t('nav.parts')) ?></h1>
    <div class="page-header__actions">
        <?= $view->partial('partials/export_buttons', ['table' => 'parts', 'exportTitle' => t('nav.parts')]) ?>
        <?php if ($currentUser->can('parts.manage')): ?>
            <a class="btn" href="<?= e(url('/procurement')) ?>"><i data-lucide="shopping-cart"></i><?= e(t('nav.procurement')) ?></a>
            <a class="btn btn--primary" href="<?= e(url('/parts/create')) ?>"><i data-lucide="plus"></i><?= e(t('parts.new')) ?></a>
        <?php endif; ?>
    </div>
</div>
<section class="card">
    <form class="filters" data-filters-for="parts">
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
        <label class="check"><input type="checkbox" name="wear" value="1" data-filter<?= $filters['wear'] === '1' ? ' checked' : '' ?>> <?= e(t('parts.only_wear')) ?></label>
        <label class="check"><input type="checkbox" name="low" value="1" data-filter<?= $filters['low'] === '1' ? ' checked' : '' ?>> <?= e(t('parts.only_low')) ?></label>
    </form>
    <div class="table-wrap">
        <table class="display" style="width:100%" data-table="parts" data-source="<?= e(url('/parts/data')) ?>" data-row-link="<?= e(url('/parts/{id}')) ?>"></table>
    </div>
</section>
