<?php
/**
 * @var array $sites  @var int|null $siteId  @var string $mode  @var DateTimeImmutable $date  @var DateTimeImmutable $from
 * @var DateTimeImmutable $to  @var string $prev  @var string $next  @var array $events  @var array $byDay  @var int $overdue
 */
$today = date('Y-m-d');
$kindIcons = ['preventive' => 'calendar-clock', 'preventive_planned' => 'calendar-check', 'work_order' => 'wrench', 'contract' => 'file-clock', 'document' => 'file-badge'];
$locale = \App\Core\I18n::current()->locale();
$dayNames = $locale === 'en' ? ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] : ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
$monthNames = $locale === 'en'
    ? ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
    : ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
$label = match ($mode) {
    'day' => $dayNames[(int) $date->format('N') - 1] . ' ' . $date->format('j') . ' ' . $monthNames[(int) $date->format('n') - 1] . ' ' . $date->format('Y'),
    'week' => t('planning.week_of', ['n' => $date->format('W'), 'from' => format_date($from->format('Y-m-d')), 'to' => format_date($to->format('Y-m-d'))]),
    'month' => ucfirst($monthNames[(int) $date->format('n') - 1]) . ' ' . $date->format('Y'),
};
$counts = array_count_values(array_column($events, 'kind'));
$renderEvent = static function (array $ev, bool $compact) use ($kindIcons): string {
    $cls = 'ev ev--' . $ev['kind'] . ($ev['overdue'] ? ' ev--overdue' : '') . ($ev['priority'] === 'critical' ? ' ev--critical' : '');
    return '<button type="button" class="' . e($cls) . '" data-event="' . (int) $ev['_i'] . '" title="' . e($ev['title'] . ' · ' . $ev['subtitle'] . ' · ' . $ev['site']) . '">'
        . '<i data-lucide="' . e($kindIcons[$ev['kind']]) . '"></i><span class="ev__text"><strong>' . e($ev['subtitle']) . '</strong>'
        . ($compact ? '' : '<span>' . e($ev['title']) . '</span><small>' . e($ev['site']) . '</small>') . '</span></button>';
};

