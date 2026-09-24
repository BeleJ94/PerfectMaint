<?php /** @var string $email  @var string|null $error */ ?>
<div class="auth__card">
    <div class="auth__brand"><i data-lucide="wrench"></i> PerfectMaint</div>
    <h1><?= e(t('auth.title')) ?></h1>
    <p class="muted"><?= e(t('auth.subtitle')) ?></p>
    <?php if (!empty($error)): ?>
        <div class="alert alert--error" role="alert"><i data-lucide="alert-triangle"></i><div><?= e($error) ?></div></div>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/login')) ?>" class="grid">
        <?= csrf_field() ?>
        <div class="field">
            <label for="email"><?= e(t('fields.email')) ?></label>
            <input id="email" name="email" type="email" class="input" autocomplete="username" required autofocus value="<?= e($email) ?>">
        </div>
        <div class="field">
            <label for="password"><?= e(t('fields.password')) ?></label>
            <input id="password" name="password" type="password" class="input" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn btn--primary btn--block"><?= e(t('auth.submit')) ?></button>
    </form>
    <div style="margin-top: 24px; display: flex; justify-content: center;">
        <?= $view->partial('partials/lang_switch', ['currentPath' => '/login']) ?>
    </div>
</div>
