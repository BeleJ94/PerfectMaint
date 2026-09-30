<?php /** @var array $sites  @var int|null $selected */ ?>
<?php if (count($sites) > 1): ?>
<div class="field">
    <label for="filter-site"><?= e(t('fields.site_id')) ?></label>
    <select id="filter-site" name="site_id" class="input" data-filter>
        <option value=""><?= e(t('common.all_sites')) ?></option>
        <?php foreach ($sites as $site): ?>
            <option value="<?= e($site['id']) ?>"<?= (int) $site['id'] === (int) ($selected ?? 0) ? ' selected' : '' ?>><?= e($site['name']) ?></option>
        <?php endforeach; ?>
    </select>
</div>
<?php endif; ?>
