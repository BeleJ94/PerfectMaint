<?php /** @var array $sites  @var array $filters */ ?>
<div class="page-header">
    <h1><?= e(t('nav.work_orders')) ?></h1>
    <div class="page-header__actions">
        <?= $view->partial('partials/export_buttons', ['table' => 'work_orders', 'exportTitle' => t('nav.work_orders')]) ?>
        <?php if ($currentUser->can('workorder.create')): ?>
            <a class="btn btn--primary" href="<?= e(url('/work-orders/create')) ?>"><i data-lucide="plus"></i><?= e(t('work_orders.new')) ?></a>
        <?php endif; ?>
    </div>
</div>
<section class="card">
    <form class="filters" data-filters-for="work_orders">
        <?= $view->partial('partials/site_filter', ['sites' => $sites, 'selected' => $filters['site_id']]) ?>
        <div class="field">
            <label for="filter-wo-status"><?= e(t('fields.status')) ?></label>
            <select id="filter-wo-status" name="status" class="input" data-filter>
                <option value="all"<?= $filters['status'] === 'all' ? ' selected' : '' ?>><?= e(t('common.all')) ?></option>
                <option value="active"<?= $filters['status'] === 'active' ? ' selected' : '' ?>><?= e(t('work_orders.filter_active')) ?></option>
                <option value="overdue"<?= $filters['status'] === 'overdue' ? ' selected' : '' ?>><?= e(t('work_orders.filter_overdue')) ?></option>
                <?php foreach (\App\Domain\WorkOrderStatus::values() as $s): ?>
                    <option value="<?= e($s) ?>"<?= $filters['status'] === $s ? ' selected' : '' ?>><?= e(label('wo_status', $s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="filter-type"><?= e(t('fields.type')) ?></label>
            <select id="filter-type" name="type" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach (\App\Services\WorkOrderService::TYPES as $type): ?>
                    <option value="<?= e($type) ?>"><?= e(label('wo_type', $type)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="filter-priority"><?= e(t('fields.priority')) ?></label>
            <select id="filter-priority" name="priority" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach (\App\Services\WorkOrderService::PRIORITIES as $p): ?>
                    <option value="<?= e($p) ?>"><?= e(label('priority', $p)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($filters['equipment_id']): ?>
            <input type="hidden" name="equipment_id" value="<?= e($filters['equipment_id']) ?>" data-filter>
        <?php endif; ?>
        <label class="check"><input type="checkbox" name="mine" value="1" data-filter> <?= e(t('work_orders.mine')) ?></label>
    </form>
    <div class="table-wrap">
        <table class="display" style="width:100%" data-table="work_orders" data-source="<?= e(url('/work-orders/data')) ?>" data-row-link="<?= e(url('/work-orders/{id}')) ?>"></table>
    </div>
</section>
