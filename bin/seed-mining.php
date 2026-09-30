<?php

declare(strict_types=1);

/**
 * Données de démonstration minières (idempotent) : php bin/seed-mining.php
 * - archive les sites / engins de démonstration « entrepôt » (LYO, LIL, MRS) ;
 * - crée le parc des sites CHEMAF, KAMAO, METALKOL (terrassement, ADT, bennes) et KINGAKILA, SICOMINES (bus) ;
 * - ouvre quelques OT pour animer les écrans.
 * Passe par les services métier : historiques et journal cohérents.
 */

use App\Core\App;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Security\Auth;
use App\Services\EquipmentService;
use App\Services\WorkOrderService;

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$db = new Database($config['db']);

$adminId = (int) $db->fetchValue("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1");
if ($adminId === 0) {
    fwrite(STDERR, "Aucun administrateur actif : lancez d'abord bin/seed.php ou créez un compte.\n");
    exit(1);
}
$app = App::create($config, Session::memory(['auth_user_id' => $adminId]), $db, new Request('CLI', '/'));
$c = $app->container();
if ($c->get(Auth::class)->user() === null) {
    fwrite(STDERR, "Session administrateur impossible.\n");
    exit(1);
}

// 1. Démonstration « entrepôt » archivée (historique conservé)
$db->execute("UPDATE equipment e JOIN sites s ON s.id = e.site_id SET e.is_archived = 1 WHERE s.code IN ('LYO', 'LIL', 'MRS')");
$db->execute("UPDATE sites SET is_active = 0 WHERE code IN ('LYO', 'LIL', 'MRS')");
$db->execute("UPDATE work_orders w JOIN sites s ON s.id = w.site_id SET w.status = 'cancelled', w.completed_at = COALESCE(w.completed_at, NOW())
               WHERE s.code IN ('LYO', 'LIL', 'MRS') AND w.status IN ('open', 'in_progress', 'waiting_part')");
$db->execute("UPDATE preventive_plans p JOIN sites s ON s.id = p.site_id SET p.is_active = 0 WHERE s.code IN ('LYO', 'LIL', 'MRS')");

$sites = array_column($db->fetchAll('SELECT id, code FROM sites'), 'id', 'code');

// Comptes de démonstration rattachés aux seuls sites miniers (accès « entrepôt » retirés)
$db->execute("DELETE us FROM user_sites us JOIN sites s ON s.id = us.site_id WHERE s.code IN ('LYO', 'LIL', 'MRS')");
$access = [
    'marc.responsable@perfect-logistics.test' => ['CHEMAF', 'KAMAO', 'METALKOL'],
    'sofia.tech@perfect-logistics.test' => ['KAMAO'],
    'karim.tech@perfect-logistics.test' => ['METALKOL'],
    'lea.lecture@perfect-logistics.test' => ['KINGAKILA', 'SICOMINES'],
];
foreach ($access as $email => $codes) {
    foreach ($codes as $code) {
        $db->execute(
            'INSERT IGNORE INTO user_sites (user_id, site_id) SELECT u.id, :s FROM users u WHERE u.email = :e',
            ['s' => $sites[$code] ?? 0, 'e' => $email]
        );
    }
}
$model = static function (string $brand, string $name) use ($db): int {
    return (int) $db->fetchValue(
        'SELECT m.id FROM equipment_models m JOIN equipment_brands b ON b.id = m.brand_id WHERE b.name = :b AND m.name = :m',
        ['b' => $brand, 'm' => $name]
    );
};

// 2. Parc : [préfixe, marque, modèle, désignation, nombre, heures de base]
$earthmoving = [
    ['PEL', 'Komatsu', 'PC350LC-8', 'Pelle 35 t', 2, 9800],
    ['PEL', 'Caterpillar', '336D2', 'Pelle 36 t', 1, 12400],
    ['BUL', 'Caterpillar', 'D8R', 'Bulldozer', 1, 15100],
    ['CHG', 'Caterpillar', '950H', 'Chargeuse', 1, 8700],
    ['ADT', 'Bell', 'B40E', 'Tombereau articulé 40 t', 3, 7600],
    ['ADT', 'Volvo', 'A40G', 'Tombereau articulé 40 t', 2, 6900],
    ['BEN', 'HOWO', 'ZZ3257 371', 'Camion benne 25 t', 3, 5200],
    ['NIV', 'Caterpillar', '140K', 'Niveleuse', 1, 11200],
    ['TEL', 'Manitou', 'MT 1840', 'Télescopique 18 m', 1, 3100],
    ['VL', 'Toyota', 'Land Cruiser 79', 'Véhicule de liaison', 1, 0],
];
$buses = [
    ['BUS', 'Yutong', 'ZK6122H9', 'Bus 60 places', 5, 14200],
    ['BUS', 'Tata', 'LPO 1618', 'Bus 50 places', 3, 18800],
    ['MBU', 'Toyota', 'Coaster', 'Minibus 29 places', 2, 9600],
];
$fleet = ['CHEMAF' => $earthmoving, 'KAMAO' => $earthmoving, 'METALKOL' => $earthmoving, 'KINGAKILA' => $buses, 'SICOMINES' => $buses];

$equipmentService = $c->get(EquipmentService::class);
$created = 0;
$ids = [];
foreach ($fleet as $siteCode => $rows) {
    if (!isset($sites[$siteCode])) {
        continue;
    }
    $n = 0;
    foreach ($rows as [$prefix, $brand, $modelName, $label, $count, $hours]) {
        for ($i = 1; $i <= $count; $i++) {
            $n++;
            $code = sprintf('%s-%s-%02d', substr($siteCode, 0, 3), $prefix, $n);
            $existing = $db->fetchValue('SELECT id FROM equipment WHERE code = :c', ['c' => $code]);
            if ($existing) {
                $ids[$siteCode][] = (int) $existing;
                continue;
            }
            $ids[$siteCode][] = $equipmentService->create([
                'site_id' => $sites[$siteCode], 'code' => $code, 'name' => $label, 'model_id' => $model($brand, $modelName),
                'serial_number' => strtoupper(substr(md5($code), 0, 12)),
                'commissioned_at' => date('Y-m-d', strtotime('-' . (400 + 37 * $n) . ' days')),
                'hour_meter' => $hours > 0 ? $hours + 173 * $n : null,
            ]);
            $created++;
        }
    }
}

// 3. Quelques interventions ouvertes (une seule fois)
$wo = $c->get(WorkOrderService::class);
if ((int) $db->fetchValue("SELECT COUNT(*) FROM work_orders w JOIN sites s ON s.id = w.site_id WHERE s.code IN ('CHEMAF', 'KAMAO', 'METALKOL', 'KINGAKILA', 'SICOMINES')") === 0) {
    $scenarios = [
        ['KAMAO', 0, 'Fuite vérin de flèche', 'critical', true, [['status' => 'in_progress']]],
        ['KAMAO', 4, 'Surchauffe moteur en rampe', 'high', true, []],
        ['KAMAO', 7, 'Pneu arrière gauche entaillé', 'normal', true, [['status' => 'in_progress'], ['status' => 'waiting_part']]],
        ['CHEMAF', 2, 'Barbotin usé côté droit', 'high', true, [['status' => 'in_progress']]],
        ['CHEMAF', 10, 'Voyant pression huile', 'normal', false, []],
        ['METALKOL', 5, 'Frein de parc inopérant', 'critical', true, []],
        ['KINGAKILA', 1, 'Climatisation HS', 'low', false, []],
        ['SICOMINES', 6, 'Embrayage patine', 'high', true, [['status' => 'in_progress'], ['status' => 'waiting_part']]],
    ];
    foreach ($scenarios as [$siteCode, $index, $title, $priority, $immobilizing, $steps]) {
        if (!isset($ids[$siteCode][$index])) {
            continue;
        }
        $id = $wo->create([
            'equipment_id' => $ids[$siteCode][$index], 'title' => $title, 'priority' => $priority, 'type' => 'corrective',
            'immobilizing' => $immobilizing ? '1' : '0',
            'due_date' => date('Y-m-d', strtotime($priority === 'critical' ? '-1 day' : '+3 days')),
        ]);
        foreach ($steps as $step) {
            $wo->transition($id, $step);
        }
    }
}

// 4. Identification, carburant et contrats (engins pas encore renseignés)
$rows = $db->fetchAll(
    "SELECT e.id, e.code, s.code AS site FROM equipment e JOIN sites s ON s.id = e.site_id
      WHERE e.is_archived = 0 AND e.plate_number IS NULL AND s.code IN ('CHEMAF', 'KAMAO', 'METALKOL', 'KINGAKILA', 'SICOMINES')"
);
$meters = $c->get(\App\Services\MeterService::class);
foreach ($rows as $k => $row) {
    $isBus = in_array($row['site'], ['KINGAKILA', 'SICOMINES'], true);
    $rental = $k % 5 === 2;
    $db->execute(
        "UPDATE equipment SET plate_number = :plate, chassis_number = :chassis, assignment = :assign,
                ownership = :own, contract_ref = :ref, contract_party = :party, contract_start = :cs, contract_end = :ce
          WHERE id = :id",
        [
            'plate' => sprintf('%04d%s%02d', 1000 + $k * 37, ['AB', 'KZ', 'LB', 'KW'][$k % 4], 10 + $k % 80),
            'chassis' => strtoupper(substr(md5('ch' . $row['code']), 0, 17)),
            'assign' => $isBus ? 'Navette personnel – rotation ' . ['A', 'B', 'C'][$k % 3] : ['Fosse nord – roulage minerai', 'Découverture – stérile', 'Pistes & entretien', 'Stock pile – reprise'][$k % 4],
            'own' => $rental ? ($k % 2 ? 'rental' : 'po') : 'owned',
            'ref' => $rental ? sprintf('PO-2026-%04d', 100 + $k) : null,
            'party' => $rental ? ['Mantrac', 'Tractafric', 'SMT Congo'][$k % 3] : null,
            'cs' => $rental ? date('Y-m-d', strtotime('-200 days')) : null,
            'ce' => $rental ? date('Y-m-d', strtotime('+' . (($k * 11) % 70 - 5) . ' days')) : null,
            'id' => $row['id'],
        ]
    );
    $current = $db->fetchOne('SELECT hour_meter, odometer_km FROM equipment WHERE id = :id', ['id' => $row['id']]);
    $meters->record((int) $row['id'], [
        'hour_meter' => $current['hour_meter'] !== null ? (int) $current['hour_meter'] + 8 : null,
        'odometer_km' => $isBus || str_contains($row['code'], '-BEN-') || str_contains($row['code'], '-VL-') ? 40000 + $k * 1370 : null,
        'fuel_level' => [85, 60, 40, 25, 10, 95, 70][$k % 7],
        'note' => 'Prise de poste',
    ]);
}

// 5. Équipes par shift, personnel et affectations (sites sans équipe uniquement)
$workforce = $c->get(\App\Services\WorkforceService::class);
$firstNames = ['Jean', 'Patrick', 'Dieudonné', 'Christian', 'Serge', 'Olivier', 'Blaise', 'Papy', 'Junior', 'Didier', 'Fiston', 'Héritier', 'Glody', 'Trésor', 'Cédric', 'Aimé', 'Josué', 'Rachel', 'Grâce', 'Mireille'];
$lastNames = ['Kabila', 'Mukendi', 'Tshibanda', 'Ilunga', 'Kasongo', 'Mbuyi', 'Ngoy', 'Kalenga', 'Mwamba', 'Banza', 'Kabeya', 'Lukusa', 'Mutombo', 'Nkulu', 'Kyungu', 'Kapend'];
$teamsBySite = [
    'earthmoving' => [
        ['Maintenance A', 'maintenance', 'day', ['team_lead', 'mechanic', 'mechanic', 'electrician', 'welder', 'tyre_fitter', 'lubricator']],
        ['Maintenance B', 'maintenance', 'night', ['team_lead', 'mechanic', 'electrician', 'lubricator']],
        ['Exploitation A', 'operations', 'day', ['supervisor', 'operator', 'operator', 'operator', 'driver', 'driver', 'driver', 'driver', 'driver', 'driver']],
        ['Exploitation B', 'operations', 'night', ['supervisor', 'operator', 'operator', 'driver', 'driver', 'driver', 'driver']],
    ],
    'transport' => [
        ['Maintenance bus', 'maintenance', 'day', ['team_lead', 'mechanic', 'electrician', 'tyre_fitter']],
        ['Chauffeurs A', 'operations', 'day', ['supervisor', 'driver', 'driver', 'driver', 'driver', 'driver', 'driver']],
        ['Chauffeurs B', 'operations', 'night', ['supervisor', 'driver', 'driver', 'driver', 'driver']],
    ],
];
$personCount = 0;
$n = 0;
foreach ($db->fetchAll("SELECT id, code, activity FROM sites WHERE is_active = 1 AND activity <> 'office'") as $site) {
    if ((int) $db->fetchValue('SELECT COUNT(*) FROM teams WHERE site_id = :s', ['s' => $site['id']]) > 0) {
        continue;
    }
    $drivers = ['day' => [], 'night' => []];
    foreach ($teamsBySite[$site['activity']] ?? [] as $t => [$teamName, $kind, $shift, $jobs]) {
        $teamId = $workforce->createTeam([
            'site_id' => $site['id'], 'name' => $teamName, 'kind' => $kind, 'shift' => $shift, 'is_active' => '1',
            'dispatch_name' => 'Dispatch ' . $site['code'], 'dispatch_phone' => sprintf('+243 97%d %03d %03d', $t, 100 + $site['id'] * 7, 200 + $t * 11),
        ]);
        $leaderId = null;
        foreach ($jobs as $job) {
            $n++;
            $personId = $workforce->createPerson([
                'site_id' => $site['id'], 'team_id' => $teamId, 'job' => $job, 'is_active' => '1',
                'first_name' => $firstNames[($n * 7) % count($firstNames)], 'last_name' => $lastNames[($n * 5) % count($lastNames)],
                'employee_no' => sprintf('PL-%s-%03d', substr($site['code'], 0, 3), $n),
                'phone' => sprintf('+243 8%d %03d %04d', $n % 10, 100 + $n * 3 % 900, 1000 + $n * 37 % 9000),
            ]);
            $personCount++;
            if (in_array($job, ['team_lead', 'supervisor'], true) && $leaderId === null) {
                $leaderId = $personId;
            }
            if (in_array($job, ['driver', 'operator'], true)) {
                $drivers[$shift][] = $personId;
            }
        }
        if ($leaderId !== null) {
            $team = $db->fetchOne('SELECT * FROM teams WHERE id = :id', ['id' => $teamId]);
            $workforce->updateTeam($teamId, ['leader_id' => $leaderId] + $team);
        }
    }
    // Un conducteur de jour (et de nuit si disponible) par engin, dans l'ordre du parc
    $fleetIds = array_map('intval', array_column($db->fetchAll('SELECT id FROM equipment WHERE site_id = :s AND is_archived = 0 ORDER BY code', ['s' => $site['id']]), 'id'));
    foreach (['day', 'night'] as $shift) {
        foreach ($drivers[$shift] as $i => $personId) {
            if (isset($fleetIds[$i])) {
                $workforce->assign($fleetIds[$i], ['personnel_id' => $personId, 'shift' => $shift]);
            }
        }
    }
}

// 6. Intervenants et remarques sur les OT en cours (une seule fois par OT)
$remarks = [
    'Constat à la prise en charge : fuite visible, niveau hydraulique bas. Engin consigné.',
    'Pièce commandée au magasin central, délai annoncé 48 h.',
    'Recommandation : contrôler la pression des pneus à chaque prise de poste sur cette piste.',
];
foreach ($db->fetchAll(
    "SELECT w.id, w.site_id, w.status FROM work_orders w JOIN sites s ON s.id = w.site_id
      WHERE w.status IN ('in_progress', 'waiting_part') AND s.activity <> 'office'
        AND NOT EXISTS (SELECT 1 FROM work_order_technicians t WHERE t.work_order_id = w.id)"
) as $k => $open) {
    $mechanics = $db->fetchAll(
        "SELECT id FROM personnel WHERE site_id = :s AND job IN ('mechanic', 'electrician', 'tyre_fitter') AND is_active = 1 ORDER BY id LIMIT 2",
        ['s' => $open['site_id']]
    );
    foreach ($mechanics as $i => $mechanic) {
        $wo->addTechnician((int) $open['id'], ['personnel_id' => $mechanic['id'], 'minutes' => (string) (45 + 30 * $i + 15 * ($k % 3))]);
    }
    $wo->addComment((int) $open['id'], ['body' => $remarks[0]]);
    if ($open['status'] === 'waiting_part') {
        $wo->addComment((int) $open['id'], ['body' => $remarks[1]]);
    }
}

// 7. Catalogue minier : pièces d'usure, pneus, lubrifiants, filtres, compatibilité et stocks (une seule fois)
$partsCreated = 0;
if ((int) $db->fetchValue("SELECT COUNT(*) FROM parts WHERE reference = 'LUB-15W40'") === 0) {
    $partService = $c->get(\App\Services\PartService::class);
    $stockService = $c->get(\App\Services\StockService::class);
    $models = static function (array $pairs) use ($model): array {
        return array_values(array_filter(array_map(static fn ($p) => (string) $model($p[0], $p[1]), $pairs)));
    };
    $adt = [['Bell', 'B40E'], ['Bell', 'B30E'], ['Volvo', 'A40G']];
    $excavators = [['Komatsu', 'PC350LC-8'], ['Caterpillar', '336D2'], ['Komatsu', 'PC200-8']];
    $cat = [['Caterpillar', 'D8R'], ['Caterpillar', '950H'], ['Caterpillar', '140K'], ['Caterpillar', '336D2']];
    $trucks = [['HOWO', 'ZZ3257 371'], ['Volvo', 'FMX 440 6x4']];
    $buses = [['Yutong', 'ZK6122H9'], ['Tata', 'LPO 1618'], ['Toyota', 'Coaster']];
    $all = array_merge($adt, $excavators, $cat, $trucks, $buses);
    // [réf, désignation, type, fabricant, unité, coût, usure, vie h, vie km, modèles, stock mini terrassement, stock mini bus]
    $catalogue = [
        ['LUB-15W40', 'Huile moteur 15W40', 'lubricant', 'Shell Rimula R4', 'L', 4.8, true, 500, null, $all, 400, 200],
        ['LUB-HYD46', 'Huile hydraulique HV46', 'lubricant', 'Total Equivis', 'L', 4.2, true, 2000, null, array_merge($adt, $excavators, $cat), 600, 0],
        ['LUB-TO4-30', 'Huile transmission TO-4 SAE30', 'lubricant', 'Caterpillar', 'L', 6.5, false, 1000, null, array_merge($adt, $cat), 200, 0],
        ['LUB-80W90', 'Huile de pont 80W90', 'lubricant', 'Shell Spirax', 'L', 5.1, false, 2000, 60000, array_merge($trucks, $buses), 60, 80],
        ['GRS-EP2', 'Graisse lithium EP2', 'lubricant', 'Mobilux', 'kg', 7.9, true, null, null, $all, 120, 30],
        ['FLT-HUI-1R0750', 'Filtre à huile moteur', 'filter', 'Caterpillar 1R-0750', 'u', 38.0, true, 500, null, $cat, 12, 0],
        ['FLT-HUI-KOM', 'Filtre à huile moteur', 'filter', 'Komatsu 6736-51-5142', 'u', 32.0, true, 500, null, $excavators, 10, 0],
        ['FLT-GO-VOE', 'Filtre à gasoil', 'filter', 'Volvo 21380488', 'u', 45.0, true, 500, null, $adt, 12, 0],
        ['FLT-AIR-BUS', 'Filtre à air', 'filter', 'Donaldson P778905', 'u', 62.0, true, 1000, 30000, $buses, 0, 8],
        ['PNEU-29.5R25', 'Pneu 29.5R25 L4', 'tyre', 'Michelin XHA2', 'u', 5800.0, true, 5000, null, array_merge($adt, [['Caterpillar', '950H']]), 6, 0],
        ['PNEU-12R22.5', 'Pneu 12R22.5', 'tyre', 'Bridgestone', 'u', 420.0, true, null, 90000, array_merge($trucks, $buses), 8, 12],
        ['DENT-GODET-J350', 'Dent de godet J350', 'part', 'Caterpillar 1U3352', 'u', 48.0, true, 400, null, array_merge($excavators, [['Caterpillar', '950H']]), 30, 0],
        ['CHAIN-D8R', 'Maillon de chaîne (train de chenilles)', 'part', 'Berco', 'u', 310.0, true, 6000, null, [['Caterpillar', 'D8R']], 4, 0],
        ['PLAQ-FREIN-BUS', 'Jeu de plaquettes de frein', 'part', 'Knorr-Bremse', 'u', 180.0, true, null, 40000, $buses, 0, 10],
        ['GASOIL', 'Gasoil', 'fuel', null, 'L', 1.35, false, null, null, $all, 3000, 1500],
    ];
    $sitesByActivity = $db->fetchAll("SELECT id, activity FROM sites WHERE is_active = 1 AND activity <> 'office'");
    foreach ($catalogue as [$ref, $name, $type, $maker, $unit, $cost, $wear, $lifeH, $lifeKm, $compat, $minEarth, $minBus]) {
        $partId = $partService->create([
            'reference' => $ref, 'name' => $name, 'part_type' => $type, 'manufacturer' => $maker, 'unit' => $unit, 'unit_cost' => (string) $cost,
            'is_wear_part' => $wear ? '1' : '0', 'expected_life_hours' => $lifeH, 'expected_life_km' => $lifeKm,
            'model_ids' => $models($compat), 'is_active' => '1',
        ]);
        $partsCreated++;
        foreach ($sitesByActivity as $k => $site) {
            $min = $site['activity'] === 'transport' ? $minBus : $minEarth;
            if ($min <= 0) {
                continue;
            }
            // Stock initial volontairement contrasté : ruptures, seuils franchis, stocks confortables.
            $initial = [2.6, 1.4, 0.9, 3.0, 0.6][($partsCreated + $k) % 5] * $min;
            $stockService->move($partId, (int) $site['id'], 'in', max(1, round($initial)), 'Stock initial');
            $stockService->updateSettings($partId, ['site_id' => $site['id'], 'min_quantity' => $min, 'location' => 'Magasin ' . ['A', 'B', 'C'][$k % 3] . '-' . (1 + $partsCreated % 9)]);
            // Consommation des 90 derniers jours (sorties datées)
            $available = (float) $db->fetchValue('SELECT quantity FROM part_stocks WHERE part_id = :p AND site_id = :s', ['p' => $partId, 's' => $site['id']]);
            for ($m = 0; $m < 6; $m++) {
                $qty = round($min * [0.18, 0.25, 0.12, 0.3, 0.2, 0.15][$m], $unit === 'u' ? 0 : 1);
                if ($qty <= 0 || $qty > $available - 0.1) {
                    continue;
                }
                $stockService->move($partId, (int) $site['id'], 'out', $qty, 'Consommation atelier');
                $available -= $qty;
                $db->execute('UPDATE stock_movements SET created_at = NOW() - INTERVAL :d DAY ORDER BY id DESC LIMIT 1', ['d' => 5 + $m * 14]);
            }
        }
    }
    // Appoints d'huile et de graisse sur une partie du parc
    $fluidService = $c->get(\App\Services\FluidService::class);
    $oilId = (int) $db->fetchValue("SELECT id FROM parts WHERE reference = 'LUB-15W40'");
    $greaseId = (int) $db->fetchValue("SELECT id FROM parts WHERE reference = 'GRS-EP2'");
    foreach ($db->fetchAll("SELECT e.id, e.code FROM equipment e JOIN sites s ON s.id = e.site_id WHERE e.is_archived = 0 AND s.code IN ('KAMAO', 'CHEMAF', 'SICOMINES') AND e.hour_meter IS NOT NULL ORDER BY e.code LIMIT 14") as $i => $eq) {
        foreach ([[$oilId, 4 + $i % 3 * 3], [$greaseId, 1.5]] as [$fluidPart, $qty]) {
            try {
                $fluidService->log((int) $eq['id'], ['part_id' => $fluidPart, 'quantity' => (string) $qty, 'kind' => 'top_up', 'note' => 'Graissage / appoint de poste']);
                $db->execute('UPDATE equipment SET hour_meter = hour_meter + 400 WHERE id = :id', ['id' => $eq['id']]);
                $fluidService->log((int) $eq['id'], ['part_id' => $fluidPart, 'quantity' => (string) ($qty * ($i % 4 === 0 ? 3 : 1)), 'kind' => 'top_up']);
            } catch (\App\Core\ValidationException) {
                // stock épuisé sur ce site : on passe
            }
        }
    }
}

// 8. Plans préventifs et historique d'interventions clôturées (causes, remplacements) — une seule fois
$history = 0;
if ((int) $db->fetchValue("SELECT COUNT(*) FROM preventive_plans p JOIN sites s ON s.id = p.site_id WHERE s.code = 'KAMAO'") === 0) {
    $preventiveService = $c->get(\App\Services\PreventiveService::class);
    $fleetRows = $db->fetchAll(
        "SELECT e.id, e.code, e.site_id, s.code AS site FROM equipment e JOIN sites s ON s.id = e.site_id
          WHERE e.is_archived = 0 AND s.activity <> 'office' AND s.is_active = 1 ORDER BY s.code, e.code"
    );
    foreach ($fleetRows as $k => $eq) {
        $preventiveService->create([
            'equipment_id' => $eq['id'], 'title' => 'Service 250 h – graissage, niveaux, filtres', 'is_active' => '1',
            'instructions' => "Graissage général.\nContrôle niveaux huile moteur, hydraulique, transmission.\nFiltres selon gamme constructeur.",
            'interval_days' => 14, 'interval_hours' => 250, 'last_done_at' => date('Y-m-d', strtotime('-' . (3 + $k % 13) . ' days')),
        ]);
        if ($k % 3 === 0) {
            $preventiveService->create([
                'equipment_id' => $eq['id'], 'title' => 'Vidange moteur 500 h', 'is_active' => '1',
                'interval_days' => 30, 'interval_hours' => 500, 'last_done_at' => date('Y-m-d', strtotime('-' . (10 + $k % 25) . ' days')),
            ]);
        }
    }

    // Interventions clôturées : causes variées, conducteur en poste, pièces remplacées à compteurs réels
    $closures = [
        ['Crevaison pneu AV droit', 'PNEU-29.5R25', 'misuse', 'Pneu remplacé ; piste à reprofiler.'],
        ['Filtre gasoil colmaté', 'FLT-GO-VOE', 'maintenance_lapse', 'Filtre remplacé, réservoir purgé.'],
        ['Dents de godet cassées', 'DENT-GODET-J350', 'wear', 'Jeu de dents remplacé.'],
        ['Fuite flexible hydraulique', 'LUB-HYD46', 'component_failure', 'Flexible remplacé, appoint huile.'],
        ['Choc contre merlon', null, 'accident', 'Rétroviseur et marchepied remplacés.'],
        ['Plaquettes de frein usées', 'PLAQ-FREIN-BUS', 'wear', 'Plaquettes remplacées AV / AR.'],
    ];
    foreach ($fleetRows as $k => $eq) {
        if ($k % 2 !== 0) {
            continue;
        }
        [$title, $ref, $cause, $resolution] = $closures[$k % count($closures)];
        $partId = $ref !== null ? (int) $db->fetchValue('SELECT id FROM parts WHERE reference = :r', ['r' => $ref]) : 0;
        // Deux remplacements rapprochés sur quelques engins : matière pour les alertes d'usure prématurée
        $repeats = $k % 8 === 0 ? 2 : 1;
        for ($r = 0; $r < $repeats; $r++) {
            try {
                $id = $wo->create(['equipment_id' => $eq['id'], 'title' => $title, 'priority' => $cause === 'accident' ? 'high' : 'normal', 'immobilizing' => '1']);
                $wo->transition($id, ['status' => 'in_progress']);
                if ($partId > 0) {
                    $wo->consumePart($id, ['part_id' => $partId, 'quantity' => $ref === 'LUB-HYD46' ? '20' : '1']);
                }
                $mech = $db->fetchValue("SELECT id FROM personnel WHERE site_id = :s AND job = 'mechanic' ORDER BY id LIMIT 1", ['s' => $eq['site_id']]);
                if ($mech) {
                    $wo->addTechnician($id, ['personnel_id' => $mech, 'minutes' => (string) (60 + 30 * ($k % 4))]);
                }
                $wo->transition($id, ['status' => 'done', 'resolution' => $resolution, 'failure_cause' => $cause]);
                $daysAgo = 3 + ($k * 7) % 80 + $r * 25;
                $db->execute(
                    'UPDATE work_orders SET reported_at = NOW() - INTERVAL :d DAY, started_at = NOW() - INTERVAL :d2 DAY + INTERVAL 2 HOUR,
                            completed_at = NOW() - INTERVAL :d3 DAY + INTERVAL :h HOUR WHERE id = :id',
                    ['d' => $daysAgo, 'd2' => $daysAgo, 'd3' => $daysAgo, 'h' => 6 + $k % 30, 'id' => $id]
                );
                $db->execute('UPDATE work_order_parts SET created_at = NOW() - INTERVAL :d DAY, hour_meter = GREATEST(0, hour_meter - :back) WHERE work_order_id = :id', ['d' => $daysAgo, 'back' => $r === 0 && $repeats === 2 ? 900 : 0, 'id' => $id]);
                $history++;
            } catch (\App\Core\ValidationException) {
                // stock insuffisant sur ce site pour la pièce : on passe
            }
        }
    }
}

// 9. Cas d'école pour les alertes coûts (une seule fois) : pneu remplacé trop tôt, engin qui consomme trop d'huile
if ((int) $db->fetchValue("SELECT COUNT(*) FROM fluid_logs WHERE note = 'Démo alerte'") === 0 && isset($sites['KAMAO'])) {
    $adts = array_map('intval', array_column($db->fetchAll(
        "SELECT e.id FROM equipment e JOIN equipment_models m ON m.id = e.model_id WHERE e.site_id = :s AND m.name = 'B40E' AND e.is_archived = 0 ORDER BY e.code",
        ['s' => $sites['KAMAO']]
    ), 'id'));
    $tyreId = (int) $db->fetchValue("SELECT id FROM parts WHERE reference = 'PNEU-29.5R25'");
    $oilId = (int) $db->fetchValue("SELECT id FROM parts WHERE reference = 'LUB-15W40'");
    $stockService = $c->get(\App\Services\StockService::class);
    $fluidService = $c->get(\App\Services\FluidService::class);
    if (count($adts) >= 3 && $tyreId > 0 && $oilId > 0) {
        $stockService->move($tyreId, $sites['KAMAO'], 'in', 4, 'Réception fournisseur');
        $stockService->move($oilId, $sites['KAMAO'], 'in', 400, 'Réception fournisseur');
        // Pneu : 2 remplacements à 1 100 h d'intervalle (durée attendue 5 000 h)
        foreach ([[-1100, 60], [0, 12]] as [$offset, $daysAgo]) {
            $id = $wo->create(['equipment_id' => $adts[0], 'title' => 'Pneu AR gauche entaillé', 'immobilizing' => '1']);
            $wo->consumePart($id, ['part_id' => $tyreId, 'quantity' => '1']);
            $wo->transition($id, ['status' => 'done', 'resolution' => 'Pneu remplacé.', 'failure_cause' => 'misuse']);
            $db->execute('UPDATE work_order_parts SET hour_meter = hour_meter + :o, created_at = NOW() - INTERVAL :d DAY WHERE work_order_id = :id', ['o' => $offset, 'd' => $daysAgo, 'id' => $id]);
            $db->execute('UPDATE work_orders SET reported_at = NOW() - INTERVAL :d DAY, completed_at = NOW() - INTERVAL :d2 DAY + INTERVAL 9 HOUR WHERE id = :id', ['d' => $daysAgo, 'd2' => $daysAgo, 'id' => $id]);
        }
        // Huile : trois ADT identiques sur 100 h ; le troisième consomme ~3 fois plus
        foreach ([[$adts[0], 3], [$adts[1], 4], [$adts[2], 14]] as [$eqId, $litres]) {
            $fluidService->log($eqId, ['part_id' => $oilId, 'quantity' => '1', 'kind' => 'top_up', 'note' => 'Démo alerte']);
            $db->execute('UPDATE equipment SET hour_meter = hour_meter + 100 WHERE id = :id', ['id' => $eqId]);
            $fluidService->log($eqId, ['part_id' => $oilId, 'quantity' => (string) $litres, 'kind' => 'top_up', 'note' => 'Démo alerte']);
        }
    }
}

echo "Parc minier : $created engin(s) créé(s), " . count($rows) . " fiche(s) complétée(s), $personCount personne(s), $partsCreated article(s), $history intervention(s) historisée(s).\n";
