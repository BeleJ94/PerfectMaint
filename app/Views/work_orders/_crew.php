<?php
/**
 * Intervenants d'un OT et temps passé.
 * @var array $wo  @var array $technicians  @var array $maintenanceStaff  @var bool $canExecute  @var string $back
 */
$totalMinutes = array_sum(array_map(static fn (array $t) => (int) $t['minutes'], $technicians));
$already = array_map('intval', array_column($technicians, 'personnel_id'));
$format = static fn (int $m) => $m >= 60 ? intdiv($m, 60) . ' h ' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT) : $m . ' min';
?>
<section class="card">
    <div class="card__title">
        <h2><?= e(t('work_orders.crew')) ?></h2>
        <?php if ($totalMinutes > 0): ?><span class="chip"><i data-lucide="timer"></i>&nbsp;<?= e($format($totalMinutes)) ?></span><?php endif; ?>
    </div>
    <?php if ($technicians === []): ?>
        <p class="empty"><?= e(t('work_orders.no_crew')) ?></p>
    <?php else: ?>
        <ul class="crew">
            <?php foreach ($technicians as $tech): ?>
                <li>
                    <span class="avatar avatar--sm" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($tech['name'], 0, 1) . mb_substr((string) strrchr($tech['name'], ' '), 1, 1))) ?></span>
                    <span class="crew__name"><strong><?= e($tech['name']) ?></strong><small><?= e(t('job.' . $tech['job'])) ?></small></span>
                    <span class="crew__time"><?= $tech['minutes'] !== null ? e($format((int) $tech['minutes'])) : '—' ?></span>
                    <?php if ($canExecute): ?>
                        <form method="post" action="<?= e(url('/work-orders/' . $wo['id'] . '/technicians/' . $tech['id'] . '/delete')) ?>" data-confirm="<?= e(t('work_orders.remove_tech_confirm', ['name' => $tech['name']])) ?>">
                            <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
                            <button type="submit" class="icon-btn icon-btn--danger" aria-label="<?= e(t('common.delete')) ?>"><i data-lucide="x"></i></button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($canExecute && $maintenanceStaff !== []): ?>
        <form method="post" action="<?= e(url('/work-orders/' . $wo['id'] . '/technicians')) ?>" class="crew-add">
            <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
            <select name="personnel_id" class="input input--sm" required aria-label="<?= e(t('work_orders.technician')) ?>">
                <option value=""><?= e(t('work_orders.technician')) ?>…</option>
                <?php foreach ($maintenanceStaff as $person): ?>
                    <option value="<?= e($person['id']) ?>"><?= e($person['name']) ?> · <?= e(t('job.' . $person['job'])) ?><?= in_array((int) $person['id'], $already, true) ? ' ✓' : '' ?></option>
                <?php endforeach; ?>
            </select>
            <input name="minutes" type="number" class="input input--sm crew-add__min" min="0" max="10000" step="5" inputmode="numeric" placeholder="min" aria-label="<?= e(t('work_orders.minutes')) ?>">
            <button type="submit" class="btn btn--sm btn--primary"><i data-lucide="plus"></i><span class="sr-only"><?= e(t('common.add')) ?></span></button>
        </form>
        <p class="hint small"><?= e(t('work_orders.crew_hint')) ?></p>
    <?php endif; ?>
</section>
