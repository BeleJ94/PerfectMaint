<?php
/** @var array $sites  @var array $types  @var array $filters  @var array<string, int> $summary */
$kpis = [
    'expired' => ['kpi--alert', 'file-x', 'documents.hint_expired'],
    'soon' => ['kpi--warn', 'file-clock', 'documents.hint_soon'],
    'missing' => ['', 'file-question', 'documents.hint_missing'],
    'valid' => ['', 'file-check', 'documents.hint_valid'],
];
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="file-badge"></i></span>
        <div>
            <h1><?= e(t('nav.documents')) ?></h1>
            <div class="page-title__meta"><span><?= e(t('documents.subtitle')) ?></span></div>
        </div>
    </div>
    <div class="page-header__actions">
        <?= $view->partial('partials/export_buttons', ['table' => 'documents', 'exportTitle' => t('nav.documents')]) ?>
        <?php if ($currentUser->can('documents.manage')): ?>
            <a class="btn btn--primary" href="<?= e(url('/documents/create')) ?>"><i data-lucide="plus"></i><?= e(t('documents.add')) ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="kpis kpis--compact">
    <?php foreach ($kpis as $state => [$class, $icon, $hint]): ?>
        <button type="button" class="kpi kpi--button<?= $summary[$state] > 0 && $class !== '' ? ' ' . $class : '' ?>" data-set-filter="state" data-value="<?= e($state) ?>">
            <div class="kpi__label"><i data-lucide="<?= e($icon) ?>"></i> <?= e(t('doc_state_title.' . $state)) ?></div>
            <div class="kpi__value"><?= e($summary[$state]) ?></div>
            <div class="kpi__hint"><?= e(t($hint)) ?></div>
        </button>
    <?php endforeach; ?>
</div>

<section class="card">
    <form class="filters" id="documents-filters" data-filters-for="documents">
        <?= $view->partial('partials/site_filter', ['sites' => $sites, 'selected' => $filters['site_id']]) ?>
        <div class="field">
            <label for="filter-type"><?= e(t('fields.document_type_id')) ?></label>
            <select id="filter-type" name="document_type_id" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach ($types as $type): ?>
                    <option value="<?= e($type['id']) ?>"<?= (int) $type['id'] === (int) $filters['document_type_id'] ? ' selected' : '' ?>><?= e(doc_type_label($type)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="filter-state"><?= e(t('fields.status')) ?></label>
            <select id="filter-state" name="state" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <option value="action"<?= $filters['state'] === 'action' ? ' selected' : '' ?>><?= e(t('documents.state_action')) ?></option>
                <?php foreach (\App\Repositories\EquipmentDocumentRepository::STATES as $state): ?>
                    <option value="<?= e($state) ?>"<?= $filters['state'] === $state ? ' selected' : '' ?>><?= e(t('doc_state_title.' . $state)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
    <div class="table-wrap">
        <table class="display" style="width:100%" data-table="documents" data-source="<?= e(url('/documents/data')) ?>"
               data-row-link="<?= e(url('/equipment/{equipment_id}')) ?>#documents"></table>
    </div>
</section>
