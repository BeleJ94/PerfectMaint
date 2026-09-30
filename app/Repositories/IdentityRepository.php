<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Accès aux comptes pour l'authentification uniquement. Intervient AVANT que le périmètre
 * de sites ne soit connu : ne manipule aucune donnée métier rattachée à un site.
 */
final class IdentityRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function findByEmail(string $email): ?array
    {
        return $this->db->fetchOne('SELECT * FROM users WHERE email = :email', ['email' => mb_strtolower($email)]);
    }

    public function findActive(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM users WHERE id = :id AND is_active = 1', ['id' => $id]);
    }

    /** @return list<int> */
    public function siteIds(int $userId): array
    {
        return array_map(
            'intval',
            array_column($this->db->fetchAll('SELECT site_id FROM user_sites WHERE user_id = :id', ['id' => $userId]), 'site_id')
        );
    }

    public function recordSuccess(int $id): void
    {
        $this->db->execute(
            'UPDATE users SET failed_logins = 0, locked_until = NULL, last_login_at = NOW() WHERE id = :id',
            ['id' => $id]
        );
    }

    public function recordFailure(int $id, int $maxAttempts, int $lockMinutes): void
    {
        $this->db->execute(
            'UPDATE users
                SET failed_logins = failed_logins + 1,
                    locked_until = IF(failed_logins >= :max, NOW() + INTERVAL :minutes MINUTE, locked_until)
              WHERE id = :id',
            ['id' => $id, 'max' => $maxAttempts, 'minutes' => $lockMinutes]
        );
    }

    public function updateLocale(int $id, string $locale): void
    {
        $this->db->execute('UPDATE users SET locale = :locale WHERE id = :id', ['locale' => $locale, 'id' => $id]);
    }
}
