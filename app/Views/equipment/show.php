<?php
/** @var array $item  @var array $history  @var array $workOrders  @var array $plans  @var array $activity  @var \App\Domain\EquipmentStatus[] $statuses */
?>
<div class="page-header">
    <div>
        <h1><?= e($item['code']) ?> · <?= e($item['name']) ?></h1>
        <?= status_badge($item['status']) ?> <span class="muted"><?= e(t('equipment.since', ['date' => format_date($item['status_changed_at'], true)])) ?></span>
    </div>
    <div class="page-header__actions">
        <?php if ($currentUser->can('workorder.create')): ?>
            <a class="btn btn--danger" href="<?= e(url('/work-orders/create', ['equipment_id' => $item['id']])) ?>"><i data-lucide="alert-octagon"></i><?= e(t('equipment.declare_breakdown')) ?></a>
        <?php endif; ?>
        <?php if ($currentUser->can('equipment.status')): ?>
            <button type="button" class="btn" data-open="#status-dialog"><i data-lucide="refresh-cw"></i><?= e(t('equipment.change_status')) ?></button>
        <?php endif; ?>
        <?php if ($currentUser->can('equipment.manage')): ?>
            <a class="btn" href="<?= e(url('/equipment/' . $item['id'] . '/edit')) ?>"><i data-lucide="pencil"></i><?= e(t('common.edit')) ?></a>
            <form method="post" action="<?= e(url('/equipment/' . $item['id'] . '/archive')) ?>" data-confirm="<?= e(t('equipment.archive_confirm')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn--ghost"><i data-lucide="archive"></i><?= e(t('common.archive')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid--main-side">
    <div>
        <section class="card">
            <div class="card__title"><h2><?= e(t('equipment.details')) ?></h2></div>
            <dl class="dl">
                <dt><?= e(t('fields.site_id')) ?></dt><dd><?= e($item['site']) ?></dd>
                <dt><?= e(t('fields.category_id')) ?></dt><dd><?= e($item['category'] ?? '—') ?></dd>
                <dt><?= e(t('fields.brand')) ?> / <?= e(t('fields.model')) ?></dt><dd><?= e(trim(($item['brand'] ?? '') . ' ' . ($item['model'] ?? '')) ?: '—') ?></dd>
                <dt><?= e(t('fields.serial_number')) ?></dt><dd><?= e($item['serial_number'] ?? '—') ?></dd>
                <dt><?= e(t('fields.commissioned_at')) ?></dt><dd><?= e(format_date($item['commissioned_at']) ?: '—') ?></dd>
                <dt><?= e(t('fields.hour_meter')) ?></dt><dd><?= $item['hour_meter'] !== null ? e(format_number($item['hour_meter'])) . ' h' : '—' ?></dd>
                <?php if ($item['notes']): ?><dt><?= e(t('fields.notes')) ?></dt><dd><?= nl2br(e($item['notes'])) ?></dd><?php endif; ?>
            </dl>
        </section>

        <section class="card">
            <div class="card__title">
                <h2><?= e(t('equipment.work_orders')) ?></h2>
                <a href="<?= e(url('/work-orders', ['equipment_id' => $item['id'], 'status' => 'all'])) ?>"><?= e(t('common.see_all')) ?></a>
            </div>
            <?php if ($workOrders === []): ?>
                <p class="empty"><?= e(t('common.none')) ?></p>
            <?php else: ?>
                <div class="table-wrap"><table class="table">
                    <thead><tr><th><?= e(t('fields.reference')) ?></th><th><?= e(t('fields.title')) ?></th><th><?= e(t('fields.status')) ?></th><th><?= e(t('fields.reported_at')) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($workOrders as $wo): ?>
                        <tr>
                            <td class="nowrap"><a href="<?= e(url('/work-orders/' . $wo['id'])) ?>"><?= e($wo['reference']) ?></a></td>
                            <td><?= e($wo['title']) ?> <span class="muted">· <?= e(label('wo_type', $wo['type'])) ?></span></td>
                            <td><?= wo_status_badge($wo['status']) ?></td>
                            <td class="nowrap"><?= e(format_date($wo['reported_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </section>
    </div>

    <div>
        <section class="card">
            <div class="card__title">
                <h2><?= e(t('nav.preventive')) ?></h2>
                <?php if ($currentUser->can('preventive.manage')): ?>
                    <a class="btn btn--sm" href="<?= e(url('/preventive/create', ['equipment_id' => $item['id']])) ?>"><i data-lucide="plus"></i><?= e(t('common.add')) ?></a>
                <?php endif; ?>
            </div>
            <?php if ($plans === []): ?>
                <p class="empty"><?= e(t('common.none')) ?></p>
            <?php else: ?>
                <ul class="timeline">
                    <?php foreach ($plans as $plan): ?>
                        <li>
                            <strong><?= e($plan['title']) ?></strong><?= $plan['is_active'] ? '' : ' <span class="badge badge--neutral">' . e(t('common.inactive')) . '</span>' ?>
                            <time><?= e(t('preventive.next_due')) ?> : <?= e(format_date($plan['next_due_date']) ?: '—') ?><?= $plan['next_due_hours'] !== null ? ' · ' . e(format_number($plan['next_due_hours'])) . ' h' : '' ?></time>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="card__title"><h2><?= e(t('equipment.status_history')) ?></h2></div>
            <?php if ($history === []): ?>
                <p class="empty"><?= e(t('common.none')) ?></p>
            <?php else: ?>
                <ul class="timeline">
                    <?php foreach ($history as $h): ?>
                        <li>
                            <time><?= e(format_date($h['changed_at'], true)) ?><?= $h['changed_by'] ? ' · ' . e($h['changed_by']) : '' ?></time>
                            <?= $h['from_status'] ? status_badge($h['from_status']) . ' → ' : '' ?><?= status_badge($h['to_status']) ?>
                            <?php if ($h['reason']): ?><div class="muted"><?= e($h['reason']) ?></div><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>

<?= $view->partial('partials/activity', ['activity' => $activity]) ?>

<?php if ($currentUser->can('equipment.status')): ?>
<template id="status-dialog">
    <form method="post" action="<?= e(url('/equipment/' . $item['id'] . '/status')) ?>" class="grid" style="text-align:left">
        <?= csrf_field() ?>
        <div class="field">
            <label for="st-status"><?= e(t('fields.status')) ?></label>
            <select id="st-status" name="status" class="input" required>
                <?php foreach ($statuses as $status): ?>
                    <option value="<?= e($status->value) ?>"<?= $status->value === $item['status'] ? ' selected' : '' ?>><?= e(t($status->labelKey())) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="st-reason" class="required"><?= e(t('fields.reason')) ?></label>
            <input id="st-reason" name="reason" class="input" required maxlength="255">
        </div>
    </form>
</template>
<?php endif; ?>
