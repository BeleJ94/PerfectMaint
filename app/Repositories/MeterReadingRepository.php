<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DataTableRequest;

/**
 * Relevés d'index (heures, km) pris individuellement : répertoire de l'administrateur, saisie d'un jour passé,
 * encadrement d'un relevé par ses voisins et resynchronisation des compteurs de l'engin.
 */
final class MeterReadingRepository extends Repository
{
    public const SORTABLE = [
        'recorded_at' => 'r.recorded_at',
        'code' => 'e.code',
        'site' => 's.name',
        'hour_meter' => 'r.hour_meter',
        'odometer_km' => 'r.odometer_km',
        'fuel_level' => 'r.fuel_level',
        'recorded_by_name' => 'u.last_name',
    ];

    private const INDEX = '(r.hour_meter IS NOT NULL OR r.odometer_km IS NOT NULL)';

    /** @param array{site_id?: ?int, equipment_id?: ?int, user_id?: ?int, from?: ?string, to?: ?string} $filters */
    public function datatable(DataTableRequest $dt, array $filters): array
    {
        [$scopeSql, $params] = $this->scope->clause('e.site_id');
        $where = [];
        $filterParams = [];
        if (!empty($filters['site_id'])) {
            $where[] = 'e.site_id = :site';
            $filterParams['site'] = $filters['site_id'];
        }
        if (!empty($filters['equipment_id'])) {
            $where[] = 'r.equipment_id = :equipment';
            $filterParams['equipment'] = $filters['equipment_id'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = 'r.recorded_by = :user';
            $filterParams['user'] = $filters['user_id'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'r.recorded_at >= :from';
            $filterParams['from'] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[] = 'r.recorded_at <= :to';
            $filterParams['to'] = $filters['to'] . ' 23:59:59';
        }

        return $this->paginate(
            $dt,
            "r.id, r.equipment_id, r.recorded_at, r.hour_meter, r.odometer_km, r.fuel_level, r.fuel_liters, r.fuel_mark, r.fuel_marks, r.note,
             e.code, e.site_id, e.hour_meter AS current_hours, e.odometer_km AS current_km, e.name AS equipment_name, s.name AS site, CONCAT(u.first_name, ' ', u.last_name) AS recorded_by_name",
            'meter_readings r JOIN equipment e ON e.id = r.equipment_id JOIN sites s ON s.id = e.site_id
             LEFT JOIN users u ON u.id = r.recorded_by',
            [$scopeSql, self::INDEX],
            $params,
            $where,
            $filterParams,
            ['e.code', 'e.name', 'r.note'],
        );
    }

    /** Auteurs de relevés dans le périmètre (filtre du répertoire). */
    public function recorders(): array
    {
        [$scopeSql, $params] = $this->scope->clause('e.site_id');

        return $this->db->fetchAll(
            "SELECT DISTINCT u.id, CONCAT(u.first_name, ' ', u.last_name) AS name
               FROM meter_readings r JOIN equipment e ON e.id = r.equipment_id JOIN users u ON u.id = r.recorded_by
              WHERE $scopeSql ORDER BY name",
            $params
        );
    }

    public function find(int $id): ?array
    {
        [$scopeSql, $params] = $this->scope->clause('e.site_id');

        return $this->db->fetchOne(
            "SELECT r.*, e.code, e.name AS equipment_name, e.site_id, s.name AS site,
                    CONCAT(u.first_name, ' ', u.last_name) AS recorded_by_name
               FROM meter_readings r
               JOIN equipment e ON e.id = r.equipment_id
               JOIN sites s ON s.id = e.site_id
               LEFT JOIN users u ON u.id = r.recorded_by
              WHERE r.id = :id AND " . self::INDEX . " AND $scopeSql",
            ['id' => $id] + $params
        );
    }

    /** Un relevé d'index existe-t-il déjà ce jour-là pour l'engin (hors relevé $exceptId) ? */
    public function existsOnDay(int $equipmentId, string $date, ?int $exceptId = null): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT COUNT(*) FROM meter_readings r
              WHERE r.equipment_id = :e AND ' . self::INDEX . ' AND r.recorded_at BETWEEN :from AND :to AND r.id <> :except',
            ['e' => $equipmentId, 'from' => $date . ' 00:00:00', 'to' => $date . ' 23:59:59', 'except' => $exceptId ?? 0]
        );
    }

