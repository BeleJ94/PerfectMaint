<?php /** @var string $email  @var string|null $error */ ?>
<div class="login">
    <aside class="login__hero" aria-hidden="true">
        <div class="login__brand"><span class="login__logo"><i data-lucide="wrench"></i></span> PerfectMaint</div>
        <div class="login__pitch">
            <h2><?= e(t('auth.hero_title')) ?></h2>
            <p><?= e(t('auth.hero_text')) ?></p>
        </div>
        <div class="login__footnote">© <?= date('Y') ?> Perfect Logistics</div>
    </aside>

    <section class="login__panel">
        <div class="login__top">
            <div class="login__brand login__brand--compact"><span class="login__logo"><i data-lucide="wrench"></i></span> PerfectMaint</div>
            <?= $view->partial('partials/lang_switch', ['currentPath' => '/login']) ?>
        </div>

        <div class="login__form">
            <h1><?= e(t('auth.title')) ?></h1>
            <p class="muted"><?= e(t('auth.subtitle')) ?></p>
            <?php if (!empty($error)): ?>
                <div class="alert alert--error" role="alert"><i data-lucide="alert-triangle"></i><div><?= e($error) ?></div></div>
            <?php endif; ?>
            <form method="post" action="<?= e(url('/login')) ?>" class="grid">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="email"><?= e(t('fields.email')) ?></label>
                    <div class="input-icon">
                        <i data-lucide="mail"></i>
                        <input id="email" name="email" type="email" class="input" autocomplete="username" required autofocus
                               placeholder="<?= e(t('auth.email_placeholder')) ?>" value="<?= e($email) ?>">
                    </div>
                </div>
                <div class="field">
                    <label for="password"><?= e(t('fields.password')) ?></label>
                    <div class="input-icon">
                        <i data-lucide="lock"></i>
                        <input id="password" name="password" type="password" class="input" autocomplete="current-password" required>
                        <button type="button" class="input-icon__toggle" data-toggle-password="password"
                                aria-label="<?= e(t('auth.show_password')) ?>" aria-pressed="false"
                                data-label-show="<?= e(t('auth.show_password')) ?>" data-label-hide="<?= e(t('auth.hide_password')) ?>">
                            <i data-lucide="eye"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn btn--primary btn--block login__submit">
                    <?= e(t('auth.submit')) ?> <i data-lucide="arrow-right"></i>
                </button>
            </form>
            <p class="login__secure muted"><i data-lucide="shield-check"></i><?= e(t('auth.secure_note')) ?></p>
        </div>
    </section>
</div>
