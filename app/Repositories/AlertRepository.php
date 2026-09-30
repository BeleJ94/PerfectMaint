<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Détection des dérives qui pèsent sur le coût opérationnel :
 * - usure prématurée : intervalle entre deux remplacements d'une même pièce sur un même engin
 *   inférieur à une fraction de la durée de vie attendue (heures ou km) ;
 * - surconsommation de lubrifiant : litres / 100 h d'un engin supérieurs au multiple de la moyenne
 *   des engins du même modèle (même lubrifiant, même période).
 */
final class AlertRepository extends Repository
{
    /** Remplacement jugé prématuré en dessous de 70 % de la durée de vie attendue. */
    public const WEAR_RATIO = 0.7;

    /** Surconsommation au-delà de 1,5 × la moyenne du modèle. */
    public const OVERUSE_FACTOR = 1.5;

    public function prematureWear(?int $siteId = null, int $days = 365): array
    {
        [$scopeSql, $params] = $this->scope->clause('w.site_id');
        $siteSql = '';
        if ($siteId !== null) {
            $siteSql = ' AND w.site_id = :site';
            $params['site'] = $siteId;
        }

        return $this->db->fetchAll(
            "SELECT r.*, p.reference, p.name AS part_name, p.part_type, p.expected_life_hours, p.expected_life_km,
                    e.code AS equipment_code, e.name AS equipment_name, e.brand, e.model, s.name AS site,
                    r.hour_meter - r.prev_hours AS interval_hours, r.odometer_km - r.prev_km AS interval_km,
                    ROUND(LEAST(
                        COALESCE((r.hour_meter - r.prev_hours) / NULLIF(p.expected_life_hours, 0), 9),
                        COALESCE((r.odometer_km - r.prev_km) / NULLIF(p.expected_life_km, 0), 9)
                    ) * 100) AS life_pct
               FROM (
                    SELECT wp.part_id, w.equipment_id, w.site_id, w.id AS work_order_id, w.reference AS work_order_ref,
                           w.failure_cause, wp.hour_meter, wp.odometer_km, wp.created_at,
                           LAG(wp.hour_meter) OVER (PARTITION BY w.equipment_id, wp.part_id ORDER BY wp.created_at, wp.id) AS prev_hours,
                           LAG(wp.odometer_km) OVER (PARTITION BY w.equipment_id, wp.part_id ORDER BY wp.created_at, wp.id) AS prev_km,
                           LAG(wp.created_at) OVER (PARTITION BY w.equipment_id, wp.part_id ORDER BY wp.created_at, wp.id) AS prev_at
                      FROM work_order_parts wp
                      JOIN work_orders w ON w.id = wp.work_order_id
                     WHERE $scopeSql $siteSql
               ) r
               JOIN parts p ON p.id = r.part_id
               JOIN equipment e ON e.id = r.equipment_id
               JOIN sites s ON s.id = r.site_id
              WHERE r.created_at >= NOW() - INTERVAL :days DAY
                AND (
                    (p.expected_life_hours IS NOT NULL AND r.prev_hours IS NOT NULL AND r.hour_meter >= r.prev_hours
                        AND r.hour_meter - r.prev_hours < p.expected_life_hours * :ratio_h)
                    OR (p.expected_life_km IS NOT NULL AND r.prev_km IS NOT NULL AND r.odometer_km >= r.prev_km
                        AND r.odometer_km - r.prev_km < p.expected_life_km * :ratio_k)
                )
              ORDER BY life_pct ASC, r.created_at DESC",
            ['days' => $days, 'ratio_h' => self::WEAR_RATIO, 'ratio_k' => self::WEAR_RATIO] + $params
        );
    }

    /**
     * Consommation de lubrifiant (appoints) par engin comparée à la moyenne des engins du même modèle.
     *
     * @return list<array<string, mixed>> engins au-delà du seuil, les plus forts écarts d'abord
     */
    public function lubricantOveruse(?int $siteId = null, int $days = 90): array
    {
        [$scopeSql, $params] = $this->scope->clause('f.site_id');
        $rows = $this->db->fetchAll(
            "SELECT f.equipment_id, f.part_id, e.model_id, e.site_id, e.code AS equipment_code, e.brand, e.model,
                    s.name AS site, p.reference, p.name AS part_name, p.unit,
                    SUM(f.quantity) AS quantity, MAX(f.hour_meter) - MIN(f.hour_meter) AS hours, COUNT(*) AS logs
               FROM fluid_logs f
               JOIN equipment e ON e.id = f.equipment_id AND e.is_archived = 0
               JOIN parts p ON p.id = f.part_id AND p.part_type = 'lubricant'
               JOIN sites s ON s.id = f.site_id
              WHERE f.kind = 'top_up' AND f.logged_at >= NOW() - INTERVAL :days DAY AND e.model_id IS NOT NULL AND $scopeSql
              GROUP BY f.equipment_id, f.part_id
             HAVING hours > 0",
            ['days' => $days] + $params
        );
        $groups = [];
        foreach ($rows as &$row) {
            $row['rate'] = round((float) $row['quantity'] / (int) $row['hours'] * 100, 2);
            $groups[$row['model_id'] . ':' . $row['part_id']][] = $row['rate'];
        }
        unset($row);

        $alerts = [];
        foreach ($rows as $row) {
            $peers = $groups[$row['model_id'] . ':' . $row['part_id']];
            if (count($peers) < 2 || ($siteId !== null && (int) $row['site_id'] !== $siteId)) {
                continue;
            }
            $average = array_sum($peers) / count($peers);
            if ($average > 0 && $row['rate'] > $average * self::OVERUSE_FACTOR) {
                $row['fleet_rate'] = round($average, 2);
                $row['excess_pct'] = (int) round(($row['rate'] / $average - 1) * 100);
                $row['peers'] = count($peers);
                $alerts[] = $row;
            }
        }
        usort($alerts, static fn (array $a, array $b) => $b['excess_pct'] <=> $a['excess_pct']);

        return $alerts;
    }
}
