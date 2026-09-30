<?php

declare(strict_types=1);

/**
 * Jeu de données de démonstration (base vide uniquement) : php bin/seed.php
 * Passe par les services métier : statuts, historiques et journal sont cohérents.
 */

use App\Core\App;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Security\Auth;
use App\Services\PartService;
use App\Services\PreventiveService;
use App\Services\SiteService;
use App\Services\StockService;
use App\Services\UserService;
use App\Services\EquipmentService;
use App\Services\WorkOrderService;

$config = require dirname(__DIR__) . '/app/bootstrap.php';

$password = $argv[1] ?? 'PerfectMaint2026!';
$db = new Database($config['db']);
if ((int) $db->fetchValue('SELECT COUNT(*) FROM sites') > 0) {
    fwrite(STDERR, "La base contient déjà des données : seed annulé.\n");
    exit(1);
}

$app = App::create($config, Session::memory(), $db, new Request('CLI', '/'));
$c = $app->container();

// 1. Administrateur initial, puis ouverture de session pour attribuer le journal.
$db->insert(
    "INSERT INTO users (email, password_hash, first_name, last_name, role, all_sites) VALUES (:e, :h, 'Claire', 'Admin', 'admin', 1)",
    ['e' => 'admin@perfect-logistics.test', 'h' => password_hash($password, PASSWORD_DEFAULT)]
);
$c->get(Auth::class)->attempt('admin@perfect-logistics.test', $password);

// 2. Sites
$sites = [];
foreach ([['LYO', 'Plateforme Lyon Saint-Priest', 'Saint-Priest'], ['LIL', 'Entrepôt Lille Lesquin', 'Lesquin'], ['MRS', 'Hub Marseille Vitrolles', 'Vitrolles']] as [$code, $name, $city]) {
    $sites[$code] = $c->get(SiteService::class)->create(['code' => $code, 'name' => $name, 'city' => $city, 'is_active' => '1']);
}

// 3. Utilisateurs
$users = $c->get(UserService::class);
$people = [
    ['marc.responsable@perfect-logistics.test', 'Marc', 'Durand', 'manager', [$sites['LYO'], $sites['MRS']]],
    ['sofia.tech@perfect-logistics.test', 'Sofia', 'Martin', 'technician', [$sites['LYO']]],
    ['karim.tech@perfect-logistics.test', 'Karim', 'Benali', 'technician', [$sites['LIL']]],
    ['lea.lecture@perfect-logistics.test', 'Léa', 'Petit', 'viewer', [$sites['LIL']]],
];
$userIds = [];
foreach ($people as [$email, $first, $last, $role, $siteIds]) {
    $userIds[$email] = $users->create([
        'email' => $email, 'first_name' => $first, 'last_name' => $last, 'role' => $role, 'locale' => 'fr',
        'is_active' => '1', 'site_ids' => $siteIds, 'password' => $password,
    ]);
}

// 4. Engins
$categories = array_column($db->fetchAll('SELECT id, code FROM equipment_categories'), 'id', 'code');
$models = [
    ['CHARIOT_FRONTAL', 'Chariot frontal 2,5 t', 'Linde', 'H25D'],
    ['CHARIOT_RETRACT', 'Chariot rétractable 1,6 t', 'Still', 'FM-X 16'],
    ['TRANSPALETTE', 'Transpalette électrique', 'Jungheinrich', 'ERE 225'],
    ['PREPARATEUR', 'Préparateur de commandes', 'Toyota', 'OSE250'],
    ['GERBEUR', 'Gerbeur 1,4 t', 'Linde', 'L14'],
    ['NACELLE', 'Nacelle ciseaux', 'Haulotte', 'Compact 10'],
];
$equipmentService = $c->get(EquipmentService::class);
$equipment = [];
$n = 0;
foreach ($sites as $code => $siteId) {
    for ($i = 1; $i <= 8; $i++) {
        [$cat, $name, $brand, $model] = $models[($i + $n) % count($models)];
        $equipment[$code][] = $equipmentService->create([
            'site_id' => $siteId, 'category_id' => $categories[$cat], 'code' => sprintf('%s-%s-%03d', $code, substr($cat, 0, 3), $i),
            'name' => $name, 'brand' => $brand, 'model' => $model, 'serial_number' => strtoupper(substr(md5($code . $i), 0, 10)),
            'commissioned_at' => date('Y-m-d', strtotime('-' . (200 + 90 * $i) . ' days')), 'hour_meter' => 800 + 437 * $i,
        ]);
    }
    $n++;
}

