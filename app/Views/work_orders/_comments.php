<?php
/**
 * Fil des remarques et commentaires des maintenanciers.
 * @var array $wo  @var array $comments  @var bool $canComment  @var string $back
 */
?>
<section class="card" id="comments">
    <div class="card__title"><h2><?= e(t('work_orders.comments')) ?> <span class="muted small"><?= e(count($comments)) ?></span></h2></div>
    <?php if ($comments === []): ?>
        <p class="empty"><?= e(t('work_orders.no_comment')) ?></p>
    <?php else: ?>
        <ol class="thread">
            <?php foreach ($comments as $c): ?>
                <li class="thread__item<?= (int) $c['user_id'] === $currentUser->id ? ' is-mine' : '' ?>">
                    <span class="avatar avatar--sm" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $c['author'], 0, 1) . mb_substr((string) strrchr((string) $c['author'], ' '), 1, 1))) ?></span>
                    <div class="thread__bubble">
                        <div class="thread__meta"><strong><?= e($c['author'] ?? '—') ?></strong><?= $c['role'] ? ' · ' . e(t('role.' . $c['role'])) : '' ?> · <time><?= e(format_date($c['created_at'], true)) ?></time></div>
                        <div class="thread__body"><?= nl2br(e($c['body'])) ?></div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
    <?php if ($canComment): ?>
        <form method="post" action="<?= e(url('/work-orders/' . $wo['id'] . '/comments')) ?>" class="thread-form">
            <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
            <textarea name="body" class="input" rows="2" maxlength="4000" required placeholder="<?= e(t('work_orders.comment_placeholder')) ?>" aria-label="<?= e(t('work_orders.comments')) ?>" data-autogrow></textarea>
            <button type="submit" class="btn btn--primary"><i data-lucide="send"></i><?= e(t('work_orders.send')) ?></button>
        </form>
    <?php endif; ?>
</section>
