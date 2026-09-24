<?php /** @var array $sites  @var array $categories  @var \App\Domain\EquipmentStatus[] $statuses  @var array $filters */ ?>
<div class="page-header">
    <h1><?= e(t('nav.equipment')) ?></h1>
    <div class="page-header__actions">
        <?= $view->partial('partials/export_buttons', ['table' => 'equipment', 'exportTitle' => t('nav.equipment')]) ?>
        <?php if ($currentUser->can('equipment.manage')): ?>
            <a class="btn btn--primary" href="<?= e(url('/equipment/create', $filters['site_id'] ? ['site_id' => $filters['site_id']] : [])) ?>"><i data-lucide="plus"></i><?= e(t('equipment.new')) ?></a>
        <?php endif; ?>
    </div>
</div>
<section class="card">
    <form class="filters" id="equipment-filters" data-filters-for="equipment">
        <?= $view->partial('partials/site_filter', ['sites' => $sites, 'selected' => $filters['site_id']]) ?>
        <div class="field">
            <label for="filter-status"><?= e(t('fields.status')) ?></label>
            <select id="filter-status" name="status" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach ($statuses as $status): ?>
                    <option value="<?= e($status->value) ?>"<?= $filters['status'] === $status->value ? ' selected' : '' ?>><?= e(t($status->labelKey())) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="filter-category"><?= e(t('fields.category_id')) ?></label>
            <select id="filter-category" name="category_id" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= e($category['id']) ?>"><?= e($category['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
    <div class="table-wrap">
        <table class="display" style="width:100%" data-table="equipment" data-source="<?= e(url('/equipment/data')) ?>" data-row-link="<?= e(url('/equipment/{id}')) ?>"></table>
    </div>
</section>
