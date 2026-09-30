<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\HttpException;
use App\Core\ValidationException;
use App\Repositories\CatalogRepository;
use App\Services\CatalogService;
use App\Services\EquipmentService;
use Tests\DatabaseTestCase;

/** Lot 1 : référentiel minier (marques, modèles, catégories) et fenêtre par site. */
final class MiningCatalogTest extends DatabaseTestCase
{
    private int $kamao;
    private int $sicomines;
    private int $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kamao = $this->createSite('KAMAO');
        $this->sicomines = $this->createSite('SICOMINES');
        $this->manager = $this->createUser('manager', [$this->kamao]);
    }

    private function modelId(string $brand, string $model): int
    {
        return (int) $this->value(
            'SELECT m.id FROM equipment_models m JOIN equipment_brands b ON b.id = m.brand_id WHERE b.name = :b AND m.name = :m',
            ['b' => $brand, 'm' => $model]
        );
    }

    public function testReferenceDataCoversTheThirteenBrandsAndMiningCategories(): void
    {
        $brands = array_column($this->service($this->manager, CatalogRepository::class)->brands(true), 'name');
        foreach (['Komatsu', 'Caterpillar', 'Volvo', 'HOWO', 'XCMG', 'Bell', 'Tata', 'Yutong', 'CHL', 'Maxima', 'Manitou', 'JCB', 'Toyota'] as $brand) {
            self::assertContains($brand, $brands);
        }
        $codes = array_column($this->service($this->manager, CatalogRepository::class)->categories(), 'code');
        foreach (['PELLE_CHENILLE', 'ADT', 'BENNE', 'BUS', 'TELESCOPIQUE'] as $code) {
            self::assertContains($code, $codes);
        }
        self::assertNotContains('TRANSPALETTE', $codes, 'Catégories entrepôt masquées');
    }

    public function testModelDrivesBrandCategoryAndLabels(): void
    {
        $model = $this->modelId('Bell', 'B40E');
        $id = $this->service($this->manager, EquipmentService::class)->create([
            'site_id' => $this->kamao, 'code' => 'adt-01', 'name' => 'Tombereau 40 t', 'model_id' => $model,
        ]);

        $row = $this->row('SELECT e.*, c.code AS cat FROM equipment e LEFT JOIN equipment_categories c ON c.id = e.category_id WHERE e.id = :id', ['id' => $id]);
        self::assertSame('ADT-01', $row['code']);
        self::assertSame(['Bell', 'B40E', 'ADT'], [$row['brand'], $row['model'], $row['cat']]);
        self::assertSame($model, (int) $row['model_id']);
        self::assertSame((int) $this->value("SELECT id FROM equipment_brands WHERE name = 'Bell'"), (int) $row['brand_id']);
    }

    public function testUnknownModelIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->service($this->manager, EquipmentService::class)->create([
            'site_id' => $this->kamao, 'code' => 'X-1', 'name' => 'X', 'model_id' => 999999,
        ]);
    }

    public function testCatalogManagementIsUniqueAndLogged(): void
    {
        $catalog = $this->service($this->manager, CatalogService::class);
        $brand = $catalog->createBrand(['name' => 'Liebherr']);
        $model = $catalog->createModel(['brand_id' => $brand, 'name' => 'R 9100', 'category_id' => '']);
        self::assertSame('R 9100', $model['name']);
        self::assertSame(2, (int) $this->value("SELECT COUNT(*) FROM activity_log WHERE entity_type IN ('brand', 'model')"));

        try {
            $catalog->createModel(['brand_id' => $brand, 'name' => 'R 9100']);
            self::fail('Doublon accepté');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('name', $e->errors);
        }

        $catalog->updateBrand($brand, ['name' => 'Liebherr', 'is_active' => '0']);
        self::assertNotContains('Liebherr', array_column($this->service($this->manager, CatalogRepository::class)->brands(true), 'name'));
    }

    public function testSiteWindowIsScopedAndListsTheFleet(): void
    {
        $this->createEquipment($this->kamao, 'KAM-01', 'breakdown');
        $this->createEquipment($this->kamao, 'KAM-02');
        $this->createEquipment($this->sicomines, 'SIC-01');
        $session = \App\Core\Session::memory(['auth_user_id' => $this->manager, '_csrf_token' => str_repeat('a', 64)]);

        $page = $this->http($session, 'GET', "/sites/{$this->kamao}");
        self::assertSame(200, $page->status);
        self::assertStringContainsString('KAM-01', $page->body);
        self::assertStringContainsString('KAM-02', $page->body);
        self::assertStringNotContainsString('SIC-01', $page->body);
        self::assertStringContainsString('50', $page->body, 'Disponibilité 50 %');

        self::assertSame(404, $this->http($session, 'GET', "/sites/{$this->sicomines}")->status, 'Site hors périmètre');
        // La navigation ne propose que les sites du périmètre.
        self::assertStringNotContainsString('/sites/' . $this->sicomines . '"', $page->body);
    }

    public function testViewerCannotManageCatalog(): void
    {
        $viewer = $this->createUser('viewer', [$this->kamao]);
        $session = \App\Core\Session::memory(['auth_user_id' => $viewer, '_csrf_token' => str_repeat('a', 64)]);
        self::assertSame(403, $this->http($session, 'GET', '/catalog')->status);
        self::assertSame(200, $this->http($session, 'GET', "/sites/{$this->kamao}")->status, 'Consultation de la fenêtre site');
    }
}
