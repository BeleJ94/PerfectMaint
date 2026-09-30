<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\DataTableRequest;
use App\Core\Session;
use App\Core\ValidationException;
use App\Repositories\FluidRepository;
use App\Repositories\PartRepository;
use App\Repositories\ProcurementRepository;
use App\Services\FluidService;
use App\Services\PartService;
use App\Services\StockService;
use Tests\DatabaseTestCase;

/** Lot 5 : typologie des articles, compatibilité, appoints de fluides, besoins d'achat. */
final class PartsConsumablesTest extends DatabaseTestCase
{
    private int $kamao;
    private int $sicomines;
    private int $manager;
    private int $tech;
    private int $equipment;
    private int $modelB40;
    private int $modelPc350;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kamao = $this->createSite('KAMAO');
        $this->sicomines = $this->createSite('SICOMINES');
        $this->manager = $this->createUser('manager', [$this->kamao, $this->sicomines]);
        $this->tech = $this->createUser('technician', [$this->kamao]);
        $this->modelB40 = (int) $this->value("SELECT m.id FROM equipment_models m JOIN equipment_brands b ON b.id = m.brand_id WHERE b.name = 'Bell' AND m.name = 'B40E'");
        $this->modelPc350 = (int) $this->value("SELECT m.id FROM equipment_models m JOIN equipment_brands b ON b.id = m.brand_id WHERE m.name = 'PC350LC-8'");
        $this->equipment = $this->createEquipment($this->kamao, 'KAM-ADT-01', 'available', 7000);
        $this->db->execute('UPDATE equipment SET model_id = :m, odometer_km = 50000 WHERE id = :id', ['m' => $this->modelB40, 'id' => $this->equipment]);
    }

    private function part(array $data): int
    {
        return $this->service($this->manager, PartService::class)->create($data + ['unit' => 'u', 'unit_cost' => '10', 'is_active' => '1']);
    }

    public function testTypologyAndCompatibility(): void
    {
        $tyre = $this->part([
            'reference' => 'pn-2900r49', 'name' => 'Pneu 29.5R25', 'part_type' => 'tyre', 'manufacturer' => 'Michelin',
            'is_wear_part' => '1', 'expected_life_hours' => '4500', 'model_ids' => [(string) $this->modelB40, '', (string) $this->modelPc350],
        ]);
        $row = $this->row('SELECT reference, part_type, is_wear_part, expected_life_hours FROM parts WHERE id = :id', ['id' => $tyre]);
        self::assertSame(['PN-2900R49', 'tyre', 1, 4500], [$row['reference'], $row['part_type'], (int) $row['is_wear_part'], (int) $row['expected_life_hours']]);

        $repo = $this->service($this->manager, PartRepository::class);
        self::assertCount(2, $repo->compatibleModels($tyre));
        self::assertSame(['PN-2900R49'], array_column($repo->forModel($this->modelB40, $this->kamao), 'reference'));

        // Mise à jour : compatibilité remplacée ; filtre par modèle dans la liste.
        $this->service($this->manager, PartService::class)->update($tyre, [
            'reference' => 'PN-2900R49', 'name' => 'Pneu 29.5R25', 'unit' => 'u', 'part_type' => 'tyre', 'model_ids' => [(string) $this->modelPc350], 'is_active' => '1',
        ]);
        self::assertSame([], $repo->forModel($this->modelB40, $this->kamao));
        $dt = DataTableRequest::fromQuery(['length' => '50'], PartRepository::SORTABLE, ['p.reference', 'ASC']);
        self::assertSame(1, $repo->datatable($dt, ['model_id' => $this->modelPc350])['recordsFiltered']);
        self::assertSame(0, $repo->datatable($dt, ['part_type' => 'lubricant'])['recordsFiltered']);
    }

    public function testFluidTopUpIssuesStockAndComputesRate(): void
    {
        $oil = $this->part(['reference' => 'HUI-15W40', 'name' => 'Huile moteur 15W40', 'part_type' => 'lubricant', 'unit' => 'L', 'model_ids' => [(string) $this->modelB40]]);
        $this->setStock($oil, $this->kamao, 50, 20);
        $fluids = $this->service($this->tech, FluidService::class);

        $fluids->log($this->equipment, ['part_id' => $oil, 'quantity' => '4', 'kind' => 'top_up']);
        $this->db->execute('UPDATE equipment SET hour_meter = 7200 WHERE id = :id', ['id' => $this->equipment]);
        $fluids->log($this->equipment, ['part_id' => $oil, 'quantity' => '6', 'kind' => 'top_up', 'note' => 'Niveau bas']);

        self::assertSame(40.0, (float) $this->value('SELECT quantity FROM part_stocks WHERE part_id = :p AND site_id = :s', ['p' => $oil, 's' => $this->kamao]));
        self::assertSame(2, (int) $this->value('SELECT COUNT(*) FROM stock_movements WHERE equipment_id = :e AND type = \'out\'', ['e' => $this->equipment]));
        $rates = $this->service($this->tech, FluidRepository::class)->ratesForEquipment($this->equipment);
        self::assertSame(10.0, (float) $rates[0]['quantity']);
        self::assertSame(5.0, $rates[0]['rate'], '10 L sur 200 h = 5 L / 100 h');

        // Pas de stock négatif ; une pièce qui n'est pas un fluide est refusée.
        foreach ([['part_id' => $oil, 'quantity' => '999', 'kind' => 'top_up'], ['part_id' => $this->part(['reference' => 'FLT-1', 'name' => 'Filtre', 'part_type' => 'filter']), 'quantity' => '1', 'kind' => 'change']] as $bad) {
            try {
                $fluids->log($this->equipment, $bad);
                self::fail('Appoint invalide accepté');
            } catch (ValidationException) {
                self::assertSame(2, (int) $this->value('SELECT COUNT(*) FROM fluid_logs'));
            }
        }
    }

    public function testProcurementLevelsAndSuggestions(): void
    {
        $filter = $this->part(['reference' => 'FLT-HYD', 'name' => 'Filtre hydraulique', 'part_type' => 'filter', 'unit_cost' => '50']);
        $tyre = $this->part(['reference' => 'PNEU-1', 'name' => 'Pneu', 'part_type' => 'tyre', 'unit_cost' => '2000']);
        $grease = $this->part(['reference' => 'GRS-EP2', 'name' => 'Graisse EP2', 'part_type' => 'lubricant', 'unit' => 'kg', 'unit_cost' => '8']);
        $this->setStock($filter, $this->kamao, 0, 4);   // rupture → cible 8
        $this->setStock($tyre, $this->kamao, 2, 3);     // sous le minimum → cible 6
        $this->setStock($grease, $this->sicomines, 30, 5); // suffisant
        $stock = $this->service($this->manager, StockService::class);
        $stock->move($grease, $this->sicomines, 'out', 24); // 24 kg en 90 j → 8 / mois ; reste 6 < 8 → à surveiller

        $repo = $this->service($this->manager, ProcurementRepository::class);
        $dt = DataTableRequest::fromQuery(['length' => '50'], ProcurementRepository::SORTABLE, ['x.level_rank', 'ASC']);
        $rows = array_column($repo->datatable($dt, [])['data'], null, 'reference');
        self::assertSame(['out', 8], [$rows['FLT-HYD']['level'], (int) $rows['FLT-HYD']['suggested']]);
        self::assertSame(['low', 4], [$rows['PNEU-1']['level'], (int) $rows['PNEU-1']['suggested']]);
        self::assertSame('watch', $rows['GRS-EP2']['level']);
        self::assertSame(10, (int) $rows['GRS-EP2']['suggested'], 'Cible 16 kg (2 mois) − 6 kg');

        $summary = $repo->summary();
        self::assertSame([1, 1, 1], [(int) $summary['out_count'], (int) $summary['low_count'], (int) $summary['watch_count']]);
        self::assertEqualsWithDelta(8 * 50 + 4 * 2000 + 10 * 8, (float) $summary['order_value'], 0.01);
        self::assertSame(2, $repo->datatable($dt, ['site_id' => $this->kamao, 'level' => 'action'])['recordsFiltered']);

        // Périmètre : le technicien de KAMAO ne voit pas SICOMINES.
        $techRows = $this->service($this->tech, ProcurementRepository::class)->datatable($dt, []);
        self::assertNotContains('GRS-EP2', array_column($techRows['data'], 'reference'));
    }

    public function testPagesRender(): void
    {
        $oil = $this->part(['reference' => 'HUI-1', 'name' => 'Huile', 'part_type' => 'lubricant', 'unit' => 'L', 'model_ids' => [(string) $this->modelB40]]);
        $this->setStock($oil, $this->kamao, 10, 2);
        $session = Session::memory(['auth_user_id' => $this->manager, '_csrf_token' => str_repeat('a', 64)]);
        foreach (['/procurement', "/parts/$oil", "/parts/$oil/edit", '/parts/create', "/equipment/{$this->equipment}"] as $path) {
            $res = $this->http($session, 'GET', $path);
            self::assertSame(200, $res->status, $path);
        }
        self::assertStringContainsString('HUI-1', $this->http($session, 'GET', "/equipment/{$this->equipment}")->body, 'Pièce compatible affichée sur la fiche engin');
        $json = json_decode($this->http($session, 'GET', '/procurement/data?draw=1', [], ['X-Requested-With' => 'XMLHttpRequest'])->body, true);
        self::assertArrayHasKey('data', $json);

        $res = $this->http($session, 'POST', "/equipment/{$this->equipment}/fluids", ['_csrf' => str_repeat('a', 64), 'part_id' => $oil, 'quantity' => '2.5', 'kind' => 'top_up']);
        self::assertSame(302, $res->status);
        self::assertSame(7.5, (float) $this->value('SELECT quantity FROM part_stocks WHERE part_id = :p', ['p' => $oil]));
    }
}
