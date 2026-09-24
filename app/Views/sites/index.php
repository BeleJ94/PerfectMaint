<div class="page-header">
    <h1><?= e(t('nav.sites')) ?></h1>
    <div class="page-header__actions">
        <a class="btn btn--primary" href="<?= e(url('/sites/create')) ?>"><i data-lucide="plus"></i><?= e(t('sites.new')) ?></a>
    </div>
</div>
<section class="card">
    <div class="table-wrap">
        <table class="display" style="width:100%" data-table="sites" data-source="<?= e(url('/sites/data')) ?>" data-row-link="<?= e(url('/sites/{id}/edit')) ?>"></table>
    </div>
</section>
