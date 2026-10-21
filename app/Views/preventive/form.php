<?php
/**
 * Plan préventif, organisé selon les règles du suivi des index :
 * périodicité dans le compteur de suivi de l'engin (heures ou km, l'autre compteur en option),
 * point de départ obligatoire (date + index de la dernière réalisation) et aperçu de l'avancement.
 * @var array $item  @var int|null $id  @var array|null $equipment
 */
$view->share('pageScripts', ['js/preventive-form.js']);
// Création depuis un engin : point de départ proposé = index actuel et date du jour, visibles et modifiables.
$basis = $equipment['meter_basis'] ?? null;
if (!$id && $equipment) {
    if ($basis === 'km') {
        $item['last_done_km'] ??= $equipment['odometer_km'] ?? null;
    } else {
        $item['last_done_hours'] ??= $equipment['hour_meter'] ?? null;
    }
    $item['last_done_at'] ??= date('Y-m-d');
}
// Plan existant sans point de départ saisi (créé avant la règle) : à confirmer.
$unconfirmed = $id && (($item['interval_hours'] ?? null) !== null && ($item['last_done_hours'] ?? null) === null
    || ($item['interval_km'] ?? null) !== null && ($item['last_done_km'] ?? null) === null);
$f = static fn (string $name, string $type = 'text', array $extra = []) => $view->partial('partials/field', $extra + [
    'name' => $name, 'labelKey' => 'fields.' . $name, 'type' => $type, 'value' => old($name, $item[$name] ?? null),
]);
$meter = static fn (string $unit, string $html) => '<div data-meter="' . $unit . '">' . $html . '</div>';
$active = (bool) old('is_active', $item['is_active'] ?? true);
$equipmentJson = $equipment ? [
    'hour_meter' => $equipment['hour_meter'] !== null ? (int) $equipment['hour_meter'] : null,
    'odometer_km' => $equipment['odometer_km'] !== null ? (int) $equipment['odometer_km'] : null,
    'meter_basis' => $basis,
] : null;
?>
<div class="page-header"><h1><?= e($title) ?></h1></div>

<?php if ($unconfirmed): ?>
    <div class="alert alert--warning"><i data-lucide="alert-triangle"></i><span><?= e(t('preventive.unconfirmed_notice')) ?></span></div>
<?php endif; ?>

<form method="post" action="<?= e(url($id ? "/preventive/$id" : '/preventive')) ?>" class="card form form--sections" data-preventive-form data-new="<?= $id ? '0' : '1' ?>">
    <?= csrf_field() ?>

    <fieldset class="form-section">
        <legend><i data-lucide="truck"></i><?= e(t('preventive.section_equipment')) ?></legend>
        <div class="form-grid">
            <div class="span-2"><?= $view->partial('partials/equipment_picker', ['equipment' => $equipment]) ?></div>
            <div class="plan-meter span-2" data-preventive-meter<?= $equipment ? '' : ' hidden' ?>>
                <span class="badge badge--maintenance" data-preventive-basis-label><?= $basis ? e($basis === 'km' ? t('meter_basis.km') : t('meter_basis.hours')) : '' ?></span>
                <span data-preventive-current></span>
            </div>
        </div>
    </fieldset>

    <fieldset class="form-section">
        <legend><i data-lucide="clipboard-list"></i><?= e(t('preventive.section_plan')) ?></legend>
        <div class="form-grid">
            <?= $f('title', 'text', ['required' => true, 'class' => 'span-2', 'attrs' => ['maxlength' => 150, 'placeholder' => t('preventive.title_placeholder')]]) ?>
            <?= $f('instructions', 'textarea', ['class' => 'span-2', 'hint' => t('preventive.instructions_hint')]) ?>
            <div class="field">
                <input type="hidden" name="is_active" value="0">
                <label class="check"><input type="checkbox" name="is_active" value="1"<?= $active ? ' checked' : '' ?>> <?= e(t('fields.is_active')) ?></label>
            </div>
        </div>
    </fieldset>

    <fieldset class="form-section">
        <legend><i data-lucide="repeat"></i><?= e(t('preventive.section_interval')) ?></legend>
        <p class="hint" data-preventive-basis-hint data-hours="<?= e(t('preventive.basis_hours')) ?>" data-km="<?= e(t('preventive.basis_km')) ?>" data-none="<?= e(t('preventive.basis_none')) ?>">
            <?= e($basis === 'km' ? t('preventive.basis_km') : ($basis ? t('preventive.basis_hours') : t('preventive.basis_none'))) ?>
        </p>
        <div class="form-grid">
            <?= $meter('hours', $f('interval_hours', 'number', ['attrs' => ['min' => 1, 'step' => 1, 'inputmode' => 'numeric', 'placeholder' => '250']])) ?>
            <?= $meter('km', $f('interval_km', 'number', ['attrs' => ['min' => 1, 'step' => 1, 'inputmode' => 'numeric', 'placeholder' => '10000']])) ?>
            <?= $f('interval_days', 'number', ['attrs' => ['min' => 1, 'step' => 1, 'inputmode' => 'numeric'], 'hint' => t('preventive.interval_days_hint')]) ?>
        </div>
        <button type="button" class="link-btn small" data-preventive-other hidden
                data-hours="<?= e(t('preventive.add_hours')) ?>" data-km="<?= e(t('preventive.add_km')) ?>"><i data-lucide="plus"></i><span></span></button>
    </fieldset>

    <fieldset class="form-section">
        <legend><i data-lucide="history"></i><?= e(t('preventive.section_last_done')) ?></legend>
        <p class="hint"><?= e(t('preventive.last_done_hint')) ?></p>
        <div class="form-grid">
            <?= $f('last_done_at', 'date', ['attrs' => ['max' => date('Y-m-d')]]) ?>
            <?= $meter('hours', $f('last_done_hours', 'number', ['attrs' => ['min' => 0, 'step' => 1, 'inputmode' => 'numeric']])) ?>
            <?= $meter('km', $f('last_done_km', 'number', ['attrs' => ['min' => 0, 'step' => 1, 'inputmode' => 'numeric']])) ?>
            <?= $f('next_due_date', 'date', ['hint' => t('preventive.next_due_hint')]) ?>
        </div>
    </fieldset>

    <fieldset class="form-section">
        <legend><i data-lucide="gauge"></i><?= e(t('preventive.section_preview')) ?></legend>
        <div class="plan-preview" data-preventive-preview aria-live="polite"><p class="muted"><?= e(t('preventive.preview_empty')) ?></p></div>
    </fieldset>

    <div class="form-actions">
        <?php if ($id && $currentUser->can('preventive.delete')): ?>
            <button type="submit" class="btn btn--danger form-actions__start" form="preventive-delete"><i data-lucide="trash-2"></i><?= e(t('common.delete')) ?></button>
        <?php endif; ?>
        <a class="btn" href="<?= e(url('/preventive')) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary"><i data-lucide="save"></i><?= e(t('common.save')) ?></button>
    </div>
</form>

<?php if ($id && $currentUser->can('preventive.delete')): ?>
    <?php /* Formulaire séparé (pas d'imbrication) : suppression définitive, réservée à l'administrateur. */ ?>
    <form method="post" action="<?= e(url("/preventive/$id/delete")) ?>" id="preventive-delete" hidden
          data-confirm="<?= e(t('preventive.delete_confirm', ['title' => $item['title'] ?? ''])) ?>">
        <?= csrf_field() ?>
    </form>
<?php endif; ?>

<script type="application/json" id="preventive-data"><?= json_for_html(['equipment' => $equipmentJson]) ?></script>
