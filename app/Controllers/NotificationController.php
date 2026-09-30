<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\AlertRepository;
use App\Repositories\EquipmentRepository;
use App\Repositories\PreventivePlanRepository;
use App\Repositories\ProcurementRepository;
use App\Repositories\WorkOrderRepository;
use App\Services\PreventiveService;

/**
 * Centre de notifications (cloche de la barre supérieure) : points d'attention du périmètre,
 * chacun ouvrant la fenêtre de détail correspondante (tableau filtrable, exports).
 */
final class NotificationController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly WorkOrderRepository $workOrders,
        private readonly EquipmentRepository $equipment,
        private readonly PreventivePlanRepository $plans,
        private readonly ProcurementRepository $procurement,
        private readonly AlertRepository $alerts,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        $user = $this->user();
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
        if ($user->can('parts.view')) {
            $summary = $this->procurement->summary();
            $add('stock_out', 'bad', 'package-x', (int) $summary['out_count'],
                $spec('procurement', '/procurement/data', t('level.out'), ['level' => 'out'], '/procurement', '/parts/{id}'));
            $add('stock_low', 'warn', 'package-minus', (int) $summary['low_count'],
                $spec('procurement', '/procurement/data', t('level.low'), ['level' => 'low'], '/procurement', '/parts/{id}'));
        }
        $add('contracts', 'warn', 'file-clock', count($this->equipment->contractsEnding(30)),
            $spec('equipment', '/equipment/data', t('site.contracts_ending'), ['contract_days' => '30'], '/equipment', '/equipment/{id}'));
        $add('wear', 'warn', 'trending-down', count($this->alerts->prematureWear()), null, url('/alerts') . '#wear');
        $add('overuse', 'warn', 'droplets', count($this->alerts->lubricantOveruse()), null, url('/alerts') . '#overuse');

        return $this->json([
            'count' => count(array_filter($items, static fn (array $i) => $i['level'] === 'bad')),
            'total' => count($items),
            'items' => $items,
        ]);
    }
}
