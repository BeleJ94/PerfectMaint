<?php /** @var array $wo  @var array $availableParts  @var string $back */ ?>
<?php if ($availableParts === []): ?>
    <p class="muted" style="margin-top: 12px"><?= e(t('work_orders.no_parts_in_stock')) ?></p>
<?php else: ?>
<form method="post" action="<?= e(url('/work-orders/' . $wo['id'] . '/parts')) ?>" class="grid" style="margin-top: 16px">
    <?= csrf_field() ?>
    <input type="hidden" name="back" value="<?= e($back) ?>">
    <div class="field">
        <label for="part-id"><?= e(t('work_orders.add_part')) ?></label>
        <select id="part-id" name="part_id" class="input" required>
            <option value="">—</option>
            <?php foreach ($availableParts as $part): ?>
                <option value="<?= e($part['id']) ?>"><?= e($part['reference'] . ' · ' . $part['name'] . ' (' . format_qty($part['quantity']) . ' ' . $part['unit'] . ')') ?></option>
            <?php endforeach; ?>
        </select>
        <?php if (field_error('part_id')): ?><span class="error"><?= e(field_error('part_id')) ?></span><?php endif; ?>
    </div>
    <div class="field">
        <label for="part-qty"><?= e(t('fields.quantity')) ?></label>
        <input id="part-qty" name="quantity" type="number" min="0.01" step="0.01" value="1" inputmode="decimal" class="input" required>
        <?php if (field_error('quantity')): ?><span class="error"><?= e(field_error('quantity')) ?></span><?php endif; ?>
    </div>
    <button type="submit" class="btn"><i data-lucide="plus"></i><?= e(t('work_orders.consume')) ?></button>
</form>
<?php endif; ?>
