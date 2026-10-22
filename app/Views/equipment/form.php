<?php
/**
 * Fiche engin (création / modification), dans l'ordre de la saisie réelle :
 * identité → modèle (qui apporte catégorie, compteur de suivi, indicateur et réservoir) → immatriculation
 * → compteurs et carburant → propriété et contrat → notes.
 * Panneau de synthèse fixe : ce que l'engin héritera réellement (et d'où), sections à compléter, actions.
 * @var array $item  @var int|null $id  @var array $sites  @var array $categories  @var array $brands  @var array $models  @var array $gauges
 */
$view->share('pageScripts', ['js/equipment-form.js']);
$siteOptions = ['' => '—'];
foreach ($sites as $site) { $siteOptions[$site['id']] = $site['name']; }
$brandId = old('brand_id', $item['brand_id'] ?? null);
$modelId = old('model_id', $item['model_id'] ?? null);
$categoryId = old('category_id', $item['category_id'] ?? null);
$ownership = (string) old('ownership', $item['ownership'] ?? 'owned');
$gaugeNames = array_column($gauges, 'name', 'id');
$f = static fn (string $name, string $type = 'text', array $extra = []) => $view->partial('partials/field', $extra + [
    'name' => $name, 'labelKey' => 'fields.' . $name, 'type' => $type, 'value' => old($name, $item[$name] ?? null),
]);
$fieldError = static fn (string $name) => field_error($name) ? '<span class="error">' . e(field_error($name)) . '</span>' : '';
$sections = [
    'identity' => ['equipment.section_identity', 'id-card'],
    'model' => ['equipment.section_model', 'boxes'],
    'registration' => ['equipment.section_registration', 'file-badge'],
    'meters' => ['equipment.section_meters', 'gauge'],
    'contract' => ['equipment.section_contract', 'file-signature'],
    'notes' => ['fields.notes', 'sticky-note'],
];
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="<?= $id ? 'pencil' : 'truck' ?>"></i></span>
        <div>
            <h1><?= e($title) ?></h1>
            <?php if ($id): ?><div class="page-title__meta"><span><?= e($item['code'] ?? '') ?> · <?= e($item['name'] ?? '') ?></span></div><?php endif; ?>
        </div>
    </div>
</div>

