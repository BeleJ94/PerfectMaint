<?php
/** Boutons de changement de statut ; « Terminer » ouvre une saisie (résolution, temps, compteur). */
/** @var array $wo  @var \App\Domain\WorkOrderStatus[] $transitions  @var string $back */
$icons = ['in_progress' => 'play', 'waiting_part' => 'package-search', 'done' => 'check-circle-2', 'cancelled' => 'x-circle'];
$classes = ['in_progress' => 'btn--primary', 'done' => 'btn--primary', 'cancelled' => 'btn--ghost'];
?>
<div class="<?= $back === 'terrain' ? 'sticky-actions' : 'page-header__actions' ?>">
    <?php foreach ($transitions as $target): ?>
        <?php if ($target === \App\Domain\WorkOrderStatus::Done): ?>
            <button type="button" class="btn btn--primary<?= $back === 'terrain' ? ' btn--lg' : '' ?>" data-open="#done-dialog"><i data-lucide="check-circle-2"></i><?= e(t('transition.done')) ?></button>
        <?php else: ?>
            <form method="post" action="<?= e(url('/work-orders/' . $wo['id'] . '/transition')) ?>"<?= $target === \App\Domain\WorkOrderStatus::Cancelled ? ' data-confirm="' . e(t('work_orders.cancel_confirm')) . '"' : '' ?>>
                <?= csrf_field() ?>
                <input type="hidden" name="status" value="<?= e($target->value) ?>">
                <input type="hidden" name="back" value="<?= e($back) ?>">
                <button type="submit" class="btn btn--block <?= e($classes[$target->value] ?? '') ?><?= $back === 'terrain' ? ' btn--lg' : '' ?>"><i data-lucide="<?= e($icons[$target->value]) ?>"></i><?= e(t('transition.' . $target->value)) ?></button>
            </form>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<template id="done-dialog">
    <form method="post" action="<?= e(url('/work-orders/' . $wo['id'] . '/transition')) ?>" class="grid" style="text-align:left">
        <?= csrf_field() ?>
        <input type="hidden" name="status" value="done">
        <input type="hidden" name="back" value="<?= e($back) ?>">
        <div class="field">
            <label for="done-resolution" class="required"><?= e(t('fields.resolution')) ?></label>
            <textarea id="done-resolution" name="resolution" class="input" required maxlength="5000"></textarea>
        </div>
        <div class="field">
            <label for="done-cause"<?= $wo['type'] === 'corrective' ? ' class="required"' : '' ?>><?= e(t('fields.failure_cause')) ?></label>
            <select id="done-cause" name="failure_cause" class="input"<?= $wo['type'] === 'corrective' ? ' required' : '' ?>>
                <option value=""></option>
                <?php foreach (\App\Services\WorkOrderService::FAILURE_CAUSES as $cause): ?>
                    <option value="<?= e($cause) ?>"><?= e(t('cause.' . $cause)) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (!empty($wo['operator_name'])): ?><span class="hint"><?= e(t('work_orders.cause_hint', ['name' => $wo['operator_name']])) ?></span><?php endif; ?>
        </div>
        <div class="form-grid">
            <div class="field">
                <label for="done-hours"><?= e(t('fields.hour_meter')) ?></label>
                <input id="done-hours" name="hour_meter" type="number" min="<?= e($wo['equipment_hour_meter'] ?? 0) ?>" step="1" inputmode="numeric" class="input" value="<?= e($wo['equipment_hour_meter'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="done-km"><?= e(t('fields.odometer_km')) ?></label>
                <input id="done-km" name="odometer_km" type="number" min="<?= e($wo['equipment_odometer'] ?? 0) ?>" step="1" inputmode="numeric" class="input" value="<?= e($wo['equipment_odometer'] ?? '') ?>">
            </div>
        </div>
        <div class="field">
            <label for="done-labor"><?= e(t('fields.labor_minutes')) ?></label>
            <input id="done-labor" name="labor_minutes" type="number" min="0" step="5" inputmode="numeric" class="input" placeholder="<?= e(t('work_orders.labor_auto')) ?>">
        </div>
    </form>
</template>
