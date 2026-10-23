<?php
/**
 * Modification d'un relevé d'index par l'administrateur ; suppression dans un formulaire séparé.
 * @var array $item relevé (MeterReadingRepository::find)
 */
$id = (int) $item['id'];
$f = static fn (string $name, string $type = 'text', array $extra = []) => $view->partial('partials/field', $extra + [
    'name' => $name, 'labelKey' => 'fields.' . $name, 'type' => $type, 'value' => old($name, $item[$name] ?? null),
]);
$recordedAt = old('recorded_at', date('Y-m-d\TH:i', strtotime((string) $item['recorded_at'])));
?>
<div class="page-header"><h1><?= e($title) ?></h1></div>

<form method="post" action="<?= e(url("/readings/$id")) ?>" class="card form form--sections">
    <?= csrf_field() ?>
    <fieldset class="form-section">
        <legend><i data-lucide="truck"></i><a href="<?= e(url('/equipment/' . $item['equipment_id'])) ?>"><?= e($item['code']) ?></a> · <?= e($item['equipment_name']) ?> · <?= e($item['site']) ?></legend>
        <p class="hint">
            <?= e($item['recorded_by_name'] ? t('entry.by', ['name' => $item['recorded_by_name']]) : t('entry.by_unknown')) ?>
            <?php if ($item['fuel_mark'] !== null): ?> · <?= e(t('readings.fuel_mark_read', ['mark' => format_number((float) $item['fuel_mark'], 1), 'n' => $item['fuel_marks']])) ?><?php endif; ?>
        </p>
        <div class="form-grid">
            <?= $view->partial('partials/field', ['name' => 'recorded_at', 'labelKey' => 'fields.recorded_at', 'type' => 'datetime-local', 'value' => $recordedAt,
                'required' => true, 'attrs' => ['max' => date('Y-m-d\TH:i')]]) ?>
            <?= $f('hour_meter', 'number', ['attrs' => ['min' => 0, 'step' => 1, 'inputmode' => 'numeric']]) ?>
            <?= $f('odometer_km', 'number', ['attrs' => ['min' => 0, 'step' => 1, 'inputmode' => 'numeric']]) ?>
            <?= $f('fuel_level', 'number', ['attrs' => ['min' => 0, 'max' => 100, 'step' => 1, 'inputmode' => 'numeric'], 'hint' => t('readings.fuel_hint')]) ?>
            <?= $f('note', 'text', ['class' => 'span-2', 'attrs' => ['maxlength' => 255]]) ?>
        </div>
        <p class="hint"><?= e(t('readings.bounds_hint')) ?></p>
    </fieldset>

    <div class="form-actions">
        <button type="submit" class="btn btn--danger form-actions__start" form="reading-delete"><i data-lucide="trash-2"></i><?= e(t('common.delete')) ?></button>
        <a class="btn" href="<?= e(url('/readings')) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary"><i data-lucide="save"></i><?= e(t('common.save')) ?></button>
    </div>
</form>

<form method="post" action="<?= e(url("/readings/$id/delete")) ?>" id="reading-delete" hidden
      data-confirm="<?= e(t('readings.delete_confirm', ['code' => $item['code'], 'date' => format_date((string) $item['recorded_at'], true)])) ?>">
    <?= csrf_field() ?>
</form>
