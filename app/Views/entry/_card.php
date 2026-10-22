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
if ($row['km_first']) {
    $index = array_reverse($index); // compteur de suivi en premier
}
if ($row['fuel_liters'] !== null) {
    $liters = (float) $row['fuel_liters'];
    $index[] = format_number($liters, fmod($liters, 1.0) === 0.0 ? 0 : 1) . ' L';
} elseif ($row['fuel_level'] !== null) {
    $index[] = $row['fuel_level'] . ' %';
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
            <?php
            // Compteur de suivi de l'engin (catégorie ou forçage) en premier : km pour les véhicules, heures pour les engins.
            $fields = [];
            if ($row['has_hours']) {
                $fields[] = $number('hour_meter', t('entry.hours'), $row['hour_meter'] !== null ? (int) $row['hour_meter'] : null, $own);
            }
            if ($row['has_km']) {
                $km = $number('odometer_km', t('entry.km'), $row['odometer_km'] !== null ? (int) $row['odometer_km'] : null, $own);
                $row['km_first'] ? array_unshift($fields, $km) : $fields[] = $km;
            }
            echo implode('', $fields);
            ?>
            <?php
            // Carburant lu sur l'indicateur : graduation atteinte par l'aiguille (demi-graduation acceptée), convertie en litres.
            $marks = $row['fuel_kind'] === 'marks' && $row['fuel_marks'] ? (int) $row['fuel_marks'] : null;
            $capacity = $row['tank_capacity'] !== null ? (int) $row['tank_capacity'] : null;
            $fuelId = 'entry-fuel-' . $id;
            ?>
            <label class="entry-field entry-field--fuel" data-fuel-field data-marks="<?= e($marks) ?>" data-capacity="<?= e($capacity) ?>">
                <span><?= e($marks ? t('entry.fuel_marks', ['n' => $marks]) : t('entry.fuel_percent')) ?></span>
                <?php if ($marks): ?>
                    <input type="number" name="fuel_mark" id="<?= e($fuelId) ?>" class="input" inputmode="decimal" step="0.5" min="0" max="<?= e($marks) ?>"
                           autocomplete="off" placeholder="<?= e(t('entry.fuel_marks_placeholder', ['n' => $marks])) ?>"
                           value="<?= e($own && $row['last_fuel_mark'] !== null ? (float) $row['last_fuel_mark'] : '') ?>">
                <?php else: ?>
                    <input type="number" name="fuel_level" id="<?= e($fuelId) ?>" class="input" inputmode="numeric" step="1" min="0" max="100"
                           autocomplete="off" placeholder="<?= e(t('entry.optional')) ?>">
                <?php endif; ?>
                <small class="entry-fuel__preview" data-fuel-preview aria-live="polite"><?= e($marks ? t('entry.fuel_marks_hint', ['n' => $marks]) : t('entry.fuel_no_gauge')) ?></small>
            </label>
        </div>
        <p class="entry-card__error" data-entry-error role="alert" hidden></p>
        <button type="submit" class="btn btn--primary btn--block"><i data-lucide="check"></i><?= e(t($own ? 'entry.save_correction' : 'common.save')) ?></button>
    </form>
</article>
