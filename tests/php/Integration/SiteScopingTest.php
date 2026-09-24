<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\DataTableRequest;
use App\Core\HttpException;
use App\Repositories\ActivityLogRepository;
use App\Repositories\EquipmentRepository;
use App\Repositories\PartRepository;
use App\Repositories\PreventivePlanRepository;
use App\Repositories\SiteRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkOrderRepository;
use App\Services\ActivityLogger;
use App\Services\WorkOrderService;
use Tests\DatabaseTestCase;

/**
 * Règle : chaque repository filtre par les sites autorisés de l'utilisateur.
 */
final class SiteScopingTest extends DatabaseTestCase
{
    private int $lyon;
    private int $lille;
    private int $techLyon;
    private int $admin;
    private int $eqLyon;
    private int $eqLille;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lyon = $this->createSite('LYO');
        $this->lille = $this->createSite('LIL');
        $this->techLyon = $this->createUser('technician', [$this->lyon]);
        $this->admin = $this->createUser('admin');
        $this->eqLyon = $this->createEquipment($this->lyon, 'LYO-001');
        $this->eqLille = $this->createEquipment($this->lille, 'LIL-001');
    }

    private function dt(): DataTableRequest
    {
        return DataTableRequest::fromQuery(['length' => '100'], EquipmentRepository::SORTABLE, ['e.code', 'ASC']);
    }

    public function testEquipmentIsFilteredBySite(): void
    {
        $repo = $this->service($this->techLyon, EquipmentRepository::class);

        self::assertNotNull($repo->find($this->eqLyon));
        self::assertNull($repo->find($this->eqLille), 'Engin d\'un autre site invisible');
        self::assertNull($repo->findForUpdate($this->eqLille));
        $table = $repo->datatable($this->dt(), []);
        self::assertSame(1, $table['recordsTotal']);
        self::assertSame(['LYO-001'], array_column($table['data'], 'code'));
        self::assertSame(['LYO-001'], array_column($repo->search('00'), 'code'));
        self::assertSame(['available' => 1], $repo->countByStatus());

        // Un filtre d'interface vers un site hors périmètre ne révèle rien.
        self::assertSame(0, $repo->datatable($this->dt(), ['site_id' => $this->lille])['recordsFiltered']);
    }

    public function testAdminSeesEverySite(): void
    {
        $repo = $this->service($this->admin, EquipmentRepository::class);
        self::assertSame(2, $repo->datatable($this->dt(), [])['recordsTotal']);
    }

    public function testUserWithoutSiteSeesNothing(): void
    {
        $orphan = $this->createUser('manager');
        $repo = $this->service($orphan, EquipmentRepository::class);
        self::assertSame(0, $repo->datatable($this->dt(), [])['recordsTotal']);
        self::assertSame([], $this->service($orphan, SiteRepository::class)->listActive());
    }

    public function testAnonymousScopeSeesNothing(): void
    {
        self::assertNull($this->service(null, EquipmentRepository::class)->find($this->eqLyon));
    }

    public function testWritesOutsideScopeAreRejected(): void
    {
        $repo = $this->service($this->techLyon, EquipmentRepository::class);
        $this->expectExceptionObject(HttpException::forbidden());
        $repo->create(['site_id' => $this->lille, 'code' => 'X', 'name' => 'X', 'category_id' => null]);
    }

    public function testStatusUpdateCannotTouchForeignEquipment(): void
    {
        $this->service($this->techLyon, EquipmentRepository::class)->updateStatus($this->eqLille, 'breakdown');
        self::assertSame('available', $this->value('SELECT status FROM equipment WHERE id = :id', ['id' => $this->eqLille]));
    }

    public function testWorkOrdersPlansPartsAndLogsAreFiltered(): void
    {
        $managerAll = $this->createUser('manager', [], true);
        $woService = $this->service($managerAll, WorkOrderService::class);
        $woLyon = $woService->create(['equipment_id' => $this->eqLyon, 'title' => 'A']);
        $woLille = $woService->create(['equipment_id' => $this->eqLille, 'title' => 'B']);
        $this->db->execute(
            'INSERT INTO preventive_plans (site_id, equipment_id, title, interval_days, next_due_date) VALUES (:s, :e, \'P\', 30, CURDATE())',
            ['s' => $this->lille, 'e' => $this->eqLille]
        );
        $part = $this->createPart('P-1');
        $this->setStock($part, $this->lyon, 5, 10);
        $this->setStock($part, $this->lille, 50, 10);

        $app = $this->appAs($this->techLyon);
        $wo = $app->container()->get(WorkOrderRepository::class);
        self::assertNotNull($wo->find($woLyon));
        self::assertNull($wo->find($woLille));
        self::assertSame(1, $wo->kpis()['open']);
        self::assertCount(1, $wo->fieldQueue($this->techLyon));

        $plans = $app->container()->get(PreventivePlanRepository::class);
        self::assertSame([], $plans->dueWithoutOpenWorkOrder(7));
        self::assertSame(0, $plans->countDue(7));

        $parts = $app->container()->get(PartRepository::class);
        self::assertSame([$this->lyon], array_map('intval', array_column($parts->stocks($part), 'site_id')));
        $table = $parts->datatable(DataTableRequest::fromQuery([], PartRepository::SORTABLE, ['p.reference', 'ASC']), []);
        self::assertSame(5.0, (float) $table['data'][0]['quantity'], 'Stock agrégé sur le seul périmètre');
        self::assertSame([], $parts->availableOnSite($this->lille));
        self::assertSame(1, $parts->countBelowMin());

        $logs = $app->container()->get(ActivityLogRepository::class);
        $entries = $logs->datatable(DataTableRequest::fromQuery(['length' => '100'], ActivityLogRepository::SORTABLE, ['l.id', 'DESC']), []);
        self::assertNotEmpty($entries['data']);
        foreach ($entries['data'] as $entry) {
            self::assertSame('Site LYO', $entry['site']);
        }
    }

    public function testActivityWithoutSiteIsReservedToGlobalScope(): void
    {
        $this->service($this->admin, ActivityLogger::class)->log('create', 'part', 1, null, null, ['reference' => 'X']);
        $dt = DataTableRequest::fromQuery([], ActivityLogRepository::SORTABLE, ['l.id', 'DESC']);

        self::assertSame(1, $this->service($this->admin, ActivityLogRepository::class)->datatable($dt, [])['recordsTotal']);
        self::assertSame(0, $this->service($this->techLyon, ActivityLogRepository::class)->datatable($dt, [])['recordsTotal']);
    }

    public function testUsersAndAssigneesAreFiltered(): void
    {
        $techLille = $this->createUser('technician', [$this->lille]);
        $managerLyon = $this->createUser('manager', [$this->lyon]);
        $users = $this->service($managerLyon, UserRepository::class);

        self::assertNull($users->find($techLille));
        self::assertNotNull($users->find($this->techLyon));
        $assignable = array_map('intval', array_column($users->assignableForSite($this->lyon), 'id'));
        self::assertContains($this->techLyon, $assignable);
        self::assertNotContains($techLille, $assignable);
        self::assertSame([], $users->assignableForSite($this->lille));
    }
}
