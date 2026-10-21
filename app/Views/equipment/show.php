<?php
/**
 * @var array $item  @var array $history  @var array $workOrders  @var array $plans  @var array $activity
 * @var array $photos  @var array $readings  @var \App\Domain\EquipmentStatus[] $statuses
 */
$eqUrl = '/equipment/' . $item['id'];
$primary = $photos[0] ?? null;
$fuel = $item['fuel_level'] !== null ? (int) $item['fuel_level'] : null;
$fuelClass = $fuel === null ? '' : ($fuel <= 15 ? 'bad' : ($fuel <= 35 ? 'warn' : 'good'));
$daysLeft = $item['contract_end'] ? (int) floor((strtotime($item['contract_end']) - strtotime('today')) / 86400) : null;
$openWo = count(array_filter($workOrders, static fn (array $w) => in_array($w['status'], ['open', 'in_progress', 'waiting_part'], true)));
?>
<div class="eq-hero">
    <div class="eq-hero__photo">
        <?php if ($primary): ?>
            <button type="button" class="eq-hero__img" data-lightbox="<?= e(url($eqUrl . '/photos/' . $primary['id'])) ?>" aria-label="<?= e(t('equipment.photos')) ?>">
                <img src="<?= e(url($eqUrl . '/photos/' . $primary['id'])) ?>" alt="<?= e($item['code']) ?>" loading="lazy">
            </button>
        <?php else: ?>
            <div class="eq-hero__placeholder"><i data-lucide="image"></i></div>
        <?php endif; ?>
    </div>
    <div class="eq-hero__body">
        <div class="eq-hero__title">
            <h1><?= e($item['code']) ?></h1>
            <?= status_badge($item['status']) ?>
        </div>
        <p class="eq-hero__name"><?= e(trim(($item['brand'] ?? '') . ' ' . ($item['model'] ?? ''))) ?: e($item['name']) ?> <span class="muted">· <?= e($item['name']) ?></span></p>
        <div class="eq-hero__tags">
            <a class="tag" href="<?= e(url('/sites/' . $item['site_id'])) ?>"><i data-lucide="map-pin"></i><?= e($item['site']) ?></a>
            <?php if ($item['category']): ?><span class="tag"><i data-lucide="layers"></i><?= e($item['category']) ?></span><?php endif; ?>
            <?php if ($item['plate_number']): ?><span class="tag tag--plate"><?= e($item['plate_number']) ?></span><?php endif; ?>
            <span class="tag"><i data-lucide="clock"></i><?= e(t('equipment.since', ['date' => format_date($item['status_changed_at'], true)])) ?></span>
        </div>
    </div>
    <div class="eq-hero__actions">
        <?php if ($currentUser->can('workorder.create')): ?>
            <a class="btn btn--danger" href="<?= e(url('/work-orders/create', ['equipment_id' => $item['id']])) ?>"><i data-lucide="alert-octagon"></i><?= e(t('equipment.declare_breakdown')) ?></a>
        <?php endif; ?>
        <?php if ($currentUser->can('equipment.reading')): ?>
            <button type="button" class="btn btn--primary" data-open="#reading-dialog"><i data-lucide="gauge"></i><?= e(t('equipment.new_reading')) ?></button>
        <?php endif; ?>
        <div class="menu" data-menu>
            <button type="button" class="btn" data-menu-toggle aria-haspopup="true" aria-expanded="false" aria-label="<?= e(t('common.more')) ?>"><i data-lucide="more-horizontal"></i></button>
            <div class="menu__list" role="menu" hidden>
                <?php if ($currentUser->can('equipment.status')): ?>
                    <button type="button" role="menuitem" data-open="#status-dialog"><i data-lucide="refresh-cw"></i><?= e(t('equipment.change_status')) ?></button>
                <?php endif; ?>
                <?php if ($currentUser->can('equipment.manage')): ?>
                    <a role="menuitem" href="<?= e(url($eqUrl . '/edit')) ?>"><i data-lucide="pencil"></i><?= e(t('common.edit')) ?></a>
                    <form method="post" action="<?= e(url($eqUrl . '/archive')) ?>" data-confirm="<?= e(t('equipment.archive_confirm')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" role="menuitem" class="is-danger"><i data-lucide="archive"></i><?= e(t('common.archive')) ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="vitals">
    <div class="vital">
        <span class="vital__icon"><i data-lucide="timer"></i></span>
        <div><div class="vital__label"><?= e(t('fields.hour_meter')) ?></div>
        <div class="vital__value"><?= $item['hour_meter'] !== null ? e(format_number($item['hour_meter'])) . '<small> h</small>' : '—' ?></div></div>
    </div>
    <div class="vital">
        <span class="vital__icon"><i data-lucide="route"></i></span>
        <div><div class="vital__label"><?= e(t('fields.odometer_km')) ?></div>
        <div class="vital__value"><?= $item['odometer_km'] !== null ? e(format_number($item['odometer_km'])) . '<small> km</small>' : '—' ?></div></div>
    </div>
    <div class="vital">
        <span class="vital__icon vital__icon--<?= e($fuelClass) ?>"><i data-lucide="fuel"></i></span>
        <div class="vital__grow"><div class="vital__label"><?= e(t('fields.fuel_level')) ?></div>
        <div class="vital__value"><?= $fuel !== null ? e($fuel) . '<small> %</small>' : '—' ?></div>
        <?php if ($fuel !== null): ?><div class="meter meter--<?= e($fuelClass) ?>"><span style="width: <?= e($fuel) ?>%"></span></div><?php endif; ?></div>
    </div>
    <div class="vital">
        <span class="vital__icon"><i data-lucide="file-signature"></i></span>
        <div><div class="vital__label"><?= e(t('ownership.' . $item['ownership'])) ?><?= $item['contract_ref'] ? ' · ' . e($item['contract_ref']) : '' ?></div>
        <div class="vital__value vital__value--sm">
            <?php if ($item['ownership'] === 'owned'): ?>
                <?= e($item['assignment'] ?: t('equipment.no_assignment')) ?>
            <?php elseif ($daysLeft !== null): ?>
                <span class="<?= $daysLeft < 0 ? 'overdue' : ($daysLeft <= 30 ? 'text-warn' : '') ?>"><?= e($daysLeft < 0 ? t('equipment.contract_expired', ['days' => -$daysLeft]) : t('equipment.contract_days_left', ['days' => $daysLeft])) ?></span>
            <?php else: ?>—<?php endif; ?>
        </div></div>
    </div>
    <a class="vital<?= $openWo > 0 ? ' vital--alert' : '' ?>" href="<?= e(url('/work-orders', ['equipment_id' => $item['id'], 'status' => 'active'])) ?>">
        <span class="vital__icon"><i data-lucide="wrench"></i></span>
        <div><div class="vital__label"><?= e(t('js.open_wo')) ?></div>
        <div class="vital__value"><?= e($openWo) ?></div></div>
    </a>
