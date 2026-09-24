<?php /** @var array $part  @var array $stocks  @var array $movements */ ?>
<div class="page-header">
    <div>
        <h1><?= e($part['reference']) ?> · <?= e($part['name']) ?></h1>
        <span class="muted"><?= e(t('fields.unit_cost')) ?> : <?= e(format_number($part['unit_cost'], 2)) ?> € / <?= e($part['unit']) ?></span>
    </div>
    <div class="page-header__actions">
        <?php if ($currentUser->can('parts.manage')): ?>
            <a class="btn" href="<?= e(url('/parts/' . $part['id'] . '/edit')) ?>"><i data-lucide="pencil"></i><?= e(t('common.edit')) ?></a>
        <?php endif; ?>
    </div>
</div>
<div class="grid grid--main-side">
    <section class="card">
        <div class="card__title"><h2><?= e(t('parts.stock_by_site')) ?></h2></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th><?= e(t('fields.site_id')) ?></th><th class="num"><?= e(t('fields.quantity')) ?></th><th class="num"><?= e(t('fields.min_quantity')) ?></th><th><?= e(t('fields.location')) ?></th><?php if ($currentUser->can('parts.manage')): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($stocks as $s): ?>
                <?php $low = (float) $s['min_quantity'] > 0 && (float) $s['quantity'] < (float) $s['min_quantity']; ?>
                <tr>
                    <td><?= e($s['site']) ?></td>
                    <td class="num<?= $low ? ' overdue' : '' ?>"><?= e(format_qty($s['quantity'])) ?> <?= e($part['unit']) ?><?= $low ? ' <span class="sr-only">' . e(t('parts.below_min')) . '</span>' : '' ?></td>
                    <td class="num"><?= e(format_qty($s['min_quantity'])) ?></td>
                    <td><?= e($s['location'] ?? '') ?></td>
                    <?php if ($currentUser->can('parts.manage')): ?>
                        <td class="nowrap">
                            <button type="button" class="btn btn--sm" data-open="#move-<?= e($s['site_id']) ?>"><i data-lucide="arrow-down-up"></i><?= e(t('parts.move')) ?></button>
                            <button type="button" class="btn btn--sm btn--ghost" data-open="#settings-<?= e($s['site_id']) ?>" aria-label="<?= e(t('parts.settings')) ?>"><i data-lucide="settings-2"></i></button>
                            <template id="move-<?= e($s['site_id']) ?>">
                                <form method="post" action="<?= e(url('/parts/' . $part['id'] . '/movements')) ?>" class="grid" style="text-align:left">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="site_id" value="<?= e($s['site_id']) ?>">
                                    <p><strong><?= e($s['site']) ?></strong> — <?= e(t('parts.current_stock')) ?> : <?= e(format_qty($s['quantity'])) ?> <?= e($part['unit']) ?></p>
                                    <div class="field">
                                        <label for="mv-type-<?= e($s['site_id']) ?>"><?= e(t('fields.type')) ?></label>
                                        <select id="mv-type-<?= e($s['site_id']) ?>" name="type" class="input">
                                            <option value="in"><?= e(t('movement.in')) ?></option>
                                            <option value="adjust"><?= e(t('movement.adjust')) ?></option>
                                        </select>
                                        <span class="hint"><?= e(t('parts.adjust_hint')) ?></span>
                                    </div>
                                    <div class="field">
                                        <label for="mv-qty-<?= e($s['site_id']) ?>"><?= e(t('fields.quantity')) ?></label>
                                        <input id="mv-qty-<?= e($s['site_id']) ?>" name="quantity" type="number" min="0" step="0.01" class="input" required>
                                    </div>
                                    <div class="field">
                                        <label for="mv-comment-<?= e($s['site_id']) ?>"><?= e(t('fields.comment')) ?></label>
                                        <input id="mv-comment-<?= e($s['site_id']) ?>" name="comment" maxlength="255" class="input">
                                    </div>
                                </form>
                            </template>
                            <template id="settings-<?= e($s['site_id']) ?>">
                                <form method="post" action="<?= e(url('/parts/' . $part['id'] . '/settings')) ?>" class="grid" style="text-align:left">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="site_id" value="<?= e($s['site_id']) ?>">
                                    <div class="field">
                                        <label for="st-min-<?= e($s['site_id']) ?>"><?= e(t('fields.min_quantity')) ?></label>
                                        <input id="st-min-<?= e($s['site_id']) ?>" name="min_quantity" type="number" min="0" step="0.01" class="input" value="<?= e((float) $s['min_quantity']) ?>" required>
                                    </div>
                                    <div class="field">
                                        <label for="st-loc-<?= e($s['site_id']) ?>"><?= e(t('fields.location')) ?></label>
                                        <input id="st-loc-<?= e($s['site_id']) ?>" name="location" maxlength="60" class="input" value="<?= e($s['location'] ?? '') ?>">
                                    </div>
                                </form>
                            </template>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <section class="card">
        <div class="card__title"><h2><?= e(t('parts.movements')) ?></h2></div>
        <?php if ($movements === []): ?>
            <p class="empty"><?= e(t('common.none')) ?></p>
        <?php else: ?>
            <ul class="timeline">
                <?php foreach ($movements as $m): ?>
                    <li>
                        <time><?= e(format_date($m['created_at'], true)) ?> · <?= e($m['user'] ?? 'system') ?> · <?= e($m['site']) ?></time>
                        <strong><?= e(t('movement.' . $m['type'])) ?></strong>
                        <?= $m['type'] === 'out' || (float) $m['quantity'] < 0 ? '−' : '+' ?><?= e(format_qty(abs((float) $m['quantity']))) ?> → <?= e(format_qty($m['stock_after'])) ?>
                        <?php if ($m['work_order_id']): ?> · <a href="<?= e(url('/work-orders/' . $m['work_order_id'])) ?>"><?= e($m['work_order_reference']) ?></a><?php endif; ?>
                        <?php if ($m['comment'] && !$m['work_order_id']): ?><div class="muted"><?= e($m['comment']) ?></div><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
