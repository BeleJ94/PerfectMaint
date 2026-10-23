<?php
/**
 * @var array $wo  @var \App\Domain\WorkOrderStatus $status  @var array $transitions  @var bool $canExecute  @var array $parts
 * @var array $availableParts  @var array $activity  @var array $technicians  @var array $comments  @var array $maintenanceStaff  @var bool $canComment
 */
$total = 0.0;
foreach ($parts as $p) { $total += (float) $p['quantity'] * (float) $p['unit_cost']; }
$duration = null;
if ($wo['completed_at']) {
    $duration = (int) round((strtotime($wo['completed_at']) - strtotime($wo['reported_at'])) / 3600);
}
?>
<div class="wo-hero wo-hero--<?= e($wo['priority']) ?>">
    <div class="wo-hero__main">
        <div class="wo-hero__ref"><?= e($wo['reference']) ?> · <?= e(label('wo_type', $wo['type'])) ?></div>
        <h1><?= e($wo['title']) ?></h1>
        <div class="eq-hero__tags">
            <?= wo_status_badge($wo['status']) ?> <?= priority_badge($wo['priority']) ?>
            <?php if ($wo['immobilizing']): ?><span class="badge badge--breakdown"><?= e(t('fields.immobilizing')) ?></span><?php endif; ?>
            <?php if ($wo['failure_cause']): ?><span class="badge badge--cause badge--cause-<?= e($wo['failure_cause']) ?>"><?= e(t('cause.' . $wo['failure_cause'])) ?></span><?php endif; ?>
        </div>
    </div>
    <a class="wo-hero__equipment" href="<?= e(url('/equipment/' . $wo['equipment_id'])) ?>">
        <span class="wo-hero__eq-code"><?= e($wo['equipment_code']) ?></span>
        <span class="muted small"><?= e($wo['equipment_name']) ?> · <?= e($wo['site']) ?></span>
        <?= status_badge($wo['equipment_status']) ?>
    </a>
    <div class="eq-hero__actions">
        <button type="button" class="btn" data-wo-preview><i data-lucide="eye"></i><?= e(t('work_orders.preview')) ?></button>
        <button type="button" class="btn" data-wo-pdf><i data-lucide="printer"></i><?= e(t('work_orders.print')) ?></button>
        <?php if ($currentUser->can('workorder.manage') && !$status->isClosed()): ?>
            <a class="btn" href="<?= e(url('/work-orders/' . $wo['id'] . '/edit')) ?>"><i data-lucide="pencil"></i><?= e(t('common.edit')) ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if ($transitions !== [] && !$status->isClosed()): ?>
<section class="card card--actions">
    <div class="card__title"><h2><?= e(t('work_orders.actions')) ?></h2></div>
    <?= $view->partial('work_orders/_transitions', ['wo' => $wo, 'transitions' => $transitions, 'back' => 'desktop']) ?>
</section>
<?php endif; ?>

<div class="vitals">
    <div class="vital">
        <span class="vital__icon"><i data-lucide="user-round"></i></span>
        <div><div class="vital__label"><?= e(t('work_orders.operator_at_report')) ?></div>
        <div class="vital__value vital__value--sm"><?= e($wo['operator_name'] ?? t('work_orders.no_operator')) ?></div></div>
    </div>
    <div class="vital">
        <span class="vital__icon"><i data-lucide="timer"></i></span>
        <div><div class="vital__label"><?= e(t('work_orders.meters_at_report')) ?></div>
        <div class="vital__value vital__value--sm"><?= $wo['hour_meter'] !== null ? e(format_number($wo['hour_meter'])) . ' h' : '—' ?><?= $wo['odometer_km'] !== null ? ' · ' . e(format_number($wo['odometer_km'])) . ' km' : '' ?></div></div>
    </div>
    <div class="vital">
        <span class="vital__icon"><i data-lucide="hard-hat"></i></span>
        <div><div class="vital__label"><?= e(t('fields.assigned_to')) ?></div>
        <div class="vital__value vital__value--sm"><?= e($wo['assignee'] ?? t('work_orders.unassigned')) ?></div></div>
    </div>
    <div class="vital">
        <span class="vital__icon"><i data-lucide="hourglass"></i></span>
        <div><div class="vital__label"><?= e($duration !== null ? t('work_orders.downtime') : t('fields.due_date')) ?></div>
        <div class="vital__value vital__value--sm"><?= $duration !== null ? e(t('work_orders.hours_value', ['hours' => $duration])) : e(format_date($wo['due_date']) ?: '—') ?></div></div>
    </div>
    <div class="vital">
        <span class="vital__icon"><i data-lucide="coins"></i></span>
        <div><div class="vital__label"><?= e(t('work_orders.parts_cost')) ?></div>
        <div class="vital__value vital__value--sm"><?= e(format_money($total)) ?></div></div>
    </div>
