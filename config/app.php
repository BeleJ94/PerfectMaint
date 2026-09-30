<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'name' => 'PerfectMaint',
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => Env::bool('APP_DEBUG'),
    'base_path' => Env::get('APP_BASE_PATH', ''),
    'default_locale' => 'fr',
    'locales' => ['fr', 'en'],
    // Sites en RDC (Lubumbashi / Kolwezi, UTC+2, sans heure d'été)
    'timezone' => Env::get('APP_TIMEZONE', 'Africa/Lubumbashi'),
    'session' => [
        'name' => 'perfectmaint_session',
        'lifetime' => 60 * 60 * 8,
        'secure' => Env::bool('SESSION_SECURE'),
    ],
    'security' => [
        'max_login_attempts' => 5,
        'lockout_minutes' => 15,
    ],
    'db' => [
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => (int) Env::get('DB_PORT', '3306'),
        'name' => Env::get('DB_NAME', 'perfectmaint'),
        'user' => Env::get('DB_USER', 'root'),
        'pass' => Env::get('DB_PASS', ''),
    ],
];
