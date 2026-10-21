<?php
/** @var array $item  @var int|null $id  @var array $sites  @var array $categories  @var array $brands  @var array $models */
$siteOptions = ['' => '—'];
foreach ($sites as $site) { $siteOptions[$site['id']] = $site['name']; }
$categoryOptions = ['' => '—'];
foreach ($categories as $category) { $categoryOptions[$category['id']] = $category['name']; }
$brandId = old('brand_id', $item['brand_id'] ?? null);
$modelId = old('model_id', $item['model_id'] ?? null);
$ownership = (string) old('ownership', $item['ownership'] ?? 'owned');
$f = static fn (string $name, string $type = 'text', array $extra = []) => $view->partial('partials/field', $extra + [
    'name' => $name, 'labelKey' => 'fields.' . $name, 'type' => $type, 'value' => old($name, $item[$name] ?? null),
]);
$fieldError = static fn (string $name) => field_error($name) ? '<span class="error">' . e(field_error($name)) . '</span>' : '';
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="<?= $id ? 'pencil' : 'truck' ?>"></i></span>
        <div>
            <h1><?= e($title) ?></h1>
            <?php if ($id): ?><div class="page-title__meta"><span><?= e($item['code'] ?? '') ?></span></div><?php endif; ?>
        </div>
    </div>
</div>
<form method="post" action="<?= e(url($id ? "/equipment/$id" : '/equipment')) ?>" class="card form form--sections">
    <?= csrf_field() ?>

    <fieldset class="form-section">
        <legend><i data-lucide="id-card"></i><?= e(t('equipment.section_identity')) ?></legend>
        <div class="form-grid">
            <?= $f('code', 'text', ['required' => true, 'attrs' => ['maxlength' => 30, 'autocapitalize' => 'characters', 'placeholder' => 'KAM-ADT-01']]) ?>
            <?= $f('name', 'text', ['required' => true, 'attrs' => ['maxlength' => 150, 'placeholder' => t('equipment.name_placeholder')]]) ?>
            <?= $f('site_id', 'select', ['required' => true, 'options' => $siteOptions]) ?>
            <?= $f('category_id', 'select', ['options' => $categoryOptions]) ?>
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
                            <option value="<?= e($model['id']) ?>" data-brand="<?= e($model['brand_id']) ?>" data-category="<?= e($model['category_id']) ?>"<?= (string) $model['id'] === (string) $modelId ? ' selected' : '' ?>><?= e($model['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn" data-quick-model data-url="<?= e(url('/catalog/models')) ?>" title="<?= e(t('catalog.new_model')) ?>" aria-label="<?= e(t('catalog.new_model')) ?>"><i data-lucide="plus"></i></button>
                </div>
                <?= $fieldError('model_id') ?>
            </div>
            <input type="hidden" name="brand" value="<?= e($item['brand'] ?? '') ?>">
            <input type="hidden" name="model" value="<?= e($item['model'] ?? '') ?>">
            <?= $f('chassis_number', 'text', ['attrs' => ['maxlength' => 60, 'autocapitalize' => 'characters']]) ?>
            <?= $f('plate_number', 'text', ['attrs' => ['maxlength' => 30, 'autocapitalize' => 'characters']]) ?>
            <?= $f('serial_number', 'text', ['attrs' => ['maxlength' => 80]]) ?>
            <?= $f('commissioned_at', 'date') ?>
        </div>
    </fieldset>

    <fieldset class="form-section">
        <legend><i data-lucide="gauge"></i><?= e(t('equipment.section_meters')) ?></legend>
        <div class="form-grid">
            <?= $f('meter_basis', 'select', [
                'value' => old('meter_basis', $item['meter_basis_override'] ?? null),
                'options' => ['' => t('equipment.meter_basis_category'), 'hours' => t('meter_basis.hours'), 'km' => t('meter_basis.km')],
                'hint' => t('equipment.meter_basis_hint'),
            ]) ?>
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
        </div>
    </fieldset>

    <fieldset class="form-section">
        <legend><i data-lucide="file-signature"></i><?= e(t('equipment.section_contract')) ?></legend>
        <div class="form-grid">
            <?= $f('assignment', 'text', ['class' => 'span-2', 'attrs' => ['maxlength' => 150, 'placeholder' => t('equipment.assignment_placeholder')]]) ?>
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
        </div>
    </fieldset>

    <fieldset class="form-section">
        <legend><i data-lucide="sticky-note"></i><?= e(t('fields.notes')) ?></legend>
        <?= $f('notes', 'textarea', ['labelKey' => 'equipment.notes_label']) ?>
    </fieldset>

    <div class="form-actions">
        <a class="btn" href="<?= e(url($id ? "/equipment/$id" : '/equipment')) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary"><i data-lucide="save"></i><?= e(t('common.save')) ?></button>
    </div>
</form>