</div>

<div class="grid grid--main-side">
    <div>
        <section class="card">
            <div class="card__title"><h2><?= e(t('work_orders.details')) ?></h2></div>
            <dl class="dl dl--2col">
                <dt><?= e(t('fields.reported_by')) ?></dt><dd><?= e($wo['reporter'] ?? '—') ?> · <?= e(format_date($wo['reported_at'], true)) ?></dd>
                <dt><?= e(t('fields.due_date')) ?></dt><dd><?= e(format_date($wo['due_date']) ?: '—') ?></dd>
                <?php if ($wo['plan_title']): ?><dt><?= e(t('fields.preventive_plan_id')) ?></dt><dd><?= e($wo['plan_title']) ?></dd><?php endif; ?>
                <?php if ($wo['started_at']): ?><dt><?= e(t('fields.started_at')) ?></dt><dd><?= e(format_date($wo['started_at'], true)) ?></dd><?php endif; ?>
                <?php if ($wo['completed_at']): ?><dt><?= e(t('fields.completed_at')) ?></dt><dd><?= e(format_date($wo['completed_at'], true)) ?></dd><?php endif; ?>
                <?php if ($wo['labor_minutes'] !== null): ?><dt><?= e(t('fields.labor_minutes')) ?></dt><dd><?= e($wo['labor_minutes']) ?> min</dd><?php endif; ?>
            </dl>
            <?php if ($wo['description']): ?>
                <h3 class="subhead"><?= e(t('fields.description')) ?></h3>
                <p><?= nl2br(e($wo['description'])) ?></p>
            <?php endif; ?>
            <?php if ($wo['resolution']): ?>
                <h3 class="subhead"><?= e(t('fields.resolution')) ?></h3>
                <p><?= nl2br(e($wo['resolution'])) ?></p>
            <?php endif; ?>
        </section>

        <?= $view->partial('work_orders/_comments', ['wo' => $wo, 'comments' => $comments, 'canComment' => $canComment, 'back' => 'desktop']) ?>
    </div>
    <div>
        <?= $view->partial('work_orders/_crew', ['wo' => $wo, 'technicians' => $technicians, 'maintenanceStaff' => $maintenanceStaff, 'canExecute' => $canExecute, 'back' => 'desktop']) ?>

        <section class="card">
            <div class="card__title"><h2><?= e(t('work_orders.parts')) ?></h2></div>
            <?php if ($parts === []): ?>
                <p class="empty"><?= e(t('common.none')) ?></p>
            <?php else: ?>
                <table class="table table--compact">
                    <tbody>
                    <?php foreach ($parts as $p): ?>
                        <tr>
                            <td><strong><?= e($p['reference']) ?></strong><br><span class="muted small"><?= e($p['name']) ?></span>
                                <?php if ($p['hour_meter'] !== null || $p['odometer_km'] !== null): ?>
                                    <br><span class="muted small"><i data-lucide="gauge" class="i-inline"></i><?= $p['hour_meter'] !== null ? e(format_number($p['hour_meter'])) . ' h' : '' ?><?= $p['odometer_km'] !== null ? ' · ' . e(format_number($p['odometer_km'])) . ' km' : '' ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="num nowrap"><?= e(format_qty($p['quantity'])) ?> <?= e($p['unit']) ?><br><span class="muted small"><?= e(format_money((float) $p['quantity'] * (float) $p['unit_cost'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <?php if ($canExecute): ?>
                <?= $view->partial('work_orders/_add_part', ['wo' => $wo, 'availableParts' => $availableParts, 'back' => 'desktop']) ?>
            <?php endif; ?>
        </section>
    </div>
</div>

<?= $view->partial('partials/activity', ['activity' => $activity]) ?>

<script type="application/json" id="wo-data"><?= json_for_html(['wo' => $wo, 'parts' => $parts, 'technicians' => $technicians ?? []]) ?></script>
