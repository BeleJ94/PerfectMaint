<?php /** @var array $wo  @var \App\Domain\WorkOrderStatus $status  @var array $transitions  @var bool $canExecute  @var array $parts  @var array $availableParts */ ?>
<div class="wo-card wo-card--<?= e($wo['priority']) ?>">
    <div class="wo-card__head"><span><?= e($wo['reference']) ?></span><?= wo_status_badge($wo['status']) ?></div>
    <div class="wo-card__title"><?= e($wo['title']) ?></div>
    <p style="margin: 8px 0"><strong><?= e($wo['equipment_code']) ?></strong> · <?= e($wo['equipment_name']) ?> <?= status_badge($wo['equipment_status']) ?></p>
    <p class="muted" style="margin:0"><?= e($wo['site']) ?> · <?= e(format_date($wo['reported_at'], true)) ?> · <?= e($wo['reporter'] ?? '') ?></p>
    <?php if ($wo['description']): ?><p style="margin-top: 12px"><?= nl2br(e($wo['description'])) ?></p><?php endif; ?>
    <?php if ($wo['resolution']): ?><p><strong><?= e(t('fields.resolution')) ?> :</strong> <?= nl2br(e($wo['resolution'])) ?></p><?php endif; ?>
</div>

<?php if ($parts !== []): ?>
    <h2><?= e(t('work_orders.parts')) ?></h2>
    <ul class="timeline">
        <?php foreach ($parts as $p): ?>
            <li><strong><?= e(format_qty($p['quantity'])) ?> <?= e($p['unit']) ?></strong> · <?= e($p['reference']) ?> — <?= e($p['name']) ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($canExecute): ?>
    <details class="card" style="padding: 16px">
        <summary style="min-height: 44px; display: flex; align-items: center; font-weight: 600; cursor: pointer"><?= e(t('work_orders.add_part')) ?></summary>
        <?= $view->partial('work_orders/_add_part', ['wo' => $wo, 'availableParts' => $availableParts, 'back' => 'terrain']) ?>
    </details>
<?php endif; ?>

<?php if ($transitions !== [] && !$status->isClosed()): ?>
    <?= $view->partial('work_orders/_transitions', ['wo' => $wo, 'transitions' => $transitions, 'back' => 'terrain']) ?>
<?php endif; ?>
