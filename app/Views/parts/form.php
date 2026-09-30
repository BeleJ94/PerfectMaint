<?php
/** @var array $item  @var int|null $id  @var array $models  @var list<int> $selectedModels */
$f = static fn (string $name, string $type = 'text', array $extra = []) => $view->partial('partials/field', $extra + [
    'name' => $name, 'labelKey' => 'fields.' . $name, 'type' => $type, 'value' => old($name, $item[$name] ?? null),
]);
$active = (bool) old('is_active', $item['is_active'] ?? true);
$wear = (bool) old('is_wear_part', $item['is_wear_part'] ?? false);
$type = (string) old('part_type', $item['part_type'] ?? 'part');
$selected = array_map('intval', (array) old('model_ids', $selectedModels));
$byBrand = [];
foreach ($models as $model) {
    $byBrand[$model['brand']][] = $model;
}
$typeIcons = ['part' => 'cog', 'tyre' => 'circle-dot', 'lubricant' => 'droplet', 'filter' => 'filter', 'consumable' => 'box', 'fuel' => 'fuel'];
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="package"></i></span>
        <div><h1><?= e($title) ?></h1><?php if ($id): ?><div class="page-title__meta"><span><?= e($item['reference'] ?? '') ?></span></div><?php endif; ?></div>
    </div>
</div>
<form method="post" action="<?= e(url($id ? "/parts/$id" : '/parts')) ?>" class="card form form--sections">
    <?= csrf_field() ?>
    <fieldset class="form-section">
        <legend><i data-lucide="tag"></i><?= e(t('parts.section_identity')) ?></legend>
        <div class="field">
            <span class="label"><?= e(t('fields.part_type')) ?></span>
            <div class="type-picker" role="radiogroup" aria-label="<?= e(t('fields.part_type')) ?>">
                <?php foreach (\App\Services\PartService::TYPES as $value): ?>
                    <label><input type="radio" name="part_type" value="<?= e($value) ?>"<?= $type === $value ? ' checked' : '' ?>><span><i data-lucide="<?= e($typeIcons[$value]) ?>"></i><?= e(t('part_type.' . $value)) ?></span></label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="form-grid" style="margin-top: 16px">
            <?= $f('reference', 'text', ['required' => true, 'attrs' => ['maxlength' => 40, 'autocapitalize' => 'characters']]) ?>
            <?= $f('name', 'text', ['required' => true, 'attrs' => ['maxlength' => 150]]) ?>
            <?= $f('manufacturer', 'text', ['attrs' => ['maxlength' => 80, 'placeholder' => 'Caterpillar, Shell, Michelin…']]) ?>
            <?= $f('manufacturer_ref', 'text', ['attrs' => ['maxlength' => 60, 'placeholder' => '1R-0750']]) ?>
        </div>
    </fieldset>
    <fieldset class="form-section">
        <legend><i data-lucide="coins"></i><?= e(t('parts.section_stock')) ?></legend>
        <div class="form-grid">
            <?= $f('unit', 'text', ['required' => true, 'attrs' => ['maxlength' => 10], 'hint' => t('parts.unit_hint')]) ?>
            <?= $f('unit_cost', 'number', ['attrs' => ['min' => 0, 'step' => '0.01'], 'hint' => currency_symbol()]) ?>
            <div class="field span-2">
                <input type="hidden" name="is_wear_part" value="0">
                <label class="check"><input type="checkbox" name="is_wear_part" value="1"<?= $wear ? ' checked' : '' ?>> <?= e(t('fields.is_wear_part')) ?></label>
                <span class="hint"><?= e(t('parts.wear_hint')) ?></span>
            </div>
        </div>
    </fieldset>
    <fieldset class="form-section">
        <legend><i data-lucide="activity"></i><?= e(t('parts.section_life')) ?></legend>
        <div class="form-grid">
            <?= $f('expected_life_hours', 'number', ['attrs' => ['min' => 1, 'step' => 1, 'inputmode' => 'numeric'], 'hint' => t('parts.life_hint')]) ?>
            <?= $f('expected_life_km', 'number', ['attrs' => ['min' => 1, 'step' => 1, 'inputmode' => 'numeric']]) ?>
        </div>
    </fieldset>
    <fieldset class="form-section">
        <legend><i data-lucide="link"></i><?= e(t('parts.section_compat')) ?> <span class="chip" data-compat-count><?= e(count($selected)) ?></span></legend>
        <div class="compat" data-compat>
            <div class="search-inline compat__search">
                <i data-lucide="search"></i>
                <input type="search" class="input input--sm" placeholder="<?= e(t('parts.compat_search')) ?>" data-compat-search aria-label="<?= e(t('parts.compat_search')) ?>">
            </div>
            <input type="hidden" name="model_ids[]" value="">
            <div class="compat__brands">
                <?php foreach ($byBrand as $brand => $brandModels): ?>
                    <div class="compat__brand" data-compat-brand>
                        <label class="compat__brand-name"><input type="checkbox" data-compat-all> <strong><?= e($brand) ?></strong></label>
                        <div class="compat__models">
                            <?php foreach ($brandModels as $model): ?>
                                <label class="compat__model" data-compat-item data-search="<?= e(mb_strtolower($brand . ' ' . $model['name'] . ' ' . ($model['category'] ?? ''))) ?>">
                                    <input type="checkbox" name="model_ids[]" value="<?= e($model['id']) ?>"<?= in_array((int) $model['id'], $selected, true) ? ' checked' : '' ?>>
                                    <span><?= e($model['name']) ?><small><?= e($model['category'] ?? '') ?></small></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </fieldset>
    <fieldset class="form-section">
        <div class="field">
            <input type="hidden" name="is_active" value="0">
            <label class="check"><input type="checkbox" name="is_active" value="1"<?= $active ? ' checked' : '' ?>> <?= e(t('fields.is_active')) ?></label>
        </div>
    </fieldset>
    <div class="form-actions">
        <a class="btn" href="<?= e(url($id ? "/parts/$id" : '/parts')) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary"><i data-lucide="save"></i><?= e(t('common.save')) ?></button>
    </div>
</form>
