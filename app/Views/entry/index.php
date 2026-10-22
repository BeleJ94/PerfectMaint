<?php
/** @var array $sheet  @var array $sites  @var int|null $siteId */
$view->share('pageScripts', ['js/pm-fuel.js', 'js/entry.js']);
$doneCount = count($sheet['done']);
$total = $sheet['total'];
?>
<?= $view->partial('entry/_nav', ['sites' => $sites, 'siteId' => $siteId, 'tab' => 'entry']) ?>
<div class="entry-top">
    <div class="entry-progress" data-entry-progress>
        <div class="entry-progress__text">
            <strong><span data-entry-done><?= e($doneCount) ?></span> / <span data-entry-total><?= e($total) ?></span></strong>
            <span><?= e(t('entry.progress')) ?> · <?= e(format_date(date('Y-m-d'))) ?></span>
        </div>
        <div class="meter meter--good"><span data-entry-bar style="width: <?= e($total > 0 ? round($doneCount / $total * 100) : 0) ?>%"></span></div>
    </div>
    <div class="entry-tools">
        <input type="search" class="input" data-entry-search autocomplete="off" placeholder="<?= e(t('entry.search')) ?>" aria-label="<?= e(t('entry.search')) ?>">
    </div>
</div>

<?php if ($total === 0): ?>
    <p class="empty"><?= e(t('entry.no_equipment')) ?></p>
<?php endif; ?>

<h2 class="entry-section" data-entry-todo-title<?= $sheet['todo'] === [] ? ' hidden' : '' ?>><?= e(t('entry.todo_title')) ?> <span data-entry-todo-count><?= e(count($sheet['todo'])) ?></span></h2>
<div class="entry-list" data-entry-todo>
    <?php foreach ($sheet['todo'] as $row): ?>
        <?= $view->partial('entry/_card', ['row' => $row]) ?>
    <?php endforeach; ?>
</div>
<p class="entry-all-done" data-entry-all-done<?= $sheet['todo'] !== [] || $total === 0 ? ' hidden' : '' ?>><i data-lucide="check-circle-2"></i><?= e(t('entry.all_done')) ?></p>

<h2 class="entry-section" data-entry-done-title<?= $sheet['done'] === [] ? ' hidden' : '' ?>><?= e(t('entry.done_title')) ?> <span data-entry-done-count><?= e($doneCount) ?></span></h2>
<div class="entry-list" data-entry-done-list>
    <?php foreach ($sheet['done'] as $row): ?>
        <?= $view->partial('entry/_card', ['row' => $row]) ?>
    <?php endforeach; ?>
</div>
<p class="empty" data-entry-no-match hidden><?= e(t('js.no_result')) ?></p>
