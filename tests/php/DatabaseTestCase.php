<?php

declare(strict_types=1);

namespace Tests;

use App\Core\App;
use App\Core\Database;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use PHPUnit\Framework\TestCase;

/**
 * Base des tests d'intégration et fonctionnels : base de test vidée avant chaque test,
 * fabriques de données et exécution de l'application en mémoire (sans serveur HTTP).
 */
abstract class DatabaseTestCase extends TestCase
{
    protected const PASSWORD = 'Secret-Password-42';

    /** Tables conservées entre les tests (référentiels issus des migrations). */
    private const KEEP = ['schema_migrations', 'equipment_categories', 'equipment_brands', 'equipment_models'];

    protected Database $db;

    protected function setUp(): void
    {
        $this->db = test_database();
        $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->db->fetchAll('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"') as $row) {
            $table = (string) array_values($row)[0];
            if (!in_array($table, self::KEEP, true)) {
                $this->db->pdo()->exec("TRUNCATE TABLE `$table`");
            }
        }
        $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
        I18n::setCurrent(new I18n(dirname(__DIR__, 2) . '/lang'));
    }

    protected static function config(): array
    {
        return require dirname(__DIR__, 2) . '/config/app.php';
    }

    // --- Fabriques ------------------------------------------------------------

    protected function createSite(string $code): int
    {
        return $this->db->insert('INSERT INTO sites (code, name) VALUES (:c, :n)', ['c' => $code, 'n' => "Site $code"]);
    }

    /** @param list<int> $siteIds */
    protected function createUser(string $role, array $siteIds = [], bool $allSites = false, ?string $email = null): int
    {
        $email ??= $role . '.' . bin2hex(random_bytes(3)) . '@test.local';
        $id = $this->db->insert(
            'INSERT INTO users (email, password_hash, first_name, last_name, role, all_sites)
             VALUES (:e, :h, :f, :l, :r, :a)',
            ['e' => $email, 'h' => password_hash(self::PASSWORD, PASSWORD_DEFAULT), 'f' => ucfirst($role), 'l' => 'Test', 'r' => $role, 'a' => $allSites ? 1 : 0]
        );
        foreach ($siteIds as $siteId) {
            $this->db->execute('INSERT INTO user_sites (user_id, site_id) VALUES (:u, :s)', ['u' => $id, 's' => $siteId]);
        }

        return $id;
    }

    protected function createEquipment(int $siteId, string $code, string $status = 'available', int $hours = 100): int
    {
        return $this->db->insert(
            'INSERT INTO equipment (site_id, code, name, status, hour_meter) VALUES (:s, :c, :n, :st, :h)',
            ['s' => $siteId, 'c' => $code, 'n' => "Engin $code", 'st' => $status, 'h' => $hours]
        );
    }

    protected function createPart(string $reference, float $unitCost = 10.0): int
    {
        return $this->db->insert(
            'INSERT INTO parts (reference, name, unit, unit_cost) VALUES (:r, :n, \'u\', :c)',
            ['r' => $reference, 'n' => "Pièce $reference", 'c' => $unitCost]
        );
    }

    protected function setStock(int $partId, int $siteId, float $quantity, float $min = 0): void
    {
        $this->db->execute(
            'INSERT INTO part_stocks (part_id, site_id, quantity, min_quantity) VALUES (:p, :s, :q, :m)
             ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), min_quantity = VALUES(min_quantity)',
            ['p' => $partId, 's' => $siteId, 'q' => $quantity, 'm' => $min]
        );
    }

    // --- Application en mémoire -------------------------------------------------

    /** Application dont la session est ouverte au nom de $userId (null = anonyme). */
    protected function appAs(?int $userId, ?Request $request = null): App
    {
        $session = Session::memory($userId !== null ? ['auth_user_id' => $userId] : []);

        return App::create(self::config(), $session, $this->db, $request ?? new Request('GET', '/'));
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    protected function service(?int $userId, string $class): object
    {
        return $this->appAs($userId)->container()->get($class);
    }

    /** Exécute une requête HTTP simulée ; la session est partagée entre les appels. */
    protected function http(Session $session, string $method, string $path, array $body = [], array $headers = []): Response
    {
        [$path, $query] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($query, $queryParams);
        $request = new Request($method, $path, $queryParams, $body, array_change_key_case($headers), ['REMOTE_ADDR' => '127.0.0.1']);

        return App::create(self::config(), $session, $this->db, $request)->handle($request);
    }

    protected function row(string $sql, array $params = []): array
    {
        return $this->db->fetchOne($sql, $params) ?? [];
    }

    protected function value(string $sql, array $params = []): mixed
    {
        return $this->db->fetchValue($sql, $params);
    }
}
