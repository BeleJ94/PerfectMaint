<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\HttpException;
use App\Core\ValidationException;
use App\Services\WorkOrderService;
use Tests\DatabaseTestCase;

final class WorkOrderServiceTest extends DatabaseTestCase
{
    private int $site;
    private int $manager;
    private int $tech;
    private int $equipment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->createSite('LYO');
        $this->manager = $this->createUser('manager', [$this->site]);
        $this->tech = $this->createUser('technician', [$this->site]);
        $this->equipment = $this->createEquipment($this->site, 'CH-01', 'available', 1000);
    }

    private function wo(int $userId): WorkOrderService
    {
        return $this->service($userId, WorkOrderService::class);
    }

    private function equipmentStatus(): string
    {
        return (string) $this->value('SELECT status FROM equipment WHERE id = :id', ['id' => $this->equipment]);
    }

    public function testImmobilizingBreakdownPutsEquipmentDownAndIsLogged(): void
    {
        $id = $this->wo($this->tech)->create(['equipment_id' => $this->equipment, 'title' => 'Fuite', 'immobilizing' => '1', 'priority' => 'critical']);

        $wo = $this->row('SELECT * FROM work_orders WHERE id = :id', ['id' => $id]);
        self::assertMatchesRegularExpression('/^OT-\d{4}-00001$/', $wo['reference']);
        self::assertSame('open', $wo['status']);
        self::assertSame($this->tech, (int) $wo['reported_by']);
        self::assertSame(1000, (int) $wo['hour_meter']);
        self::assertSame('breakdown', $this->equipmentStatus());

        $history = $this->row('SELECT * FROM equipment_status_history WHERE equipment_id = :id ORDER BY id DESC LIMIT 1', ['id' => $this->equipment]);
        self::assertSame(['available', 'breakdown', $id], [$history['from_status'], $history['to_status'], (int) $history['work_order_id']]);

        $log = $this->row("SELECT * FROM activity_log WHERE entity_type = 'work_order' AND entity_id = :id", ['id' => $id]);
        self::assertSame('create', $log['action']);
        self::assertSame($this->tech, (int) $log['user_id']);
        self::assertSame($this->site, (int) $log['site_id']);
        self::assertSame('Fuite', json_decode($log['after_data'], true)['title']);
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM activity_log WHERE entity_type = 'equipment' AND action = 'status'"));
    }

    public function testReferencesAreSequential(): void
    {
        $a = $this->wo($this->tech)->create(['equipment_id' => $this->equipment, 'title' => 'A']);
        $b = $this->wo($this->tech)->create(['equipment_id' => $this->equipment, 'title' => 'B']);
        $refs = array_column($this->db->fetchAll('SELECT reference FROM work_orders WHERE id IN (:a, :b) ORDER BY id', ['a' => $a, 'b' => $b]), 'reference');
        self::assertSame(['00001', '00002'], array_map(static fn ($r) => substr($r, -5), $refs));
    }

    public function testNonImmobilizingWorkOrderLeavesEquipmentAvailable(): void
    {
        $this->wo($this->tech)->create(['equipment_id' => $this->equipment, 'title' => 'Klaxon', 'immobilizing' => '0']);
        self::assertSame('available', $this->equipmentStatus());
    }

    public function testFullLifecycleDrivesEquipmentStatus(): void
    {
        $id = $this->wo($this->tech)->create(['equipment_id' => $this->equipment, 'title' => 'Fuite', 'immobilizing' => '1']);

        $this->wo($this->tech)->transition($id, ['status' => 'in_progress']);
        $wo = $this->row('SELECT * FROM work_orders WHERE id = :id', ['id' => $id]);
        self::assertSame('in_progress', $wo['status']);
        self::assertNotNull($wo['started_at']);
        self::assertSame($this->tech, (int) $wo['assigned_to'], 'Prise en charge automatique');
        self::assertSame('maintenance', $this->equipmentStatus());

        $this->wo($this->tech)->transition($id, ['status' => 'waiting_part']);
        self::assertSame('waiting_part', $this->equipmentStatus());

        $this->wo($this->tech)->transition($id, ['status' => 'done', 'resolution' => 'Joint changé', 'labor_minutes' => '90', 'hour_meter' => '1042']);
        $wo = $this->row('SELECT * FROM work_orders WHERE id = :id', ['id' => $id]);
        self::assertSame(['done', 'Joint changé', 90], [$wo['status'], $wo['resolution'], (int) $wo['labor_minutes']]);
        self::assertNotNull($wo['completed_at']);
        self::assertSame('available', $this->equipmentStatus());
        self::assertSame(1042, (int) $this->value('SELECT hour_meter FROM equipment WHERE id = :id', ['id' => $this->equipment]));

        $transitions = $this->db->fetchAll("SELECT before_data, after_data FROM activity_log WHERE action = 'transition' ORDER BY id");
        self::assertCount(3, $transitions);
        self::assertSame(['status' => 'waiting_part'], array_intersect_key(json_decode($transitions[2]['before_data'], true), ['status' => 1]));
        self::assertSame('done', json_decode($transitions[2]['after_data'], true)['status']);
    }

    public function testHourMeterNeverDecreases(): void
    {
        $id = $this->wo($this->tech)->create(['equipment_id' => $this->equipment, 'title' => 'X']);
        $this->wo($this->tech)->transition($id, ['status' => 'done', 'resolution' => 'ok', 'hour_meter' => '10']);
        self::assertSame(1000, (int) $this->value('SELECT hour_meter FROM equipment WHERE id = :id', ['id' => $this->equipment]));
    }

    public function testClosingOneWorkOrderKeepsStatusOfOtherOpenOnes(): void
    {
        $first = $this->wo($this->tech)->create(['equipment_id' => $this->equipment, 'title' => 'A', 'immobilizing' => '1']);
        $second = $this->wo($this->tech)->create(['equipment_id' => $this->equipment, 'title' => 'B', 'immobilizing' => '1']);
        $this->wo($this->tech)->transition($second, ['status' => 'in_progress']);

        $this->wo($this->manager)->transition($second, ['status' => 'cancelled']);
        self::assertSame('breakdown', $this->equipmentStatus(), 'Le premier OT immobilisant reste ouvert');

        $this->wo($this->tech)->transition($first, ['status' => 'done', 'resolution' => 'ok']);
        self::assertSame('available', $this->equipmentStatus());
    }

    public function testImmobilizedEquipmentIsNotChangedByWorkOrders(): void
    {
        $this->db->execute("UPDATE equipment SET status = 'immobilized' WHERE id = :id", ['id' => $this->equipment]);
        $id = $this->wo($this->tech)->create(['equipment_id' => $this->equipment, 'title' => 'A', 'immobilizing' => '1']);
        $this->wo($this->tech)->transition($id, ['status' => 'done', 'resolution' => 'ok']);
        self::assertSame('immobilized', $this->equipmentStatus());
    }

    public function testInvalidTransitionAndMissingResolutionAreRejected(): void
    {
        $id = $this->wo($this->tech)->create(['equipment_id' => $this->equipment, 'title' => 'A']);
        try {
            $this->wo($this->tech)->transition($id, ['status' => 'done']);
            self::fail('Résolution obligatoire');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('resolution', $e->errors);
        }
        $this->wo($this->tech)->transition($id, ['status' => 'done', 'resolution' => 'ok']);

        $this->expectException(ValidationException::class);
        $this->wo($this->manager)->transition($id, ['status' => 'in_progress']);
    }

    public function testTechnicianCannotCancelNorActOnSomeoneElsesWorkOrder(): void
    {
        $other = $this->createUser('technician', [$this->site]);
        $id = $this->wo($this->manager)->create(['equipment_id' => $this->equipment, 'title' => 'A', 'assigned_to' => (string) $other]);

        try {
            $this->wo($this->tech)->transition($id, ['status' => 'in_progress']);
            self::fail('403 attendu');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        $this->expectExceptionObject(HttpException::forbidden());
        $this->wo($other)->transition($id, ['status' => 'cancelled']);
    }

    public function testTechnicianCanOnlyAssignThemself(): void
    {
        $other = $this->createUser('technician', [$this->site]);
        $id = $this->wo($this->tech)->create(['equipment_id' => $this->equipment, 'title' => 'A', 'assigned_to' => (string) $other]);
        self::assertNull($this->value('SELECT assigned_to FROM work_orders WHERE id = :id', ['id' => $id]));
    }

    public function testManagerCannotAssignSomeoneWithoutSiteAccess(): void
    {
        $outsider = $this->createUser('technician', [$this->createSite('LIL')]);
        $this->expectException(ValidationException::class);
        $this->wo($this->manager)->create(['equipment_id' => $this->equipment, 'title' => 'A', 'assigned_to' => (string) $outsider]);
    }

    public function testCannotCreateOnForeignOrArchivedEquipment(): void
    {
        $foreign = $this->createEquipment($this->createSite('MRS'), 'MRS-1');
        try {
            $this->wo($this->tech)->create(['equipment_id' => $foreign, 'title' => 'A']);
            self::fail('Engin hors périmètre');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('equipment_id', $e->errors);
        }
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM work_orders'));
    }

    public function testConsumingPartsUpdatesStockInOneTransaction(): void
    {
        $part = $this->createPart('FLT-01', 25.0);
        $this->setStock($part, $this->site, 3);
        $id = $this->wo($this->tech)->create(['equipment_id' => $this->equipment, 'title' => 'A']);

        $this->wo($this->tech)->consumePart($id, ['part_id' => (string) $part, 'quantity' => '2']);
        self::assertSame(1.0, (float) $this->value('SELECT quantity FROM part_stocks WHERE part_id = :p', ['p' => $part]));
        $line = $this->row('SELECT * FROM work_order_parts WHERE work_order_id = :id', ['id' => $id]);
        self::assertSame([2.0, 25.0], [(float) $line['quantity'], (float) $line['unit_cost']]);
        $movement = $this->row('SELECT * FROM stock_movements WHERE work_order_id = :id', ['id' => $id]);
        self::assertSame(['out', 2.0, 1.0], [$movement['type'], (float) $movement['quantity'], (float) $movement['stock_after']]);

        try {
            $this->wo($this->tech)->consumePart($id, ['part_id' => (string) $part, 'quantity' => '5']);
            self::fail('Stock insuffisant attendu');
        } catch (ValidationException $e) {
            self::assertStringContainsString('Stock insuffisant', $e->errors['quantity']);
        }
        self::assertSame(1.0, (float) $this->value('SELECT quantity FROM part_stocks WHERE part_id = :p', ['p' => $part]));
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM work_order_parts'), 'Aucune ligne orpheline après rollback');
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM activity_log WHERE action = 'part_consumed'"));
    }

    public function testCannotConsumePartsOnClosedWorkOrder(): void
    {
        $part = $this->createPart('FLT-01');
        $this->setStock($part, $this->site, 3);
        $id = $this->wo($this->tech)->create(['equipment_id' => $this->equipment, 'title' => 'A']);
        $this->wo($this->tech)->transition($id, ['status' => 'done', 'resolution' => 'ok']);

        $this->expectException(ValidationException::class);
        $this->wo($this->tech)->consumePart($id, ['part_id' => (string) $part, 'quantity' => '1']);
    }

    public function testUpdateMakingWorkOrderImmobilizingSetsBreakdown(): void
    {
        $id = $this->wo($this->manager)->create(['equipment_id' => $this->equipment, 'title' => 'A', 'immobilizing' => '0']);
        self::assertSame('available', $this->equipmentStatus());

        $this->wo($this->manager)->update($id, ['title' => 'A bis', 'priority' => 'high', 'immobilizing' => '1']);
        self::assertSame('breakdown', $this->equipmentStatus());
        $log = $this->row("SELECT * FROM activity_log WHERE action = 'update' AND entity_type = 'work_order'");
        self::assertEquals(['title' => 'A', 'priority' => 'normal', 'immobilizing' => 0], json_decode($log['before_data'], true));
    }
}
