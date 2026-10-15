<?php
/** @var array|null $equipment  @var array $types  @var int|null $typeId */
$selectedType = (int) old('document_type_id', $typeId);
?>
<div class="page-header"><h1><?= e($title) ?></h1></div>
<form method="post" action="<?= e(url('/documents')) ?>" class="card form" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="field span-2">
            <?= $view->partial('partials/equipment_picker', ['equipment' => $equipment]) ?>
        </div>
        <div class="field">
            <label for="doc-type" class="required"><?= e(t('fields.document_type_id')) ?></label>
            <select id="doc-type" name="document_type_id" class="input" required>
                <?php foreach ($types as $type): ?>
                    <option value="<?= e($type['id']) ?>"<?= (int) $type['id'] === $selectedType ? ' selected' : '' ?>><?= e(doc_type_label($type)) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($error = field_error('document_type_id')): ?><span class="error"><?= e($error) ?></span><?php endif; ?>
        </div>
        <?= $view->partial('partials/document_fields', ['doc' => []]) ?>
    </div>
    <div class="form-actions">
        <a class="btn" href="<?= e(url('/documents')) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary"><i data-lucide="save"></i><?= e(t('common.save')) ?></button>
    </div>
</form>