// Détail de chaque événement pour la fenêtre modale et l'export matriciel
$byDay = [];
$payloadEvents = [];
foreach ($events as $i => $ev) {
    $ev['_i'] = $i;
    $byDay[$ev['date']][] = $ev;
    $fields = [[t('js.date'), format_date($ev['date'])], [t('fields.site_id'), $ev['site']], [t('fields.equipment_id'), $ev['code'] . ' · ' . $ev['equipment_name']]];
    $viewUrl = $edit = null;
    $editLabel = t('common.edit');
    switch ($ev['kind']) {
        case 'preventive':
        case 'preventive_planned':
            $interval = array_filter([
                $ev['interval_days'] ? t('js.every_days', ['n' => $ev['interval_days']]) : null,
                $ev['interval_hours'] ? t('js.every_hours', ['n' => $ev['interval_hours']]) : null,
            ]);
            $fields[] = [t('js.interval'), implode(' / ', $interval) ?: '—'];
            $fields[] = [t('fields.last_done_at'), format_date($ev['last_done_at']) ?: '—'];
            if ($ev['next_due_hours'] !== null) {
                $fields[] = [t('js.next_due_hours'), format_number($ev['next_due_hours']) . ' h' . ($ev['hour_meter'] !== null ? ' (' . t('planning.current_meter', ['h' => format_number($ev['hour_meter'])]) . ')' : '')];
            }
            if ($ev['kind'] === 'preventive_planned') {
                $fields[] = [t('js.work_order'), $ev['wo_reference'] . ' · ' . t('wo_status.' . $ev['status'])];
                $viewUrl = url('/work-orders/' . $ev['id']);
            } else {
                $viewUrl = url('/equipment/' . $ev['equipment_id']);
            }
            if ($currentUser->can('preventive.manage')) {
                $edit = url('/preventive/' . $ev['plan_id'] . '/edit');
                $editLabel = t('planning.edit_plan');
            }
            break;
        case 'work_order':
            $fields[] = [t('fields.reference'), $ev['wo_reference']];
            $fields[] = [t('fields.status'), t('wo_status.' . $ev['status'])];
            $fields[] = [t('fields.priority'), t('priority.' . $ev['priority']) . ($ev['immobilizing'] ? ' · ' . t('fields.immobilizing') : '')];
            $fields[] = [t('fields.assigned_to'), $ev['assignee'] ?? t('work_orders.unassigned')];
            $viewUrl = url('/work-orders/' . $ev['id']);
            if ($currentUser->can('workorder.manage')) {
                $edit = url('/work-orders/' . $ev['id'] . '/edit');
                $editLabel = t('planning.edit_wo');
            }
            break;
        case 'contract':
            $fields[] = [t('fields.ownership'), t('ownership.' . $ev['ownership'])];
            $fields[] = [t('fields.contract_ref'), $ev['contract_ref'] ?? '—'];
            $fields[] = [t('fields.contract_party'), $ev['contract_party'] ?? '—'];
            $fields[] = [t('equipment.contract_period'), (format_date($ev['contract_start']) ?: '…') . ' → ' . format_date($ev['date'])];
            $viewUrl = url('/equipment/' . $ev['equipment_id']);
            if ($currentUser->can('equipment.manage')) {
                $edit = url('/equipment/' . $ev['equipment_id'] . '/edit');
                $editLabel = t('planning.edit_contract');
            }
            break;
        case 'document':
            $fields[] = [t('fields.document_type_id'), $ev['document_type']];
            $fields[] = [t('fields.issuer'), $ev['issuer'] ?? '—'];
            $fields[] = [t('fields.document_reference'), $ev['reference'] ?? '—'];
            $fields[] = [t('documents.period'), (format_date($ev['issued_at']) ?: '…') . ' → ' . format_date($ev['date'])];
            $viewUrl = url('/equipment/' . $ev['equipment_id']) . '#documents';
            break;
    }
    $payloadEvents[] = [
        'kind' => $ev['kind'], 'kindLabel' => label('planning', 'kind_' . $ev['kind']), 'icon' => $kindIcons[$ev['kind']],
        'title' => $ev['title'], 'subtitle' => $ev['subtitle'], 'site' => $ev['site'], 'code' => $ev['code'],
        'equipment_name' => $ev['equipment_name'], 'date' => $ev['date'], 'overdue' => $ev['overdue'], 'priority' => $ev['priority'],
        'fields' => $fields, 'view' => $viewUrl, 'edit' => $edit, 'editLabel' => $editLabel,
    ];
}
$matrixDays = [];
for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
    $matrixDays[] = ['key' => $d->format('Y-m-d'), 'label' => $dayNames[(int) $d->format('N') - 1] . ' ' . $d->format('d/m'), 'weekend' => (int) $d->format('N') >= 6];
}
$planningPayload = [
    'events' => $payloadEvents,
    'days' => $matrixDays,
    'labels' => [
        'title' => t('nav.planning') . ' — ' . $label,
        'period' => $label,
        'site' => t('fields.site_id'),
        'equipment' => t('fields.equipment_id'),
        'total' => t('js.total'),
        'overdue' => t('planning.overdue_short'),
        'legend' => t('planning.legend'),
        'kinds' => [
            'preventive' => label('planning', 'kind_preventive'),
            'preventive_planned' => label('planning', 'kind_preventive_planned'),
            'work_order' => label('planning', 'kind_work_order'),
            'contract' => label('planning', 'kind_contract'),
            'document' => label('planning', 'kind_document'),
        ],
        'short' => [
            'preventive' => t('planning.short_preventive'),
            'preventive_planned' => t('planning.short_preventive_planned'),
            'work_order' => t('planning.short_work_order'),
            'contract' => t('planning.short_contract'),
            'document' => t('planning.short_document'),
        ],
        'open' => t('planning.open'),
        'close' => t('js.close'),
        'generated' => t('planning.generated', ['date' => format_date(date('Y-m-d H:i'), true)]),
        'scope' => $siteId ? ($sites[array_search($siteId, array_map('intval', array_column($sites, 'id')), true)]['name'] ?? '') : t('common.all_sites'),
    ],
];
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="calendar-range"></i></span>
        <div>
            <h1><?= e(t('nav.planning')) ?></h1>
            <div class="page-title__meta"><span><?= e(t('planning.subtitle')) ?></span></div>
        </div>
    </div>
    <div class="page-header__actions">
        <button type="button" class="btn" data-planning-export="excel"<?= $events === [] ? ' disabled' : '' ?>><i data-lucide="file-spreadsheet"></i><?= e(t('common.export_excel')) ?></button>
        <button type="button" class="btn" data-planning-export="pdf"<?= $events === [] ? ' disabled' : '' ?>><i data-lucide="file-text"></i><?= e(t('common.export_pdf')) ?></button>
    </div>
</div>

<?php if ($overdue > 0): ?>
    <div class="alert alert--error"><i data-lucide="alarm-clock"></i><div><?= e(t('planning.overdue', ['count' => $overdue])) ?> <a href="<?= e(url('/work-orders', ['status' => 'overdue'] + ($siteId ? ['site_id' => $siteId] : []))) ?>"><?= e(t('common.see_all')) ?></a></div></div>
<?php endif; ?>

