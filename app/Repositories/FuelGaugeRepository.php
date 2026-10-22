<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\FuelGauge;

/**
 * Types d'indicateur de carburant (référentiel commun à tous les sites) et réglage effectif d'un engin :
 * indicateur et capacité de l'engin s'ils sont renseignés, sinon ceux de son modèle.
 */
final class FuelGaugeRepository extends Repository
{
    /** Types d'indicateur, avec le nombre de modèles et d'engins qui les utilisent. */
    public function all(bool $activeOnly = false): array
    {
        return $this->db->fetchAll(
            'SELECT g.id, g.name, g.kind, g.marks, g.is_active,
                    (SELECT COUNT(*) FROM equipment_models m WHERE m.fuel_gauge_id = g.id) AS model_count,
                    (SELECT COUNT(*) FROM equipment e WHERE e.fuel_gauge_id = g.id AND e.is_archived = 0) AS equipment_count
               FROM fuel_gauges g' . ($activeOnly ? ' WHERE g.is_active = 1' : '') . '
              ORDER BY g.kind, g.marks, g.name'
        );
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT id, name, kind, marks, is_active FROM fuel_gauges WHERE id = :id', ['id' => $id]);
    }

    public function nameExists(string $name, ?int $exceptId = null): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT 1 FROM fuel_gauges WHERE name = :name AND id <> :id',
            ['name' => $name, 'id' => $exceptId ?? 0]
        );
    }

    public function create(string $name, string $kind, ?int $marks): int
    {
        return $this->db->insert(
            'INSERT INTO fuel_gauges (name, kind, marks) VALUES (:name, :kind, :marks)',
            ['name' => $name, 'kind' => $kind, 'marks' => $marks]
        );
    }

    public function update(int $id, string $name, string $kind, ?int $marks, bool $active): void
    {
        $this->db->execute(
            'UPDATE fuel_gauges SET name = :name, kind = :kind, marks = :marks, is_active = :a WHERE id = :id',
            ['name' => $name, 'kind' => $kind, 'marks' => $marks, 'a' => $active ? 1 : 0, 'id' => $id]
        );
    }

    public function setModelFuel(int $modelId, ?int $gaugeId, ?int $capacity): void
    {
        $this->db->execute(
            'UPDATE equipment_models SET fuel_gauge_id = :g, tank_capacity_l = :c WHERE id = :id',
            ['g' => $gaugeId, 'c' => $capacity, 'id' => $modelId]
        );
    }

    /**
     * Réglage effectif d'un engin (dans le périmètre de l'utilisateur).
     *
     * @return array{gauge_id: int|null, name: string|null, kind: string|null, marks: int|null, capacity: int|null, source: string}|null
     */
    public function forEquipment(int $equipmentId): ?array
    {
        [$scopeSql, $params] = $this->scope->clause('e.site_id');
        $row = $this->db->fetchOne(
            "SELECT g.id AS gauge_id, g.name, g.kind, g.marks,
                    COALESCE(e.tank_capacity_l, m.tank_capacity_l) AS capacity,
                    CASE WHEN e.fuel_gauge_id IS NOT NULL OR e.tank_capacity_l IS NOT NULL THEN 'equipment'
                         WHEN m.fuel_gauge_id IS NOT NULL OR m.tank_capacity_l IS NOT NULL THEN 'model' ELSE 'none' END AS source
               FROM equipment e
               LEFT JOIN equipment_models m ON m.id = e.model_id
               LEFT JOIN fuel_gauges g ON g.id = COALESCE(e.fuel_gauge_id, m.fuel_gauge_id)
              WHERE e.id = :id AND $scopeSql",
            ['id' => $equipmentId] + $params
        );
        if ($row === null) {
            return null;
        }

        return [
            'gauge_id' => $row['gauge_id'] !== null ? (int) $row['gauge_id'] : null,
            'name' => $row['name'],
            'kind' => $row['kind'],
            'marks' => $row['marks'] !== null ? (int) $row['marks'] : null,
            'capacity' => $row['capacity'] !== null ? (int) $row['capacity'] : null,
            'source' => $row['source'],
        ];
    }

    /** Objet de conversion pour un engin (indicateur non configuré : saisie en %). */
    public function gaugeFor(int $equipmentId): FuelGauge
    {
        $cfg = $this->forEquipment($equipmentId);
        if ($cfg === null || $cfg['kind'] === null) {
            return FuelGauge::unconfigured($cfg['capacity'] ?? null);
        }

        return new FuelGauge($cfg['kind'], $cfg['marks'], $cfg['capacity'], $cfg['name']);
    }

    /** Détail de la lecture sur un relevé : graduation saisie, nombre de graduations, litres. */
    public function saveReadingDetails(int $readingId, ?float $mark, ?int $marks, ?float $liters): void
    {
        $this->db->execute(
            'UPDATE meter_readings SET fuel_mark = :m, fuel_marks = :n, fuel_liters = :l WHERE id = :id',
            ['m' => $mark, 'n' => $marks, 'l' => $liters, 'id' => $readingId]
        );
    }

    public function saveEquipmentLiters(int $equipmentId, ?float $liters): void
    {
        [$scopeSql, $params] = $this->scope->clause('site_id');
        $this->db->execute(
            "UPDATE equipment SET fuel_liters = :l WHERE id = :id AND $scopeSql",
            ['l' => $liters, 'id' => $equipmentId] + $params
        );
    }
}
