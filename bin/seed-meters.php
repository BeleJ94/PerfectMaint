<?php

declare(strict_types=1);

/**
 * Simulation de relevés d'index pour la supervision des index (démonstration, jamais en production) :
 *   php bin/seed-meters.php          génère la simulation
 *   php bin/seed-meters.php --purge  la retire et restaure les valeurs d'origine
 *
 * - 18 mois de relevés quotidiens (heures, et km pour les engins qui en ont) par engin, à un rythme propre à chacun ;
 * - quelques engins non relevés depuis 1 à 5 jours ;
 * - échéances des plans horaires recalées pour couvrir tous les cas : échues, à préparer, dans les temps.
 * Les relevés simulés portent la note « [simulation] » ; les index et échéances d'origine sont sauvegardés
 * dans storage/logs pour être restaurés par --purge.
 */

use App\Core\App;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Services\MeterMonitorService;

const MARK = '[simulation]';
const HISTORY_DAYS = 548; // 18 mois

$config = require dirname(__DIR__) . '/app/bootstrap.php';
if ($config['env'] === 'production') {
    fwrite(STDERR, "Refusé : APP_ENV=production. Cette simulation modifie les index et les échéances.\n");
    exit(1);
}
$db = new Database($config['db']);
$backupFile = dirname(__DIR__) . '/storage/logs/meters-simulation-backup.json';

/** Retire la simulation et restaure index et échéances d'origine. */
$purge = static function () use ($db, $backupFile): bool {
    if (!is_file($backupFile)) {
        return false;
    }
    $backup = json_decode((string) file_get_contents($backupFile), true, 512, JSON_THROW_ON_ERROR);
    $db->transaction(static function () use ($db, $backup): void {
        $db->execute('DELETE FROM meter_readings WHERE note = :m', ['m' => MARK]);
        foreach ($backup['equipment'] as $e) {
            $db->execute(
                'UPDATE equipment SET hour_meter = :h, odometer_km = :k, meter_updated_at = :u WHERE id = :id',
                ['h' => $e['hour_meter'], 'k' => $e['odometer_km'], 'u' => $e['meter_updated_at'], 'id' => $e['id']]
            );
        }
        foreach ($backup['plans'] as $p) {
            $db->execute(
                'UPDATE preventive_plans SET last_done_hours = :l, next_due_hours = :n WHERE id = :id',
                ['l' => $p['last_done_hours'], 'n' => $p['next_due_hours'], 'id' => $p['id']]
            );
        }
    });
    unlink($backupFile);

    return true;
};

if (in_array('--purge', $argv, true)) {
    echo $purge() ? "Simulation retirée, valeurs d'origine restaurées.\n" : "Aucune simulation à retirer.\n";
    exit(0);
}
if ($purge()) {
    echo "Simulation précédente retirée avant régénération.\n";
}

$equipment = $db->fetchAll(
    'SELECT id, code, hour_meter, odometer_km, meter_updated_at FROM equipment
      WHERE is_archived = 0 AND hour_meter IS NOT NULL ORDER BY code'
);
if ($equipment === []) {
    fwrite(STDERR, "Aucun engin avec compteur horaire : lancez d'abord bin/seed-mining.php.\n");
    exit(1);
}

mt_srand(20261012);
$today = new DateTimeImmutable('today');
$now = new DateTimeImmutable('now');
$insert = 0;

