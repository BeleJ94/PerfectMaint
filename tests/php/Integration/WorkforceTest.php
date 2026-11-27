<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\HttpException;
use App\Core\Session;
use App\Core\ValidationException;
use App\Repositories\AssignmentRepository;
use App\Repositories\PersonnelRepository;
use App\Services\WorkforceService;
use Tests\DatabaseTestCase;

/** Lot 3 : personnel, équipes par shift, affectations chauffeurs / opérateurs. */
final class WorkforceTest extends DatabaseTestCase
{
    private int $kamao;
    private int $metalkol;
    private int $manager;
    private int $eq1;
    private int $eq2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kamao = $this->createSite('KAMAO');
        $this->metalkol = $this->createSite('METALKOL');
        $this->manager = $this->createUser('manager', [$this->kamao]);
        $this->eq1 = $this->createEquipment($this->kamao, 'KAM-ADT-01');
        $this->eq2 = $this->createEquipment($this->kamao, 'KAM-ADT-02');
    }

    private function workforce(?int $user = null): WorkforceService
    {
        return $this->service($user ?? $this->manager, WorkforceService::class);
    }

    private function person(string $first, string $job, ?int $site = null): int
    {
        return $this->workforce()->createPerson([
            'site_id' => $site ?? $this->kamao, 'first_name' => $first, 'last_name' => 'Mukendi', 'job' => $job,
            'phone' => '+243 970 000 001', 'is_active' => '1',
        ]);
    }

    public function testTeamWithLeaderAndDispatch(): void
    {
        $leader = $this->person('jean', 'team_lead');
        $team = $this->workforce()->createTeam([
            'site_id' => $this->kamao, 'name' => 'Maintenance A', 'kind' => 'maintenance', 'shift' => 'night',
            'leader_id' => $leader, 'dispatch_name' => 'Dispatch fosse', 'dispatch_phone' => '+243 810 111 222', 'is_active' => '1',
        ]);
        self::assertSame($team, (int) $this->value('SELECT team_id FROM personnel WHERE id = :id', ['id' => $leader]), 'Le chef rejoint son équipe');
        $person = $this->row('SELECT first_name, last_name FROM personnel WHERE id = :id', ['id' => $leader]);
        self::assertSame(['Jean', 'MUKENDI'], [$person['first_name'], $person['last_name']]);

        // Un chef d'équipe d'un autre site est refusé ; un nom en double aussi.
        $foreign = $this->db->insert("INSERT INTO personnel (site_id, first_name, last_name, job) VALUES (:s, 'X', 'Y', 'team_lead')", ['s' => $this->metalkol]);
        foreach ([['name' => 'Maintenance B', 'leader_id' => $foreign], ['name' => 'Maintenance A']] as $bad) {
            try {
                $this->workforce()->createTeam($bad + ['site_id' => $this->kamao, 'kind' => 'maintenance', 'shift' => 'day']);
                self::fail('Équipe invalide acceptée');
            } catch (ValidationException) {
                self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM teams'));
            }
        }

        $session = Session::memory(['auth_user_id' => $this->manager, '_csrf_token' => str_repeat('a', 64)]);
        $page = $this->http($session, 'GET', '/teams');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('tel:+243810111222', $page->body);
        self::assertStringContainsString('Maintenance A', $this->http($session, 'GET', "/sites/{$this->kamao}")->body);
    }

    public function testAssignmentsAreExclusivePerShiftAndPerPerson(): void
    {
        $alice = $this->person('alice', 'driver');
        $bob = $this->person('bob', 'operator');
        $wf = $this->workforce();

        $wf->assign($this->eq1, ['personnel_id' => $alice, 'shift' => 'day']);
        $wf->assign($this->eq1, ['personnel_id' => $bob, 'shift' => 'night']);
        self::assertCount(2, $this->service($this->manager, AssignmentRepository::class)->current($this->eq1), 'Un conducteur par shift');

        // Bob passe de jour sur l'engin 1 : Alice (jour) est relevée, l'affectation de nuit de Bob aussi.
        $wf->assign($this->eq1, ['personnel_id' => $bob, 'shift' => 'day']);
        $current = $this->service($this->manager, AssignmentRepository::class)->current($this->eq1);
        self::assertSame([['bob', 'day']], array_map(static fn ($a) => [strtolower(explode(' ', $a['name'])[0]), $a['shift']], $current));

        // Alice prend l'engin 2 : visible dans la liste du personnel.
        $wf->assign($this->eq2, ['personnel_id' => $alice, 'shift' => 'day']);
        $dt = \App\Core\DataTableRequest::fromQuery(['length' => '50'], PersonnelRepository::SORTABLE, ['p.last_name', 'ASC']);
        $rows = array_column($this->service($this->manager, PersonnelRepository::class)->datatable($dt, [])['data'], 'equipment', 'first_name');
        self::assertSame('KAM-ADT-02', $rows['Alice']);

        // L'opérateur au moment T est retrouvé (pour rattacher une panne).
        $operator = $this->service($this->manager, AssignmentRepository::class)->operatorAt($this->eq2, date('Y-m-d H:i:s', time() + 5));
        self::assertSame($alice, (int) $operator['personnel_id']);

        // Désactiver Alice clôt son affectation.
        $wf->updatePerson($alice, ['site_id' => $this->kamao, 'first_name' => 'Alice', 'last_name' => 'Mukendi', 'job' => 'driver', 'is_active' => '0']);
        self::assertSame([], $this->service($this->manager, AssignmentRepository::class)->current($this->eq2));
        self::assertSame(4, (int) $this->value("SELECT COUNT(*) FROM activity_log WHERE action = 'assign'"));
    }

    public function testOnlyActiveOperatorsOfTheSameSiteCanBeAssigned(): void
    {
        $mechanic = $this->person('marc', 'mechanic');
        $foreignDriver = $this->db->insert("INSERT INTO personnel (site_id, first_name, last_name, job) VALUES (:s, 'Paul', 'K', 'driver')", ['s' => $this->metalkol]);
        foreach ([$mechanic, $foreignDriver] as $personId) {
            try {
                $this->workforce()->assign($this->eq1, ['personnel_id' => $personId, 'shift' => 'day']);
                self::fail('Affectation invalide acceptée');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('personnel_id', $e->errors);
            }
        }
        $other = $this->createEquipment($this->metalkol, 'MET-01');
        $this->expectException(HttpException::class);
        $this->workforce()->assign($other, ['personnel_id' => $foreignDriver, 'shift' => 'day']);
    }

    public function testDispatcherRunsOperationsButNotMaintenanceOrStock(): void
    {
        $csrf = ['_csrf' => str_repeat('a', 64)];
        $session = Session::memory(['auth_user_id' => $this->createUser('dispatcher', [$this->kamao]), '_csrf_token' => $csrf['_csrf']]);
        $driver = $this->person('lina', 'driver');

        // Exploitation : équipes, personnel, affectation d'un conducteur, signalement d'une panne, saisie des index.
        foreach (['/', "/sites/{$this->kamao}", '/alerts', '/planning', '/equipment', "/equipment/{$this->eq1}", '/documents', '/meters',
            '/work-orders', '/work-orders/create', '/preventive', '/teams', '/teams/create', '/personnel', "/personnel/$driver/edit", '/saisie'] as $path) {
            self::assertSame(200, $this->http($session, 'GET', $path)->status, $path);
        }
        self::assertSame(302, $this->http($session, 'POST', "/equipment/{$this->eq1}/assignments", $csrf + ['personnel_id' => $driver, 'shift' => 'day'])->status);
        self::assertSame($driver, (int) $this->value('SELECT personnel_id FROM equipment_assignments WHERE equipment_id = :e AND ended_at IS NULL', ['e' => $this->eq1]));
        self::assertSame(302, $this->http($session, 'POST', '/work-orders', $csrf + ['equipment_id' => $this->eq1, 'title' => 'Fuite hydraulique', 'immobilizing' => '1'])->status);
        $wo = (int) $this->value('SELECT id FROM work_orders WHERE equipment_id = :e', ['e' => $this->eq1]);
        self::assertGreaterThan(0, $wo);

        // Gestion complète de l'entretien : modifier, prendre en charge, commenter et clôturer l'OT ; créer un plan préventif.
        foreach (["/work-orders/$wo", "/work-orders/$wo/edit", '/preventive/create', '/work-orders/assignees?equipment_id=' . $this->eq1] as $path) {
            self::assertSame(200, $this->http($session, 'GET', $path)->status, $path);
        }
        self::assertStringContainsString("/work-orders/$wo/transition", $this->http($session, 'GET', "/work-orders/$wo")->body);
        foreach (["/work-orders/$wo" => ['equipment_id' => $this->eq1, 'title' => 'Fuite hydraulique vérin', 'priority' => 'high', 'immobilizing' => '1'],
            "/work-orders/$wo/comments" => ['body' => 'Flexible commandé'],
            "/work-orders/$wo/transition" => ['status' => 'in_progress'],
            '/preventive' => ['equipment_id' => $this->eq1, 'title' => 'Vidange', 'interval_days' => 90, 'is_active' => '1']] as $path => $body) {
            self::assertSame(302, $this->http($session, 'POST', $path, $csrf + $body)->status, $path);
        }
        self::assertSame(302, $this->http($session, 'POST', "/work-orders/$wo/transition", $csrf + ['status' => 'done', 'resolution' => 'Flexible remplacé', 'labor_minutes' => 60])->status);
        self::assertSame(['Fuite hydraulique vérin', 'high', 'done'], array_values($this->row('SELECT title, priority, status FROM work_orders WHERE id = :id', ['id' => $wo])));
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM preventive_plans WHERE equipment_id = :e', ['e' => $this->eq1]));

        // État manuel d'un engin et relevé depuis sa fiche.
        self::assertSame(302, $this->http($session, 'POST', "/equipment/{$this->eq2}/status", $csrf + ['status' => 'immobilized', 'reason' => 'Attente conducteur'])->status);
        self::assertSame(302, $this->http($session, 'POST', "/equipment/{$this->eq2}/readings", $csrf + ['hour_meter' => 150])->status);
        self::assertSame(['immobilized', 150], array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, array_values($this->row('SELECT status, hour_meter FROM equipment WHERE id = :id', ['id' => $this->eq2]))));

        // Documents des engins : ajout ouvert au dispatch.
        self::assertSame(200, $this->http($session, 'GET', '/documents/create')->status);
        self::assertStringContainsString('/documents/create', $this->http($session, 'GET', '/documents')->body);

        // Ni stock, ni rapports, ni administration, ni création d'engin, ni suppression de plan.
        $plan = (int) $this->value('SELECT id FROM preventive_plans WHERE equipment_id = :e', ['e' => $this->eq1]);
        foreach (['/parts', '/procurement', '/reports', '/terrain', '/activity', '/users', '/catalog', '/equipment/create', '/readings'] as $path) {
            self::assertSame(403, $this->http($session, 'GET', $path)->status, $path);
        }
        self::assertSame(403, $this->http($session, 'POST', "/preventive/$plan/delete", $csrf)->status);
        // Hors périmètre : un engin d'un autre site reste invisible.
        self::assertSame(404, $this->http($session, 'GET', '/equipment/' . $this->createEquipment($this->metalkol, 'MET-09'))->status);
        // Les pages ouvertes ne proposent aucun lien vers le stock.
        foreach (['/', "/sites/{$this->kamao}", '/alerts', "/equipment/{$this->eq1}"] as $path) {
            self::assertStringNotContainsString('/procurement', $this->http($session, 'GET', $path)->body, $path);
        }
    }

    public function testTechnicianCanViewButNotManageOrAssign(): void
    {
        $tech = $this->createUser('technician', [$this->kamao]);
        $session = Session::memory(['auth_user_id' => $tech, '_csrf_token' => str_repeat('a', 64)]);
        self::assertSame(200, $this->http($session, 'GET', '/teams')->status);
        self::assertSame(200, $this->http($session, 'GET', '/personnel')->status);
        self::assertSame(403, $this->http($session, 'GET', '/teams/create')->status);
        $driver = $this->person('lina', 'driver');
        $res = $this->http($session, 'POST', "/equipment/{$this->eq1}/assignments", ['_csrf' => str_repeat('a', 64), 'personnel_id' => $driver, 'shift' => 'day']);
        self::assertSame(403, $res->status);

        $mgr = Session::memory(['auth_user_id' => $this->manager, '_csrf_token' => str_repeat('a', 64)]);
        foreach (['/personnel/create', "/personnel/$driver/edit", '/teams/create', "/equipment/{$this->eq1}"] as $path) {
            self::assertSame(200, $this->http($mgr, 'GET', $path)->status, $path);
        }
        $json = $this->http($mgr, 'GET', '/personnel/data?draw=1', [], ['X-Requested-With' => 'XMLHttpRequest']);
        self::assertSame(1, json_decode($json->body, true)['recordsTotal']);
    }
}
