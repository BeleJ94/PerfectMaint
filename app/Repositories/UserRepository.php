<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DataTableRequest;

final class UserRepository extends Repository
{
    public const SORTABLE = [
        'name' => 'u.last_name',
        'email' => 'u.email',
        'role' => 'u.role',
        'is_active' => 'u.is_active',
        'last_login_at' => 'u.last_login_at',
    ];

    /**
     * Un utilisateur est visible s'il partage au moins un site du périmètre
     * (ou s'il est « tous sites » et que le périmètre est global).
     */
    private function visibility(): array
    {
        if ($this->scope->isAll()) {
            return ['1=1', []];
        }
        [$scopeSql, $params] = $this->scope->clause('us.site_id', 'uvis');

        return ["EXISTS (SELECT 1 FROM user_sites us WHERE us.user_id = u.id AND $scopeSql)", $params];
    }

    public function datatable(DataTableRequest $dt, array $filters): array
    {
        [$visSql, $params] = $this->visibility();
        $where = [];
        $filterParams = [];
        if (!empty($filters['role'])) {
            $where[] = 'u.role = :role';
            $filterParams['role'] = $filters['role'];
        }

        return $this->paginate(
            $dt,
            "u.id, u.email, u.first_name, u.last_name, CONCAT(u.first_name, ' ', u.last_name) AS name, u.role,
             u.all_sites, u.is_active, u.last_login_at,
             (SELECT GROUP_CONCAT(s.code ORDER BY s.code SEPARATOR ', ')
                FROM user_sites us2 JOIN sites s ON s.id = us2.site_id WHERE us2.user_id = u.id) AS sites",
            'users u',
            [$visSql],
            $params,
            $where,
            $filterParams,
            ['u.email', 'u.first_name', 'u.last_name'],
        );
    }

    public function find(int $id): ?array
    {
        [$visSql, $params] = $this->visibility();
        $user = $this->db->fetchOne(
            "SELECT u.id, u.email, u.first_name, u.last_name, u.role, u.locale, u.all_sites, u.is_active, u.last_login_at
               FROM users u WHERE u.id = :id AND $visSql",
            ['id' => $id] + $params
        );
        if ($user !== null) {
            $user['site_ids'] = $this->siteIds($id);
        }

        return $user;
    }

    /** @return list<int> */
    public function siteIds(int $userId): array
    {
        return array_map('intval', array_column(
            $this->db->fetchAll('SELECT site_id FROM user_sites WHERE user_id = :id ORDER BY site_id', ['id' => $userId]),
            'site_id'
        ));
    }

    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT 1 FROM users WHERE email = :email AND id <> :id',
            ['email' => mb_strtolower($email), 'id' => $exceptId ?? 0]
        );
    }

    public function create(array $data, string $passwordHash): int
    {
        return $this->db->insert(
            'INSERT INTO users (email, password_hash, first_name, last_name, role, locale, all_sites, is_active)
             VALUES (:email, :hash, :first_name, :last_name, :role, :locale, :all_sites, :is_active)',
            self::params($data) + ['hash' => $passwordHash]
        );
    }

    public function update(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE users SET email = :email, first_name = :first_name, last_name = :last_name, role = :role,
                    locale = :locale, all_sites = :all_sites, is_active = :is_active
              WHERE id = :id',
            self::params($data) + ['id' => $id]
        );
    }

    public function updatePassword(int $id, string $hash): void
    {
        $this->db->execute(
            'UPDATE users SET password_hash = :hash, failed_logins = 0, locked_until = NULL WHERE id = :id',
            ['hash' => $hash, 'id' => $id]
        );
    }

    /** @param list<int> $siteIds */
    public function syncSites(int $userId, array $siteIds): void
    {
        $this->db->execute('DELETE FROM user_sites WHERE user_id = :id', ['id' => $userId]);
        foreach ($siteIds as $siteId) {
            $this->scope->assertAllows($siteId);
            $this->db->execute(
                'INSERT INTO user_sites (user_id, site_id) VALUES (:user, :site)',
                ['user' => $userId, 'site' => $siteId]
            );
        }
    }

    /** Intervenants possibles (techniciens, responsables) ayant accès au site. */
    public function assignableForSite(int $siteId): array
    {
        if (!$this->scope->allows($siteId)) {
            return [];
        }

        return $this->db->fetchAll(
            "SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) AS name, u.role
               FROM users u
              WHERE u.is_active = 1
                AND u.role IN ('technician', 'manager', 'admin')
                AND (u.role = 'admin' OR u.all_sites = 1
                     OR EXISTS (SELECT 1 FROM user_sites us WHERE us.user_id = u.id AND us.site_id = :site))
              ORDER BY u.last_name, u.first_name",
            ['site' => $siteId]
        );
    }

    private static function params(array $data): array
    {
        return [
            'email' => mb_strtolower((string) $data['email']),
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'role' => $data['role'],
            'locale' => $data['locale'] ?? 'fr',
            'all_sites' => !empty($data['all_sites']) ? 1 : 0,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ];
    }
}
