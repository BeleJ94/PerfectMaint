<?php
/** @var array $item  @var bool $configured  @var string $defaultUrl */
// Après une erreur, seule la saisie du formulaire de configuration est réaffichée (pas celle des tests d'envoi).
$posted = old('_form') === 'smtp';
$value = static fn (string $name) => $posted ? old($name) : ($item[$name] ?? null);
$f = static fn (string $name, string $type = 'text', array $extra = []) => $view->partial('partials/field', $extra + [
    'name' => $name, 'labelKey' => 'fields.' . $name, 'type' => $type, 'value' => $value($name),
]);
$recipients = $posted ? (string) old('recipients') : implode("\n", $item['recipients']);
$alertsEnabled = $posted ? (bool) old('alerts_enabled') : $item['alerts_enabled'];
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="mail"></i></span>
        <div>
            <h1><?= e($title) ?></h1>
            <div class="page-title__meta"><span><?= e(t('mail.subtitle')) ?></span></div>
        </div>
    </div>
</div>

<div class="grid grid--main-side">
    <form method="post" action="<?= e(url('/settings/mail')) ?>" class="card form">
        <?= csrf_field() ?>
        <input type="hidden" name="_form" value="smtp">
        <div class="card__title"><h2><i data-lucide="server" class="title-icon"></i><?= e(t('mail.server')) ?></h2></div>
        <div class="form-grid">
            <?= $f('host', 'text', ['required' => true, 'attrs' => ['maxlength' => 190, 'placeholder' => 'smtp.exemple.com', 'autocomplete' => 'off']]) ?>
            <?= $f('port', 'number', ['required' => true, 'attrs' => ['min' => 1, 'max' => 65535], 'hint' => t('mail.port_hint')]) ?>
            <?= $f('encryption', 'select', ['required' => true, 'options' => array_combine(
                \App\Services\MailService::ENCRYPTIONS,
                array_map(static fn (string $e) => t('mail_encryption.' . $e), \App\Services\MailService::ENCRYPTIONS)
            )]) ?>
            <?= $f('username', 'text', ['attrs' => ['maxlength' => 190, 'autocomplete' => 'off'], 'hint' => t('mail.username_hint')]) ?>
            <div class="field span-2">
                <label for="f-password"><?= e(t('fields.password')) ?></label>
                <input type="password" id="f-password" name="password" class="input" maxlength="255" autocomplete="new-password"
                       placeholder="<?= e($item['has_password'] ? t('mail.password_kept') : '') ?>">
                <span class="hint"><?= e(t('mail.password_hint')) ?></span>
            </div>
        </div>

        <div class="card__title" style="margin-top: var(--space-5)"><h2><i data-lucide="send" class="title-icon"></i><?= e(t('mail.sender')) ?></h2></div>
        <div class="form-grid">
            <?= $f('from_address', 'email', ['required' => true, 'attrs' => ['maxlength' => 190], 'hint' => t('mail.from_hint')]) ?>
            <?= $f('from_name', 'text', ['attrs' => ['maxlength' => 120]]) ?>
        </div>

        <div class="card__title" style="margin-top: var(--space-5)"><h2><i data-lucide="bell-ring" class="title-icon"></i><?= e(t('mail.alerts')) ?></h2></div>
        <div class="form-grid">
            <?= $view->partial('partials/field', [
                'name' => 'recipients', 'labelKey' => 'fields.recipients', 'type' => 'textarea', 'value' => $recipients, 'class' => 'span-2',
                'attrs' => ['rows' => 4, 'maxlength' => 2000, 'placeholder' => "chef.atelier@exemple.com\ndirection@exemple.com"],
                'hint' => t('mail.recipients_hint', ['max' => \App\Services\MailService::MAX_RECIPIENTS]),
            ]) ?>
            <?= $f('app_url', 'url', ['class' => 'span-2', 'attrs' => ['maxlength' => 190, 'placeholder' => $defaultUrl], 'hint' => t('mail.app_url_hint')]) ?>
            <div class="field span-2">
                <input type="hidden" name="alerts_enabled" value="0">
                <label class="check"><input type="checkbox" name="alerts_enabled" value="1"<?= $alertsEnabled ? ' checked' : '' ?>> <?= e(t('fields.alerts_enabled')) ?></label>
                <span class="hint"><?= e(t('mail.alerts_enabled_hint')) ?></span>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><i data-lucide="save"></i><?= e(t('common.save')) ?></button>
        </div>
    </form>

    <div>
        <section class="card">
            <div class="card__title"><h2><i data-lucide="flask-conical" class="title-icon"></i><?= e(t('mail.test_title')) ?></h2></div>
            <?php if (!$configured): ?>
                <p class="muted"><?= e(t('mail.save_first')) ?></p>
            <?php else: ?>
                <form method="post" action="<?= e(url('/settings/mail/test')) ?>" class="form">
                    <?= csrf_field() ?>
                    <?= $view->partial('partials/field', [
                        'name' => 'test_to', 'labelKey' => 'fields.test_to', 'type' => 'email', 'required' => true,
                        'value' => old('test_to', $currentUser->email), 'attrs' => ['maxlength' => 190],
                        'hint' => t('mail.test_hint'),
                    ]) ?>
                    <div class="form-actions">
                        <button type="submit" class="btn btn--primary"><i data-lucide="send"></i><?= e(t('mail.test_send')) ?></button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="card__title"><h2><i data-lucide="siren" class="title-icon title-icon--warn"></i><?= e(t('mail.alerts_now_title')) ?></h2></div>
            <?php if (!$configured || $item['recipients'] === []): ?>
                <p class="muted"><?= e(t('mail.alerts_need_recipients')) ?></p>
            <?php else: ?>
                <p class="muted small"><?= e(t('mail.alerts_now_hint', ['count' => count($item['recipients'])])) ?></p>
                <form method="post" action="<?= e(url('/settings/mail/alerts')) ?>" data-confirm="<?= e(t('mail.alerts_now_confirm', ['count' => count($item['recipients'])])) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn"><i data-lucide="bell-ring"></i><?= e(t('mail.alerts_now_send')) ?></button>
                </form>
            <?php endif; ?>
            <p class="hint small" style="margin-top: var(--space-3)"><?= e(t('mail.cron_hint')) ?> <code>php bin/send-alerts.php</code></p>
        </section>
    </div>
</div>
