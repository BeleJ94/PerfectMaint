<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Env;
use App\Core\Migrator;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Les identifiants viennent de .env ; DB_NAME est forcé à perfectmaint_test par phpunit.xml.
Env::load(dirname(__DIR__, 2) . '/.env');
date_default_timezone_set('Europe/Paris');

/** Recrée le schéma de test une seule fois par exécution, via les migrations (comme en production). */
function test_database(): Database
{
    static $db = null;
    if ($db === null) {
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        $db = new Database($config['db']);
        $migrator = new Migrator($db, dirname(__DIR__, 2) . '/database/migrations');
        $migrator->dropAll();
        $migrator->migrate();
    }

    return $db;
}
