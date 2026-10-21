<?php

declare(strict_types=1);

namespace App\Repositories;

/** Lectures pour la supervision des index : parc, plans au compteur et historique des relevés. */
final class MeterMonitorRepository extends Repository
{
    /** Engins actifs avec leurs index courants et la date du dernier relevé d'index (hors carburant seul). */
    public function fleet(?int $siteId = null): array
    {
        [$scopeSql, $params] = $this->scope->clause('e.site_id');
        $siteSql = '';
        if ($siteId !== null) {
            $siteSql = ' AND e.site_id = :site';
            $params['site'] = $siteId;
        }

        return $this->db->fetchAll(
            "SELECT e.id, e.code, e.name, e.brand, e.model, e.status, e.hour_meter, e.odometer_km, s.name AS site,
                    (SELECT MAX(r.recorded_at) FROM meter_readings r
                      WHERE r.equipment_id = e.id AND (r.hour_meter IS NOT NULL OR r.odometer_km IS NOT NULL)) AS last_reading_at
               FROM equipment e JOIN sites s ON s.id = e.site_id
              WHERE $scopeSql AND e.is_archived = 0 $siteSql
              ORDER BY e.code",
            $params
        );
    }

    /** Plans actifs à périodicité au compteur (heures et/ou km), avec l'OT préventif éventuellement déjà ouvert. */
    public function meterPlans(?int $siteId = null, ?int $equipmentId = null): array
    {
        [$scopeSql, $params] = $this->scope->clause('p.site_id');
        $extraSql = '';
        if ($siteId !== null) {
            $extraSql .= ' AND p.site_id = :site';
            $params['site'] = $siteId;
        }
        if ($equipmentId !== null) {
            $extraSql .= ' AND p.equipment_id = :equipment';
            $params['equipment'] = $equipmentId;
        }

        return $this->db->fetchAll(
            "SELECT p.id, p.equipment_id, p.title, p.interval_hours, p.next_due_hours, p.interval_km, p.next_due_km,
                    (SELECT w.id FROM work_orders w WHERE w.preventive_plan_id = p.id
                        AND w.status IN ('open', 'in_progress', 'waiting_part') LIMIT 1) AS open_work_order_id
               FROM preventive_plans p
               JOIN equipment e ON e.id = p.equipment_id AND e.is_archived = 0
              WHERE $scopeSql AND p.is_active = 1 AND (p.next_due_hours IS NOT NULL OR p.next_due_km IS NOT NULL) $extraSql
              ORDER BY p.id",
            $params
        );
    }

    /** Relevés d'index depuis $from (tous engins du périmètre, ou un seul), par date croissante. */
    public function readingsSince(string $from, ?int $siteId = null, ?int $equipmentId = null): array
    {
        [$scopeSql, $params] = $this->scope->clause('e.site_id');
        $params['from'] = $from;
        $extraSql = '';
        if ($siteId !== null) {
            $extraSql .= ' AND e.site_id = :site';
            $params['site'] = $siteId;
        }
        if ($equipmentId !== null) {
            $extraSql .= ' AND r.equipment_id = :equipment';
            $params['equipment'] = $equipmentId;
        }

        return $this->db->fetchAll(
            "SELECT r.id, r.equipment_id, r.recorded_at, r.hour_meter, r.odometer_km, r.fuel_level, r.note,
                    CONCAT(u.first_name, ' ', u.last_name) AS recorded_by_name
               FROM meter_readings r
               JOIN equipment e ON e.id = r.equipment_id AND e.is_archived = 0
               LEFT JOIN users u ON u.id = r.recorded_by
              WHERE $scopeSql AND r.recorded_at >= :from
                AND (r.hour_meter IS NOT NULL OR r.odometer_km IS NOT NULL) $extraSql
              ORDER BY r.recorded_at, r.id",
            $params
        );
    }
}
