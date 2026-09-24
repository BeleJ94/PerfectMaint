<?php
/** @var array $item  @var int|null $id  @var array|null $equipment  @var array $assignees */
$priorityOptions = [];
foreach (\App\Services\WorkOrderService::PRIORITIES as $p) { $priorityOptions[$p] = label('priority', $p); }
$typeOptions = [];
foreach (\App\Services\WorkOrderService::TYPES as $tp) { $typeOptions[$tp] = label('wo_type', $tp); }
$assigneeOptions = ['' => t('work_orders.unassigned')];
foreach ($assignees as $a) { $assigneeOptions[$a['id']] = $a['name']; }
$f = static fn (string $name, string $type = 'text', array $extra = []) => $view->partial('partials/field', $extra + [
    'name' => $name, 'labelKey' => 'fields.' . $name, 'type' => $type, 'value' => old($name, $item[$name] ?? null),
]);
$immobilizing = (bool) old('immobilizing', $item['immobilizing'] ?? false);
?>
<div class="page-header"><h1><?= e($title) ?></h1></div>
<form method="post" action="<?= e(url($id ? "/work-orders/$id" : '/work-orders')) ?>" class="card form">
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="field span-2">
            <?php if ($id): ?>
                <span class="label"><?= e(t('fields.equipment_id')) ?></span>
                <div class="selected-equipment"><span><strong><?= e($equipment['code'] ?? '') ?></strong> · <?= e($equipment['name'] ?? '') ?></span><?= status_badge($equipment['status'] ?? null) ?></div>
            <?php else: ?>
                <?= $view->partial('partials/equipment_picker', ['equipment' => $equipment, 'withAssignees' => true]) ?>
            <?php endif; ?>
        </div>
        <?= $f('title', 'text', ['required' => true, 'class' => 'span-2', 'attrs' => ['maxlength' => 150]]) ?>
        <?= $f('description', 'textarea', ['class' => 'span-2']) ?>
        <?php if (!$id): ?><?= $f('type', 'select', ['options' => $typeOptions]) ?><?php endif; ?>
        <?= $f('priority', 'select', ['required' => true, 'options' => $priorityOptions]) ?>
        <?php if ($currentUser->can('workorder.manage')): ?>
            <?= $f('assigned_to', 'select', ['options' => $assigneeOptions, 'attrs' => ['data-assignee-select' => '1']]) ?>
        <?php endif; ?>
        <?= $f('due_date', 'date') ?>
        <div class="field span-2">
            <input type="hidden" name="immobilizing" value="0">
            <label class="check"><input type="checkbox" name="immobilizing" value="1"<?= $immobilizing ? ' checked' : '' ?>> <?= e(t('fields.immobilizing')) ?></label>
            <span class="hint"><?= e(t('work_orders.immobilizing_hint')) ?></span>
        </div>
    </div>
    <div class="form-actions">
        <a class="btn" href="<?= e(url($id ? "/work-orders/$id" : '/work-orders')) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary"><i data-lucide="save"></i><?= e(t('common.save')) ?></button>
    </div>
</form>
