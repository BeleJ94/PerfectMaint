<?php
/** @var array $item  @var array $openWorkOrders */
$rows = [
    'fields.category_id' => $item['category'],
    'fields.model' => trim(($item['brand'] ?? '') . ' ' . ($item['model'] ?? '')),
    'fields.plate_number' => $item['plate_number'],
    'fields.serial_number' => $item['serial_number'],
    'fields.hour_meter' => $item['hour_meter'] !== null ? format_number($item['hour_meter']) . ' h' : null,
    'fields.odometer_km' => $item['odometer_km'] !== null ? format_number($item['odometer_km']) . ' km' : null,
    'fields.fuel_level' => $item['fuel_level'] !== null ? format_number($item['fuel_level']) . ' %' : null,
];
?>
<div class="wo-card">
    <div class="wo-card__head"><span><?= e($item['site']) ?></span><?= status_badge($item['status']) ?></div>
    <div class="wo-card__title"><?= e($item['code']) ?> — <?= e($item['name']) ?></div>
    <dl class="dl" style="margin-top: 12px">
        <?php foreach ($rows as $labelKey => $value): ?>
            <?php if ($value !== null && $value !== ''): ?>
                <dt><?= e(t($labelKey)) ?></dt><dd><?= e($value) ?></dd>
            <?php endif; ?>
        <?php endforeach; ?>
    </dl>
</div>

<?php if ($currentUser->can('workorder.create') && !$item['is_archived']): ?>
    <div class="field-actions">
        <a class="btn btn--danger" href="<?= e(url('/terrain/declarer', ['equipment_id' => $item['id']])) ?>"><i data-lucide="alert-octagon"></i><?= e(t('field.declare')) ?></a>
    </div>
<?php endif; ?>

<h2><?= e(t('field.open_work_orders')) ?> <span class="muted">(<?= count($openWorkOrders) ?>)</span></h2>
<?php if ($openWorkOrders === []): ?>
    <p class="empty"><?= e(t('field.no_open_work_order')) ?></p>
<?php endif; ?>
<?php foreach ($openWorkOrders as $wo): ?>
    <a class="wo-card wo-card--<?= e($wo['priority']) ?>" href="<?= e(url('/terrain/ot/' . $wo['id'])) ?>">
        <div class="wo-card__head">
            <span><?= e($wo['reference']) ?> · <?= e(format_date($wo['reported_at'], true)) ?></span>
            <?= wo_status_badge($wo['status']) ?>
        </div>
        <div class="wo-card__title"><?= e($wo['title']) ?></div>
        <div class="wo-card__head">
            <span><?= priority_badge($wo['priority']) ?><?php if ($wo['immobilizing']): ?> <span class="badge badge--breakdown"><?= e(t('fields.immobilizing')) ?></span><?php endif; ?></span>
        </div>
    </a>
<?php endforeach; ?>

<p style="margin-top: 16px"><a class="btn" href="<?= e(url('/equipment/' . $item['id'])) ?>"><i data-lucide="external-link"></i><?= e(t('field.full_record')) ?></a></p>
