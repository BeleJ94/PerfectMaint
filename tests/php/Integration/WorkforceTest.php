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
