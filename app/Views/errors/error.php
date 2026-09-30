<?php /** @var int $status  @var string $message  @var string|null $debug */ ?>
<div class="auth__card">
    <div class="auth__brand"><i data-lucide="wrench"></i> PerfectMaint</div>
    <h1><?= e((string) $status) ?></h1>
    <p><?= e($message) ?></p>
    <a class="btn btn--primary" href="<?= e(url('/')) ?>"><?= e(t('common.home')) ?></a>
    <?php if (!empty($debug)): ?>
        <pre style="margin-top: 16px; font-size: 12px; white-space: pre-wrap; overflow-x: auto;"><?= e($debug) ?></pre>
    <?php endif; ?>
</div>
