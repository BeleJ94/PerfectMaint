<?php /** @var array|null $equipment */ ?>
<form method="post" action="<?= e(url('/terrain/declarer')) ?>" class="grid">
    <?= csrf_field() ?>
    <?= $view->partial('partials/equipment_picker', ['equipment' => $equipment]) ?>
    <?= $view->partial('partials/field', ['name' => 'title', 'labelKey' => 'field.what_happened', 'value' => old('title'), 'required' => true, 'attrs' => ['maxlength' => 150, 'placeholder' => t('field.title_placeholder')]]) ?>
    <?= $view->partial('partials/field', ['name' => 'description', 'labelKey' => 'fields.description', 'type' => 'textarea', 'value' => old('description')]) ?>
    <div class="field">
        <span class="label"><?= e(t('fields.priority')) ?></span>
        <?php $priority = old('priority', 'normal'); ?>
        <?php foreach (['normal', 'high', 'critical'] as $p): ?>
            <label class="check"><input type="radio" name="priority" value="<?= e($p) ?>"<?= $priority === $p ? ' checked' : '' ?>> <?= e(label('priority', $p)) ?></label>
        <?php endforeach; ?>
    </div>
    <div class="field">
        <input type="hidden" name="immobilizing" value="0">
        <label class="check"><input type="checkbox" name="immobilizing" value="1"<?= old('immobilizing', '1') ? ' checked' : '' ?>> <?= e(t('field.equipment_stopped')) ?></label>
    </div>
    <div class="sticky-actions">
        <button type="submit" class="btn btn--danger btn--lg"><i data-lucide="send"></i><?= e(t('field.send')) ?></button>
    </div>
</form>
