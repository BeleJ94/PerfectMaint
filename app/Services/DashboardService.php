<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\EquipmentStatus;
use App\Repositories\EquipmentRepository;
use App\Repositories\PartRepository;
use App\Repositories\PreventivePlanRepository;
use App\Repositories\WorkOrderRepository;
use DateTimeImmutable;

/** Indicateurs du tableau de bord, calculés dans le périmètre de l'utilisateur. */
final class DashboardService
{
    public const WEEKS = 8;

    public function __construct(
        private readonly EquipmentRepository $equipment,
        private readonly WorkOrderRepository $workOrders,
        private readonly PreventivePlanRepository $plans,
        private readonly PartRepository $parts,
    ) {
    }

    public function data(?int $siteId): array
    {
        $byStatus = $this->equipment->countByStatus($siteId);
        $counts = [];
        foreach (EquipmentStatus::cases() as $status) {
            $counts[$status->value] = $byStatus[$status->value] ?? 0;
        }
        $total = array_sum($counts);

        return [
            'equipment' => [
                'total' => $total,
                'by_status' => $counts,
                'availability' => $total > 0 ? round($counts['available'] / $total * 100, 1) : null,
            ],
            'work_orders' => $this->workOrders->kpis($siteId),
            'preventive_due' => $this->plans->countDue(PreventiveService::DEFAULT_HORIZON_DAYS, $siteId),
            'parts_below_min' => $this->parts->countBelowMin($siteId),
            'weekly' => self::weeklySeries($this->workOrders->weeklyCreated(self::WEEKS, $siteId), new DateTimeImmutable('today')),
        ];
    }

    /**
     * Série continue sur les N dernières semaines ISO (semaines vides = 0).
     *
     * @param list<array{yw: int|string, type: string, n: int|string}> $rows
     * @return array{labels: list<string>, corrective: list<int>, preventive: list<int>}
     */
    public static function weeklySeries(array $rows, DateTimeImmutable $today, int $weeks = self::WEEKS): array
    {
        $index = [];
        foreach ($rows as $row) {
            $index[(string) $row['yw']][$row['type']] = (int) $row['n'];
        }
        $series = ['labels' => [], 'keys' => [], 'corrective' => [], 'preventive' => []];
        $monday = $today->modify('monday this week');
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $week = $monday->modify("-$i weeks");
            $key = $week->format('oW');
            $series['labels'][] = 'S' . $week->format('W');
            $series['keys'][] = (int) $key;
            $series['corrective'][] = $index[$key]['corrective'] ?? 0;
            $series['preventive'][] = $index[$key]['preventive'] ?? 0;
        }

        return $series;
    }
}
