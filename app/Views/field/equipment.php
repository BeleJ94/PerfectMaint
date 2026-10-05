<?php /** @var string $term  @var array $items  @var int $limit */ ?>
<form method="get" action="<?= e(url('/terrain/engins')) ?>" class="field-search" role="search">
    <input type="search" name="q" class="input" value="<?= e($term) ?>" autocomplete="off" aria-label="<?= e(t('field.find_equipment')) ?>" placeholder="<?= e(t('field.search_placeholder')) ?>">
    <button type="submit" class="btn btn--primary" aria-label="<?= e(t('common.search')) ?>"><i data-lucide="search"></i></button>
</form>

<?php if ($items === []): ?>
    <p class="empty"><?= e(t('field.no_equipment')) ?></p>
<?php endif; ?>
<?php foreach ($items as $item): ?>
    <a class="wo-card" href="<?= e(url('/terrain/engins/' . $item['id'])) ?>">
        <div class="wo-card__head">
            <span><?= e($item['site']) ?></span>
            <?= status_badge($item['status']) ?>
        </div>
        <div class="wo-card__title"><?= e($item['code']) ?> — <?= e($item['name']) ?></div>
    </a>
<?php endforeach; ?>
<?php if (count($items) >= $limit): ?>
    <p class="hint small"><?= e(t('field.more_results', ['max' => $limit])) ?></p>
<?php endif; ?>
