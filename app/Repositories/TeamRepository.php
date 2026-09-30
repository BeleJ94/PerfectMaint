<?php

declare(strict_types=1);

namespace App\Repositories;

/** Équipes par shift (maintenance / exploitation), avec chef d'équipe et dispatch. */
final class TeamRepository extends Repository
{
    /** Équipes du périmètre (optionnellement d'un site), avec chef d'équipe et effectif. */
    public function list(?int $siteId = null, bool $activeOnly = true): array
    {
        [$scopeSql, $params] = $this->scope->clause('t.site_id');
        $siteSql = '';
        if ($siteId !== null) {
            $siteSql = ' AND t.site_id = :site';
            $params['site'] = $siteId;
        }

        return $this->db->fetchAll(
            "SELECT t.id, t.site_id, t.name, t.kind, t.shift, t.leader_id, t.dispatch_name, t.dispatch_phone, t.is_active,
                    s.name AS site, CONCAT(l.first_name, ' ', l.last_name) AS leader, l.phone AS leader_phone,
                    (SELECT COUNT(*) FROM personnel p WHERE p.team_id = t.id AND p.is_active = 1) AS member_count
               FROM teams t
               JOIN sites s ON s.id = t.site_id
               LEFT JOIN personnel l ON l.id = t.leader_id
              WHERE $scopeSql $siteSql" . ($activeOnly ? ' AND t.is_active = 1' : '') . "
              ORDER BY s.name, FIELD(t.shift, 'day', 'night', 'rotation'), t.kind, t.name",
            $params
        );
    }

    public function find(int $id): ?array
    {
        [$scopeSql, $params] = $this->scope->clause('t.site_id');

        return $this->db->fetchOne("SELECT t.* FROM teams t WHERE t.id = :id AND $scopeSql", ['id' => $id] + $params);
    }

    public function nameExists(int $siteId, string $name, ?int $exceptId = null): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT 1 FROM teams WHERE site_id = :s AND name = :n AND id <> :id',
            ['s' => $siteId, 'n' => $name, 'id' => $exceptId ?? 0]
        );
    }

    public function create(array $data): int
    {
        $this->scope->assertAllows((int) $data['site_id']);

        return $this->db->insert(
            'INSERT INTO teams (site_id, name, kind, shift, leader_id, dispatch_name, dispatch_phone, is_active)
             VALUES (:site_id, :name, :kind, :shift, :leader_id, :dispatch_name, :dispatch_phone, :is_active)',
            self::params($data)
        );
    }

    public function update(int $id, array $data): void
    {
        $this->scope->assertAllows((int) $data['site_id']);
        [$scopeSql, $params] = $this->scope->clause('site_id');
        $this->db->execute(
            "UPDATE teams SET site_id = :site_id, name = :name, kind = :kind, shift = :shift, leader_id = :leader_id,
                    dispatch_name = :dispatch_name, dispatch_phone = :dispatch_phone, is_active = :is_active
              WHERE id = :id AND $scopeSql",
            self::params($data) + ['id' => $id] + $params
        );
    }

    private static function params(array $data): array
    {
        return [
            'site_id' => (int) $data['site_id'],
            'name' => $data['name'],
            'kind' => $data['kind'],
            'shift' => $data['shift'],
            'leader_id' => $data['leader_id'] ?? null,
            'dispatch_name' => $data['dispatch_name'] ?? null,
            'dispatch_phone' => $data['dispatch_phone'] ?? null,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ];
    }
}
