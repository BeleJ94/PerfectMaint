<?php /** @var array $sites  @var array $filters */ ?>
<div class="page-header">
    <h1><?= e(t('nav.preventive')) ?></h1>
    <div class="page-header__actions">
        <?= $view->partial('partials/export_buttons', ['table' => 'preventive', 'exportTitle' => t('nav.preventive')]) ?>
        <?php if ($currentUser->can('preventive.manage')): ?>
            <form method="post" action="<?= e(url('/preventive/generate')) ?>" data-confirm="<?= e(t('preventive.generate_confirm')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn"><i data-lucide="calendar-plus"></i><?= e(t('preventive.generate')) ?></button>
            </form>
            <a class="btn btn--primary" href="<?= e(url('/preventive/create')) ?>"><i data-lucide="plus"></i><?= e(t('preventive.new')) ?></a>
        <?php endif; ?>
    </div>
</div>
<section class="card">
    <form class="filters" data-filters-for="preventive">
        <?= $view->partial('partials/site_filter', ['sites' => $sites, 'selected' => $filters['site_id']]) ?>
        <label class="check"><input type="checkbox" name="due" value="1" data-filter<?= $filters['due'] === '1' ? ' checked' : '' ?>> <?= e(t('preventive.only_due')) ?></label>
    </form>
    <div class="table-wrap">
        <table class="display" style="width:100%" data-table="preventive" data-source="<?= e(url('/preventive/data')) ?>"<?= $currentUser->can('preventive.manage') ? ' data-row-link="' . e(url('/preventive/{id}/edit')) . '"' : '' ?>></table>
    </div>
</section>
