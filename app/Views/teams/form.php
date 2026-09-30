<?php
/** @var array $item  @var int|null $id  @var array $sites  @var array $people */
$siteOptions = ['' => '—'];
foreach ($sites as $site) { $siteOptions[$site['id']] = $site['name']; }
$f = static fn (string $name, string $type = 'text', array $extra = []) => $view->partial('partials/field', $extra + [
    'name' => $name, 'labelKey' => 'fields.' . $name, 'type' => $type, 'value' => old($name, $item[$name] ?? null),
]);
$kind = (string) old('kind', $item['kind'] ?? 'maintenance');
$shift = (string) old('shift', $item['shift'] ?? 'day');
$leaderId = old('leader_id', $item['leader_id'] ?? null);
$active = (bool) old('is_active', $item['is_active'] ?? true);
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="users-round"></i></span>
        <div><h1><?= e($title) ?></h1></div>
    </div>
</div>
<form method="post" action="<?= e(url($id ? "/teams/$id" : '/teams')) ?>" class="card form form--sections">
    <?= csrf_field() ?>
    <fieldset class="form-section">
        <legend><i data-lucide="users-round"></i><?= e(t('teams.section_team')) ?></legend>
        <div class="form-grid">
            <?= $f('site_id', 'select', ['required' => true, 'options' => $siteOptions, 'attrs' => ['data-site-select' => '']]) ?>
            <?= $f('name', 'text', ['required' => true, 'attrs' => ['maxlength' => 80, 'placeholder' => t('teams.name_placeholder')]]) ?>
            <div class="field">
                <span class="label"><?= e(t('fields.kind')) ?></span>
                <div class="segmented" role="radiogroup">
                    <?php foreach (\App\Domain\Workforce::TEAM_KINDS as $value): ?>
                        <label><input type="radio" name="kind" value="<?= e($value) ?>"<?= $kind === $value ? ' checked' : '' ?>><span><?= e(t('team_kind.' . $value)) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="field">
                <span class="label"><?= e(t('fields.shift')) ?></span>
                <div class="segmented" role="radiogroup">
                    <?php foreach (\App\Domain\Workforce::SHIFTS as $value): ?>
                        <label><input type="radio" name="shift" value="<?= e($value) ?>"<?= $shift === $value ? ' checked' : '' ?>><span><?= e(t('shift.' . $value)) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </fieldset>
    <fieldset class="form-section">
        <legend><i data-lucide="phone"></i><?= e(t('teams.section_contacts')) ?></legend>
        <div class="form-grid">
            <div class="field span-2">
                <label for="f-leader_id"><?= e(t('fields.leader_id')) ?></label>
                <select id="f-leader_id" name="leader_id" class="input" data-site-filtered>
                    <option value="">—</option>
                    <?php foreach ($people as $person): ?>
                        <option value="<?= e($person['id']) ?>" data-site="<?= e($person['site_id']) ?>"<?= (string) $person['id'] === (string) $leaderId ? ' selected' : '' ?>><?= e($person['name']) ?> · <?= e(t('job.' . $person['job'])) ?><?= $person['phone'] ? ' · ' . e($person['phone']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="hint"><?= e(t('teams.leader_hint')) ?></span>
                <?php if (field_error('leader_id')): ?><span class="error"><?= e(field_error('leader_id')) ?></span><?php endif; ?>
            </div>
            <?= $f('dispatch_name', 'text', ['attrs' => ['maxlength' => 120, 'placeholder' => t('teams.dispatch_placeholder')]]) ?>
            <?= $f('dispatch_phone', 'tel', ['attrs' => ['maxlength' => 30, 'placeholder' => '+243 …', 'inputmode' => 'tel']]) ?>
            <div class="field">
                <input type="hidden" name="is_active" value="0">
                <label class="check"><input type="checkbox" name="is_active" value="1"<?= $active ? ' checked' : '' ?>> <?= e(t('fields.is_active')) ?></label>
            </div>
        </div>
    </fieldset>
    <div class="form-actions">
        <a class="btn" href="<?= e(url('/teams')) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary"><i data-lucide="save"></i><?= e(t('common.save')) ?></button>
    </div>
</form>
