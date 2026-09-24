<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DataTableRequest;

final class SiteRepository extends Repository
{
    public const SORTABLE = ['code' => 's.code', 'name' => 's.name', 'city' => 's.city', 'is_active' => 's.is_active'];

    /** Sites actifs du périmètre (listes déroulantes). */
    public function listActive(): array
    {
        [$scopeSql, $params] = $this->scope->clause('s.id');

        return $this->db->fetchAll(
            "SELECT s.id, s.code, s.name FROM sites s WHERE $scopeSql AND s.is_active = 1 ORDER BY s.name",
            $params
        );
    }

    public function find(int $id): ?array
    {
        [$scopeSql, $params] = $this->scope->clause('s.id');

        return $this->db->fetchOne("SELECT s.* FROM sites s WHERE s.id = :id AND $scopeSql", ['id' => $id] + $params);
    }

    public function datatable(DataTableRequest $dt): array
    {
        [$scopeSql, $params] = $this->scope->clause('s.id');

        return $this->paginate(
            $dt,
            's.id, s.code, s.name, s.city, s.is_active,
             (SELECT COUNT(*) FROM equipment e WHERE e.site_id = s.id AND e.is_archived = 0) AS equipment_count',
            'sites s',
            [$scopeSql],
            $params,
            [],
            [],
            ['s.code', 's.name', 's.city'],
        );
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT 1 FROM sites WHERE code = :code AND id <> :id',
            ['code' => $code, 'id' => $exceptId ?? 0]
        );
    }

    public function create(array $data): int
    {
        return $this->db->insert(
            'INSERT INTO sites (code, name, city, address, is_active) VALUES (:code, :name, :city, :address, :is_active)',
            self::params($data)
        );
    }

    public function update(int $id, array $data): void
    {
        $this->scope->assertAllows($id);
        $this->db->execute(
            'UPDATE sites SET code = :code, name = :name, city = :city, address = :address, is_active = :is_active
             WHERE id = :id',
            self::params($data) + ['id' => $id]
        );
    }

    private static function params(array $data): array
    {
        return [
            'code' => $data['code'],
            'name' => $data['name'],
            'city' => $data['city'] ?? null,
            'address' => $data['address'] ?? null,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ];
    }
}
