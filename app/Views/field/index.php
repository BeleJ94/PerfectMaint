<?php /** @var array $queue */ ?>
<div class="field-actions">
    <?php if ($currentUser->can('workorder.create')): ?>
        <a class="btn btn--danger" href="<?= e(url('/terrain/declarer')) ?>"><i data-lucide="alert-octagon"></i><?= e(t('field.declare')) ?></a>
    <?php endif; ?>
    <a class="btn" href="<?= e(url('/equipment')) ?>"><i data-lucide="search"></i><?= e(t('field.find_equipment')) ?></a>
</div>

<h2><?= e(t('field.my_queue')) ?> <span class="muted">(<?= count($queue) ?>)</span></h2>
<?php if ($queue === []): ?>
    <p class="empty"><?= e(t('field.queue_empty')) ?></p>
<?php endif; ?>
<?php foreach ($queue as $wo): ?>
    <a class="wo-card wo-card--<?= e($wo['priority']) ?>" href="<?= e(url('/terrain/ot/' . $wo['id'])) ?>">
        <div class="wo-card__head">
            <span><?= e($wo['reference']) ?> · <?= e($wo['site']) ?></span>
            <?= wo_status_badge($wo['status']) ?>
        </div>
        <div class="wo-card__title"><?= e($wo['equipment_code']) ?> — <?= e($wo['title']) ?></div>
        <div class="wo-card__head">
            <span><?= priority_badge($wo['priority']) ?><?php if ($wo['immobilizing']): ?> <span class="badge badge--breakdown"><?= e(t('fields.immobilizing')) ?></span><?php endif; ?></span>
            <span><?= $wo['assigned_to'] === null ? e(t('work_orders.unassigned')) : e(t('field.assigned_to_me')) ?></span>
        </div>
    </a>
<?php endforeach; ?>
