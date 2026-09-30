<form method="post" action="<?= e(url('/locale')) ?>" class="lang-switch" aria-label="<?= e(t('nav.language')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="back" value="<?= e($currentPath ?? '/') ?>">
    <?php foreach (['fr' => 'FR', 'en' => 'EN'] as $code => $labelText): ?>
        <button type="submit" name="locale" value="<?= e($code) ?>" aria-pressed="<?= \App\Core\I18n::current()->locale() === $code ? 'true' : 'false' ?>"><?= e($labelText) ?></button>
    <?php endforeach; ?>
</form>
