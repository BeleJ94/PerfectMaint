<?php
/** Carte de saisie d'un engin. @var array $row ligne de la feuille (MeterEntryService) */
$id = (int) $row['id'];
$done = $row['read_today'];
$index = [];
if ($row['hour_meter'] !== null) {
    $index[] = format_number($row['hour_meter']) . ' h';
}
if ($row['odometer_km'] !== null) {
    $index[] = format_number($row['odometer_km']) . ' km';
}
$when = $row['last_at'] === null
    ? t('entry.never')
    : ($done ? t('entry.today_at', ['time' => date('H:i', strtotime($row['last_at']))]) : format_date($row['last_at'], true));
// Correction de sa propre saisie : les champs reprennent la valeur du jour, sans minimum côté navigateur.
$own = $row['own'];
$number = static function (string $name, string $label, ?int $current, bool $prefill, array $extra = []) use ($id): string {
    $attrs = '';
    foreach ($extra + ['min' => $prefill || $current === null ? 0 : $current, 'placeholder' => $current ?? ''] as $attr => $value) {
        $attrs .= ' ' . e($attr) . '="' . e($value) . '"';
    }

    return '<label class="entry-field"><span>' . e($label) . '</span>'
        . '<input type="number" name="' . e($name) . '" class="input" inputmode="numeric" step="1" autocomplete="off"'
        . ' id="entry-' . e($name) . '-' . $id . '" value="' . e($prefill ? $current : '') . '"' . $attrs . '></label>';
};
?>
<article class="entry-card<?= $done ? ' is-done' : '' ?>" data-entry-card data-done="<?= $done ? '1' : '0' ?>"
         data-search="<?= e(mb_strtolower($row['code'] . ' ' . $row['label'] . ' ' . $row['name'])) ?>">
    <header class="entry-card__head">
        <div>
            <strong><?= e($row['code']) ?></strong>
            <span class="muted small"><?= e($row['label']) ?> · <?= e($row['site']) ?></span>
        </div>
        <?php if ($done): ?>
            <span class="badge badge--available"><?= e(t('entry.done')) ?></span>
        <?php else: ?>
            <span class="badge badge--waiting_part"><?= e(t('entry.todo')) ?></span>
        <?php endif; ?>
    </header>
    <p class="entry-card__last">
        <span class="entry-card__index"><?= e($index !== [] ? implode(' · ', $index) : '—') ?></span>
        <span class="muted small"><?= e($when) ?><?= $row['last_by_name'] ? ' · ' . e($row['last_by_name']) : '' ?></span>
    </p>
    <?php if ($done): ?>
        <button type="button" class="btn btn--block" data-entry-edit><i data-lucide="<?= $own ? 'pencil' : 'plus' ?>"></i><?= e(t($own ? 'entry.correct' : 'entry.again')) ?></button>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/saisie/' . $id)) ?>" class="entry-card__form" data-entry-form<?= $done ? ' hidden' : '' ?>>
        <?= csrf_field() ?>
        <div class="entry-card__fields">
            <?php if ($row['hour_meter'] !== null || $row['odometer_km'] === null): /* masqué pour un véhicule suivi au seul kilométrage */ ?>
                <?= $number('hour_meter', t('entry.hours'), $row['hour_meter'] !== null ? (int) $row['hour_meter'] : null, $own) ?>
            <?php endif; ?>
            <?php if ($row['has_km']): ?>
                <?= $number('odometer_km', t('entry.km'), $row['odometer_km'] !== null ? (int) $row['odometer_km'] : null, $own) ?>
            <?php endif; ?>
            <?= $number('fuel_level', t('entry.fuel'), $row['fuel_level'] !== null ? (int) $row['fuel_level'] : null, false, ['min' => 0, 'max' => 100, 'placeholder' => t('entry.optional')]) ?>
        </div>
        <p class="entry-card__error" data-entry-error role="alert" hidden></p>
        <button type="submit" class="btn btn--primary btn--block"><i data-lucide="check"></i><?= e(t($own ? 'entry.save_correction' : 'common.save')) ?></button>
    </form>
</article>
