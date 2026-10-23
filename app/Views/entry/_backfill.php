<?php
/**
 * Engin non relevé un jour passé : carte de rattrapage (formulaire replié, ouvert à la demande).
 * @var array $row ligne « missing » du rapport (MeterEntryService::report)  @var string $date jour à rattraper (Y-m-d)
 */
$id = (int) $row['id'];
$field = static function (string $name, string $label, ?int $prev) use ($id): string {
    return '<label class="entry-field"><span>' . e($label) . '</span>'
        . '<input type="number" name="' . e($name) . '" class="input" inputmode="numeric" step="1" min="' . e($prev ?? 0) . '" autocomplete="off"'
        . ' id="backfill-' . e($name) . '-' . $id . '" placeholder="' . e($prev ?? '') . '"></label>';
};
$fields = [];
if ($row['has_hours']) {
    $fields[] = $field('hour_meter', t('entry.hours'), $row['prev_hours'] !== null ? (int) $row['prev_hours'] : null);
}
if ($row['has_km']) {
    $km = $field('odometer_km', t('entry.km'), $row['prev_km'] !== null ? (int) $row['prev_km'] : null);
    $row['km_first'] ? array_unshift($fields, $km) : $fields[] = $km;
}
$marks = $row['fuel_kind'] === 'marks' && $row['fuel_marks'] ? (int) $row['fuel_marks'] : null;
$capacity = $row['tank_capacity'] !== null ? (int) $row['tank_capacity'] : null;
?>
<article class="entry-card" data-entry-card>
    <header class="entry-card__head">
        <div>
            <strong><?= e($row['code']) ?></strong>
            <span class="muted small"><?= e($row['label']) ?> · <?= e($row['site']) ?></span>
        </div>
        <span class="badge badge--breakdown"><?= e(t('entry.todo')) ?></span>
    </header>
    <button type="button" class="btn btn--block" data-entry-edit><i data-lucide="calendar-plus"></i><?= e(t('entry.backfill', ['date' => format_date($date)])) ?></button>
    <form method="post" action="<?= e(url('/saisie/' . $id . '/jour')) ?>" class="entry-card__form" data-backfill-form hidden>
        <?= csrf_field() ?>
        <input type="hidden" name="date" value="<?= e($date) ?>">
        <div class="entry-card__inputs" data-fuel-field data-marks="<?= e($marks) ?>" data-capacity="<?= e($capacity) ?>">
            <div class="entry-card__fields entry-card__fields--<?= e(count($fields) + 1) ?>">
                <?= implode('', $fields) ?>
                <label class="entry-field">
                    <span><?= e($marks ? t('entry.fuel_marks', ['n' => $marks]) : t('entry.fuel_percent')) ?></span>
                    <?php if ($marks): ?>
                        <input type="number" name="fuel_mark" class="input" inputmode="decimal" step="0.5" min="0" max="<?= e($marks) ?>"
                               autocomplete="off" placeholder="<?= e(t('entry.fuel_marks_placeholder', ['n' => $marks])) ?>">
                    <?php else: ?>
                        <input type="number" name="fuel_level" class="input" inputmode="numeric" step="1" min="0" max="100"
                               autocomplete="off" placeholder="<?= e(t('entry.optional')) ?>">
                    <?php endif; ?>
                </label>
            </div>
            <p class="entry-fuel__preview" data-fuel-preview aria-live="polite"><i data-lucide="fuel"></i><span data-fuel-preview-text><?= e($marks ? t('entry.fuel_marks_hint', ['n' => $marks]) : t('entry.fuel_no_gauge')) ?></span></p>
        </div>
        <p class="entry-card__error" data-entry-error role="alert" hidden></p>
        <button type="submit" class="btn btn--primary btn--block"><i data-lucide="check"></i><?= e(t('common.save')) ?></button>
    </form>
</article>
