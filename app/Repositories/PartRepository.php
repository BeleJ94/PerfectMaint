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
        if (($filters['low'] ?? '') === '1') {
            $where[] = 'st.sites_below_min > 0';
        }

        return $this->paginate(
            $dt,
            'p.id, p.reference, p.name, p.unit, p.unit_cost, p.is_active,
             COALESCE(st.quantity, 0) AS quantity, COALESCE(st.min_quantity, 0) AS min_quantity,
             COALESCE(st.sites_below_min, 0) AS sites_below_min',
            $from,
            ['p.is_active = 1'],
            $params,
            $where,
            [],
            ['p.reference', 'p.name'],
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

    public function addMovement(int $partId, int $siteId, string $type, float $quantity, float $stockAfter, ?int $workOrderId, ?string $comment, ?int $userId): int
    {
        $this->scope->assertAllows($siteId);

        return $this->db->insert(
            'INSERT INTO stock_movements (part_id, site_id, work_order_id, type, quantity, stock_after, comment, user_id)
             VALUES (:part, :site, :wo, :type, :qty, :after, :comment, :user)',
            [
                'part' => $partId, 'site' => $siteId, 'wo' => $workOrderId, 'type' => $type,
                'qty' => $quantity, 'after' => $stockAfter, 'comment' => $comment, 'user' => $userId,
            ]
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
            'INSERT INTO parts (reference, name, unit, unit_cost, is_active) VALUES (:reference, :name, :unit, :unit_cost, :is_active)',
            self::params($data)
        );
    }

    public function update(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE parts SET reference = :reference, name = :name, unit = :unit, unit_cost = :unit_cost,
                    is_active = :is_active WHERE id = :id',
            self::params($data) + ['id' => $id]
        );
    }

    private static function params(array $data): array
    {
        return [
            'reference' => $data['reference'],
            'name' => $data['name'],
            'unit' => $data['unit'] ?? 'u',
            'unit_cost' => $data['unit_cost'] ?? 0,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ];
    }
}
