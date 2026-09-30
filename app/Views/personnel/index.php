<?php /** @var array $sites  @var array $teams  @var array $filters */ ?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="contact"></i></span>
        <div><h1><?= e(t('nav.personnel')) ?></h1><div class="page-title__meta"><span><?= e(t('personnel.subtitle')) ?></span></div></div>
    </div>
    <div class="page-header__actions">
        <?= $view->partial('partials/export_buttons', ['table' => 'personnel', 'exportTitle' => t('nav.personnel')]) ?>
        <a class="btn" href="<?= e(url('/teams')) ?>"><i data-lucide="users-round"></i><?= e(t('nav.teams')) ?></a>
        <?php if ($currentUser->can('personnel.manage')): ?>
            <a class="btn btn--primary" href="<?= e(url('/personnel/create', $filters['site_id'] ? ['site_id' => $filters['site_id']] : [])) ?>"><i data-lucide="user-plus"></i><?= e(t('personnel.new')) ?></a>
        <?php endif; ?>
    </div>
</div>
<section class="card">
    <form class="filters" data-filters-for="personnel">
        <?= $view->partial('partials/site_filter', ['sites' => $sites, 'selected' => $filters['site_id']]) ?>
        <div class="field">
            <label for="filter-job"><?= e(t('fields.job')) ?></label>
            <select id="filter-job" name="job" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach (\App\Domain\Workforce::JOBS as $job): ?>
                    <option value="<?= e($job) ?>"<?= $filters['job'] === $job ? ' selected' : '' ?>><?= e(t('job.' . $job)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="filter-team"><?= e(t('fields.team_id')) ?></label>
            <select id="filter-team" name="team_id" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach ($teams as $team): ?>
                    <option value="<?= e($team['id']) ?>"<?= (int) $filters['team_id'] === (int) $team['id'] ? ' selected' : '' ?>><?= e($team['site'] . ' · ' . $team['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <label class="check"><input type="checkbox" name="inactive" value="1" data-filter> <?= e(t('personnel.show_inactive')) ?></label>
    </form>
    <div class="table-wrap">
        <table class="display" style="width:100%" data-table="personnel" data-source="<?= e(url('/personnel/data')) ?>"<?= $currentUser->can('personnel.manage') ? ' data-row-link="' . e(url('/personnel/{id}/edit')) . '"' : '' ?>></table>
    </div>
</section>
