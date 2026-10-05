<?php
/**
 * @var array $sites  @var array $entityTypes  @var array $actions  @var array $actors
 * @var array $filters filtres préremplis par l'URL  @var array $links fiches ouvrables par type d'élément
 */
$view->share('pageScripts', ['js/activity.js']);
$sel = static fn (mixed $current, mixed $value): string => $current !== null && (string) $current === (string) $value ? ' selected' : '';
?>
<div class="page-header">
    <h1><?= e(t('nav.activity')) ?></h1>
    <div class="page-header__actions">
        <?= $view->partial('partials/export_buttons', ['table' => 'activity', 'exportTitle' => t('nav.activity')]) ?>
    </div>
</div>

<section class="card" data-activity-summary data-url="<?= e(url('/activity/summary')) ?>">
    <div class="card__title">
        <h2><i data-lucide="users"></i><?= e(t('activity.by_user')) ?></h2>
        <span class="muted small"><?= e(t('activity.by_user_hint')) ?></span>
    </div>
    <div class="log-users" data-activity-users aria-live="polite"><p class="empty"><?= e(t('js.dt.loading')) ?></p></div>
</section>

<section class="card">
    <form class="filters" data-filters-for="activity">
        <?= $view->partial('partials/site_filter', ['sites' => $sites, 'selected' => $filters['site_id']]) ?>
        <div class="field">
            <label for="filter-user"><?= e(t('js.user')) ?></label>
            <select id="filter-user" name="user_id" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach ($actors as $actor): ?>
                    <option value="<?= e($actor['id']) ?>"<?= $sel($filters['user_id'], $actor['id']) ?>><?= e($actor['name']) ?></option>
                <?php endforeach; ?>
                <option value="<?= e(\App\Repositories\ActivityLogRepository::NO_USER) ?>"<?= $sel($filters['user_id'], \App\Repositories\ActivityLogRepository::NO_USER) ?>><?= e(t('activity.no_user')) ?></option>
            </select>
        </div>
        <div class="field">
            <label for="filter-action"><?= e(t('js.action')) ?></label>
            <select id="filter-action" name="action" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach ($actions as $action): ?>
                    <option value="<?= e($action) ?>"<?= $sel($filters['action'], $action) ?>><?= e(t('action.' . $action)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="filter-entity"><?= e(t('fields.entity_type')) ?></label>
            <select id="filter-entity" name="entity_type" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach ($entityTypes as $type): ?>
                    <option value="<?= e($type) ?>"<?= $sel($filters['entity_type'], $type) ?>><?= e(t('entity.' . $type)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="filter-from"><?= e(t('common.from')) ?></label>
            <input id="filter-from" type="date" name="from" class="input" value="<?= e($filters['from']) ?>" data-filter>
        </div>
        <div class="field">
            <label for="filter-to"><?= e(t('common.to')) ?></label>
            <input id="filter-to" type="date" name="to" class="input" value="<?= e($filters['to']) ?>" data-filter>
        </div>
        <?php if ($filters['entity_id'] !== null): ?>
            <input type="hidden" name="entity_id" value="<?= e($filters['entity_id']) ?>">
        <?php endif; ?>
    </form>
    <div class="table-wrap">
        <table class="display" style="width:100%" data-table="activity" data-source="<?= e(url('/activity/data')) ?>"></table>
    </div>
    <p class="hint small"><?= e(t('activity.row_hint')) ?></p>
</section>
<script type="application/json" id="activity-data"><?= json_for_html(['links' => $links]) ?></script>
