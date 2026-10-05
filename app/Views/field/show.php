<?php /** @var array $wo  @var \App\Domain\WorkOrderStatus $status  @var array $transitions  @var bool $canExecute  @var array $parts  @var array $availableParts  @var array $technicians  @var array $comments  @var array $maintenanceStaff  @var bool $canComment */ ?>
<div class="wo-card wo-card--<?= e($wo['priority']) ?>">
    <div class="wo-card__head"><span><?= e($wo['reference']) ?></span><?= wo_status_badge($wo['status']) ?></div>
    <div class="wo-card__title"><?= e($wo['title']) ?></div>
    <p style="margin: 8px 0"><strong><?= e($wo['equipment_code']) ?></strong> · <?= e($wo['equipment_name']) ?> <?= status_badge($wo['equipment_status']) ?></p>
    <p class="muted" style="margin:0"><?= e($wo['site']) ?> · <?= e(format_date($wo['reported_at'], true)) ?> · <?= e($wo['reporter'] ?? '') ?></p>
    <?php if ($wo['operator_name']): ?><p class="muted" style="margin:4px 0 0"><?= e(t('work_orders.operator_at_report')) ?> : <strong><?= e($wo['operator_name']) ?></strong></p><?php endif; ?>
    <?php if ($wo['description']): ?><p style="margin-top: 12px"><?= nl2br(e($wo['description'])) ?></p><?php endif; ?>
    <?php if ($wo['resolution']): ?><p><strong><?= e(t('fields.resolution')) ?> :</strong> <?= nl2br(e($wo['resolution'])) ?></p><?php endif; ?>
    <p style="margin: 12px 0 0"><button type="button" class="btn" data-wo-pdf><i data-lucide="printer"></i><?= e(t('work_orders.print')) ?></button></p>
</div>
<script type="application/json" id="wo-data"><?= json_for_html(['wo' => $wo, 'parts' => $parts]) ?></script>

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

<?= $view->partial('work_orders/_crew', ['wo' => $wo, 'technicians' => $technicians, 'maintenanceStaff' => $maintenanceStaff, 'canExecute' => $canExecute, 'back' => 'terrain']) ?>
<?= $view->partial('work_orders/_comments', ['wo' => $wo, 'comments' => $comments, 'canComment' => $canComment, 'back' => 'terrain']) ?>

<?php if ($transitions !== [] && !$status->isClosed()): ?>
    <?= $view->partial('work_orders/_transitions', ['wo' => $wo, 'transitions' => $transitions, 'back' => 'terrain']) ?>
<?php endif; ?>
