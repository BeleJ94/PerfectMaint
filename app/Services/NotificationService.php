<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AlertRepository;
use App\Repositories\EquipmentDocumentRepository;
use App\Repositories\EquipmentRepository;
use App\Repositories\PreventivePlanRepository;
use App\Repositories\ProcurementRepository;
use App\Repositories\WorkOrderRepository;

/**
 * Points d'attention du périmètre courant : source commune de la cloche de notifications
 * et du récapitulatif des alarmes envoyé par e-mail.
 */
final class NotificationService
{
    public function __construct(
        private readonly WorkOrderRepository $workOrders,
        private readonly EquipmentRepository $equipment,
        private readonly PreventivePlanRepository $plans,
        private readonly ProcurementRepository $procurement,
        private readonly AlertRepository $alerts,
        private readonly MeterMonitorService $meters,
        private readonly EquipmentDocumentRepository $documents,
    ) {
    }

    /**
     * @param bool $withStock inclure les alertes de stock (réservées aux profils qui voient les pièces)
     * @return list<array{key: string, level: string, icon: string, count: int, label: string, drill: ?array, link: ?string}>
     */
    public function items(bool $withStock = true): array
    {
        $wo = $this->workOrders->kpis();
        $down = $this->equipment->countByStatus()['breakdown'] ?? 0;
        $items = [];
        $add = static function (string $key, string $level, string $icon, int $count, ?array $drill, ?string $link = null) use (&$items): void {
            if ($count > 0) {
                $items[] = ['key' => $key, 'level' => $level, 'icon' => $icon, 'count' => $count,
                    'label' => t('notify.' . $key, ['count' => $count]), 'drill' => $drill, 'link' => $link];
            }
        };
        $spec = static fn (string $table, string $source, string $title, array $params, string $page, string $row) => [
            'table' => $table, 'source' => url($source), 'title' => $title, 'params' => $params,
            'page' => url($page, $params), 'row' => url($row), 'chips' => [],
        ];

        $add('down', 'bad', 'alert-octagon', (int) $down,
            $spec('equipment', '/equipment/data', t('dashboard.breakdowns'), ['status' => 'breakdown'], '/equipment', '/equipment/{id}'));
        $add('critical', 'bad', 'flame', (int) $wo['critical'],
            $spec('work_orders', '/work-orders/data', t('notify.title_critical'), ['status' => 'active', 'priority' => 'critical'], '/work-orders', '/work-orders/{id}'));
        $add('overdue', 'bad', 'alarm-clock', (int) $wo['overdue'],
            $spec('work_orders', '/work-orders/data', t('dashboard.overdue'), ['status' => 'overdue'], '/work-orders', '/work-orders/{id}'));
        $add('preventive', 'warn', 'calendar-clock', $this->plans->countDue(PreventiveService::DEFAULT_HORIZON_DAYS),
            $spec('preventive', '/preventive/data', t('dashboard.preventive_due'), ['due' => '1'], '/preventive', '/preventive/{id}/edit'));
        $meters = $this->meters->summary();
        $add('meter_soon', 'warn', 'gauge', $meters['soon'], null, url('/meters'));
        $add('meter_stale', 'warn', 'timer-off', $meters['stale'], null, url('/meters'));
        if ($withStock) {
            $summary = $this->procurement->summary();
            $add('stock_out', 'bad', 'package-x', (int) $summary['out_count'],
                $spec('procurement', '/procurement/data', t('level.out'), ['level' => 'out'], '/procurement', '/parts/{id}'));
            $add('stock_low', 'warn', 'package-minus', (int) $summary['low_count'],
                $spec('procurement', '/procurement/data', t('level.low'), ['level' => 'low'], '/procurement', '/parts/{id}'));
        }
        $add('contracts', 'warn', 'file-clock', count($this->equipment->contractsEnding(30)),
            $spec('equipment', '/equipment/data', t('site.contracts_ending'), ['contract_days' => '30'], '/equipment', '/equipment/{id}'));
        $documents = $this->documents->expiring();
        $expired = count(array_filter($documents, static fn (array $d) => (int) $d['days_left'] < 0));
        $add('documents_expired', 'bad', 'file-x', $expired, null, url('/alerts') . '#documents');
        $add('documents_soon', 'warn', 'file-clock', count($documents) - $expired, null, url('/alerts') . '#documents');
        $add('wear', 'info', 'trending-down', count($this->alerts->prematureWear()), null, url('/alerts') . '#wear');
        $add('overuse', 'info', 'droplets', count($this->alerts->lubricantOveruse()), null, url('/alerts') . '#overuse');

        return $items;
    }
}
