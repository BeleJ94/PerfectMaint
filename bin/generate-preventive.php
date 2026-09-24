<?php

declare(strict_types=1);

/**
 * Tâche planifiée (cron quotidien) : crée les OT préventifs des plans à échéance, tous sites.
 *   0 5 * * *  php /chemin/vers/perfectmaint/bin/generate-preventive.php
 */

use App\Core\App;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Security\SiteScope;
use App\Services\PreventiveService;

$config = require dirname(__DIR__) . '/app/bootstrap.php';

$horizon = (int) ($argv[1] ?? PreventiveService::DEFAULT_HORIZON_DAYS);
$app = App::create($config, Session::memory(), new Database($config['db']), new Request('CLI', '/'));
// Exécution système : périmètre global, journalisé sous l'utilisateur « system ».
$app->container()->set(SiteScope::class, SiteScope::all());

$created = $app->container()->get(PreventiveService::class)->generateDue($horizon);
echo count($created) . " OT préventif(s) créé(s).\n";
