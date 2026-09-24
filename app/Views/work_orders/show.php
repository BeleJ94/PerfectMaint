<?php
/** @var array $wo  @var \App\Domain\WorkOrderStatus $status  @var array $transitions  @var bool $canExecute  @var array $parts  @var array $availableParts  @var array $activity */
$total = 0.0;
foreach ($parts as $p) { $total += (float) $p['quantity'] * (float) $p['unit_cost']; }
?>
<div class="page-header">
    <div>
        <h1><?= e($wo['reference']) ?> · <?= e($wo['title']) ?></h1>
        <?= wo_status_badge($wo['status']) ?> <?= priority_badge($wo['priority']) ?>
        <span class="badge badge--neutral"><?= e(label('wo_type', $wo['type'])) ?></span>
        <?php if ($wo['immobilizing']): ?><span class="badge badge--breakdown"><?= e(t('fields.immobilizing')) ?></span><?php endif; ?>
    </div>
    <div class="page-header__actions">
        <button type="button" class="btn" data-wo-pdf><i data-lucide="printer"></i><?= e(t('work_orders.print')) ?></button>
        <?php if ($currentUser->can('workorder.manage') && !$status->isClosed()): ?>
            <a class="btn" href="<?= e(url('/work-orders/' . $wo['id'] . '/edit')) ?>"><i data-lucide="pencil"></i><?= e(t('common.edit')) ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if ($transitions !== [] && !$status->isClosed()): ?>
<section class="card">
    <div class="card__title"><h2><?= e(t('work_orders.actions')) ?></h2></div>
    <?= $view->partial('work_orders/_transitions', ['wo' => $wo, 'transitions' => $transitions, 'back' => 'desktop']) ?>
</section>
<?php endif; ?>

<div class="grid grid--main-side">
    <div>
        <section class="card">
            <div class="card__title"><h2><?= e(t('work_orders.details')) ?></h2></div>
            <dl class="dl">
                <dt><?= e(t('fields.equipment_id')) ?></dt>
                <dd><a href="<?= e(url('/equipment/' . $wo['equipment_id'])) ?>"><?= e($wo['equipment_code']) ?> · <?= e($wo['equipment_name']) ?></a> <?= status_badge($wo['equipment_status']) ?></dd>
                <dt><?= e(t('fields.site_id')) ?></dt><dd><?= e($wo['site']) ?></dd>
                <dt><?= e(t('fields.reported_by')) ?></dt><dd><?= e($wo['reporter'] ?? '—') ?> · <?= e(format_date($wo['reported_at'], true)) ?></dd>
                <dt><?= e(t('fields.assigned_to')) ?></dt><dd><?= e($wo['assignee'] ?? t('work_orders.unassigned')) ?></dd>
                <dt><?= e(t('fields.due_date')) ?></dt><dd><?= e(format_date($wo['due_date']) ?: '—') ?></dd>
                <?php if ($wo['plan_title']): ?><dt><?= e(t('fields.preventive_plan_id')) ?></dt><dd><?= e($wo['plan_title']) ?></dd><?php endif; ?>
                <?php if ($wo['started_at']): ?><dt><?= e(t('fields.started_at')) ?></dt><dd><?= e(format_date($wo['started_at'], true)) ?></dd><?php endif; ?>
                <?php if ($wo['completed_at']): ?><dt><?= e(t('fields.completed_at')) ?></dt><dd><?= e(format_date($wo['completed_at'], true)) ?></dd><?php endif; ?>
                <?php if ($wo['labor_minutes'] !== null): ?><dt><?= e(t('fields.labor_minutes')) ?></dt><dd><?= e($wo['labor_minutes']) ?> min</dd><?php endif; ?>
                <?php if ($wo['hour_meter'] !== null): ?><dt><?= e(t('fields.hour_meter')) ?></dt><dd><?= e(format_number($wo['hour_meter'])) ?> h</dd><?php endif; ?>
            </dl>
            <?php if ($wo['description']): ?>
                <h3 style="margin-top: 16px"><?= e(t('fields.description')) ?></h3>
                <p><?= nl2br(e($wo['description'])) ?></p>
            <?php endif; ?>
            <?php if ($wo['resolution']): ?>
                <h3><?= e(t('fields.resolution')) ?></h3>
                <p><?= nl2br(e($wo['resolution'])) ?></p>
            <?php endif; ?>
        </section>
    </div>
    <div>
        <section class="card">
            <div class="card__title"><h2><?= e(t('work_orders.parts')) ?></h2></div>
            <?php if ($parts === []): ?>
                <p class="empty"><?= e(t('common.none')) ?></p>
            <?php else: ?>
                <table class="table">
                    <tbody>
                    <?php foreach ($parts as $p): ?>
                        <tr><td><strong><?= e($p['reference']) ?></strong><br><span class="muted"><?= e($p['name']) ?></span></td><td class="num nowrap"><?= e(format_qty($p['quantity'])) ?> <?= e($p['unit']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="muted" style="margin-top: 8px"><?= e(t('work_orders.parts_cost')) ?> : <?= e(format_number($total, 2)) ?> €</p>
            <?php endif; ?>
            <?php if ($canExecute): ?>
                <?= $view->partial('work_orders/_add_part', ['wo' => $wo, 'availableParts' => $availableParts, 'back' => 'desktop']) ?>
            <?php endif; ?>
        </section>
    </div>
</div>

<?= $view->partial('partials/activity', ['activity' => $activity]) ?>

<script type="application/json" id="wo-data"><?= json_for_html(['wo' => $wo, 'parts' => $parts]) ?></script>