// 5. Pièces et stocks
$parts = $c->get(PartService::class);
$stock = $c->get(StockService::class);
$catalogue = [
    ['FLT-HYD-01', 'Filtre hydraulique', 'u', 38.50],
    ['FLT-HUI-02', 'Filtre à huile moteur', 'u', 21.90],
    ['HUI-HYD-20', 'Huile hydraulique HV46', 'L', 6.20],
    ['ROU-PU-230', 'Roue porteuse polyuréthane 230 mm', 'u', 142.00],
    ['BAT-24V-01', 'Batterie de traction 24 V', 'u', 1890.00],
    ['FRE-PLQ-01', 'Jeu de plaquettes de frein', 'u', 64.00],
    ['CHN-MAT-01', 'Chaîne de levage mât', 'u', 310.00],
    ['FUS-150A', 'Fusible 150 A', 'u', 7.80],
];
$partIds = [];
foreach ($catalogue as [$ref, $name, $unit, $cost]) {
    $partIds[$ref] = $parts->create(['reference' => $ref, 'name' => $name, 'unit' => $unit, 'unit_cost' => $cost, 'is_active' => '1']);
    foreach ($sites as $siteId) {
        $qty = $unit === 'L' ? 60 : random_int(1, 12);
        $stock->move($partIds[$ref], $siteId, 'in', $qty, 'Stock initial');
        $stock->updateSettings($partIds[$ref], ['site_id' => $siteId, 'min_quantity' => $unit === 'L' ? 40 : 3, 'location' => 'Magasin A' . random_int(1, 9)]);
    }
}

// 6. Plans préventifs (certains déjà à échéance)
$preventive = $c->get(PreventiveService::class);
foreach ($equipment as $code => $ids) {
    foreach ($ids as $k => $equipmentId) {
        $preventive->create([
            'equipment_id' => $equipmentId, 'title' => 'Révision périodique', 'is_active' => '1',
            'instructions' => "Contrôle freins, niveaux, chaînes et fourches.\nGraissage mât. Vérification batterie.",
            'interval_days' => 90, 'interval_hours' => 500,
            'last_done_at' => date('Y-m-d', strtotime('-' . (60 + 7 * $k) . ' days')),
        ]);
        if ($k % 3 === 0) {
            $preventive->create([
                'equipment_id' => $equipmentId, 'title' => 'VGP – Vérification générale périodique', 'is_active' => '1',
                'interval_days' => 180, 'last_done_at' => date('Y-m-d', strtotime('-' . (170 + $k) . ' days')),
            ]);
        }
    }
}

// 7. Ordres de travail dans différents états
$wo = $c->get(WorkOrderService::class);
$sofia = $userIds['sofia.tech@perfect-logistics.test'];
$karim = $userIds['karim.tech@perfect-logistics.test'];
$scenarios = [
    [$equipment['LYO'][0], 'Fuite hydraulique au vérin de levée', 'critical', true, $sofia, []],
    [$equipment['LYO'][1], 'Klaxon inopérant', 'low', false, null, []],
    [$equipment['LYO'][2], 'Roue porteuse usée', 'high', true, $sofia, [['status' => 'in_progress']]],
    [$equipment['LYO'][3], 'Batterie ne tient plus la charge', 'high', true, $sofia, [['status' => 'in_progress'], ['status' => 'waiting_part']]],
    [$equipment['LIL'][0], 'Bruit anormal chaîne de mât', 'normal', true, $karim, [['status' => 'in_progress'], ['status' => 'done', 'resolution' => 'Chaîne retendue et graissée.', 'labor_minutes' => 45]]],
    [$equipment['LIL'][4], 'Écran tableau de bord HS', 'normal', false, $karim, []],
    [$equipment['MRS'][2], 'Freinage faible', 'critical', true, null, []],
];
foreach ($scenarios as [$equipmentId, $title, $priority, $immobilizing, $assignee, $steps]) {
    $id = $wo->create([
        'equipment_id' => $equipmentId, 'title' => $title, 'priority' => $priority, 'type' => 'corrective',
        'immobilizing' => $immobilizing ? '1' : '0', 'assigned_to' => $assignee,
        'due_date' => date('Y-m-d', strtotime($priority === 'critical' ? '-1 day' : '+5 days')),
    ]);
    foreach ($steps as $step) {
        $wo->transition($id, $step);
    }
}
$db->execute("UPDATE work_orders SET reported_at = reported_at - INTERVAL FLOOR(RAND() * 40) DAY");

// 8. OT préventifs à échéance
$created = $preventive->generateDue();
echo "Données de démonstration créées.\n";
echo "  Sites : " . count($sites) . " · Engins : " . array_sum(array_map('count', $equipment)) . " · OT préventifs générés : " . count($created) . "\n";
echo "  Comptes (mot de passe : $password)\n";
echo "    admin@perfect-logistics.test (administrateur)\n";
foreach ($people as [$email, , , $role]) {
    echo "    $email ($role)\n";
}
