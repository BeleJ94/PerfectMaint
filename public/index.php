<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;

$config = require dirname(__DIR__) . '/app/bootstrap.php';

$request = Request::fromGlobals((string) $config['base_path']);
$session = Session::native($config['session']);

App::create($config, $session, new Database($config['db']), $request)
    ->handle($request)
    ->send();
