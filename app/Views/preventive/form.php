<?php
/** @var array $item  @var int|null $id  @var array|null $equipment */
$f = static fn (string $name, string $type = 'text', array $extra = []) => $view->partial('partials/field', $extra + [
    'name' => $name, 'labelKey' => 'fields.' . $name, 'type' => $type, 'value' => old($name, $item[$name] ?? null),
]);
$active = (bool) old('is_active', $item['is_active'] ?? true);
?>
<div class="page-header"><h1><?= e($title) ?></h1></div>
<form method="post" action="<?= e(url($id ? "/preventive/$id" : '/preventive')) ?>" class="card form">
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="span-2"><?= $view->partial('partials/equipment_picker', ['equipment' => $equipment]) ?></div>
        <?= $f('title', 'text', ['required' => true, 'class' => 'span-2', 'attrs' => ['maxlength' => 150]]) ?>
        <?= $f('instructions', 'textarea', ['class' => 'span-2', 'hint' => t('preventive.instructions_hint')]) ?>
        <?= $f('interval_days', 'number', ['attrs' => ['min' => 1, 'step' => 1], 'hint' => t('preventive.interval_hint')]) ?>
        <?= $f('interval_hours', 'number', ['attrs' => ['min' => 1, 'step' => 1]]) ?>
        <?= $f('interval_km', 'number', ['attrs' => ['min' => 1, 'step' => 1]]) ?>
        <?= $f('last_done_at', 'date') ?>
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
