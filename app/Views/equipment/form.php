<?php
/** @var array $item  @var int|null $id  @var array $sites  @var array $categories */
$siteOptions = ['' => '—'];
foreach ($sites as $site) { $siteOptions[$site['id']] = $site['name']; }
$categoryOptions = ['' => '—'];
foreach ($categories as $category) { $categoryOptions[$category['id']] = $category['name']; }
$f = static fn (string $name, string $type = 'text', array $extra = []) => $view->partial('partials/field', $extra + [
    'name' => $name, 'labelKey' => 'fields.' . $name, 'type' => $type, 'value' => old($name, $item[$name] ?? null),
]);
?>
<div class="page-header">
    <h1><?= e($title) ?></h1>
</div>
<form method="post" action="<?= e(url($id ? "/equipment/$id" : '/equipment')) ?>" class="card form">
    <?= csrf_field() ?>
    <div class="form-grid">
        <?= $f('code', 'text', ['required' => true, 'attrs' => ['maxlength' => 30, 'autocapitalize' => 'characters']]) ?>
        <?= $f('name', 'text', ['required' => true, 'attrs' => ['maxlength' => 150]]) ?>
        <?= $f('site_id', 'select', ['required' => true, 'options' => $siteOptions]) ?>
        <?= $f('category_id', 'select', ['options' => $categoryOptions]) ?>
        <?= $f('brand', 'text', ['attrs' => ['maxlength' => 80]]) ?>
        <?= $f('model', 'text', ['attrs' => ['maxlength' => 80]]) ?>
        <?= $f('serial_number', 'text', ['attrs' => ['maxlength' => 80]]) ?>
        <?= $f('commissioned_at', 'date') ?>
        <?= $f('hour_meter', 'number', ['attrs' => ['min' => 0, 'step' => 1, 'inputmode' => 'numeric']]) ?>
        <?= $f('notes', 'textarea', ['class' => 'span-2']) ?>
    </div>
    <div class="form-actions">
        <a class="btn" href="<?= e(url($id ? "/equipment/$id" : '/equipment')) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary"><i data-lucide="save"></i><?= e(t('common.save')) ?></button>
    </div>
</form>
