<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DataTableRequest;

/**
 * Catalogue de pièces (commun à tous les sites) et stocks par site (filtrés par périmètre).
 */
final class PartRepository extends Repository
{
    public const SORTABLE = [
        'reference' => 'p.reference',
        'name' => 'p.name',
        'unit_cost' => 'p.unit_cost',
        'part_type' => 'p.part_type',
        'manufacturer' => 'p.manufacturer',
        'quantity' => 'quantity',
    ];

    public function datatable(DataTableRequest $dt, array $filters): array
    {
        [$scopeSql, $params] = $this->scope->clause('ps.site_id');
        $siteSql = '';
        if (!empty($filters['site_id'])) {
            $siteSql = ' AND ps.site_id = :f_site';
            $params['f_site'] = (int) $filters['site_id'];
        }
        // Agrégat de stock restreint au périmètre (et au site filtré le cas échéant).
        $from = "parts p LEFT JOIN (
                    SELECT ps.part_id, SUM(ps.quantity) AS quantity, SUM(ps.min_quantity) AS min_quantity,
                           SUM(ps.quantity < ps.min_quantity) AS sites_below_min
                      FROM part_stocks ps WHERE $scopeSql $siteSql GROUP BY ps.part_id
                 ) st ON st.part_id = p.id";
        $where = [];
        $fp = [];
        if (($filters['low'] ?? '') === '1') {
            $where[] = 'st.sites_below_min > 0';
        }
        if (!empty($filters['part_type'])) {
            $where[] = 'p.part_type = :f_type';
            $fp['f_type'] = (string) $filters['part_type'];
        }
        if (($filters['wear'] ?? '') === '1') {
            $where[] = 'p.is_wear_part = 1';
        }
        if (!empty($filters['model_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM part_compatibility pc WHERE pc.part_id = p.id AND pc.model_id = :f_model)';
            $fp['f_model'] = (int) $filters['model_id'];
        }

        return $this->paginate(
            $dt,
            'p.id, p.reference, p.name, p.part_type, p.manufacturer, p.is_wear_part, p.unit, p.unit_cost, p.is_active,
             (SELECT GROUP_CONCAT(DISTINCT b.name ORDER BY b.name SEPARATOR \', \') FROM part_compatibility pc
                JOIN equipment_models m ON m.id = pc.model_id JOIN equipment_brands b ON b.id = m.brand_id
               WHERE pc.part_id = p.id) AS brands,
             COALESCE(st.quantity, 0) AS quantity, COALESCE(st.min_quantity, 0) AS min_quantity,
             COALESCE(st.sites_below_min, 0) AS sites_below_min',
            $from,
            ['p.is_active = 1'],
            $params,
            $where,
            $fp,
            ['p.reference', 'p.name', 'p.manufacturer', 'p.manufacturer_ref'],
        );
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM parts WHERE id = :id', ['id' => $id]);
    }

    /** Stocks de la pièce sur les sites du périmètre (tous les sites visibles, même sans stock). */
    public function stocks(int $partId): array
    {
        [$scopeSql, $params] = $this->scope->clause('s.id');

        return $this->db->fetchAll(
            "SELECT s.id AS site_id, s.name AS site, COALESCE(ps.quantity, 0) AS quantity,
                    COALESCE(ps.min_quantity, 0) AS min_quantity, ps.location
               FROM sites s
               LEFT JOIN part_stocks ps ON ps.site_id = s.id AND ps.part_id = :part
              WHERE $scopeSql AND s.is_active = 1
              ORDER BY s.name",
            ['part' => $partId] + $params
        );
    }

    /** Ligne de stock verrouillée (créée à 0 si absente). À appeler dans une transaction. */
    public function lockStock(int $partId, int $siteId): array
    {
        $this->scope->assertAllows($siteId);
        $this->db->execute(
            'INSERT INTO part_stocks (part_id, site_id, quantity) VALUES (:part, :site, 0)
             ON DUPLICATE KEY UPDATE part_id = part_id',
            ['part' => $partId, 'site' => $siteId]
        );

        return $this->db->fetchOne(
            'SELECT * FROM part_stocks WHERE part_id = :part AND site_id = :site FOR UPDATE',
            ['part' => $partId, 'site' => $siteId]
        ) ?? [];
    }

    public function setQuantity(int $partId, int $siteId, float $quantity): void
    {
        $this->scope->assertAllows($siteId);
        $this->db->execute(
            'UPDATE part_stocks SET quantity = :qty WHERE part_id = :part AND site_id = :site',
            ['qty' => $quantity, 'part' => $partId, 'site' => $siteId]
        );
    }

    public function setStockSettings(int $partId, int $siteId, float $minQuantity, ?string $location): void
    {
        $this->scope->assertAllows($siteId);
        $this->db->execute(
            'UPDATE part_stocks SET min_quantity = :min, location = :loc WHERE part_id = :part AND site_id = :site',
            ['min' => $minQuantity, 'loc' => $location, 'part' => $partId, 'site' => $siteId]
        );
    }

    public function addMovement(int $partId, int $siteId, string $type, float $quantity, float $stockAfter, ?int $workOrderId, ?string $comment, ?int $userId, ?int $equipmentId = null): int
    {
        $this->scope->assertAllows($siteId);

        return $this->db->insert(
            'INSERT INTO stock_movements (part_id, site_id, work_order_id, equipment_id, type, quantity, stock_after, comment, user_id)
             VALUES (:part, :site, :wo, :eq, :type, :qty, :after, :comment, :user)',
            [
                'part' => $partId, 'site' => $siteId, 'wo' => $workOrderId, 'eq' => $equipmentId, 'type' => $type,
                'qty' => $quantity, 'after' => $stockAfter, 'comment' => $comment, 'user' => $userId,
            ]
        );
    }

    // --- Compatibilité engins -------------------------------------------------

    public function compatibleModels(int $partId): array
    {
        return $this->db->fetchAll(
            'SELECT m.id, m.name, b.id AS brand_id, b.name AS brand, c.name AS category
               FROM part_compatibility pc
               JOIN equipment_models m ON m.id = pc.model_id
               JOIN equipment_brands b ON b.id = m.brand_id
               LEFT JOIN equipment_categories c ON c.id = m.category_id
              WHERE pc.part_id = :part ORDER BY b.name, m.name',
            ['part' => $partId]
        );
    }

    /** @param list<int> $modelIds */
    public function setCompatibility(int $partId, array $modelIds): void
    {
        $this->db->execute('DELETE FROM part_compatibility WHERE part_id = :part', ['part' => $partId]);
        foreach (array_unique($modelIds) as $modelId) {
            $this->db->execute(
                'INSERT INTO part_compatibility (part_id, model_id) SELECT :part, id FROM equipment_models WHERE id = :model',
                ['part' => $partId, 'model' => (int) $modelId]
            );
        }
    }

    /** Pièces montables sur un modèle d'engin, avec le stock du site de l'engin. */
    public function forModel(int $modelId, int $siteId): array
    {
        return $this->db->fetchAll(
            'SELECT p.id, p.reference, p.name, p.part_type, p.unit, p.unit_cost, p.is_wear_part,
                    COALESCE(ps.quantity, 0) AS quantity, COALESCE(ps.min_quantity, 0) AS min_quantity
               FROM part_compatibility pc
               JOIN parts p ON p.id = pc.part_id AND p.is_active = 1
               LEFT JOIN part_stocks ps ON ps.part_id = p.id AND ps.site_id = :site
              WHERE pc.model_id = :model
              ORDER BY FIELD(p.part_type, \'lubricant\', \'filter\', \'tyre\', \'part\', \'consumable\', \'fuel\'), p.reference',
            ['model' => $modelId, 'site' => $this->scope->allows($siteId) ? $siteId : 0]
        );
    }

    /** Fluides (lubrifiants, carburant) disponibles sur un site : sélecteur d'appoint. */
    public function fluidsOnSite(int $siteId, ?int $modelId = null): array
    {
        if (!$this->scope->allows($siteId)) {
            return [];
        }

        return $this->db->fetchAll(
            'SELECT p.id, p.reference, p.name, p.unit, p.part_type, COALESCE(ps.quantity, 0) AS quantity,
                    EXISTS (SELECT 1 FROM part_compatibility pc WHERE pc.part_id = p.id AND pc.model_id = :model) AS compatible
               FROM parts p LEFT JOIN part_stocks ps ON ps.part_id = p.id AND ps.site_id = :site
              WHERE p.is_active = 1 AND p.part_type IN (\'lubricant\', \'fuel\')
              ORDER BY compatible DESC, p.name',
            ['site' => $siteId, 'model' => $modelId ?? 0]
        );
    }

    // --- Consommation ---------------------------------------------------------

    /** Sorties de stock des $days derniers jours, par site du périmètre. */
    public function consumptionBySite(int $partId, int $days = 90): array
    {
        [$scopeSql, $params] = $this->scope->clause('m.site_id');

        return $this->db->fetchAll(
            "SELECT s.name AS site, SUM(m.quantity) AS quantity, COUNT(*) AS movements
               FROM stock_movements m JOIN sites s ON s.id = m.site_id
              WHERE m.part_id = :part AND m.type = 'out' AND m.created_at >= NOW() - INTERVAL :days DAY AND $scopeSql
              GROUP BY s.id ORDER BY quantity DESC",
            ['part' => $partId, 'days' => $days] + $params
        );
    }

    /** Remplacements (consommations sur OT) avec compteurs de l'engin : base du suivi d'usure. */
    public function replacements(int $partId, int $limit = 20): array
    {
        [$scopeSql, $params] = $this->scope->clause('w.site_id');

        return $this->db->fetchAll(
            "SELECT wp.quantity, wp.hour_meter, wp.odometer_km, wp.created_at, w.id AS work_order_id, w.reference,
                    e.id AS equipment_id, e.code AS equipment_code, s.name AS site
               FROM work_order_parts wp
               JOIN work_orders w ON w.id = wp.work_order_id
               JOIN equipment e ON e.id = w.equipment_id
               JOIN sites s ON s.id = w.site_id
              WHERE wp.part_id = :part AND $scopeSql
              ORDER BY wp.created_at DESC LIMIT " . max(1, $limit),
            ['part' => $partId] + $params
        );
    }

    public function movements(int $partId, int $limit = 50): array
    {
        [$scopeSql, $params] = $this->scope->clause('m.site_id');

        return $this->db->fetchAll(
            "SELECT m.id, m.type, m.quantity, m.stock_after, m.comment, m.created_at, s.name AS site,
                    w.id AS work_order_id, w.reference AS work_order_reference,
                    CONCAT(u.first_name, ' ', u.last_name) AS user
               FROM stock_movements m
               JOIN sites s ON s.id = m.site_id
               LEFT JOIN work_orders w ON w.id = m.work_order_id
               LEFT JOIN users u ON u.id = m.user_id
              WHERE m.part_id = :part AND $scopeSql
              ORDER BY m.created_at DESC, m.id DESC LIMIT " . max(1, $limit),
            ['part' => $partId] + $params
        );
    }

    /** Pièces disponibles sur un site (sélecteur de consommation sur OT). */
    public function availableOnSite(int $siteId): array
    {
        if (!$this->scope->allows($siteId)) {
            return [];
        }

        return $this->db->fetchAll(
            'SELECT p.id, p.reference, p.name, p.unit, ps.quantity
               FROM parts p JOIN part_stocks ps ON ps.part_id = p.id AND ps.site_id = :site
              WHERE p.is_active = 1 AND ps.quantity > 0
              ORDER BY p.reference',
            ['site' => $siteId]
        );
    }

    public function countBelowMin(?int $siteId = null): int
    {
        [$scopeSql, $params] = $this->scope->clause('ps.site_id');
        $siteSql = '';
        if ($siteId !== null) {
            $siteSql = ' AND ps.site_id = :site';
            $params['site'] = $siteId;
        }

        return (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM part_stocks ps JOIN parts p ON p.id = ps.part_id AND p.is_active = 1
              WHERE $scopeSql $siteSql AND ps.min_quantity > 0 AND ps.quantity < ps.min_quantity",
            $params
        );
    }

    public function referenceExists(string $reference, ?int $exceptId = null): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT 1 FROM parts WHERE reference = :ref AND id <> :id',
            ['ref' => $reference, 'id' => $exceptId ?? 0]
        );
    }

    public function create(array $data): int
    {
        return $this->db->insert(
            'INSERT INTO parts (reference, name, part_type, manufacturer, manufacturer_ref, is_wear_part, expected_life_hours,
                                expected_life_km, unit, unit_cost, is_active)
             VALUES (:reference, :name, :part_type, :manufacturer, :manufacturer_ref, :is_wear_part, :expected_life_hours,
                     :expected_life_km, :unit, :unit_cost, :is_active)',
            self::params($data)
        );
    }

    public function update(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE parts SET reference = :reference, name = :name, part_type = :part_type, manufacturer = :manufacturer,
                    manufacturer_ref = :manufacturer_ref, is_wear_part = :is_wear_part, expected_life_hours = :expected_life_hours,
                    expected_life_km = :expected_life_km, unit = :unit, unit_cost = :unit_cost, is_active = :is_active
              WHERE id = :id',
            self::params($data) + ['id' => $id]
        );
    }

    private static function params(array $data): array
    {
        return [
            'reference' => $data['reference'],
            'name' => $data['name'],
            'part_type' => $data['part_type'] ?? 'part',
            'manufacturer' => $data['manufacturer'] ?? null,
            'manufacturer_ref' => $data['manufacturer_ref'] ?? null,
            'is_wear_part' => !empty($data['is_wear_part']) ? 1 : 0,
            'expected_life_hours' => $data['expected_life_hours'] ?? null,
            'expected_life_km' => $data['expected_life_km'] ?? null,
            'unit' => $data['unit'] ?? 'u',
            'unit_cost' => $data['unit_cost'] ?? 0,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ];
    }
}
