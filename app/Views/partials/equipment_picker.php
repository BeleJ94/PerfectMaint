<?php
/**
 * Sélecteur d'engin avec recherche (code, nom, n° de série). Fonctionne au doigt.
 * @var array|null $equipment  engin présélectionné
 */
$error = field_error('equipment_id');
$selectedId = old('equipment_id', $equipment['id'] ?? null);
?>
<div class="field" data-equipment-picker data-search-url="<?= e(url('/equipment/search')) ?>" data-assignees-url="<?= e(url('/work-orders/assignees')) ?>"<?= !empty($withAssignees) ? ' data-with-assignees="1"' : '' ?>>
    <label for="equipment-search" class="required"><?= e(t('fields.equipment_id')) ?></label>
    <input type="hidden" name="equipment_id" value="<?= e($selectedId) ?>" data-picker-value>
    <div class="selected-equipment" data-picker-selected<?= $equipment ? '' : ' hidden' ?>>
        <span data-picker-label><?php if ($equipment): ?><strong><?= e($equipment['code']) ?></strong> · <?= e($equipment['name']) ?><?php endif; ?></span>
        <button type="button" class="btn btn--sm" data-picker-clear><?= e(t('common.change')) ?></button>
    </div>
    <div data-picker-search<?= $equipment ? ' hidden' : '' ?>>
        <input id="equipment-search" type="search" class="input" autocomplete="off" placeholder="<?= e(t('field.search_placeholder')) ?>"<?= $error ? ' aria-invalid="true"' : '' ?>>
        <ul class="search-results" data-picker-results></ul>
    </div>
    <?php if ($error): ?><span class="error"><?= e($error) ?></span><?php endif; ?>
</div>
