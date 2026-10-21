<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\ValidationException;
use App\Services\EquipmentService;
use App\Services\PreventiveService;
use App\Services\StockService;
use App\Services\UserService;
use App\Services\WorkOrderService;
use Tests\DatabaseTestCase;

final class StockAndPreventiveTest extends DatabaseTestCase
{
    private int $site;
    private int $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->createSite('LYO');
        $this->manager = $this->createUser('manager', [$this->site]);
    }

    public function testStockMovements(): void
    {
        $part = $this->createPart('HUI-01');
        $stock = $this->service($this->manager, StockService::class);

        self::assertSame(10.0, $stock->move($part, $this->site, 'in', 10, 'Réception'));
        self::assertSame(7.5, $stock->move($part, $this->site, 'out', 2.5));
        self::assertSame(9.0, $stock->move($part, $this->site, 'adjust', 9, 'Inventaire'));

        $movements = $this->db->fetchAll('SELECT type, quantity, stock_after FROM stock_movements ORDER BY id');
        self::assertSame([['in', 10.0, 10.0], ['out', 2.5, 7.5], ['adjust', 1.5, 9.0]], array_map(
            static fn ($m) => [$m['type'], (float) $m['quantity'], (float) $m['stock_after']],
            $movements
        ));
        $log = $this->row("SELECT * FROM activity_log WHERE action = 'stock_adjust'");
        self::assertSame(['quantity' => 7.5], json_decode($log['before_data'], true));
        self::assertSame(9, json_decode($log['after_data'], true)['quantity']);
    }

    public function testStockCannotGoNegativeNorLeaveScope(): void
    {
        $part = $this->createPart('HUI-01');
        $stock = $this->service($this->manager, StockService::class);
        try {
            $stock->move($part, $this->site, 'out', 1);
            self::fail('Stock négatif refusé');
        } catch (ValidationException) {
            self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM stock_movements'));
        }

        $this->expectException(\App\Core\HttpException::class);
        $stock->move($part, $this->createSite('LIL'), 'in', 5);
    }

    public function testPreventivePlanComputesNextDueAndGeneratesWorkOrderOnce(): void
    {
        $equipment = $this->createEquipment($this->site, 'CH-01', 'available', 1200);
        $preventive = $this->service($this->manager, PreventiveService::class);

        $planId = $preventive->create([
            'equipment_id' => (string) $equipment, 'title' => 'Révision', 'interval_days' => '30', 'interval_hours' => '250',
            'last_done_at' => date('Y-m-d', strtotime('-40 days')), 'is_active' => '1',
        ]);
        $plan = $this->row('SELECT * FROM preventive_plans WHERE id = :id', ['id' => $planId]);
        self::assertSame(date('Y-m-d', strtotime('-10 days')), $plan['next_due_date']);
        self::assertSame(1450, (int) $plan['next_due_hours']);
        self::assertSame($this->site, (int) $plan['site_id'], 'Le site du plan est celui de l\'engin');

        $created = $preventive->generateDue();
        self::assertCount(1, $created);
        self::assertSame([], $this->service($this->manager, PreventiveService::class)->generateDue(), 'Pas de doublon tant que l\'OT est ouvert');

        $wo = $this->row('SELECT * FROM work_orders WHERE id = :id', ['id' => $created[0]]);
        self::assertSame(['preventive', $planId, 'Révision'], [$wo['type'], (int) $wo['preventive_plan_id'], $wo['title']]);

        // Réalisation : l'échéance est recalée depuis aujourd'hui et le compteur saisi.
        $this->service($this->manager, WorkOrderService::class)->transition($created[0], ['status' => 'done', 'resolution' => 'RAS', 'hour_meter' => '1300']);
        $plan = $this->row('SELECT * FROM preventive_plans WHERE id = :id', ['id' => $planId]);
        self::assertSame(date('Y-m-d'), $plan['last_done_at']);
        self::assertSame(date('Y-m-d', strtotime('+30 days')), $plan['next_due_date']);
        self::assertSame(1550, (int) $plan['next_due_hours']);
    }

    public function testPlanUpdateLogsOnlyChangedFields(): void
    {
        $equipment = $this->createEquipment($this->site, 'CH-04');
        $preventive = $this->service($this->manager, PreventiveService::class);
        $input = ['equipment_id' => (string) $equipment, 'title' => 'Révision', 'interval_days' => '90', 'is_active' => '1', 'next_due_date' => '2026-12-01'];
        $id = $preventive->create($input);
        $preventive->update($id, ['title' => 'Révision complète'] + $input);

        $log = $this->row("SELECT * FROM activity_log WHERE entity_type = 'preventive_plan' AND action = 'update'");
        self::assertSame(['title' => 'Révision'], json_decode($log['before_data'], true));
        self::assertSame(['title' => 'Révision complète'], json_decode($log['after_data'], true));
    }

    public function testPreventiveTriggeredByHourMeter(): void
    {
        $equipment = $this->createEquipment($this->site, 'CH-02', 'available', 500);
        $preventive = $this->service($this->manager, PreventiveService::class);
        $preventive->create(['equipment_id' => (string) $equipment, 'title' => 'Vidange', 'interval_hours' => '200', 'last_done_hours' => '250', 'is_active' => '1']);
        self::assertCount(1, $preventive->generateDue(), '500 h ≥ 250 + 200 h');
    }

    public function testPreventiveTriggeredByOdometer(): void
    {
        $equipment = $this->createEquipment($this->site, 'BUS-02');
        $this->db->execute("UPDATE equipment SET odometer_km = 61000, meter_basis = 'km' WHERE id = :id", ['id' => $equipment]);
        $preventive = $this->service($this->manager, PreventiveService::class);
        $id = $preventive->create(['equipment_id' => (string) $equipment, 'title' => 'Vidange', 'interval_km' => '10000', 'last_done_km' => '50000', 'is_active' => '1']);
        self::assertSame(60000, (int) $this->db->fetchValue('SELECT next_due_km FROM preventive_plans WHERE id = :id', ['id' => $id]));
        self::assertCount(1, $preventive->generateDue(), '61 000 km ≥ 50 000 + 10 000 km');
    }

    public function testMeterIntervalMustMatchTheTrackingMeter(): void
    {
        $bus = $this->createEquipment($this->site, 'BUS-03');
        $this->db->execute("UPDATE equipment SET meter_basis = 'km' WHERE id = :id", ['id' => $bus]);
        $this->expectException(\App\Core\ValidationException::class);
        $this->service($this->manager, PreventiveService::class)->create([
            'equipment_id' => (string) $bus, 'title' => 'Vidange', 'interval_hours' => '500', 'is_active' => '1',
        ]);
    }

    public function testPreventiveRequiresAnInterval(): void
    {
        $equipment = $this->createEquipment($this->site, 'CH-03');
        $this->expectException(ValidationException::class);
        $this->service($this->manager, PreventiveService::class)->create(['equipment_id' => (string) $equipment, 'title' => 'X', 'is_active' => '1']);
    }

    public function testEquipmentCodeIsUniqueAndUpdatesAreDiffed(): void
    {
        $service = $this->service($this->manager, EquipmentService::class);
        $id = $service->create(['site_id' => (string) $this->site, 'code' => 'ch-10', 'name' => 'Chariot', 'hour_meter' => '10']);
        self::assertSame('CH-10', $this->value('SELECT code FROM equipment WHERE id = :id', ['id' => $id]));

        try {
            $service->create(['site_id' => (string) $this->site, 'code' => 'CH-10', 'name' => 'Doublon']);
            self::fail('Code dupliqué');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('code', $e->errors);
        }

        $service->update($id, ['site_id' => (string) $this->site, 'code' => 'CH-10', 'name' => 'Chariot Linde', 'hour_meter' => '10']);
        $log = $this->row("SELECT * FROM activity_log WHERE action = 'update' AND entity_type = 'equipment'");
        self::assertSame(['name' => 'Chariot'], json_decode($log['before_data'], true));
        self::assertSame(['name' => 'Chariot Linde'], json_decode($log['after_data'], true));

        $service->changeStatus($id, ['status' => 'immobilized', 'reason' => 'VGP non conforme']);
        $log = $this->row("SELECT * FROM activity_log WHERE action = 'status'");
        self::assertSame(['status' => 'available'], json_decode($log['before_data'], true));
        self::assertSame('VGP non conforme', json_decode($log['after_data'], true)['reason']);
    }

    public function testUserServiceHashesPasswordsAndPreventsSelfLockout(): void
    {
        $admin = $this->createUser('admin');
        $users = $this->service($admin, UserService::class);
        $id = $users->create([
            'email' => 'New.Tech@Test.local', 'first_name' => 'N', 'last_name' => 'T', 'role' => 'technician',
            'locale' => 'fr', 'is_active' => '1', 'site_ids' => [(string) $this->site], 'password' => 'long-enough-pass',
        ]);
        $row = $this->row('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        self::assertSame('new.tech@test.local', $row['email']);
        self::assertTrue(password_verify('long-enough-pass', $row['password_hash']));
        $log = $this->row("SELECT after_data FROM activity_log WHERE entity_type = 'user' AND action = 'create'");
        self::assertStringNotContainsString('long-enough-pass', $log['after_data']);

        $this->expectException(ValidationException::class);
        $users->update($admin, [
            'email' => 'admin@test.local', 'first_name' => 'A', 'last_name' => 'B', 'role' => 'manager',
            'locale' => 'fr', 'is_active' => '1', 'all_sites' => '1',
        ]);
    }

    public function testNonAdminUserNeedsAtLeastOneSite(): void
    {
        $admin = $this->createUser('admin');
        $this->expectException(ValidationException::class);
        $this->service($admin, UserService::class)->create([
            'email' => 'x@test.local', 'first_name' => 'X', 'last_name' => 'Y', 'role' => 'technician',
            'locale' => 'fr', 'is_active' => '1', 'password' => 'long-enough-pass',
        ]);
    }
}
