<?php

declare(strict_types=1);

namespace App\Repositories;

/** Appoints et vidanges de fluides (lubrifiants, carburant) par engin. */
final class FluidRepository extends Repository
{
    public function add(array $data): int
    {
        $this->scope->assertAllows((int) $data['site_id']);

        return $this->db->insert(
            'INSERT INTO fluid_logs (equipment_id, site_id, part_id, kind, quantity, hour_meter, odometer_km, note, logged_by)
             VALUES (:equipment_id, :site_id, :part_id, :kind, :quantity, :hour_meter, :odometer_km, :note, :logged_by)',
            $data
        );
    }

    public function forEquipment(int $equipmentId, int $limit = 12): array
    {
        [$scopeSql, $params] = $this->scope->clause('f.site_id');

        return $this->db->fetchAll(
            "SELECT f.id, f.kind, f.quantity, f.hour_meter, f.odometer_km, f.note, f.logged_at,
                    p.reference, p.name, p.unit, CONCAT(u.first_name, ' ', u.last_name) AS logged_by
               FROM fluid_logs f
               JOIN parts p ON p.id = f.part_id
               LEFT JOIN users u ON u.id = f.logged_by
              WHERE f.equipment_id = :id AND $scopeSql
              ORDER BY f.logged_at DESC, f.id DESC LIMIT " . max(1, $limit),
            ['id' => $equipmentId] + $params
        );
    }

    /**
     * Consommation d'un fluide sur un engin entre deux relevés horaires : litres / 100 h.
     * Calculée sur les appoints (hors vidanges) des $days derniers jours.
     *
     * @return list<array{part_id: int, reference: string, name: string, unit: string, quantity: float, hours: int|null, rate: float|null}>
     */
    public function ratesForEquipment(int $equipmentId, int $days = 90): array
    {
        [$scopeSql, $params] = $this->scope->clause('f.site_id');
        $rows = $this->db->fetchAll(
            "SELECT f.part_id, p.reference, p.name, p.unit, SUM(f.quantity) AS quantity,
                    MAX(f.hour_meter) - MIN(f.hour_meter) AS hours, COUNT(*) AS n
               FROM fluid_logs f JOIN parts p ON p.id = f.part_id
              WHERE f.equipment_id = :id AND f.kind = 'top_up' AND f.logged_at >= NOW() - INTERVAL :days DAY AND $scopeSql
              GROUP BY f.part_id ORDER BY quantity DESC",
            ['id' => $equipmentId, 'days' => $days] + $params
        );
        foreach ($rows as &$row) {
            $hours = $row['hours'] !== null ? (int) $row['hours'] : null;
            $row['rate'] = $hours !== null && $hours > 0 ? round((float) $row['quantity'] / $hours * 100, 1) : null;
        }

        return $rows;
    }
}
