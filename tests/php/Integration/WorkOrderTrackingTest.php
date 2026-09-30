<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\HttpException;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\WorkOrderService;
use App\Services\WorkforceService;
use Tests\DatabaseTestCase;

/** Lot 4 : opérateur au signalement, cause de panne, intervenants, remarques, compteurs des pièces. */
final class WorkOrderTrackingTest extends DatabaseTestCase
{
    private int $site;
    private int $manager;
    private int $tech;
    private int $equipment;
    private int $driver;
    private int $mechanic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->createSite('KAMAO');
        $this->manager = $this->createUser('manager', [$this->site]);
        $this->tech = $this->createUser('technician', [$this->site]);
        $this->equipment = $this->createEquipment($this->site, 'KAM-BEN-01', 'available', 5000);
        $this->db->execute('UPDATE equipment SET odometer_km = 120000 WHERE id = :id', ['id' => $this->equipment]);
        $wf = $this->service($this->manager, WorkforceService::class);
        $this->driver = $wf->createPerson(['site_id' => $this->site, 'first_name' => 'Papy', 'last_name' => 'Ilunga', 'job' => 'driver', 'is_active' => '1']);
        $this->mechanic = $wf->createPerson(['site_id' => $this->site, 'first_name' => 'Serge', 'last_name' => 'Banza', 'job' => 'mechanic', 'is_active' => '1']);
        $wf->assign($this->equipment, ['personnel_id' => $this->driver, 'shift' => 'day']);
        $this->db->execute('UPDATE equipment_assignments SET started_at = NOW() - INTERVAL 2 HOUR');
    }

    private function wo(int $user): WorkOrderService
    {
        return $this->service($user, WorkOrderService::class);
    }

    public function testBreakdownCapturesOperatorAndMeters(): void
    {
        $id = $this->wo($this->tech)->create(['equipment_id' => $this->equipment, 'title' => 'Frein de parc HS', 'immobilizing' => '1']);
        $row = $this->row('SELECT operator_id, operator_name, hour_meter, odometer_km FROM work_orders WHERE id = :id', ['id' => $id]);
        self::assertSame([$this->driver, 'Papy ILUNGA', 5000, 120000], [(int) $row['operator_id'], $row['operator_name'], (int) $row['hour_meter'], (int) $row['odometer_km']]);
    }

    public function testClosureRecordsCauseMetersAndLaborFromTechnicians(): void
    {
        $service = $this->wo($this->tech);
        $id = $service->create(['equipment_id' => $this->equipment, 'title' => 'Pneu éclaté', 'immobilizing' => '1']);
        $service->transition($id, ['status' => 'in_progress']);
        $service->addTechnician($id, ['personnel_id' => $this->mechanic, 'minutes' => '45']);
        $service->addTechnician($id, ['personnel_id' => $this->mechanic, 'minutes' => '30']);
        self::assertSame(75, (int) $this->value('SELECT minutes FROM work_order_technicians WHERE work_order_id = :id', ['id' => $id]), 'Minutes cumulées');

        $partId = $this->createPart('PNEU-2900', 2400.0);
        $this->setStock($partId, $this->site, 4);
        $service->consumePart($id, ['part_id' => $partId, 'quantity' => '1']);
        $part = $this->row('SELECT hour_meter, odometer_km FROM work_order_parts WHERE work_order_id = :id', ['id' => $id]);
        self::assertSame([5000, 120000], [(int) $part['hour_meter'], (int) $part['odometer_km']], 'Compteurs au remplacement');

        $service->transition($id, ['status' => 'done', 'resolution' => 'Pneu remplacé', 'failure_cause' => 'misuse', 'hour_meter' => '5012', 'odometer_km' => '120350']);
        $wo = $this->row('SELECT failure_cause, labor_minutes, status FROM work_orders WHERE id = :id', ['id' => $id]);
        self::assertSame(['misuse', 75, 'done'], [$wo['failure_cause'], (int) $wo['labor_minutes'], $wo['status']]);
        $eq = $this->row('SELECT hour_meter, odometer_km FROM equipment WHERE id = :id', ['id' => $this->equipment]);
        self::assertSame([5012, 120350], [(int) $eq['hour_meter'], (int) $eq['odometer_km']]);
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM meter_readings WHERE equipment_id = :id', ['id' => $this->equipment]), 'Relevé historisé à la clôture');

        try {
            $service->transition($this->wo($this->tech)->create(['equipment_id' => $this->equipment, 'title' => 'X']), ['status' => 'done', 'resolution' => 'ok', 'failure_cause' => 'bad']);
            self::fail('Cause inconnue acceptée');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('failure_cause', $e->errors);
        }
    }

    public function testTechniciansMustBelongToTheSiteAndCanBeRemoved(): void
    {
        $service = $this->wo($this->manager);
        $id = $service->create(['equipment_id' => $this->equipment, 'title' => 'Diagnostic']);
        $other = $this->createSite('METALKOL');
        $foreign = $this->db->insert("INSERT INTO personnel (site_id, first_name, last_name, job) VALUES (:s, 'A', 'B', 'mechanic')", ['s' => $other]);
        try {
            $service->addTechnician($id, ['personnel_id' => $foreign]);
            self::fail('Intervenant d\'un autre site accepté');
        } catch (ValidationException) {
            self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM work_order_technicians'));
        }
        $service->addTechnician($id, ['personnel_id' => $this->mechanic, 'minutes' => '20']);
        $techId = (int) $this->value('SELECT id FROM work_order_technicians');
        $service->removeTechnician($id, $techId);
        self::assertNull($this->value('SELECT labor_minutes FROM work_orders WHERE id = :id', ['id' => $id]));
        $this->expectException(HttpException::class);
        $service->removeTechnician($id, $techId);
    }

    public function testCommentsThreadAndPermissions(): void
    {
        $id = $this->wo($this->tech)->create(['equipment_id' => $this->equipment, 'title' => 'Bruit boîte']);
        $session = Session::memory(['auth_user_id' => $this->tech, '_csrf_token' => str_repeat('a', 64)]);
        $res = $this->http($session, 'POST', "/work-orders/$id/comments", ['_csrf' => str_repeat('a', 64), 'body' => "Jeu anormal sur l'arbre <b>secondaire</b>"]);
        self::assertSame(302, $res->status);
        $page = $this->http($session, 'GET', "/work-orders/$id");
        self::assertSame(200, $page->status);
        self::assertStringContainsString('&lt;b&gt;secondaire&lt;/b&gt;', $page->body, 'Remarque échappée');
        self::assertStringContainsString('Papy ILUNGA', $page->body);
        self::assertSame(200, $this->http($session, 'GET', "/terrain/ot/$id")->status);

        $viewer = $this->createUser('viewer', [$this->site]);
        $viewerSession = Session::memory(['auth_user_id' => $viewer, '_csrf_token' => str_repeat('a', 64)]);
        self::assertSame(403, $this->http($viewerSession, 'POST', "/work-orders/$id/comments", ['_csrf' => str_repeat('a', 64), 'body' => 'x'])->status);
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM work_order_comments'));
    }
}
