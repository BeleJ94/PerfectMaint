<?php

declare(strict_types=1);

/**
 * Tâche planifiée (cron quotidien) : envoie le récapitulatif des alarmes par e-mail, tous sites.
 *   0 6 * * *  php /chemin/vers/perfectmaint/bin/send-alerts.php
 * N'envoie rien si les alarmes par e-mail sont désactivées (Paramètres > E-mails) ou s'il n'y a rien à signaler.
 *   --force  envoie même si les alarmes par e-mail sont désactivées
 */

use App\Core\App;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Core\ValidationException;
use App\Security\SiteScope;
use App\Services\MailService;

$config = require dirname(__DIR__) . '/app/bootstrap.php';

$app = App::create($config, Session::memory(), new Database($config['db']), new Request('CLI', '/'));
// Exécution système : périmètre global.
$app->container()->set(SiteScope::class, SiteScope::all());
$mail = $app->container()->get(MailService::class);

if (!$mail->settings()['alerts_enabled'] && !in_array('--force', $argv, true)) {
    echo "Alarmes par e-mail désactivées : aucun envoi (utilisez --force pour envoyer quand même).\n";
    exit(0);
}
try {
    $count = $mail->sendAlerts();
} catch (ValidationException $e) {
    fwrite(STDERR, implode("\n", $e->errors) . "\n");
    exit(1);
}
echo $count > 0 ? "Récapitulatif envoyé : $count point(s) d'attention.\n" : "Rien à signaler : aucun e-mail envoyé.\n";
