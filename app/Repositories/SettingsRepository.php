<?php

declare(strict_types=1);

namespace App\Repositories;

/** Paramètres globaux de l'application (non rattachés à un site). */
final class SettingsRepository extends Repository
{
    /** @return array<string, string|null> paramètres dont le nom commence par $prefix */
    public function all(string $prefix): array
    {
        $rows = $this->db->fetchAll(
            'SELECT name, value FROM settings WHERE name LIKE :p',
            ['p' => addcslashes($prefix, '%_\\') . '%']
        );

        return array_column($rows, 'value', 'name');
    }

    /** @param array<string, string|null> $values */
    public function setMany(array $values): void
    {
        foreach ($values as $name => $value) {
            $this->db->execute(
                'INSERT INTO settings (name, value) VALUES (:n, :v) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                ['n' => $name, 'v' => $value]
            );
        }
    }
}
