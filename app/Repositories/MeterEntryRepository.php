<?php

declare(strict_types=1);

namespace App\Repositories;

/** Feuille de saisie des index : engins du périmètre avec leur dernier relevé, et correction d'un relevé. */
final class MeterEntryRepository extends Repository
{
    /**
     * Engins actifs avec leur dernier relevé d'index (heures ou km) et son auteur.
     *
     * @return list<array<string, mixed>>
     */
    public function sheet(?int $siteId = null, ?int $equipmentId = null): array
    {
        [$scopeSql, $params] = $this->scope->clause('e.site_id');
        $extraSql = '';
        if ($siteId !== null) {
            $extraSql .= ' AND e.site_id = :site';
            $params['site'] = $siteId;
        }
        if ($equipmentId !== null) {
            $extraSql .= ' AND e.id = :equipment';
            $params['equipment'] = $equipmentId;
        }

        return $this->db->fetchAll(
            "SELECT e.id, e.code, e.name, e.brand, e.model, e.status, e.hour_meter, e.odometer_km, e.fuel_level, s.name AS site,
                    lr.id AS last_id, lr.recorded_at AS last_at, lr.recorded_by AS last_by, lr.hour_meter AS last_hours,
                    lr.odometer_km AS last_km, CONCAT(u.first_name, ' ', u.last_name) AS last_by_name
               FROM equipment e
               JOIN sites s ON s.id = e.site_id
               LEFT JOIN meter_readings lr ON lr.id = (
                    SELECT r.id FROM meter_readings r
                     WHERE r.equipment_id = e.id AND (r.hour_meter IS NOT NULL OR r.odometer_km IS NOT NULL)
                     ORDER BY r.recorded_at DESC, r.id DESC LIMIT 1)
               LEFT JOIN users u ON u.id = lr.recorded_by
              WHERE $scopeSql AND e.is_archived = 0 $extraSql
              ORDER BY e.code",
            $params
        );
    }

    /**
     * Rapport d'une journée : chaque engin avec son dernier relevé d'index de ce jour-là (s'il y en a un),
     * le nombre de relevés du jour et les index connus juste avant cette journée.
     *
     * @return list<array<string, mixed>>
     */
    public function report(string $date, ?int $siteId = null): array
    {
        [$scopeSql, $params] = $this->scope->clause('e.site_id');
        $params += ['from' => $date . ' 00:00:00', 'from2' => $date . ' 00:00:00', 'from3' => $date . ' 00:00:00', 'from4' => $date . ' 00:00:00',
            'to' => $date . ' 23:59:59', 'to2' => $date . ' 23:59:59'];
        $siteSql = '';
        if ($siteId !== null) {
            $siteSql = ' AND e.site_id = :site';
            $params['site'] = $siteId;
        }
        $isIndex = '(r.hour_meter IS NOT NULL OR r.odometer_km IS NOT NULL)';

        return $this->db->fetchAll(
            "SELECT e.id, e.code, e.name, e.brand, e.model, s.name AS site,
                    d.recorded_at, d.recorded_by, d.hour_meter, d.odometer_km, d.fuel_level, d.note,
                    CONCAT(u.first_name, ' ', u.last_name) AS recorded_by_name,
                    (SELECT COUNT(*) FROM meter_readings r WHERE r.equipment_id = e.id AND $isIndex
                        AND r.recorded_at BETWEEN :from AND :to) AS readings,
                    (SELECT r.hour_meter FROM meter_readings r WHERE r.equipment_id = e.id AND r.hour_meter IS NOT NULL
                        AND r.recorded_at < :from2 ORDER BY r.recorded_at DESC, r.id DESC LIMIT 1) AS prev_hours,
                    (SELECT r.odometer_km FROM meter_readings r WHERE r.equipment_id = e.id AND r.odometer_km IS NOT NULL
                        AND r.recorded_at < :from3 ORDER BY r.recorded_at DESC, r.id DESC LIMIT 1) AS prev_km
               FROM equipment e
               JOIN sites s ON s.id = e.site_id
               LEFT JOIN meter_readings d ON d.id = (
                    SELECT r.id FROM meter_readings r
                     WHERE r.equipment_id = e.id AND $isIndex AND r.recorded_at BETWEEN :from4 AND :to2
                     ORDER BY r.recorded_at DESC, r.id DESC LIMIT 1)
               LEFT JOIN users u ON u.id = d.recorded_by
              WHERE $scopeSql AND e.is_archived = 0 $siteSql
              ORDER BY e.code",
            $params
        );
    }

    /**
     * Les deux derniers relevés d'index d'un engin, du plus récent au plus ancien.
     *
     * @return list<array<string, mixed>>
     */
    public function lastTwo(int $equipmentId): array
    {
        return $this->db->fetchAll(
            'SELECT id, hour_meter, odometer_km, fuel_level, recorded_by, recorded_at FROM meter_readings
              WHERE equipment_id = :e AND (hour_meter IS NOT NULL OR odometer_km IS NOT NULL)
              ORDER BY recorded_at DESC, id DESC LIMIT 2',
            ['e' => $equipmentId]
        );
    }

    public function updateReading(int $id, ?int $hours, ?int $km, ?int $fuel, ?string $note): void
    {
        $this->db->execute(
            'UPDATE meter_readings SET hour_meter = :h, odometer_km = :k, fuel_level = COALESCE(:f, fuel_level),
                    note = COALESCE(:n, note), recorded_at = NOW()
              WHERE id = :id',
            ['h' => $hours, 'k' => $km, 'f' => $fuel, 'n' => $note, 'id' => $id]
        );
    }

    /** Fixe les compteurs d'un engin après correction d'un relevé (peut les abaisser, contrairement à un relevé normal). */
    public function setMeters(int $equipmentId, ?int $hours, ?int $km, ?int $fuel): void
    {
        [$scopeSql, $params] = $this->scope->clause('site_id');
        $this->db->execute(
            "UPDATE equipment SET hour_meter = :h, odometer_km = :k, fuel_level = COALESCE(:f, fuel_level), meter_updated_at = NOW()
              WHERE id = :id AND $scopeSql",
            ['h' => $hours, 'k' => $km, 'f' => $fuel, 'id' => $equipmentId] + $params
        );
    }
}
