<?php
/**
 * Rapport de saisie d'une journée : consultation seule, aucun champ de saisie.
 * @var array $report  @var array $sites  @var int|null $siteId  @var \DateTimeImmutable $date  @var \DateTimeImmutable $today
 */
$view->share('pageScripts', ['js/entry.js']);
$iso = $date->format('Y-m-d');
$isToday = $date == $today;
$readCount = count($report['read']);
$delta = static function (?int $value, string $unit): string {
    if ($value === null) {
        return '';
    }

    return ' <span class="entry-report__delta">' . ($value >= 0 ? '+' : '−') . e(format_number(abs($value))) . ' ' . e($unit) . '</span>';
};
?>
<?= $view->partial('entry/_nav', ['sites' => $sites, 'siteId' => $siteId, 'tab' => 'report', 'query' => ['date' => $iso]]) ?>

<div class="entry-top">
    <form method="get" action="<?= e(url('/saisie/rapport')) ?>" class="entry-date">
        <a class="btn" href="<?= e(url('/saisie/rapport', ['date' => $date->modify('-1 day')->format('Y-m-d')])) ?>" aria-label="<?= e(t('entry.prev_day')) ?>"><i data-lucide="chevron-left"></i></a>
        <input type="date" name="date" class="input" value="<?= e($iso) ?>" max="<?= e($today->format('Y-m-d')) ?>" data-autosubmit aria-label="<?= e(t('js.date')) ?>">
        <?php if ($isToday): ?>
            <span class="btn" aria-disabled="true"><i data-lucide="chevron-right"></i></span>
        <?php else: ?>
            <a class="btn" href="<?= e(url('/saisie/rapport', ['date' => $date->modify('+1 day')->format('Y-m-d')])) ?>" aria-label="<?= e(t('entry.next_day')) ?>"><i data-lucide="chevron-right"></i></a>
        <?php endif; ?>
    </form>
    <div class="entry-progress">
        <div class="entry-progress__text">
            <strong><?= e($readCount) ?> / <?= e($report['total']) ?></strong>
            <span><?= e(t('entry.progress')) ?> · <?= e($isToday ? t('js.meters.today') : format_date($iso)) ?> · <?= e(t('entry.mine', ['count' => $report['mine']])) ?></span>
        </div>
        <div class="meter meter--good"><span style="width: <?= e($report['total'] > 0 ? round($readCount / $report['total'] * 100) : 0) ?>%"></span></div>
    </div>
    <p class="entry-readonly"><i data-lucide="eye"></i><?= e(t('entry.read_only')) ?></p>
</div>

<?php if ($report['total'] === 0): ?>
    <p class="empty"><?= e(t('entry.no_equipment')) ?></p>
<?php endif; ?>

<?php if ($report['read'] !== []): ?>
    <h2 class="entry-section"><?= e(t('entry.report_read')) ?> <span><?= e($readCount) ?></span></h2>
    <div class="entry-list">
        <?php foreach ($report['read'] as $row): ?>
            <article class="entry-card is-done">
                <header class="entry-card__head">
                    <div>
                        <strong><?= e($row['code']) ?></strong>
                        <span class="muted small"><?= e($row['label']) ?> · <?= e($row['site']) ?></span>
                    </div>
                    <span class="badge badge--<?= $row['own'] ? 'maintenance' : 'neutral' ?>"><?= e(date('H:i', strtotime($row['recorded_at']))) ?></span>
                </header>
                <dl class="entry-report">
                    <?php if ($row['hour_meter'] !== null): ?>
                        <div><dt><?= e(t('entry.hours')) ?></dt><dd><?= e(format_number($row['hour_meter'])) ?> h<?= $delta($row['delta_hours'], 'h') ?></dd></div>
                    <?php endif; ?>
                    <?php if ($row['odometer_km'] !== null): ?>
                        <div><dt><?= e(t('entry.km')) ?></dt><dd><?= e(format_number($row['odometer_km'])) ?> km<?= $delta($row['delta_km'], 'km') ?></dd></div>
                    <?php endif; ?>
                    <?php if ($row['fuel_level'] !== null): ?>
                        <div><dt><?= e(t('entry.fuel')) ?></dt><dd><?= e($row['fuel_level']) ?> %</dd></div>
                    <?php endif; ?>
                </dl>
                <p class="muted small entry-report__by">
                    <?= e($row['recorded_by_name'] ? t('entry.by', ['name' => $row['recorded_by_name']]) : t('entry.by_unknown')) ?>
                    <?php if ((int) $row['readings'] > 1): ?> · <?= e(t('entry.readings_count', ['count' => (int) $row['readings']])) ?><?php endif; ?>
                    <?php if ($row['note']): ?> · <?= e($row['note']) ?><?php endif; ?>
                </p>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($report['missing'] !== []): ?>
    <h2 class="entry-section"><?= e(t('entry.report_missing')) ?> <span><?= e(count($report['missing'])) ?></span></h2>
    <ul class="entry-missing">
        <?php foreach ($report['missing'] as $row): ?>
            <li><strong><?= e($row['code']) ?></strong><span class="muted small"><?= e($row['label']) ?> · <?= e($row['site']) ?></span></li>
        <?php endforeach; ?>
    </ul>
<?php elseif ($report['total'] > 0): ?>
    <p class="entry-all-done"><i data-lucide="check-circle-2"></i><?= e(t('entry.report_complete')) ?></p>
<?php endif; ?>
