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
        $plans = self::group($this->monitor->meterPlans($siteId));
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

        return $this->row($e, $recent, $this->monitor->meterPlans(null, $equipmentId), $today) + [
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
        $km = $e['odometer_km'] !== null ? (int) $e['odometer_km'] : null;
        $rateHours = MeterForecast::dailyRate(self::points($readings, 'hour_meter'));
        $rateKm = MeterForecast::dailyRate(self::points($readings, 'odometer_km'));
        $daysSince = MeterForecast::daysSince($e['last_reading_at'], $today);
        $meters = [
            'hour_meter' => ['unit' => 'h', 'current' => $hours, 'rate' => $rateHours, 'interval' => 'interval_hours', 'due' => 'next_due_hours'],
            'odometer_km' => ['unit' => 'km', 'current' => $km, 'rate' => $rateKm, 'interval' => 'interval_km', 'due' => 'next_due_km'],
        ];

        // Une échéance par plan et par compteur suivi (un plan « 500 h ou 10 000 km » en donne deux).
        $projected = [];
        foreach ($plans as $plan) {
            foreach ($meters as $metric => $m) {
                if ($plan[$m['due']] === null || $m['current'] === null) {
                    continue;
                }
                $interval = $plan[$m['interval']] !== null ? (int) $plan[$m['interval']] : null;
                $projected[] = [
                    'id' => (int) $plan['id'],
                    'title' => $plan['title'],
                    'metric' => $metric,
                    'unit' => $m['unit'],
                    'interval' => $interval,
                    'next_due' => (int) $plan[$m['due']],
                    'open_work_order_id' => $plan['open_work_order_id'] !== null ? (int) $plan['open_work_order_id'] : null,
                ] + MeterForecast::project($m['current'], (int) $plan[$m['due']], $interval, $m['rate'], $today);
            }
        }
        usort($projected, static fn (array $a, array $b) => self::planUrgency($a) <=> self::planUrgency($b));

        // Compteur de référence de l'engin : celui de l'échéance la plus proche, sinon celui qui est relevé.
        $metric = $projected[0]['metric'] ?? ($hours === null && $km !== null ? 'odometer_km' : 'hour_meter');

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
            'metric' => $metric,
            'unit' => $meters[$metric]['unit'],
            'current' => $meters[$metric]['current'],
            'rate' => $meters[$metric]['rate'] !== null ? round($meters[$metric]['rate'], 1) : null,
            'status' => $projected[0]['status'] ?? 'none',
            'plans' => $projected,
        ];
    }

    /**
     * Tri des échéances d'un engin : statut, puis délai prévu, puis part de l'intervalle consommée
     * (comparables entre heures et km, contrairement au reste).
     */
    private static function planUrgency(array $plan): array
    {
        return [self::STATUS_RANK[$plan['status']], $plan['days_left'] ?? PHP_INT_MAX, -($plan['pct'] ?? 0)];
    }

    /** Tri du parc : engin le plus urgent d'abord. */
    private static function urgency(array $row): array
    {
        return isset($row['plans'][0]) ? self::planUrgency($row['plans'][0]) : [self::STATUS_RANK['none'], PHP_INT_MAX, 0];
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
