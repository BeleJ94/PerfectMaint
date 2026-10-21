<?php
/**
 * En-tête des écrans de l'encodeur : site de travail (avec changement s'il en a plusieurs) et onglets.
 * @var array $sites sites du périmètre  @var int|null $siteId  @var string $tab entry|report|supervision  @var array $query paramètres à conserver
 */
$query ??= [];
$current = null;
foreach ($sites as $site) {
    if ((int) $site['id'] === $siteId) {
        $current = $site;
    }
}
$path = ['report' => '/saisie/rapport', 'supervision' => '/saisie/supervision'][$tab] ?? '/saisie';
?>
<div class="entry-site">
    <span class="entry-site__icon"><i data-lucide="map-pin"></i></span>
    <?php if (count($sites) > 1): ?>
        <form method="get" action="<?= e(url($path)) ?>" class="entry-site__form">
            <?php foreach ($query as $name => $value): ?>
                <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
            <?php endforeach; ?>
            <label for="entry-site"><?= e(t('entry.my_site')) ?></label>
            <select id="entry-site" name="site_id" class="input" data-autosubmit>
                <?php foreach ($sites as $site): ?>
                    <option value="<?= e($site['id']) ?>"<?= (int) $site['id'] === $siteId ? ' selected' : '' ?>><?= e($site['name']) ?></option>
                <?php endforeach; ?>
                <option value=""<?= $siteId === null ? ' selected' : '' ?>><?= e(t('entry.all_my_sites', ['count' => count($sites)])) ?></option>
            </select>
        </form>
    <?php else: ?>
        <div class="entry-site__single">
            <span><?= e(t('entry.my_site')) ?></span>
            <strong><?= e($current['name'] ?? t('entry.no_site')) ?></strong>
        </div>
    <?php endif; ?>
</div>
<nav class="entry-tabs" aria-label="<?= e(t('nav.meter_entry')) ?>">
    <a href="<?= e(url('/saisie')) ?>"<?= $tab === 'entry' ? ' aria-current="page"' : '' ?>><i data-lucide="pencil"></i><?= e(t('entry.tab_entry')) ?></a>
    <a href="<?= e(url('/saisie/rapport')) ?>"<?= $tab === 'report' ? ' aria-current="page"' : '' ?>><i data-lucide="clipboard-list"></i><?= e(t('entry.report')) ?></a>
    <a href="<?= e(url('/saisie/supervision')) ?>"<?= $tab === 'supervision' ? ' aria-current="page"' : '' ?>><i data-lucide="activity"></i><?= e(t('entry.tab_supervision')) ?></a>
</nav>
