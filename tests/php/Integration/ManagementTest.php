<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Session;
use App\Repositories\AlertRepository;
use App\Repositories\ManagementRepository;
use App\Services\FluidService;
use App\Services\PartService;
use App\Services\WorkOrderService;
use Tests\DatabaseTestCase;

/** Lot 6 : alertes coût (usure prématurée, surconsommation), planning, rapports de direction. */
final class ManagementTest extends DatabaseTestCase
{
    private int $site;
    private int $manager;
    private int $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->createSite('KAMAO');
        $this->manager = $this->createUser('manager', [$this->site]);
        $this->model = (int) $this->value("SELECT m.id FROM equipment_models m WHERE m.name = 'B40E'");
    }

    private function equipmentOfModel(string $code, int $hours): int
    {
        $id = $this->createEquipment($this->site, $code, 'available', $hours);
        $this->db->execute('UPDATE equipment SET model_id = :m WHERE id = :id', ['m' => $this->model, 'id' => $id]);

        return $id;
    }

    /** Remplacement d'une pièce sur OT à un compteur donné. */
    private function replace(int $equipment, int $part, int $hours): void
    {
        $this->db->execute('UPDATE equipment SET hour_meter = :h WHERE id = :id', ['h' => $hours, 'id' => $equipment]);
        $wo = $this->service($this->manager, WorkOrderService::class);
        $id = $wo->create(['equipment_id' => $equipment, 'title' => 'Remplacement']);
        $wo->consumePart($id, ['part_id' => $part, 'quantity' => '1']);
        $wo->transition($id, ['status' => 'done', 'resolution' => 'OK', 'failure_cause' => 'wear']);
    }

    public function testPrematureWearIsDetectedAgainstExpectedLife(): void
    {
        $parts = $this->service($this->manager, PartService::class);
        $tyre = $parts->create(['reference' => 'PNEU', 'name' => 'Pneu', 'unit' => 'u', 'part_type' => 'tyre', 'expected_life_hours' => '4000', 'is_active' => '1']);
        $filter = $parts->create(['reference' => 'FLT', 'name' => 'Filtre', 'unit' => 'u', 'part_type' => 'filter', 'expected_life_hours' => '500', 'is_active' => '1']);
        $this->setStock($tyre, $this->site, 10);
        $this->setStock($filter, $this->site, 10);
        $adt = $this->equipmentOfModel('ADT-1', 1000);

        $this->replace($adt, $tyre, 1000);
        $this->replace($adt, $tyre, 2500);   // 1 500 h sur 4 000 attendues → 38 % : prématuré
        $this->replace($adt, $filter, 2500);
        $this->replace($adt, $filter, 3000); // 500 h : durée nominale, pas d'alerte

        $alerts = $this->service($this->manager, AlertRepository::class)->prematureWear();
        self::assertCount(1, $alerts);
        self::assertSame(['PNEU', 1500, 38], [$alerts[0]['reference'], (int) $alerts[0]['interval_hours'], (int) $alerts[0]['life_pct']]);
    }

    public function testLubricantOveruseComparesUnitsOfTheSameModel(): void
    {
        $oil = $this->service($this->manager, PartService::class)->create(['reference' => 'HUI', 'name' => 'Huile', 'unit' => 'L', 'part_type' => 'lubricant', 'is_active' => '1']);
        $this->setStock($oil, $this->site, 1000);
        $fluids = $this->service($this->manager, FluidService::class);
        $rates = ['ADT-A' => 4, 'ADT-B' => 5, 'ADT-C' => 18]; // litres sur 100 h
        foreach ($rates as $code => $litres) {
            $eq = $this->equipmentOfModel($code, 1000);
            $fluids->log($eq, ['part_id' => $oil, 'quantity' => '1', 'kind' => 'top_up']);
            $this->db->execute('UPDATE equipment SET hour_meter = 1100 WHERE id = :id', ['id' => $eq]);
            $fluids->log($eq, ['part_id' => $oil, 'quantity' => (string) ($litres - 1), 'kind' => 'top_up']);
        }
        $alerts = $this->service($this->manager, AlertRepository::class)->lubricantOveruse();
        self::assertSame(['ADT-C'], array_column($alerts, 'equipment_code'));
        self::assertSame(18.0, $alerts[0]['rate']);
        self::assertEqualsWithDelta(9.0, $alerts[0]['fleet_rate'], 0.01);
        self::assertSame(100, $alerts[0]['excess_pct']);
    }

    public function testPlanningPayloadCarriesDetailsAndEditLinksByPermission(): void
    {
        $eq = $this->equipmentOfModel('ADT-7', 800);
        $plan = $this->db->insert(
            'INSERT INTO preventive_plans (site_id, equipment_id, title, interval_days, interval_hours, next_due_date) VALUES (:s, :e, \'Service 250 h\', 14, 250, CURDATE())',
            ['s' => $this->site, 'e' => $eq]
        );
        $payload = function (int $user): array {
            $body = $this->http(Session::memory(['auth_user_id' => $user]), 'GET', '/planning')->body;
            preg_match('#id="planning-data">(.*?)</script>#s', $body, $m);

            return json_decode(html_entity_decode($m[1]), true);
        };

        $data = $payload($this->manager);
        self::assertCount(7, $data['days'], 'Semaine complète');
        $ev = $data['events'][0];
        self::assertSame(['preventive', 'Service 250 h', 'ADT-7'], [$ev['kind'], $ev['title'], $ev['code']]);
        self::assertStringEndsWith("/preventive/$plan/edit", $ev['edit']);
        self::assertStringEndsWith("/equipment/$eq", $ev['view']);
        self::assertContains('tous les 14 j / toutes les 250 h', array_column($ev['fields'], 1));
        self::assertArrayHasKey('short', $data['labels']);

        $viewer = $this->createUser('viewer', [$this->site]);
        self::assertNull($payload($viewer)['events'][0]['edit'], 'Pas de bouton Modifier sans droit');
    }

    public function testReportExportPayloadIsExecutiveAndScoped(): void
    {
        $eq = $this->equipmentOfModel('ADT-3', 500);
        $wo = $this->service($this->manager, WorkOrderService::class);
        $id = $wo->create(['equipment_id' => $eq, 'title' => 'Choc', 'immobilizing' => '1']);
        $wo->transition($id, ['status' => 'done', 'resolution' => 'OK', 'failure_cause' => 'misuse']);

        $body = $this->http(Session::memory(['auth_user_id' => $this->manager]), 'GET', '/reports?days=90')->body;
        self::assertStringContainsString('data-report-export="excel"', $body);
        self::assertStringContainsString('data-report-export="pdf"', $body);
        self::assertStringNotContainsString('data-print', $body, 'Impression remplacée par les exports');
        preg_match('#id="report-export">(.*?)</script>#s', $body, $m);
        $p = json_decode(html_entity_decode($m[1]), true);

        self::assertCount(6, $p['kpis']);
        self::assertCount(11, $p['labels']['cols_site']);
        self::assertSame('Site KAMAO', $p['sites'][0][0]);
        self::assertSame([1, 1], [$p['sites'][0][1], $p['sites'][0][3]], 'Parc et pannes du site');
        self::assertEquals([['Mauvaise utilisation', 1, 100]], $p['causes']);
        self::assertStringContainsString('90', $p['labels']['subtitle']);
        self::assertSame(1, $p['kpis'][5]['value'], 'Pannes liées à l\'utilisation');
    }

    public function testPlanningEventsAndReports(): void
    {
        $eq = $this->equipmentOfModel('ADT-9', 500);
        $this->db->execute("UPDATE equipment SET ownership = 'rental', contract_ref = 'LOC-1', contract_end = CURDATE() + INTERVAL 2 DAY WHERE id = :id", ['id' => $eq]);
        $wo = $this->service($this->manager, WorkOrderService::class)->create(['equipment_id' => $eq, 'title' => 'Vérin', 'due_date' => date('Y-m-d', strtotime('+1 day'))]);
        $this->db->insert(
            'INSERT INTO preventive_plans (site_id, equipment_id, title, interval_days, next_due_date) VALUES (:s, :e, \'Vidange 500 h\', 30, CURDATE())',
            ['s' => $this->site, 'e' => $eq]
        );
        $events = $this->service($this->manager, ManagementRepository::class)->events(date('Y-m-d'), date('Y-m-d', strtotime('+6 days')));
        self::assertSame(['preventive', 'work_order', 'contract'], array_column($events, 'kind'));
        self::assertSame($wo, $events[1]['id']);

        $session = Session::memory(['auth_user_id' => $this->manager, '_csrf_token' => str_repeat('a', 64)]);
        foreach (['/alerts', '/planning', '/planning?view=month', '/planning?view=day&date=' . date('Y-m-d'), '/reports', '/reports?days=90'] as $path) {
            $res = $this->http($session, 'GET', $path);
            self::assertSame(200, $res->status, $path);
        }
        self::assertStringContainsString('Vidange 500 h', $this->http($session, 'GET', '/planning')->body);
        self::assertStringContainsString('KAMAO', $this->http($session, 'GET', '/reports')->body);

        // Les rapports sont réservés au management (pas aux techniciens).
        $tech = $this->createUser('technician', [$this->site]);
        $techSession = Session::memory(['auth_user_id' => $tech, '_csrf_token' => str_repeat('a', 64)]);
        self::assertSame(403, $this->http($techSession, 'GET', '/reports')->status);
        self::assertSame(200, $this->http($techSession, 'GET', '/planning')->status);
        $viewer = $this->createUser('viewer', [$this->site]);
        self::assertSame(200, $this->http(Session::memory(['auth_user_id' => $viewer]), 'GET', '/reports')->status);
    }
}
