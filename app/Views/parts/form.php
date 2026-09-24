<?php
/** @var array $item  @var int|null $id */
$f = static fn (string $name, string $type = 'text', array $extra = []) => $view->partial('partials/field', $extra + [
    'name' => $name, 'labelKey' => 'fields.' . $name, 'type' => $type, 'value' => old($name, $item[$name] ?? null),
]);
$active = (bool) old('is_active', $item['is_active'] ?? true);
?>
<div class="page-header"><h1><?= e($title) ?></h1></div>
<form method="post" action="<?= e(url($id ? "/parts/$id" : '/parts')) ?>" class="card form">
    <?= csrf_field() ?>
    <div class="form-grid">
        <?= $f('reference', 'text', ['required' => true, 'attrs' => ['maxlength' => 40]]) ?>
        <?= $f('name', 'text', ['required' => true, 'attrs' => ['maxlength' => 150]]) ?>
        <?= $f('unit', 'text', ['required' => true, 'attrs' => ['maxlength' => 10], 'hint' => t('parts.unit_hint')]) ?>
        <?= $f('unit_cost', 'number', ['attrs' => ['min' => 0, 'step' => '0.01']]) ?>
        <div class="field">
            <input type="hidden" name="is_active" value="0">
            <label class="check"><input type="checkbox" name="is_active" value="1"<?= $active ? ' checked' : '' ?>> <?= e(t('fields.is_active')) ?></label>
        </div>
    </div>
    <div class="form-actions">
        <a class="btn" href="<?= e(url($id ? "/parts/$id" : '/parts')) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary"><i data-lucide="save"></i><?= e(t('common.save')) ?></button>
    </div>
</form>
