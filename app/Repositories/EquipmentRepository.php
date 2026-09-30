<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DataTableRequest;

final class EquipmentRepository extends Repository
{
    public const SORTABLE = [
        'code' => 'e.code',
        'name' => 'e.name',
        'category' => 'c.name',
        'site' => 's.name',
        'status' => 'e.status',
        'hour_meter' => 'e.hour_meter',
        'status_changed_at' => 'e.status_changed_at',
    ];

    private const COLUMNS = 'e.id, e.site_id, e.category_id, e.brand_id, e.model_id, e.code, e.name, e.brand, e.model, e.serial_number,
        e.chassis_number, e.plate_number, e.commissioned_at, e.hour_meter, e.odometer_km, e.fuel_level, e.meter_updated_at,
        e.ownership, e.contract_ref, e.contract_party, e.contract_start, e.contract_end, e.assignment, e.status, e.status_changed_at, e.notes, e.is_archived,
        e.created_at, e.updated_at, s.name AS site, s.code AS site_code, s.activity AS site_activity, c.name AS category, c.family AS category_family';

    private const FROM = 'equipment e
        JOIN sites s ON s.id = e.site_id
        LEFT JOIN equipment_categories c ON c.id = e.category_id';

    public function datatable(DataTableRequest $dt, array $filters): array
    {
        [$scopeSql, $params] = $this->scope->clause('e.site_id');
        $where = [];
        $fp = [];
        if (!empty($filters['site_id'])) {
            $where[] = 'e.site_id = :f_site';
            $fp['f_site'] = (int) $filters['site_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'e.status = :f_status';
            $fp['f_status'] = (string) $filters['status'];
        }
        if (!empty($filters['contract_days'])) {
            $where[] = "e.ownership <> 'owned' AND e.contract_end IS NOT NULL AND e.contract_end <= CURDATE() + INTERVAL :f_cdays DAY";
            $fp['f_cdays'] = (int) $filters['contract_days'];
        }
        if (!empty($filters['category_id'])) {
            $where[] = 'e.category_id = :f_cat';
            $fp['f_cat'] = (int) $filters['category_id'];
        }

        return $this->paginate(
            $dt,
            'e.id, e.code, e.name, e.brand, e.model, e.plate_number, e.status, e.status_changed_at, e.hour_meter, e.odometer_km,
             s.name AS site, c.name AS category,
             (SELECT COUNT(*) FROM work_orders w WHERE w.equipment_id = e.id
                 AND w.status IN (\'open\', \'in_progress\', \'waiting_part\')) AS open_work_orders',
            self::FROM,
            [$scopeSql, 'e.is_archived = 0'],
            $params,
            $where,
            $fp,
            ['e.code', 'e.name', 'e.brand', 'e.model', 'e.serial_number', 'e.chassis_number', 'e.plate_number'],
        );
    }

    public function find(int $id): ?array
    {
        [$scopeSql, $params] = $this->scope->clause('e.site_id');

        return $this->db->fetchOne(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . " WHERE e.id = :id AND $scopeSql",
            ['id' => $id] + $params
        );
    }

    /** Verrouille la ligne pour une mise à jour cohérente (à appeler dans une transaction). */
    public function findForUpdate(int $id): ?array
    {
        [$scopeSql, $params] = $this->scope->clause('e.site_id');

        return $this->db->fetchOne(
            "SELECT e.* FROM equipment e WHERE e.id = :id AND $scopeSql FOR UPDATE",
            ['id' => $id] + $params
        );
    }

    /** Recherche rapide (sélecteurs, écran terrain). */
    public function search(string $term, ?int $siteId = null, int $limit = 20): array
    {
        [$scopeSql, $params] = $this->scope->clause('e.site_id');
        [$searchSql, $searchParams] = $this->searchClause(['e.code', 'e.name', 'e.serial_number', 'e.plate_number', 'e.chassis_number'], $term);
        $siteSql = '';
        if ($siteId !== null) {
            $siteSql = ' AND e.site_id = :site';
            $params['site'] = $siteId;
        }

        return $this->db->fetchAll(
            "SELECT e.id, e.code, e.name, e.status, e.site_id, e.hour_meter, s.name AS site
               FROM equipment e JOIN sites s ON s.id = e.site_id
              WHERE $scopeSql AND e.is_archived = 0 AND $searchSql $siteSql
              ORDER BY e.code LIMIT " . max(1, min(50, $limit)),
            $params + $searchParams
        );
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT 1 FROM equipment WHERE code = :code AND id <> :id',
            ['code' => $code, 'id' => $exceptId ?? 0]
        );
    }

    public function create(array $data): int
    {
        $this->scope->assertAllows((int) $data['site_id']);

        return $this->db->insert(
            'INSERT INTO equipment (site_id, category_id, brand_id, model_id, code, name, brand, model, serial_number, chassis_number,
                                    plate_number, commissioned_at, hour_meter, odometer_km, ownership, contract_ref, contract_party,
                                    contract_start, contract_end, assignment, status, notes)
             VALUES (:site_id, :category_id, :brand_id, :model_id, :code, :name, :brand, :model, :serial_number, :chassis_number,
                     :plate_number, :commissioned_at, :hour_meter, :odometer_km, :ownership, :contract_ref, :contract_party,
                     :contract_start, :contract_end, :assignment, :status, :notes)',
            self::params($data) + ['status' => $data['status'] ?? 'available', 'odometer_km' => $data['odometer_km'] ?? null]
        );
    }

    public function update(int $id, array $data): void
    {
        $this->scope->assertAllows((int) $data['site_id']);
        [$scopeSql, $params] = $this->scope->clause('site_id');
        $this->db->execute(
            "UPDATE equipment SET site_id = :site_id, category_id = :category_id, brand_id = :brand_id, model_id = :model_id, code = :code, name = :name,
                    brand = :brand, model = :model, serial_number = :serial_number, chassis_number = :chassis_number,
                    plate_number = :plate_number, commissioned_at = :commissioned_at, hour_meter = :hour_meter,
                    ownership = :ownership, contract_ref = :contract_ref, contract_party = :contract_party,
                    contract_start = :contract_start, contract_end = :contract_end, assignment = :assignment, notes = :notes
              WHERE id = :id AND $scopeSql",
            self::params($data) + ['id' => $id] + $params
        );
    }

    public function updateStatus(int $id, string $status): void
    {
        [$scopeSql, $params] = $this->scope->clause('site_id');
        $this->db->execute(
            "UPDATE equipment SET status = :status, status_changed_at = NOW() WHERE id = :id AND $scopeSql",
            ['status' => $status, 'id' => $id] + $params
        );
    }

    /** Le compteur ne peut qu'augmenter. */
    public function raiseHourMeter(int $id, int $hours): void
    {
        [$scopeSql, $params] = $this->scope->clause('site_id');
        $this->db->execute(
            "UPDATE equipment SET hour_meter = :hours
              WHERE id = :id AND $scopeSql AND (hour_meter IS NULL OR hour_meter < :hours_cmp)",
            ['hours' => $hours, 'hours_cmp' => $hours, 'id' => $id] + $params
        );
    }

    /** Le kilométrage ne peut qu'augmenter. */
    public function raiseOdometer(int $id, int $km): void
    {
        [$scopeSql, $params] = $this->scope->clause('site_id');
        $this->db->execute(
            "UPDATE equipment SET odometer_km = :km
              WHERE id = :id AND $scopeSql AND (odometer_km IS NULL OR odometer_km < :km_cmp)",
            ['km' => $km, 'km_cmp' => $km, 'id' => $id] + $params
        );
    }

    public function setArchived(int $id, bool $archived): void
    {
        [$scopeSql, $params] = $this->scope->clause('site_id');
        $this->db->execute(
            "UPDATE equipment SET is_archived = :a WHERE id = :id AND $scopeSql",
            ['a' => $archived ? 1 : 0, 'id' => $id] + $params
        );
    }

    public function addStatusHistory(int $equipmentId, ?string $from, string $to, ?string $reason, ?int $workOrderId, ?int $userId): void
    {
        $this->db->execute(
            'INSERT INTO equipment_status_history (equipment_id, from_status, to_status, reason, work_order_id, changed_by)
             VALUES (:equipment, :from, :to, :reason, :wo, :user)',
            ['equipment' => $equipmentId, 'from' => $from, 'to' => $to, 'reason' => $reason, 'wo' => $workOrderId, 'user' => $userId]
        );
    }

    public function statusHistory(int $equipmentId, int $limit = 20): array
    {
        [$scopeSql, $params] = $this->scope->clause('e.site_id');

        return $this->db->fetchAll(
            "SELECT h.from_status, h.to_status, h.reason, h.changed_at, h.work_order_id,
                    CONCAT(u.first_name, ' ', u.last_name) AS changed_by
               FROM equipment_status_history h
               JOIN equipment e ON e.id = h.equipment_id
               LEFT JOIN users u ON u.id = h.changed_by
              WHERE h.equipment_id = :id AND $scopeSql
              ORDER BY h.changed_at DESC, h.id DESC
              LIMIT " . max(1, $limit),
            ['id' => $equipmentId] + $params
        );
    }

    /** @return array<string, int> statut => nombre */
    public function countByStatus(?int $siteId = null): array
    {
        [$scopeSql, $params] = $this->scope->clause('site_id');
        $siteSql = '';
        if ($siteId !== null) {
            $siteSql = ' AND site_id = :site';
            $params['site'] = $siteId;
        }
        $rows = $this->db->fetchAll(
            "SELECT status, COUNT(*) AS n FROM equipment WHERE $scopeSql AND is_archived = 0 $siteSql GROUP BY status",
            $params
        );

        return array_map('intval', array_column($rows, 'n', 'status'));
    }

    /** Compteurs courants de l'engin après un relevé (valeurs null = inchangées). */
    public function applyMeters(int $id, ?int $hours, ?int $km, ?int $fuel): void
    {
        [$scopeSql, $params] = $this->scope->clause('site_id');
        $this->db->execute(
            "UPDATE equipment SET hour_meter = COALESCE(:h, hour_meter), odometer_km = COALESCE(:k, odometer_km),
                    fuel_level = COALESCE(:f, fuel_level), meter_updated_at = NOW()
              WHERE id = :id AND $scopeSql",
            ['h' => $hours, 'k' => $km, 'f' => $fuel, 'id' => $id] + $params
        );
    }

    public function addReading(int $equipmentId, ?int $hours, ?int $km, ?int $fuel, ?string $note, ?int $userId): int
    {
        return $this->db->insert(
            'INSERT INTO meter_readings (equipment_id, hour_meter, odometer_km, fuel_level, note, recorded_by)
             VALUES (:e, :h, :k, :f, :n, :u)',
            ['e' => $equipmentId, 'h' => $hours, 'k' => $km, 'f' => $fuel, 'n' => $note, 'u' => $userId]
        );
    }

    public function readings(int $equipmentId, int $limit = 15): array
    {
        [$scopeSql, $params] = $this->scope->clause('e.site_id');

        return $this->db->fetchAll(
            "SELECT r.id, r.hour_meter, r.odometer_km, r.fuel_level, r.note, r.recorded_at,
                    CONCAT(u.first_name, ' ', u.last_name) AS recorded_by
               FROM meter_readings r
               JOIN equipment e ON e.id = r.equipment_id
               LEFT JOIN users u ON u.id = r.recorded_by
              WHERE r.equipment_id = :id AND $scopeSql
              ORDER BY r.recorded_at DESC, r.id DESC LIMIT " . max(1, $limit),
            ['id' => $equipmentId] + $params
        );
    }

    /** Engins sous PO / contrat / location dont l'échéance tombe dans les $days jours (ou est dépassée). */
    public function contractsEnding(int $days, ?int $siteId = null): array
    {
        [$scopeSql, $params] = $this->scope->clause('e.site_id');
        $siteSql = '';
        if ($siteId !== null) {
            $siteSql = ' AND e.site_id = :site';
            $params['site'] = $siteId;
        }

        return $this->db->fetchAll(
            "SELECT e.id, e.code, e.name, e.ownership, e.contract_ref, e.contract_party, e.contract_end,
                    DATEDIFF(e.contract_end, CURDATE()) AS days_left, s.name AS site
               FROM equipment e JOIN sites s ON s.id = e.site_id
              WHERE $scopeSql AND e.is_archived = 0 AND e.ownership <> 'owned' AND e.contract_end IS NOT NULL
                AND e.contract_end <= CURDATE() + INTERVAL :days DAY $siteSql
              ORDER BY e.contract_end",
            ['days' => $days] + $params
        );
    }

    /** Catégories actives (+ $includeId pour conserver celle d'un engin existant). */
    public function categories(?int $includeId = null): array
    {
        return $this->db->fetchAll(
            'SELECT id, code, name, family FROM equipment_categories WHERE is_active = 1 OR id = :inc ORDER BY family, name',
            ['inc' => $includeId ?? 0]
        );
    }

    /** Parc d'un site pour sa fenêtre dédiée : engins actifs, catégorie, OT ouverts. */
    public function fleetForSite(int $siteId): array
    {
        [$scopeSql, $params] = $this->scope->clause('e.site_id');

        return $this->db->fetchAll(
            "SELECT e.id, e.code, e.name, e.brand, e.model, e.plate_number, e.status, e.status_changed_at, e.hour_meter,
                    e.odometer_km, e.fuel_level, e.ownership, e.contract_end, c.name AS category,
                    (SELECT p.id FROM equipment_photos p WHERE p.equipment_id = e.id ORDER BY p.is_primary DESC, p.id DESC LIMIT 1) AS photo_id, COALESCE(c.family, 'other') AS family,
                    (SELECT COUNT(*) FROM work_orders w WHERE w.equipment_id = e.id
                        AND w.status IN ('open', 'in_progress', 'waiting_part')) AS open_work_orders
               FROM equipment e
               LEFT JOIN equipment_categories c ON c.id = e.category_id
              WHERE e.site_id = :site AND e.is_archived = 0 AND $scopeSql
              ORDER BY c.name IS NULL, c.name, e.code",
            ['site' => $siteId] + $params
        );
    }

    private static function params(array $data): array
    {
        return [
            'site_id' => (int) $data['site_id'],
            'category_id' => $data['category_id'] ?: null,
            'brand_id' => $data['brand_id'] ?? null,
            'model_id' => $data['model_id'] ?? null,
            'code' => $data['code'],
            'name' => $data['name'],
            'brand' => $data['brand'] ?? null,
            'model' => $data['model'] ?? null,
            'serial_number' => $data['serial_number'] ?? null,
            'chassis_number' => $data['chassis_number'] ?? null,
            'plate_number' => $data['plate_number'] ?? null,
            'ownership' => $data['ownership'] ?? 'owned',
            'contract_ref' => $data['contract_ref'] ?? null,
            'contract_party' => $data['contract_party'] ?? null,
            'contract_start' => $data['contract_start'] ?? null,
            'contract_end' => $data['contract_end'] ?? null,
            'assignment' => $data['assignment'] ?? null,
            'commissioned_at' => $data['commissioned_at'] ?? null,
            'hour_meter' => $data['hour_meter'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }
}
