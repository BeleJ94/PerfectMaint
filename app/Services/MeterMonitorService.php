<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Domain\MeterForecast;
use App\Repositories\EquipmentRepository;
use App\Repositories\MeterMonitorRepository;
use DateTimeImmutable;

/**
 * Supervision des index : pour chaque engin, rythme d'utilisation tiré des relevés quotidiens
 * et projection des échéances de maintenance au compteur.
 */
final class MeterMonitorService
{
    public const PERIODS = [30, 90, 365, 548];

    private const STATUS_RANK = [MeterForecast::DUE => 0, MeterForecast::SOON => 1, MeterForecast::OK => 2, 'none' => 3];

    public function __construct(
        private readonly MeterMonitorRepository $monitor,
        private readonly EquipmentRepository $equipment,
    ) {
    }

    /**
     * @return array{summary: array<string, int>, rows: list<array<string, mixed>>, generated_at: string}
     */
    public function overview(?int $siteId = null): array
    {
        $today = new DateTimeImmutable('today');
        $plans = self::group($this->monitor->hourPlans($siteId));
        $readings = self::group($this->monitor->readingsSince(self::windowStart($today), $siteId));

        $rows = [];
        $summary = ['equipment' => 0, 'due' => 0, 'soon' => 0, 'read_today' => 0, 'stale' => 0];
        foreach ($this->monitor->fleet($siteId) as $e) {
            $id = (int) $e['id'];
            $row = $this->row($e, $readings[$id] ?? [], $plans[$id] ?? [], $today);
            $rows[] = $row;
            $summary['equipment']++;
            $summary['read_today'] += $row['days_since'] === 0 ? 1 : 0;
            $summary['stale'] += $row['stale'] ? 1 : 0;
            if (isset($summary[$row['status']])) {
                $summary[$row['status']]++;
            }
        }
        usort($rows, static fn (array $a, array $b) => self::urgency($a) <=> self::urgency($b) ?: strcmp($a['code'], $b['code']));

        return ['summary' => $summary, 'rows' => $rows, 'generated_at' => date('Y-m-d H:i:s')];
    }

    /** Compteurs de synthèse (tableau de bord, notifications). */
    public function summary(?int $siteId = null): array
    {
        return $this->overview($siteId)['summary'];
    }

    /** Évolution des index d'un engin sur $days jours, avec ses échéances projetées. */
    public function detail(int $equipmentId, int $days): array
    {
        $e = $this->equipment->find($equipmentId) ?? throw HttpException::notFound();
        $today = new DateTimeImmutable('today');
        $from = min($today->modify("-$days days")->format('Y-m-d'), self::windowStart($today));
        $readings = $this->monitor->readingsSince($from, null, $equipmentId);
        $windowStart = self::windowStart($today);
        $recent = array_values(array_filter($readings, static fn (array $r) => $r['recorded_at'] >= $windowStart));
        $chartStart = $today->modify("-$days days")->format('Y-m-d');
        $e['last_reading_at'] = $readings !== [] ? $readings[count($readings) - 1]['recorded_at'] : null;

        return $this->row($e, $recent, $this->monitor->hourPlans(null, $equipmentId), $today) + [
            'days' => $days,
            'series' => array_values(array_map(
                static fn (array $r) => [
                    'at' => $r['recorded_at'],
                    'hour_meter' => $r['hour_meter'] !== null ? (int) $r['hour_meter'] : null,
                    'odometer_km' => $r['odometer_km'] !== null ? (int) $r['odometer_km'] : null,
                    // Détail du relevé, affiché au clic sur un point de la courbe
                    'fuel_level' => $r['fuel_level'] !== null ? (int) $r['fuel_level'] : null,
                    'note' => $r['note'],
                    'by' => $r['recorded_by_name'],
                ],
                array_filter($readings, static fn (array $r) => $r['recorded_at'] >= $chartStart)
            )),
        ];
    }

    private function row(array $e, array $readings, array $plans, DateTimeImmutable $today): array
    {
        $hours = $e['hour_meter'] !== null ? (int) $e['hour_meter'] : null;
        $rateHours = MeterForecast::dailyRate(self::points($readings, 'hour_meter'));
        $rateKm = MeterForecast::dailyRate(self::points($readings, 'odometer_km'));
        $daysSince = MeterForecast::daysSince($e['last_reading_at'], $today);

        $projected = [];
        if ($hours !== null) {
            foreach ($plans as $plan) {
                $projected[] = [
                    'id' => (int) $plan['id'],
                    'title' => $plan['title'],
                    'interval_hours' => $plan['interval_hours'] !== null ? (int) $plan['interval_hours'] : null,
                    'next_due_hours' => (int) $plan['next_due_hours'],
                    'open_work_order_id' => $plan['open_work_order_id'] !== null ? (int) $plan['open_work_order_id'] : null,
                ] + MeterForecast::project(
                    $hours,
                    (int) $plan['next_due_hours'],
                    $plan['interval_hours'] !== null ? (int) $plan['interval_hours'] : null,
                    $rateHours,
                    $today
                );
            }
            usort($projected, static fn (array $a, array $b) => $a['remaining'] <=> $b['remaining']);
        }

        return [
            'id' => (int) $e['id'],
            'code' => $e['code'],
            'name' => trim(($e['brand'] ?? '') . ' ' . ($e['model'] ?? '')) ?: $e['name'],
            'site' => $e['site'],
            'equipment_status' => $e['status'],
            'hour_meter' => $hours,
            'odometer_km' => $e['odometer_km'] !== null ? (int) $e['odometer_km'] : null,
            'last_reading_at' => $e['last_reading_at'],
            'days_since' => $daysSince,
            'stale' => MeterForecast::isStale($daysSince),
            'rate_hours' => $rateHours !== null ? round($rateHours, 1) : null,
            'rate_km' => $rateKm !== null ? round($rateKm, 1) : null,
            'status' => $projected[0]['status'] ?? 'none',
            'plans' => $projected,
        ];
    }

    /** Tri : statut le plus urgent d'abord, puis le reste le plus faible. */
    private static function urgency(array $row): array
    {
        return [self::STATUS_RANK[$row['status']], $row['plans'][0]['remaining'] ?? PHP_INT_MAX];
    }

    private static function windowStart(DateTimeImmutable $today): string
    {
        return $today->modify('-' . MeterForecast::WINDOW_DAYS . ' days')->format('Y-m-d');
    }

    /** @return array<int, list<array>> lignes regroupées par engin */
    private static function group(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['equipment_id']][] = $row;
        }

        return $out;
    }

    /** @return list<array{at: string, value: int}> */
    private static function points(array $readings, string $column): array
    {
        $points = [];
        foreach ($readings as $r) {
            if ($r[$column] !== null) {
                $points[] = ['at' => $r['recorded_at'], 'value' => (int) $r[$column]];
            }
        }

        return $points;
    }
}
