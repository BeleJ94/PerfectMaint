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

    /** Situation non établie : index absent ou périmé, rythme inconnu, point de départ déduit, plan dans le mauvais compteur. */
    public const CHECK = 'check';

    private const STATUS_RANK = [MeterForecast::DUE => 0, MeterForecast::SOON => 1, self::CHECK => 2, MeterForecast::OK => 3, 'none' => 4];

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
        $summary = ['equipment' => 0, 'due' => 0, 'soon' => 0, 'check' => 0, 'ok' => 0, 'read_today' => 0, 'stale' => 0];
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
            'hour_meter' => ['unit' => 'h', 'current' => $hours, 'rate' => $rateHours, 'interval' => 'interval_hours', 'due' => 'next_due_hours', 'done' => 'last_done_hours'],
            'odometer_km' => ['unit' => 'km', 'current' => $km, 'rate' => $rateKm, 'interval' => 'interval_km', 'due' => 'next_due_km', 'done' => 'last_done_km'],
        ];
        // Compteur de suivi : celui de la catégorie, ou forcé sur l'engin (heures pour les engins, km pour les véhicules).
        $metric = ($e['meter_basis'] ?? 'hours') === 'km' ? 'odometer_km' : 'hour_meter';
        $stale = MeterForecast::isStale($daysSince);

        // Une échéance par plan, dans le compteur de l'engin ; un plan exprimé uniquement dans l'autre compteur est signalé.
        $projected = [];
        foreach ($plans as $plan) {
            $inEngineMeter = $plan[$meters[$metric]['due']] !== null;
            foreach ($meters as $planMetric => $m) {
                if ($plan[$m['due']] === null || ($inEngineMeter && $planMetric !== $metric)) {
                    continue;
                }
                $projected[] = self::projectPlan($plan, $planMetric, $m, $metric, $stale, $today);
            }
        }
        usort($projected, static fn (array $a, array $b) => self::planUrgency($a) <=> self::planUrgency($b));

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
            'stale' => $stale,
            'rate_hours' => $rateHours !== null ? round($rateHours, 1) : null,
            'rate_km' => $rateKm !== null ? round($rateKm, 1) : null,
            'metric' => $metric,
            'unit' => $meters[$metric]['unit'],
            'current' => $meters[$metric]['current'],
            'rate' => $meters[$metric]['rate'] !== null ? round($meters[$metric]['rate'], 1) : null,
            'status' => $projected[0]['status'] ?? 'none',
            'plans' => $projected,
            'last_reading' => self::lastReading($readings, $metric),
        ];
    }

    /**
     * Dernier relevé de la fenêtre dans le compteur de suivi : heure, valeur, auteur et écart avec le relevé précédent.
     * Sert au détail « Relevés du jour ».
     *
     * @param list<array<string, mixed>> $readings relevés triés par date croissante
     * @return array{at: string, value: int, by: string|null, delta: int|null, previous_at: string|null}|null
     */
    private static function lastReading(array $readings, string $metric): ?array
    {
        $points = array_values(array_filter($readings, static fn (array $r) => $r[$metric] !== null));
        $count = count($points);
        if ($count === 0) {
            return null;
        }
        $last = $points[$count - 1];
        $previous = $points[$count - 2] ?? null;

        return [
            'at' => $last['recorded_at'],
            'value' => (int) $last[$metric],
            'by' => $last['recorded_by_name'] ?? null,
            'delta' => $previous !== null ? (int) $last[$metric] - (int) $previous[$metric] : null,
            'previous_at' => $previous['recorded_at'] ?? null,
        ];
    }

    /**
     * Échéance d'un plan dans un compteur, avec les deux bornes de l'avancement :
     * départ (index à la dernière réalisation) et seuil (prochaine échéance).
     * « À vérifier » remplace « dans les temps » tant que la situation n'est pas établie ;
     * un plan exprimé dans un autre compteur que celui de l'engin, ou sans index, n'est jamais calculé.
     *
     * @param array{unit: string, current: int|null, rate: float|null, interval: string, due: string, done: string} $m
     */
    private static function projectPlan(array $plan, string $planMetric, array $m, string $engineMetric, bool $stale, DateTimeImmutable $today): array
    {
        $interval = $plan[$m['interval']] !== null ? (int) $plan[$m['interval']] : null;
        $nextDue = (int) $plan[$m['due']];
        $confirmed = $plan[$m['done']] !== null;
        $entry = [
            'id' => (int) $plan['id'],
            'title' => $plan['title'],
            'metric' => $planMetric,
            'unit' => $m['unit'],
            'interval' => $interval,
            'next_due' => $nextDue,
            'start' => $confirmed ? (int) $plan[$m['done']] : ($interval !== null ? max(0, $nextDue - $interval) : null),
            'start_confirmed' => $confirmed,
            'last_done_at' => $plan['last_done_at'],
            'current' => $m['current'],
            'open_work_order_id' => $plan['open_work_order_id'] !== null ? (int) $plan['open_work_order_id'] : null,
        ];

        $blocking = match (true) {
            $planMetric !== $engineMetric => 'mismatch',
            $m['current'] === null => 'no_index',
            default => null,
        };
        if ($blocking !== null) {
            return $entry + ['remaining' => null, 'pct' => null, 'days_left' => null, 'due_on' => null, 'status' => self::CHECK, 'issue' => $blocking];
        }

        $entry += MeterForecast::project($m['current'], $nextDue, $interval, $m['rate'], $today);
        $issue = match (true) {
            !$confirmed => 'unconfirmed',
            $stale => 'stale',
            $m['rate'] === null && $entry['remaining'] > 0 => 'no_rate',
            default => null,
        };
        // Une alerte (échue / à préparer) reste affichée ; seul « dans les temps » demande une situation établie.
        if ($issue !== null && $entry['status'] === MeterForecast::OK) {
            $entry['status'] = self::CHECK;
        }

        return $entry + ['issue' => $issue];
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
