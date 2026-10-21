<?php
/** @var array $item  @var int|null $id  @var array|null $equipment */
// Création depuis un engin : point de départ proposé = index actuel et date du jour, visibles et modifiables (jamais déduits en silence).
if (!$id && $equipment) {
    $item['last_done_hours'] ??= $equipment['hour_meter'] ?? null;
    $item['last_done_km'] ??= $equipment['odometer_km'] ?? null;
    $item['last_done_at'] ??= date('Y-m-d');
}
$basis = $equipment['meter_basis'] ?? null;
$f = static fn (string $name, string $type = 'text', array $extra = []) => $view->partial('partials/field', $extra + [
    'name' => $name, 'labelKey' => 'fields.' . $name, 'type' => $type, 'value' => old($name, $item[$name] ?? null),
]);
$active = (bool) old('is_active', $item['is_active'] ?? true);
?>
<div class="page-header"><h1><?= e($title) ?></h1></div>
<form method="post" action="<?= e(url($id ? "/preventive/$id" : '/preventive')) ?>" class="card form" data-preventive-form data-new="<?= $id ? '0' : '1' ?>">
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="span-2"><?= $view->partial('partials/equipment_picker', ['equipment' => $equipment]) ?></div>
        <p class="hint span-2" data-preventive-basis data-hours="<?= e(t('preventive.basis_hours')) ?>" data-km="<?= e(t('preventive.basis_km')) ?>"<?= $basis ? '' : ' hidden' ?>>
            <?= $basis ? e($basis === 'km' ? t('preventive.basis_km') : t('preventive.basis_hours')) : '' ?>
        </p>
        <?= $f('title', 'text', ['required' => true, 'class' => 'span-2', 'attrs' => ['maxlength' => 150]]) ?>
        <?= $f('instructions', 'textarea', ['class' => 'span-2', 'hint' => t('preventive.instructions_hint')]) ?>
        <?= $f('interval_days', 'number', ['attrs' => ['min' => 1, 'step' => 1], 'hint' => t('preventive.interval_hint')]) ?>
        <?= $f('interval_hours', 'number', ['attrs' => ['min' => 1, 'step' => 1]]) ?>
        <?= $f('interval_km', 'number', ['attrs' => ['min' => 1, 'step' => 1]]) ?>
        <?= $f('last_done_at', 'date', ['hint' => t('preventive.last_done_hint')]) ?>
        <?= $f('last_done_hours', 'number', ['attrs' => ['min' => 0, 'step' => 1]]) ?>
        <?= $f('last_done_km', 'number', ['attrs' => ['min' => 0, 'step' => 1]]) ?>
        <?= $f('next_due_date', 'date', ['hint' => t('preventive.next_due_hint')]) ?>
        <div class="field">
            <input type="hidden" name="is_active" value="0">
            <label class="check"><input type="checkbox" name="is_active" value="1"<?= $active ? ' checked' : '' ?>> <?= e(t('fields.is_active')) ?></label>
        </div>
    </div>
    <div class="form-actions">
        <a class="btn" href="<?= e(url('/preventive')) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary"><i data-lucide="save"></i><?= e(t('common.save')) ?></button>
    </div>
</form>
