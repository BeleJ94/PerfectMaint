<?php
/** @var array $sites  @var array $recorders  @var array|null $equipment engin présélectionné (?equipment_id=) */
$view->share('pageScripts', ['js/readings.js']);
?>
<div class="page-header">
    <h1><?= e(t('nav.readings')) ?></h1>
    <div class="page-header__actions">
        <?= $view->partial('partials/export_buttons', ['table' => 'readings', 'exportTitle' => t('nav.readings')]) ?>
    </div>
</div>
<p class="hint"><?= e(t('readings.intro')) ?></p>
<section class="card">
    <form class="filters" data-filters-for="readings">
        <?= $view->partial('partials/site_filter', ['sites' => $sites, 'selected' => null]) ?>
        <?php /* Engin : recherche dynamique (code, nom, plaque, châssis) limitée au site choisi ; ↑ ↓ Entrée au clavier. */ ?>
        <div class="field eq-filter" data-eq-filter data-search-url="<?= e(url('/equipment/search')) ?>">
            <label for="filter-equipment"><?= e(t('fields.equipment_id')) ?></label>
            <input type="hidden" name="equipment_id" value="<?= e($equipment['id'] ?? '') ?>" data-site="<?= e($equipment['site_id'] ?? '') ?>" data-filter data-eq-value>
            <div class="eq-filter__box">
                <i data-lucide="search"></i>
                <input id="filter-equipment" type="search" class="input" autocomplete="off" spellcheck="false" role="combobox"
                       aria-expanded="false" aria-controls="eq-filter-list" aria-autocomplete="list"
                       placeholder="<?= e(t('readings.equipment_placeholder')) ?>" data-eq-input
                       value="<?= e($equipment ? $equipment['code'] . ' · ' . $equipment['name'] : '') ?>">
                <button type="button" class="eq-filter__clear" data-eq-clear aria-label="<?= e(t('js.dt.reset')) ?>"<?= $equipment ? '' : ' hidden' ?>><i data-lucide="x"></i></button>
            </div>
            <ul class="eq-filter__list" id="eq-filter-list" role="listbox" data-eq-list hidden></ul>
        </div>
        <div class="field">
            <label for="filter-user"><?= e(t('readings.recorded_by')) ?></label>
            <select id="filter-user" name="user_id" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach ($recorders as $recorder): ?>
                    <option value="<?= e($recorder['id']) ?>"><?= e($recorder['name']) ?></option>
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
        <table class="display" style="width:100%" data-table="readings" data-source="<?= e(url('/readings/data')) ?>" data-reading-url="<?= e(url('/readings/{id}')) ?>"
               data-equipment-url="<?= e(url('/equipment/{id}')) ?>" data-now="<?= e(date('Y-m-d\TH:i')) ?>"></table>
    </div>
</section>
