<?php /** @var array $sites  @var array $entityTypes */ ?>
<div class="page-header">
    <h1><?= e(t('nav.activity')) ?></h1>
    <div class="page-header__actions">
        <?= $view->partial('partials/export_buttons', ['table' => 'activity', 'exportTitle' => t('nav.activity')]) ?>
    </div>
</div>
<section class="card">
    <form class="filters" data-filters-for="activity">
        <?= $view->partial('partials/site_filter', ['sites' => $sites, 'selected' => null]) ?>
        <div class="field">
            <label for="filter-entity"><?= e(t('fields.entity_type')) ?></label>
            <select id="filter-entity" name="entity_type" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach ($entityTypes as $type): ?>
                    <option value="<?= e($type) ?>"><?= e(t('entity.' . $type)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="filter-from"><?= e(t('common.from')) ?></label>
            <input id="filter-from" type="date" name="from" class="input" data-filter>
        </div>
        <div class="field">
            <label for="filter-to"><?= e(t('common.to')) ?></label>
            <input id="filter-to" type="date" name="to" class="input" data-filter>
        </div>
    </form>
    <div class="table-wrap">
        <table class="display" style="width:100%" data-table="activity" data-source="<?= e(url('/activity/data')) ?>"></table>
    </div>
</section>