    /**
     * Index connus juste avant et juste après un instant (hors relevé $exceptId) : un relevé inséré ou modifié
     * doit rester entre les deux, les compteurs ne reculant pas.
     *
     * @return array{prev_hours: ?int, prev_km: ?int, prev_at: ?string, next_hours: ?int, next_km: ?int}
     */
    public function bounds(int $equipmentId, string $at, ?int $exceptId = null): array
    {
        $one = function (string $column, string $op, string $dir) use ($equipmentId, $at, $exceptId): ?array {
            return $this->db->fetchOne(
                "SELECT $column AS value, recorded_at FROM meter_readings
                  WHERE equipment_id = :e AND $column IS NOT NULL AND recorded_at $op :at AND id <> :except
                  ORDER BY recorded_at $dir, id $dir LIMIT 1",
                ['e' => $equipmentId, 'at' => $at, 'except' => $exceptId ?? 0]
            );
        };
        $prevHours = $one('hour_meter', '<', 'DESC');
        $prevKm = $one('odometer_km', '<', 'DESC');
        $nextHours = $one('hour_meter', '>', 'ASC');
        $nextKm = $one('odometer_km', '>', 'ASC');
        $prevAt = max((string) ($prevHours['recorded_at'] ?? ''), (string) ($prevKm['recorded_at'] ?? ''));

        return [
            'prev_hours' => $prevHours !== null ? (int) $prevHours['value'] : null,
            'prev_km' => $prevKm !== null ? (int) $prevKm['value'] : null,
            'prev_at' => $prevAt !== '' ? $prevAt : null,
            'next_hours' => $nextHours !== null ? (int) $nextHours['value'] : null,
            'next_km' => $nextKm !== null ? (int) $nextKm['value'] : null,
        ];
    }

    public function insert(int $equipmentId, ?int $hours, ?int $km, ?int $fuel, ?string $note, ?int $userId, string $at): int
    {
        return $this->db->insert(
            'INSERT INTO meter_readings (equipment_id, hour_meter, odometer_km, fuel_level, note, recorded_by, recorded_at)
             VALUES (:e, :h, :k, :f, :n, :u, :at)',
            ['e' => $equipmentId, 'h' => $hours, 'k' => $km, 'f' => $fuel, 'n' => $note, 'u' => $userId, 'at' => $at]
        );
    }

    public function update(int $id, ?int $hours, ?int $km, ?int $fuel, ?string $note, string $at): void
    {
        $this->db->execute(
            'UPDATE meter_readings SET hour_meter = :h, odometer_km = :k, fuel_level = :f, note = :n, recorded_at = :at WHERE id = :id',
            ['h' => $hours, 'k' => $km, 'f' => $fuel, 'n' => $note, 'at' => $at, 'id' => $id]
        );
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM meter_readings WHERE id = :id', ['id' => $id]);
    }

    /** Aucun relevé d'index après cet instant : le relevé devient le plus récent de l'engin. */
    public function isLatest(int $equipmentId, string $at): bool
    {
        return !$this->db->fetchValue(
            'SELECT COUNT(*) FROM meter_readings r WHERE r.equipment_id = :e AND ' . self::INDEX . ' AND r.recorded_at > :at',
            ['e' => $equipmentId, 'at' => $at]
        );
    }

    /**
     * Recale les compteurs et le carburant de l'engin sur ses relevés les plus récents (après modification
     * ou suppression d'un relevé). Une valeur sans aucun relevé restant est conservée.
     */
    public function syncEquipment(int $equipmentId): void
    {
        [$scopeSql, $params] = $this->scope->clause('site_id');
        $latest = static fn (string $column): string => "(SELECT r.$column FROM meter_readings r
              WHERE r.equipment_id = equipment.id AND r.$column IS NOT NULL ORDER BY r.recorded_at DESC, r.id DESC LIMIT 1)";
        $this->db->execute(
            "UPDATE equipment
                SET hour_meter = COALESCE({$latest('hour_meter')}, hour_meter),
                    odometer_km = COALESCE({$latest('odometer_km')}, odometer_km),
                    fuel_level = COALESCE({$latest('fuel_level')}, fuel_level),
                    fuel_liters = CASE WHEN {$latest('fuel_level')} IS NULL THEN fuel_liters
                        ELSE (SELECT r.fuel_liters FROM meter_readings r WHERE r.equipment_id = equipment.id AND r.fuel_level IS NOT NULL
                               ORDER BY r.recorded_at DESC, r.id DESC LIMIT 1) END
              WHERE id = :id AND $scopeSql",
            ['id' => $equipmentId] + $params
        );
    }
}
