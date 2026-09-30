<?php /** @var array $activity entrées du journal pour une entité */ ?>
<?php if (!empty($activity)): ?>
<section class="card">
    <div class="card__title"><h2><?= e(t('activity.title')) ?></h2></div>
    <ul class="timeline">
        <?php foreach ($activity as $entry): ?>
            <li>
                <time datetime="<?= e($entry['created_at']) ?>"><?= e(format_date($entry['created_at'], true)) ?> · <?= e($entry['user_name']) ?></time>
                <strong><?= e(t('action.' . $entry['action'])) ?></strong>
                <?php if ($entry['summary']): ?> — <?= e($entry['summary']) ?><?php endif; ?>
                <?php
                $before = $entry['before_data'] ? json_decode($entry['before_data'], true) : [];
                $after = $entry['after_data'] ? json_decode($entry['after_data'], true) : [];
                ?>
                <?php if ($before && $after): ?>
                    <div class="diff">
                        <?php foreach ($after as $key => $newValue): ?>
                            <?php if (is_array($newValue) || is_array($before[$key] ?? null)) { continue; } ?>
                            <div><?= e(\App\Core\I18n::current()->has("fields.$key") ? t("fields.$key") : $key) ?> : <del><?= e($before[$key] ?? '∅') ?></del> → <ins><?= e($newValue ?? '∅') ?></ins></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
