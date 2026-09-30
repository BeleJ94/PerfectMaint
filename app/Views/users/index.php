<?php /** @var \App\Domain\Role[] $roles */ ?>
<div class="page-header">
    <h1><?= e(t('nav.users')) ?></h1>
    <div class="page-header__actions">
        <a class="btn btn--primary" href="<?= e(url('/users/create')) ?>"><i data-lucide="user-plus"></i><?= e(t('users.new')) ?></a>
    </div>
</div>
<section class="card">
    <form class="filters" data-filters-for="users">
        <div class="field">
            <label for="filter-role"><?= e(t('fields.role')) ?></label>
            <select id="filter-role" name="role" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach ($roles as $role): ?>
                    <option value="<?= e($role->value) ?>"><?= e(t($role->labelKey())) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
    <div class="table-wrap">
        <table class="display" style="width:100%" data-table="users" data-source="<?= e(url('/users/data')) ?>" data-row-link="<?= e(url('/users/{id}/edit')) ?>"></table>
    </div>
</section>