<form method="post" action="<?= e(url($id ? "/equipment/$id" : '/equipment')) ?>" class="equipment-form" data-equipment-form>
    <?= csrf_field() ?>
    <div class="card form form--sections equipment-form__main">

        <fieldset class="form-section" id="sec-identity">
            <legend><i data-lucide="id-card"></i><?= e(t('equipment.section_identity')) ?></legend>
            <div class="form-grid">
                <?= $f('code', 'text', ['required' => true, 'attrs' => ['maxlength' => 30, 'autocapitalize' => 'characters', 'placeholder' => 'KAM-ADT-01', 'data-summary' => 'code']]) ?>
                <?= $f('name', 'text', ['required' => true, 'attrs' => ['maxlength' => 150, 'placeholder' => t('equipment.name_placeholder'), 'data-summary' => 'name']]) ?>
                <?= $f('site_id', 'select', ['required' => true, 'options' => $siteOptions, 'attrs' => ['data-summary' => 'site']]) ?>
                <?= $f('commissioned_at', 'date') ?>
            </div>
        </fieldset>

        <fieldset class="form-section" id="sec-model">
            <legend><i data-lucide="boxes"></i><?= e(t('equipment.section_model')) ?></legend>
            <p class="hint"><?= e(t('equipment.model_hint')) ?></p>
            <div class="form-grid">
                <div class="field">
                    <label for="f-brand_id"><?= e(t('fields.brand_id')) ?></label>
                    <select id="f-brand_id" name="brand_id" class="input" data-brand-select>
                        <option value="">—</option>
                        <?php foreach ($brands as $brand): ?>
                            <option value="<?= e($brand['id']) ?>"<?= (string) $brand['id'] === (string) $brandId ? ' selected' : '' ?>><?= e($brand['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($brandId === null && !empty($item['brand'])): ?><span class="hint"><?= e(t('equipment.legacy_brand', ['brand' => trim($item['brand'] . ' ' . ($item['model'] ?? ''))])) ?></span><?php endif; ?>
                    <?= $fieldError('brand_id') ?>
                </div>
                <div class="field">
                    <label for="f-model_id"><?= e(t('fields.model_id')) ?></label>
                    <div class="input-group">
                        <select id="f-model_id" name="model_id" class="input" data-model-select data-category-target="#f-category_id">
                            <option value="">—</option>
                            <?php foreach ($models as $model): ?>
                                <option value="<?= e($model['id']) ?>" data-brand="<?= e($model['brand_id']) ?>" data-category="<?= e($model['category_id']) ?>"
                                        data-gauge="<?= e($model['fuel_gauge_id'] !== null ? ($gaugeNames[$model['fuel_gauge_id']] ?? '') : '') ?>" data-capacity="<?= e($model['tank_capacity_l']) ?>"
                                        <?= (string) $model['id'] === (string) $modelId ? ' selected' : '' ?>><?= e($model['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn" data-quick-model data-url="<?= e(url('/catalog/models')) ?>" title="<?= e(t('catalog.new_model')) ?>" aria-label="<?= e(t('catalog.new_model')) ?>"><i data-lucide="plus"></i></button>
                    </div>
                    <?= $fieldError('model_id') ?>
                </div>
                <div class="field">
                    <label for="f-category_id"><?= e(t('fields.category_id')) ?></label>
                    <select id="f-category_id" name="category_id" class="input">
                        <option value="">—</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= e($category['id']) ?>" data-basis="<?= e($category['meter_basis']) ?>"<?= (string) $category['id'] === (string) $categoryId ? ' selected' : '' ?>><?= e($category['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $fieldError('category_id') ?>
                </div>
                <div class="model-brings" data-model-brings hidden></div>
            </div>
            <input type="hidden" name="brand" value="<?= e($item['brand'] ?? '') ?>">
            <input type="hidden" name="model" value="<?= e($item['model'] ?? '') ?>">
        </fieldset>

        <fieldset class="form-section" id="sec-registration">
            <legend><i data-lucide="file-badge"></i><?= e(t('equipment.section_registration')) ?></legend>
            <div class="form-grid form-grid--3">
                <?= $f('plate_number', 'text', ['attrs' => ['maxlength' => 30, 'autocapitalize' => 'characters']]) ?>
                <?= $f('chassis_number', 'text', ['attrs' => ['maxlength' => 60, 'autocapitalize' => 'characters']]) ?>
                <?= $f('serial_number', 'text', ['attrs' => ['maxlength' => 80]]) ?>
            </div>
        </fieldset>

        <fieldset class="form-section" id="sec-meters">
            <legend><i data-lucide="gauge"></i><?= e(t('equipment.section_meters')) ?></legend>
            <div class="form-grid">
                <?= $f('meter_basis', 'select', [
                    'value' => old('meter_basis', $item['meter_basis_override'] ?? null),
                    'options' => ['' => t('equipment.meter_basis_category'), 'hours' => t('meter_basis.hours'), 'km' => t('meter_basis.km')],
                    'hint' => t('equipment.meter_basis_hint_short'),
                    'attrs' => ['data-inherit-basis' => ''],
                ]) ?>
                <div></div>
                <?= $f('hour_meter', 'number', ['attrs' => ['min' => 0, 'step' => 1, 'inputmode' => 'numeric'], 'hint' => $id ? t('equipment.meter_hint') : null]) ?>
                <?php if (!$id): ?>
                    <?= $f('odometer_km', 'number', ['attrs' => ['min' => 0, 'step' => 1, 'inputmode' => 'numeric']]) ?>
                <?php else: ?>
                    <div class="field">
                        <span class="label"><?= e(t('fields.odometer_km')) ?></span>
                        <span class="readonly-value"><?= $item['odometer_km'] !== null ? e(format_number($item['odometer_km'])) . ' km' : '—' ?></span>
                        <span class="hint"><?= e(t('equipment.odometer_hint')) ?></span>
                    </div>
                <?php endif; ?>
                <?php
                // Carburant : réglage du modèle par défaut (affiché dans la liste) ; à renseigner ici seulement si l'engin diffère.
                $gaugeOptions = ['' => t('equipment.fuel_from_model')];
                foreach ($gauges as $gauge) {
                    if ((int) $gauge['is_active'] || (int) $gauge['id'] === (int) ($item['fuel_gauge_id'] ?? 0)) {
                        $gaugeOptions[$gauge['id']] = $gauge['name'];
                    }
                }
                ?>
                <?= $f('fuel_gauge_id', 'select', ['options' => $gaugeOptions, 'hint' => t('equipment.fuel_gauge_hint_short'), 'attrs' => ['data-inherit-gauge' => '']]) ?>
                <?= $f('tank_capacity_l', 'number', ['attrs' => ['min' => 1, 'max' => 20000, 'step' => 1, 'inputmode' => 'numeric', 'data-inherit-capacity' => '']]) ?>
            </div>
        </fieldset>

        <fieldset class="form-section" id="sec-contract">
            <legend><i data-lucide="file-signature"></i><?= e(t('equipment.section_contract')) ?></legend>
            <div class="form-grid">
                <div class="field span-2">
                    <span class="label"><?= e(t('fields.ownership')) ?></span>
                    <div class="segmented" role="radiogroup" aria-label="<?= e(t('fields.ownership')) ?>">
                        <?php foreach (\App\Services\EquipmentService::OWNERSHIPS as $value): ?>
                            <label><input type="radio" name="ownership" value="<?= e($value) ?>"<?= $ownership === $value ? ' checked' : '' ?> data-toggle-contract><span><?= e(t('ownership.' . $value)) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="span-2 form-grid contract-fields" data-contract-fields<?= $ownership === 'owned' ? ' hidden' : '' ?>>
                    <?= $f('contract_ref', 'text', ['attrs' => ['maxlength' => 60, 'placeholder' => 'PO-2026-0142']]) ?>
                    <?= $f('contract_party', 'text', ['attrs' => ['maxlength' => 120]]) ?>
                    <?= $f('contract_start', 'date') ?>
                    <?= $f('contract_end', 'date') ?>
                </div>
                <?= $f('assignment', 'text', ['class' => 'span-2', 'attrs' => ['maxlength' => 150, 'placeholder' => t('equipment.assignment_placeholder')]]) ?>
            </div>
        </fieldset>

        <fieldset class="form-section" id="sec-notes">
            <legend><i data-lucide="sticky-note"></i><?= e(t('fields.notes')) ?></legend>
            <?= $f('notes', 'textarea', ['labelKey' => 'equipment.notes_label']) ?>
        </fieldset>

        <div class="form-actions equipment-form__actions">
            <span class="muted small" data-dirty-note hidden><i data-lucide="circle-dot"></i><?= e(t('equipment.unsaved')) ?></span>
            <a class="btn" href="<?= e(url($id ? "/equipment/$id" : '/equipment')) ?>"><?= e(t('common.cancel')) ?></a>
            <button type="submit" class="btn btn--primary"><i data-lucide="save"></i><?= e(t('common.save')) ?></button>
        </div>
    </div>

    <?php /* Synthèse : ce que l'engin héritera réellement, mise à jour pendant la saisie. */ ?>
    <aside class="equipment-form__aside" aria-label="<?= e(t('equipment.summary')) ?>">
        <div class="card summary-card">
            <div class="summary-card__head">
                <strong data-summary-out="code"><?= e($item['code'] ?? '') ?: '—' ?></strong>
                <span class="muted small" data-summary-out="name"><?= e($item['name'] ?? '') ?></span>
                <span class="muted small" data-summary-out="site"></span>
            </div>
            <dl class="summary-list">
                <div><dt><?= e(t('fields.model_id')) ?></dt><dd data-summary-out="model">—</dd></div>
                <div><dt><?= e(t('fields.meter_basis')) ?></dt><dd data-summary-out="basis">—</dd></div>
                <div><dt><?= e(t('fields.fuel_gauge_id')) ?></dt><dd data-summary-out="gauge">—</dd></div>
                <div><dt><?= e(t('fields.tank_capacity_l')) ?></dt><dd data-summary-out="capacity">—</dd></div>
                <div><dt><?= e(t('fields.ownership')) ?></dt><dd data-summary-out="ownership">—</dd></div>
            </dl>
            <nav class="summary-nav" aria-label="<?= e(t('equipment.sections')) ?>">
                <?php foreach ($sections as $key => [$label, $icon]): ?>
                    <a href="#sec-<?= e($key) ?>" data-section-link="<?= e($key) ?>"><i data-lucide="<?= e($icon) ?>"></i><?= e(t($label)) ?><span class="summary-nav__state" data-section-state="<?= e($key) ?>"></span></a>
                <?php endforeach; ?>
            </nav>
            <div class="summary-card__actions">
                <button type="submit" class="btn btn--primary btn--block"><i data-lucide="save"></i><?= e(t('common.save')) ?></button>
                <a class="btn btn--block" href="<?= e(url($id ? "/equipment/$id" : '/equipment')) ?>"><?= e(t('common.cancel')) ?></a>
            </div>
        </div>
    </aside>
</form>