<div class="planning-bar card">
    <div class="segmented" role="tablist">
        <?php foreach (['day', 'week', 'month'] as $v): ?>
            <a href="<?= e(url('/planning', array_filter(['view' => $v, 'date' => $date->format('Y-m-d'), 'site_id' => $siteId], static fn ($x) => $x !== null))) ?>" class="segmented__link<?= $v === $mode ? ' is-active' : '' ?>" role="tab" aria-selected="<?= $v === $mode ? 'true' : 'false' ?>"><?= e(t('planning.' . $v)) ?></a>
        <?php endforeach; ?>
    </div>
    <div class="planning-bar__nav">
        <a class="icon-btn icon-btn--lg" href="<?= e(url('/planning', array_filter(['view' => $mode, 'date' => $prev, 'site_id' => $siteId], static fn ($x) => $x !== null))) ?>" aria-label="<?= e(t('planning.previous')) ?>"><i data-lucide="chevron-left"></i></a>
        <a class="btn btn--sm" href="<?= e(url('/planning', array_filter(['view' => $mode, 'site_id' => $siteId], static fn ($x) => $x !== null))) ?>"><?= e(t('planning.today')) ?></a>
        <a class="icon-btn icon-btn--lg" href="<?= e(url('/planning', array_filter(['view' => $mode, 'date' => $next, 'site_id' => $siteId], static fn ($x) => $x !== null))) ?>" aria-label="<?= e(t('planning.next')) ?>"><i data-lucide="chevron-right"></i></a>
        <strong class="planning-bar__label"><?= e($label) ?></strong>
    </div>
    <form method="get" action="<?= e(url('/planning')) ?>" class="planning-bar__site">
        <input type="hidden" name="view" value="<?= e($mode) ?>"><input type="hidden" name="date" value="<?= e($date->format('Y-m-d')) ?>">
        <select name="site_id" class="input input--sm" data-autosubmit aria-label="<?= e(t('fields.site_id')) ?>">
            <option value=""><?= e(t('common.all_sites')) ?></option>
            <?php foreach ($sites as $site): ?><option value="<?= e($site['id']) ?>"<?= $siteId === (int) $site['id'] ? ' selected' : '' ?>><?= e($site['name']) ?></option><?php endforeach; ?>
        </select>
    </form>
</div>

<div class="legend">
    <?php foreach (['preventive', 'preventive_planned', 'work_order', 'contract', 'document'] as $kind): ?>
        <span class="legend__item"><span class="ev-dot ev-dot--<?= e($kind) ?>"></span><?= e(label('planning', 'kind_' . $kind)) ?> <b><?= e($counts[$kind] ?? 0) ?></b></span>
    <?php endforeach; ?>
</div>

<?php if ($mode === 'day'): ?>
    <section class="card">
        <?php $dayEvents = $byDay[$date->format('Y-m-d')] ?? []; ?>
        <?php if ($dayEvents === []): ?>
            <div class="empty-state empty-state--ok"><i data-lucide="calendar-check-2"></i><p><?= e(t('planning.nothing')) ?></p></div>
        <?php else: ?>
            <div class="day-list"><?php foreach ($dayEvents as $ev): ?><?= $renderEvent($ev, false) ?><?php endforeach; ?></div>
        <?php endif; ?>
    </section>
<?php else: ?>
    <div class="calendar calendar--<?= e($mode) ?>">
        <?php foreach ($dayNames as $name): ?><div class="calendar__head"><?= e($name) ?></div><?php endforeach; ?>
        <?php for ($d = $from; $d <= $to; $d = $d->modify('+1 day')): ?>
            <?php
            $key = $d->format('Y-m-d');
            $dayEvents = $byDay[$key] ?? [];
            $outside = $mode === 'month' && $d->format('m') !== $date->format('m');
            $max = $mode === 'month' ? 3 : 50;
            ?>
            <div class="calendar__day<?= $key === $today ? ' is-today' : '' ?><?= $outside ? ' is-outside' : '' ?><?= (int) $d->format('N') >= 6 ? ' is-weekend' : '' ?>">
                <a class="calendar__date" href="<?= e(url('/planning', array_filter(['view' => 'day', 'date' => $key, 'site_id' => $siteId], static fn ($x) => $x !== null))) ?>"><?= e($mode === 'week' ? $d->format('d/m') : $d->format('j')) ?></a>
                <?php foreach (array_slice($dayEvents, 0, $max) as $ev): ?><?= $renderEvent($ev, $mode === 'month') ?><?php endforeach; ?>
                <?php if (count($dayEvents) > $max): ?>
                    <a class="calendar__more" href="<?= e(url('/planning', array_filter(['view' => 'day', 'date' => $key, 'site_id' => $siteId], static fn ($x) => $x !== null))) ?>"><?= e(t('planning.more', ['n' => count($dayEvents) - $max])) ?></a>
                <?php endif; ?>
            </div>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<script type="application/json" id="planning-data"><?= json_for_html($planningPayload) ?></script>
