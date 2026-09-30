<?php
/** @var array $item  @var int|null $id  @var array $sites  @var array $teams  @var string|null $back */
$siteOptions = ['' => '—'];
foreach ($sites as $site) { $siteOptions[$site['id']] = $site['name']; }
$jobOptions = [];
foreach (\App\Domain\Workforce::JOBS as $job) { $jobOptions[$job] = t('job.' . $job); }
$f = static fn (string $name, string $type = 'text', array $extra = []) => $view->partial('partials/field', $extra + [
    'name' => $name, 'labelKey' => 'fields.' . $name, 'type' => $type, 'value' => old($name, $item[$name] ?? null),
]);
$teamId = old('team_id', $item['team_id'] ?? null);
$active = (bool) old('is_active', $item['is_active'] ?? true);
$back ??= null;
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="contact"></i></span>
        <div><h1><?= e($title) ?></h1><?php if ($id): ?><div class="page-title__meta"><span><?= e($item['name'] ?? '') ?></span></div><?php endif; ?></div>
    </div>
</div>
<form method="post" action="<?= e(url($id ? "/personnel/$id" : '/personnel')) ?>" class="card form form--sections">
    <?= csrf_field() ?>
    <?php if ($back === 'teams'): ?><input type="hidden" name="back" value="teams"><?php endif; ?>
    <fieldset class="form-section">
        <legend><i data-lucide="contact"></i><?= e(t('personnel.section_identity')) ?></legend>
        <div class="form-grid">
            <?= $f('first_name', 'text', ['required' => true, 'attrs' => ['maxlength' => 80, 'autocomplete' => 'off']]) ?>
            <?= $f('last_name', 'text', ['required' => true, 'attrs' => ['maxlength' => 80, 'autocomplete' => 'off']]) ?>
            <?= $f('job', 'select', ['required' => true, 'options' => $jobOptions]) ?>
            <?= $f('employee_no', 'text', ['attrs' => ['maxlength' => 30, 'autocapitalize' => 'characters']]) ?>
            <?= $f('phone', 'tel', ['attrs' => ['maxlength' => 30, 'placeholder' => '+243 …', 'inputmode' => 'tel']]) ?>
            <?= $f('license_number', 'text', ['attrs' => ['maxlength' => 40], 'hint' => t('personnel.license_hint')]) ?>
        </div>
    </fieldset>
    <fieldset class="form-section">
        <legend><i data-lucide="map-pin"></i><?= e(t('personnel.section_assignment')) ?></legend>
        <div class="form-grid">
            <?= $f('site_id', 'select', ['required' => true, 'options' => $siteOptions, 'attrs' => ['data-site-select' => '']]) ?>
            <div class="field">
                <label for="f-team_id"><?= e(t('fields.team_id')) ?></label>
                <select id="f-team_id" name="team_id" class="input" data-site-filtered>
                    <option value="">—</option>
                    <?php foreach ($teams as $team): ?>
                        <option value="<?= e($team['id']) ?>" data-site="<?= e($team['site_id']) ?>"<?= (string) $team['id'] === (string) $teamId ? ' selected' : '' ?>><?= e($team['name']) ?> · <?= e(t('shift.' . $team['shift'])) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (field_error('team_id')): ?><span class="error"><?= e(field_error('team_id')) ?></span><?php endif; ?>
            </div>
            <?= $f('notes', 'text', ['class' => 'span-2', 'attrs' => ['maxlength' => 255]]) ?>
            <div class="field">
                <input type="hidden" name="is_active" value="0">
                <label class="check"><input type="checkbox" name="is_active" value="1"<?= $active ? ' checked' : '' ?>> <?= e(t('fields.is_active')) ?></label>
            </div>
        </div>
    </fieldset>
    <div class="form-actions">
        <a class="btn" href="<?= e(url($back === 'teams' ? '/teams' : '/personnel')) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary"><i data-lucide="save"></i><?= e(t('common.save')) ?></button>
    </div>
</form>