</div>
<?php if ($item['meter_updated_at']): ?>
    <p class="muted small vitals__foot"><?= e(t('equipment.last_reading', ['date' => format_date($item['meter_updated_at'], true)])) ?></p>
<?php endif; ?>

<div class="grid grid--main-side">
    <div>
        <section class="card">
            <div class="card__title">
                <h2><?= e(t('equipment.photos')) ?> <span class="muted small"><?= e(count($photos)) ?></span></h2>
                <?php if ($currentUser->can('equipment.photo')): ?>
                    <label class="btn btn--sm" data-photo-upload data-url="<?= e(url($eqUrl . '/photos')) ?>">
                        <i data-lucide="camera"></i><?= e(t('equipment.add_photo')) ?>
                        <input type="file" accept="image/jpeg,image/png,image/webp" capture="environment" multiple hidden>
                    </label>
                <?php endif; ?>
            </div>
            <?php if ($photos === []): ?>
                <div class="empty-state empty-state--drop" data-photo-drop>
                    <i data-lucide="image-plus"></i>
                    <p><?= e(t('equipment.no_photo')) ?></p>
                </div>
            <?php else: ?>
                <div class="gallery" data-photo-drop>
                    <?php foreach ($photos as $photo): ?>
                        <figure class="gallery__item<?= (int) $photo['is_primary'] ? ' is-primary' : '' ?>">
                            <button type="button" class="gallery__img" data-lightbox="<?= e(url($eqUrl . '/photos/' . $photo['id'])) ?>" data-caption="<?= e($photo['caption'] ?? '') ?>">
                                <img src="<?= e(url($eqUrl . '/photos/' . $photo['id'])) ?>" alt="<?= e($photo['caption'] ?? $item['code']) ?>" loading="lazy">
                            </button>
                            <figcaption>
                                <span><?= e($photo['caption'] ?: format_date($photo['created_at'])) ?></span>
                                <span class="gallery__tools">
                                    <?php if ((int) $photo['is_primary']): ?>
                                        <i data-lucide="star" class="is-star" title="<?= e(t('equipment.primary_photo')) ?>"></i>
                                    <?php elseif ($currentUser->can('equipment.photo')): ?>
                                        <form method="post" action="<?= e(url($eqUrl . '/photos/' . $photo['id'] . '/primary')) ?>">
                                            <?= csrf_field() ?><button type="submit" class="icon-btn" title="<?= e(t('equipment.make_primary')) ?>" aria-label="<?= e(t('equipment.make_primary')) ?>"><i data-lucide="star"></i></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($currentUser->can('equipment.manage')): ?>
                                        <form method="post" action="<?= e(url($eqUrl . '/photos/' . $photo['id'] . '/delete')) ?>" data-confirm="<?= e(t('equipment.delete_photo_confirm')) ?>">
                                            <?= csrf_field() ?><button type="submit" class="icon-btn icon-btn--danger" title="<?= e(t('common.delete')) ?>" aria-label="<?= e(t('common.delete')) ?>"><i data-lucide="trash-2"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </span>
                            </figcaption>
                        </figure>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="card__title"><h2><?= e(t('equipment.details')) ?></h2></div>
            <dl class="dl dl--2col">
                <dt><?= e(t('fields.brand_id')) ?> / <?= e(t('fields.model_id')) ?></dt><dd><?= e(trim(($item['brand'] ?? '') . ' ' . ($item['model'] ?? '')) ?: '—') ?></dd>
                <dt><?= e(t('fields.category_id')) ?></dt><dd><?= e($item['category'] ?? '—') ?></dd>
                <dt><?= e(t('fields.chassis_number')) ?></dt><dd class="mono"><?= e($item['chassis_number'] ?? '—') ?></dd>
                <dt><?= e(t('fields.plate_number')) ?></dt><dd class="mono"><?= e($item['plate_number'] ?? '—') ?></dd>
                <dt><?= e(t('fields.serial_number')) ?></dt><dd class="mono"><?= e($item['serial_number'] ?? '—') ?></dd>
                <dt><?= e(t('fields.commissioned_at')) ?></dt><dd><?= e(format_date($item['commissioned_at']) ?: '—') ?></dd>
                <dt><?= e(t('fields.assignment')) ?></dt><dd><?= e($item['assignment'] ?? '—') ?></dd>
                <dt><?= e(t('fields.ownership')) ?></dt><dd><?= e(t('ownership.' . $item['ownership'])) ?></dd>
                <?php if ($item['ownership'] !== 'owned'): ?>
                    <dt><?= e(t('fields.contract_ref')) ?></dt><dd><?= e($item['contract_ref'] ?? '—') ?></dd>
                    <dt><?= e(t('fields.contract_party')) ?></dt><dd><?= e($item['contract_party'] ?? '—') ?></dd>
                    <dt><?= e(t('equipment.contract_period')) ?></dt><dd><?= e(format_date($item['contract_start']) ?: '…') ?> → <?= e(format_date($item['contract_end']) ?: '…') ?></dd>
                <?php endif; ?>
                <?php if ($item['notes']): ?><dt><?= e(t('fields.notes')) ?></dt><dd><?= nl2br(e($item['notes'])) ?></dd><?php endif; ?>
            </dl>
        </section>

        <section class="card">
            <div class="card__title">
                <h2><?= e(t('equipment.compatible_parts')) ?> <span class="muted small"><?= e(count($compatibleParts)) ?></span></h2>
                <?php if ($item['model_id']): ?><a href="<?= e(url('/parts', ['model_id' => $item['model_id']])) ?>"><?= e(t('common.see_all')) ?></a><?php endif; ?>
            </div>
            <?php if ($compatibleParts === []): ?>
                <p class="empty"><?= e($item['model_id'] ? t('equipment.no_compatible') : t('equipment.no_model')) ?></p>
            <?php else: ?>
                <div class="table-wrap"><table class="table table--compact">
                    <thead><tr><th><?= e(t('fields.reference')) ?></th><th><?= e(t('fields.part_type')) ?></th><th class="num"><?= e(t('equipment.site_stock')) ?></th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($compatibleParts, 0, 12) as $p): ?>
                        <?php $low = (float) $p['quantity'] <= 0 || ((float) $p['min_quantity'] > 0 && (float) $p['quantity'] < (float) $p['min_quantity']); ?>
                        <tr>
                            <td><a href="<?= e(url('/parts/' . $p['id'])) ?>"><strong><?= e($p['reference']) ?></strong></a> <span class="muted small"><?= e($p['name']) ?></span><?php if ((int) $p['is_wear_part']): ?> <span class="chip chip--warn"><?= e(t('parts.wear_short')) ?></span><?php endif; ?></td>
                            <td><?= e(t('part_type.' . $p['part_type'])) ?></td>
                            <td class="num<?= $low ? ' overdue' : '' ?>"><?= e(format_qty($p['quantity'])) ?> <?= e($p['unit']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="card__title">
                <h2><?= e(t('equipment.work_orders')) ?></h2>
                <a href="<?= e(url('/work-orders', ['equipment_id' => $item['id'], 'status' => 'all'])) ?>"><?= e(t('common.see_all')) ?></a>
            </div>
            <?php if ($workOrders === []): ?>
                <p class="empty"><?= e(t('common.none')) ?></p>
            <?php else: ?>
                <div class="table-wrap"><table class="table table--compact">
                    <thead><tr><th><?= e(t('fields.reference')) ?></th><th><?= e(t('fields.title')) ?></th><th><?= e(t('fields.status')) ?></th><th><?= e(t('fields.reported_at')) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($workOrders as $wo): ?>
                        <tr>
                            <td class="nowrap"><a href="<?= e(url('/work-orders/' . $wo['id'])) ?>"><?= e($wo['reference']) ?></a></td>
                            <td><?= e($wo['title']) ?> <span class="muted">· <?= e(label('wo_type', $wo['type'])) ?></span></td>
                            <td><?= wo_status_badge($wo['status']) ?></td>
                            <td class="nowrap"><?= e(format_date($wo['reported_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </section>
    </div>

    <div>
        <section class="card" id="documents">
            <div class="card__title"><h2><?= e(t('documents.title')) ?></h2></div>
            <ul class="doc-list">
                <?php foreach ($documents as $group): ?>
                    <?php $doc = $group['current']; ?>
                    <li class="doc-list__item<?= $doc !== null ? ' doc-list__item--' . e($doc['state']) : '' ?>">
                        <div class="doc-list__head">
                            <strong><?= e(doc_type_label($group['type'])) ?></strong>
                            <?= document_badge($doc) ?>
                        </div>
                        <?php if ($doc !== null): ?>
                            <div class="doc-list__meta">
                                <?= e(implode(' · ', array_filter([$doc['issuer'], $doc['reference']]))) ?>
                                <?php if ($doc['expires_at']): ?><span class="nowrap"><?= e(t('documents.until', ['date' => format_date($doc['expires_at'])])) ?></span><?php endif; ?>
                            </div>
                            <?php if ($doc['note']): ?><div class="muted small"><?= e($doc['note']) ?></div><?php endif; ?>
                            <?php if ($doc['file_name']): ?>
                                <a class="doc-file small" href="<?= e(url($eqUrl . '/documents/' . $doc['id'] . '/file')) ?>" target="_blank" rel="noopener"><i data-lucide="paperclip"></i><?= e($doc['original_name'] ?: t('documents.current_file')) ?></a>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($documentTypes !== [] || $group['history'] !== []): ?>
                            <div class="doc-list__tools">
                                <?php if ($documentTypes !== []): ?>
                                    <button type="button" class="btn btn--sm" data-open="#doc-add-<?= e($group['type']['id']) ?>"><i data-lucide="<?= $doc ? 'refresh-cw' : 'plus' ?>"></i><?= e($doc ? t('documents.renew') : t('common.add')) ?></button>
                                    <?php if ($doc !== null): ?>
                                        <button type="button" class="icon-btn" data-open="#doc-edit-<?= e($doc['id']) ?>" title="<?= e(t('common.edit')) ?>" aria-label="<?= e(t('common.edit')) ?>"><i data-lucide="pencil"></i></button>
                                        <form method="post" action="<?= e(url($eqUrl . '/documents/' . $doc['id'] . '/delete')) ?>" data-confirm="<?= e(t('documents.delete_confirm')) ?>">
                                            <?= csrf_field() ?><button type="submit" class="icon-btn icon-btn--danger" title="<?= e(t('common.delete')) ?>" aria-label="<?= e(t('common.delete')) ?>"><i data-lucide="trash-2"></i></button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($group['history'] !== []): ?>
                            <details class="crew-history">
                                <summary><?= e(t('documents.history', ['count' => count($group['history'])])) ?></summary>
                                <ul class="timeline timeline--dense">
                                    <?php foreach ($group['history'] as $old): ?>
                                        <li><?= e(implode(' · ', array_filter([$old['issuer'], $old['reference']])) ?: '—') ?><?php if ($old['file_name']): ?> <a href="<?= e(url($eqUrl . '/documents/' . $old['id'] . '/file')) ?>" target="_blank" rel="noopener" title="<?= e($old['original_name'] ?? '') ?>"><i data-lucide="paperclip"></i></a><?php endif; ?><time><?= e(format_date($old['issued_at']) ?: '…') ?> → <?= e(format_date($old['expires_at']) ?: '…') ?></time></li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="card">
            <div class="card__title">
                <h2><?= e(t('equipment.crew')) ?></h2>
                <?php if ($currentUser->can('equipment.assign')): ?>
                    <button type="button" class="btn btn--sm" data-open="#assign-dialog"><i data-lucide="user-plus"></i><?= e(t('equipment.assign')) ?></button>
                <?php endif; ?>
            </div>
            <?php if ($assignments === []): ?>
                <p class="empty"><?= e(t('equipment.no_crew')) ?></p>
            <?php else: ?>
                <ul class="crew">
                    <?php foreach ($assignments as $a): ?>
                        <li>
                            <span class="shift-badge shift-badge--<?= e($a['shift']) ?>" title="<?= e(t('shift.' . $a['shift'])) ?>"><i data-lucide="<?= e(['day' => 'sun', 'night' => 'moon', 'rotation' => 'repeat'][$a['shift']]) ?>"></i></span>
                            <span class="crew__name"><strong><?= e($a['name']) ?></strong><small><?= e(t('job.' . $a['job'])) ?> · <?= e(t('equipment.since', ['date' => format_date($a['started_at'])])) ?></small></span>
                            <?php if ($a['phone']): ?><a class="icon-btn" href="tel:<?= e(preg_replace('/[^\d+]/', '', $a['phone'])) ?>" title="<?= e($a['phone']) ?>"><i data-lucide="phone"></i></a><?php endif; ?>
                            <?php if ($currentUser->can('equipment.assign')): ?>
                                <form method="post" action="<?= e(url($eqUrl . '/assignments/' . $a['id'] . '/end')) ?>" data-confirm="<?= e(t('equipment.unassign_confirm', ['name' => $a['name']])) ?>">
                                    <?= csrf_field() ?><button type="submit" class="icon-btn icon-btn--danger" title="<?= e(t('equipment.unassign')) ?>" aria-label="<?= e(t('equipment.unassign')) ?>"><i data-lucide="user-minus"></i></button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($assignmentHistory !== []): ?>
                <details class="crew-history">
                    <summary><?= e(t('equipment.crew_history', ['count' => count($assignmentHistory)])) ?></summary>
                    <ul class="timeline timeline--dense">
                        <?php foreach ($assignmentHistory as $a): ?>
                            <li><strong><?= e($a['name']) ?></strong> · <?= e(t('shift.' . $a['shift'])) ?><time><?= e(format_date($a['started_at'])) ?> → <?= e(format_date($a['ended_at'])) ?></time></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="card__title">
                <h2><?= e(t('equipment.fluids')) ?></h2>
                <?php if ($currentUser->can('equipment.reading')): ?>
                    <button type="button" class="btn btn--sm" data-open="#fluid-dialog"><i data-lucide="droplet"></i><?= e(t('equipment.fluid_add')) ?></button>
                <?php endif; ?>
            </div>
            <?php if ($fluidRates !== []): ?>
                <ul class="rate-list">
                    <?php foreach ($fluidRates as $rate): ?>
                        <li>
                            <span><strong><?= e($rate['name']) ?></strong><small><?= e(t('equipment.fluid_90', ['qty' => format_qty($rate['quantity']), 'unit' => $rate['unit']])) ?></small></span>
                            <span class="rate-list__value"><?= $rate['rate'] !== null ? e(format_number($rate['rate'], 1)) . '<small> ' . e($rate['unit']) . '/100 h</small>' : '—' ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($fluidLogs === []): ?>
                <p class="empty"><?= e(t('equipment.no_fluid')) ?></p>
            <?php else: ?>
                <ul class="timeline timeline--dense">
                    <?php foreach (array_slice($fluidLogs, 0, 6) as $log): ?>
                        <li>
                            <time><?= e(format_date($log['logged_at'], true)) ?><?= $log['logged_by'] ? ' · ' . e($log['logged_by']) : '' ?></time>
                            <span class="badge badge--<?= $log['kind'] === 'change' ? 'maintenance' : 'neutral' ?>"><?= e(t('fluid.' . $log['kind'])) ?></span>
                            <strong><?= e(format_qty($log['quantity'])) ?> <?= e($log['unit']) ?></strong> <?= e($log['name']) ?>
                            <?php if ($log['hour_meter'] !== null): ?><span class="muted small">· <?= e(format_number($log['hour_meter'])) ?> h</span><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="card__title">
                <h2><?= e(t('equipment.readings')) ?></h2>
                <a class="btn btn--sm" href="<?= e(url('/meters', ['equipment_id' => $item['id']])) ?>"><i data-lucide="trending-up"></i><?= e(t('js.meters.show_evolution')) ?></a>
            </div>
            <?php if ($readings === []): ?>
                <p class="empty"><?= e(t('equipment.no_reading')) ?></p>
            <?php else: ?>
                <ul class="timeline timeline--dense">
                    <?php foreach ($readings as $r): ?>
                        <li>
                            <time><?= e(format_date($r['recorded_at'], true)) ?><?= $r['recorded_by'] ? ' · ' . e($r['recorded_by']) : '' ?></time>
                            <span class="reading">
                                <?php if ($r['hour_meter'] !== null): ?><span><i data-lucide="timer"></i><?= e(format_number($r['hour_meter'])) ?> h</span><?php endif; ?>
                                <?php if ($r['odometer_km'] !== null): ?><span><i data-lucide="route"></i><?= e(format_number($r['odometer_km'])) ?> km</span><?php endif; ?>
                                <?php if ($r['fuel_level'] !== null): ?><span><i data-lucide="fuel"></i><?= e($r['fuel_level']) ?> %</span><?php endif; ?>
                            </span>
                            <?php if ($r['note']): ?><div class="muted"><?= e($r['note']) ?></div><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="card__title">
                <h2><?= e(t('nav.preventive')) ?></h2>
                <?php if ($currentUser->can('preventive.manage')): ?>
                    <a class="btn btn--sm" href="<?= e(url('/preventive/create', ['equipment_id' => $item['id']])) ?>"><i data-lucide="plus"></i><?= e(t('common.add')) ?></a>
                <?php endif; ?>
            </div>
            <?php if ($plans === []): ?>
                <p class="empty"><?= e(t('common.none')) ?></p>
            <?php else: ?>
                <ul class="timeline timeline--dense">
                    <?php foreach ($plans as $plan): ?>
                        <li>
                            <strong><?= e($plan['title']) ?></strong><?= $plan['is_active'] ? '' : ' <span class="badge badge--neutral">' . e(t('common.inactive')) . '</span>' ?>
                            <time><?= e(t('preventive.next_due')) ?> : <?= e(format_date($plan['next_due_date']) ?: '—') ?><?= $plan['next_due_hours'] !== null ? ' · ' . e(format_number($plan['next_due_hours'])) . ' h' : '' ?><?= $plan['next_due_km'] !== null ? ' · ' . e(format_number($plan['next_due_km'])) . ' km' : '' ?></time>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="card__title"><h2><?= e(t('equipment.status_history')) ?></h2></div>
            <?php if ($history === []): ?>
                <p class="empty"><?= e(t('common.none')) ?></p>
            <?php else: ?>
                <ul class="timeline timeline--dense">
                    <?php foreach ($history as $h): ?>
                        <li>
                            <time><?= e(format_date($h['changed_at'], true)) ?><?= $h['changed_by'] ? ' · ' . e($h['changed_by']) : '' ?></time>
                            <?= $h['from_status'] ? status_badge($h['from_status']) . ' → ' : '' ?><?= status_badge($h['to_status']) ?>
                            <?php if ($h['reason']): ?><div class="muted"><?= e($h['reason']) ?></div><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>

<?= $view->partial('partials/activity', ['activity' => $activity]) ?>

<?php if ($currentUser->can('equipment.reading')): ?>
<template id="reading-dialog">
    <form method="post" action="<?= e(url($eqUrl . '/readings')) ?>" class="grid" style="text-align:left">
        <?= csrf_field() ?>
        <h3 class="dialog-title"><i data-lucide="gauge"></i><?= e(t('equipment.new_reading')) ?> · <?= e($item['code']) ?></h3>
        <div class="form-grid">
            <div class="field">
                <label for="rd-hours"><?= e(t('fields.hour_meter')) ?></label>
                <input id="rd-hours" name="hour_meter" type="number" class="input" min="<?= e($item['hour_meter'] ?? 0) ?>" step="1" inputmode="numeric" placeholder="<?= e($item['hour_meter'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="rd-km"><?= e(t('fields.odometer_km')) ?></label>
                <input id="rd-km" name="odometer_km" type="number" class="input" min="<?= e($item['odometer_km'] ?? 0) ?>" step="1" inputmode="numeric" placeholder="<?= e($item['odometer_km'] ?? '') ?>">
            </div>
        </div>
        <div class="field">
            <label for="rd-fuel"><?= e(t('fields.fuel_level')) ?> <output data-range-output="#rd-fuel"><?= e($fuel !== null ? $fuel . ' %' : t('equipment.fuel_untouched')) ?></output></label>
            <input id="rd-fuel" data-name="fuel_level" type="range" min="0" max="100" step="5" value="<?= e($fuel ?? 50) ?>" class="range" data-range>
        </div>
        <div class="field">
            <label for="rd-note"><?= e(t('fields.note')) ?></label>
            <input id="rd-note" name="note" class="input" maxlength="255">
        </div>
    </form>
</template>
<?php endif; ?>

<?php if ($currentUser->can('equipment.assign')): ?>
<template id="assign-dialog">
    <form method="post" action="<?= e(url($eqUrl . '/assignments')) ?>" class="grid" style="text-align:left">
        <?= csrf_field() ?>
        <h3 class="dialog-title"><i data-lucide="user-plus"></i><?= e(t('equipment.assign')) ?> · <?= e($item['code']) ?></h3>
        <?php if ($operators === []): ?>
            <p class="muted"><?= e(t('equipment.no_operator_on_site')) ?></p>
        <?php else: ?>
            <div class="field">
                <label for="as-person" class="required"><?= e(t('fields.personnel_id')) ?></label>
                <select id="as-person" name="personnel_id" class="input" required>
                    <option value=""></option>
                    <?php foreach ($operators as $op): ?>
                        <option value="<?= e($op['id']) ?>"><?= e($op['name']) ?> · <?= e(t('job.' . $op['job'])) ?><?= $op['team'] ? ' · ' . e($op['team']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <span class="label"><?= e(t('fields.shift')) ?></span>
                <div class="segmented" role="radiogroup">
                    <?php foreach (\App\Domain\Workforce::SHIFTS as $i => $value): ?>
                        <label><input type="radio" name="shift" value="<?= e($value) ?>"<?= $i === 0 ? ' checked' : '' ?>><span><?= e(t('shift.' . $value)) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="field">
                <label for="as-note"><?= e(t('fields.note')) ?></label>
                <input id="as-note" name="note" class="input" maxlength="255">
            </div>
            <p class="hint"><?= e(t('equipment.assign_hint')) ?></p>
        <?php endif; ?>
    </form>
</template>
<?php endif; ?>

<?php if ($currentUser->can('equipment.reading')): ?>
<template id="fluid-dialog">
    <form method="post" action="<?= e(url($eqUrl . '/fluids')) ?>" class="grid" style="text-align:left">
        <?= csrf_field() ?>
        <h3 class="dialog-title"><i data-lucide="droplet"></i><?= e(t('equipment.fluid_add')) ?> · <?= e($item['code']) ?></h3>
        <?php if ($fluidOptions === []): ?>
            <p class="muted"><?= e(t('equipment.no_fluid_stock')) ?></p>
        <?php else: ?>
            <div class="field">
                <label for="fl-part" class="required"><?= e(t('fields.part_id')) ?></label>
                <select id="fl-part" name="part_id" class="input" required>
                    <?php foreach ($fluidOptions as $opt): ?>
                        <option value="<?= e($opt['id']) ?>"<?= (float) $opt['quantity'] <= 0 ? ' disabled' : '' ?>><?= (int) $opt['compatible'] ? '★ ' : '' ?><?= e($opt['name']) ?> — <?= e(format_qty($opt['quantity'])) ?> <?= e($opt['unit']) ?> <?= e(t('equipment.in_stock')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <span class="label"><?= e(t('fields.kind')) ?></span>
                <div class="segmented" role="radiogroup">
                    <?php foreach (\App\Services\FluidService::KINDS as $i => $kind): ?>
                        <label><input type="radio" name="kind" value="<?= e($kind) ?>"<?= $i === 0 ? ' checked' : '' ?>><span><?= e(t('fluid.' . $kind)) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="field">
                <label for="fl-qty" class="required"><?= e(t('fields.quantity')) ?></label>
                <input id="fl-qty" name="quantity" type="number" min="0.1" step="0.1" inputmode="decimal" class="input" required>
            </div>
            <div class="field">
                <label for="fl-note"><?= e(t('fields.note')) ?></label>
                <input id="fl-note" name="note" class="input" maxlength="255">
            </div>
            <p class="hint"><?= e(t('equipment.fluid_hint', ['hours' => $item['hour_meter'] !== null ? format_number($item['hour_meter']) . ' h' : '—'])) ?></p>
        <?php endif; ?>
    </form>
</template>
<?php endif; ?>

<?php if ($documentTypes !== []): ?>
<?php
$documentForm = static function (string $action, string $titleText, array $doc, int $typeId) use ($documentTypes, $item, $view, $eqUrl): string {
    ob_start(); ?>
    <form method="post" action="<?= e(url($action)) ?>" class="grid" style="text-align:left" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <h3 class="dialog-title"><i data-lucide="file-badge"></i><?= e($titleText) ?> · <?= e($item['code']) ?></h3>
        <div class="field">
            <label class="required"><?= e(t('fields.document_type_id')) ?></label>
            <select name="document_type_id" class="input" required>
                <?php foreach ($documentTypes as $type): ?>
                    <option value="<?= e($type['id']) ?>"<?= (int) $type['id'] === $typeId ? ' selected' : '' ?>><?= e(doc_type_label($type)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-grid">
            <?= $view->partial('partials/document_fields', ['doc' => $doc,
                'fileUrl' => !empty($doc['file_name']) ? url($eqUrl . '/documents/' . $doc['id'] . '/file') : null]) ?>
        </div>
    </form>
    <?php return (string) ob_get_clean();
};
?>
<?php foreach ($documents as $group): ?>
    <?php $prev = $group['current']; ?>
    <template id="doc-add-<?= e($group['type']['id']) ?>">
        <?= $documentForm($eqUrl . '/documents', $prev ? t('documents.renew') : t('documents.add'),
            $prev ? ['issuer' => $prev['issuer']] : [], (int) $group['type']['id']) ?>
    </template>
    <?php if ($prev !== null): ?>
        <template id="doc-edit-<?= e($prev['id']) ?>">
            <?= $documentForm($eqUrl . '/documents/' . $prev['id'], t('documents.edit'), $prev, (int) $group['type']['id']) ?>
        </template>
    <?php endif; ?>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($currentUser->can('equipment.status')): ?>
<template id="status-dialog">
    <form method="post" action="<?= e(url($eqUrl . '/status')) ?>" class="grid" style="text-align:left">
        <?= csrf_field() ?>
        <div class="field">
            <label for="st-status"><?= e(t('fields.status')) ?></label>
            <select id="st-status" name="status" class="input" required>
                <?php foreach ($statuses as $status): ?>
                    <option value="<?= e($status->value) ?>"<?= $status->value === $item['status'] ? ' selected' : '' ?>><?= e(t($status->labelKey())) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="st-reason" class="required"><?= e(t('fields.reason')) ?></label>
            <input id="st-reason" name="reason" class="input" required maxlength="255">
        </div>
    </form>
</template>
<?php endif; ?>