// Sauvegarde des valeurs d'origine AVANT toute modification.
$backup = [
    'equipment' => $equipment,
    'plans' => $db->fetchAll(
        'SELECT p.id, p.last_done_hours, p.next_due_hours FROM preventive_plans p
           JOIN equipment e ON e.id = p.equipment_id
          WHERE e.is_archived = 0 AND e.hour_meter IS NOT NULL AND p.is_active = 1 AND p.interval_hours IS NOT NULL'
    ),
];
file_put_contents($backupFile, json_encode($backup, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

try {
    $db->transaction(static function () use ($db, $equipment, $today, $now, &$insert): void {
    foreach ($equipment as $i => $e) {
        $id = (int) $e['id'];
        // Rythme propre à l'engin, plafonné pour que l'historique ne remonte pas sous zéro.
        $cap = static fn (int $current): int => max(1, (int) floor($current * 0.9 / HISTORY_DAYS));
        $rate = min(mt_rand(8, 20), $cap((int) $e['hour_meter']));                       // heures par jour
        $kmRate = $e['odometer_km'] !== null ? min(mt_rand(60, 220), $cap((int) $e['odometer_km'])) : null;
        $stale = $i % 7 === 3 ? mt_rand(2, 5) : ($i % 11 === 5 ? 1 : 0); // jours sans relevé

        // Point d'ancrage : le dernier relevé réel de l'engin, sinon son index il y a 18 jours.
        $last = $db->fetchOne(
            'SELECT hour_meter, odometer_km, recorded_at FROM meter_readings
              WHERE equipment_id = :e AND hour_meter IS NOT NULL ORDER BY recorded_at DESC, id DESC LIMIT 1',
            ['e' => $id]
        );
        $anchorDay = $last !== null ? new DateTimeImmutable(substr($last['recorded_at'], 0, 10)) : $today->modify('-18 days');
        $anchorAgo = (int) $anchorDay->diff($today)->format('%a');
        $hours = $last !== null ? (int) $last['hour_meter'] : max(0, (int) $e['hour_meter'] - 18 * $rate);
        $km = $kmRate === null ? null : (int) ($last['odometer_km'] ?? $e['odometer_km']);

        $step = static function (DateTimeImmutable $day, int $perDay): int {
            // Dimanche : activité réduite.
            return max(0, (int) round(($perDay + mt_rand(-3, 3)) * ($day->format('N') === '7' ? 0.3 : 1)));
        };
        $add = static function (DateTimeImmutable $day, int $h, ?int $k) use ($db, $id, $now, &$insert): string {
            $at = $day->setTime(mt_rand(6, 8), mt_rand(0, 59));
            $at = $at > $now ? $now : $at;
            $db->execute(
                'INSERT INTO meter_readings (equipment_id, hour_meter, odometer_km, note, recorded_at) VALUES (:e, :h, :k, :n, :at)',
                ['e' => $id, 'h' => $h, 'k' => $k, 'n' => MARK, 'at' => $at->format('Y-m-d H:i:s')]
            );
            $insert++;

            return $at->format('Y-m-d H:i:s');
        };

        // Historique avant le point d'ancrage (index décroissants en remontant le temps).
        [$h, $k] = [$hours, $km];
        for ($ago = $anchorAgo + 1; $ago <= HISTORY_DAYS; $ago++) {
            $day = $today->modify("-$ago days");
            $h = max(0, $h - $step($day->modify('+1 day'), $rate));
            $k = $k === null ? null : max(0, $k - $step($day->modify('+1 day'), $kmRate));
            $add($day, $h, $k);
        }
        // Relevés quotidiens du point d'ancrage jusqu'au dernier relevé simulé.
        [$h, $k, $lastAt] = [$hours, $km, $e['meter_updated_at']];
        for ($ago = $anchorAgo - 1; $ago >= $stale; $ago--) {
            $day = $today->modify("-$ago days");
            $h += $step($day, $rate);
            $k = $k === null ? null : $k + $step($day, $kmRate);
            $lastAt = $add($day, $h, $k);
        }
        $db->execute(
            'UPDATE equipment SET hour_meter = :h, odometer_km = COALESCE(:k, odometer_km), meter_updated_at = :u WHERE id = :id',
            ['h' => $h, 'k' => $k, 'u' => $lastAt, 'id' => $id]
        );

        // Échéances : le plan le plus court porte le scénario, les suivants tombent plus tard.
        $plans = $db->fetchAll(
            'SELECT id, interval_hours, last_done_hours, next_due_hours FROM preventive_plans
              WHERE equipment_id = :e AND is_active = 1 AND interval_hours IS NOT NULL ORDER BY interval_hours, id',
            ['e' => $id]
        );
        foreach ($plans as $n => $plan) {
            $interval = (int) $plan['interval_hours'];
            if ($n === 0) {
                $remaining = match ($i % 10) {
                    0 => -mt_rand(5, 60),                                              // échue
                    1 => $rate * mt_rand(2, 6),                                        // à préparer : échéance sous 7 jours
                    2 => (int) round($interval * mt_rand(4, 9) / 100),                 // à préparer : 90 % de l'intervalle
                    default => max($rate * 9, (int) round($interval * mt_rand(30, 85) / 100)), // dans les temps
                };
                $remaining = min($remaining, $interval - 1);
                $firstDue = $h + $remaining;
                $due = $firstDue;
            } else {
                $due = $firstDue + $interval - (int) $plans[0]['interval_hours'];
            }
            $db->execute(
                'UPDATE preventive_plans SET last_done_hours = :l, next_due_hours = :n WHERE id = :id',
                ['l' => max(0, $due - $interval), 'n' => $due, 'id' => (int) $plan['id']]
            );
        }
    }
    });
} catch (Throwable $e) {
    unlink($backupFile); // transaction annulée : rien n'a changé
    throw $e;
}

$adminId = (int) $db->fetchValue("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1");
$summary = App::create($config, Session::memory(['auth_user_id' => $adminId]), $db, new Request('CLI', '/'))
    ->container()->get(MeterMonitorService::class)->summary();

printf("%d relevés simulés sur %d engins (%d jours).\n", $insert, count($equipment), HISTORY_DAYS);
printf(
    "Supervision : %d échue(s), %d à préparer, %d relevé(s) aujourd'hui sur %d, %d index non à jour.\n",
    $summary['due'], $summary['soon'], $summary['read_today'], $summary['equipment'], $summary['stale']
);
echo "Pour revenir à l'état d'origine : php bin/seed-meters.php --purge\n";
