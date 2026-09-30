<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Session;
use App\Core\ValidationException;
use App\Repositories\EquipmentPhotoRepository;
use App\Repositories\EquipmentRepository;
use App\Security\Auth;
use App\Services\ActivityLogger;
use App\Services\EquipmentService;
use App\Services\MeterService;
use App\Services\PhotoService;
use Tests\DatabaseTestCase;

/** Lot 2 : identification, compteurs, carburant, contrat, photos. */
final class EquipmentIdentityTest extends DatabaseTestCase
{
    /** PNG 1×1 valide. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private int $kamao;
    private int $metalkol;
    private int $manager;
    private int $tech;
    private int $equipment;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kamao = $this->createSite('KAMAO');
        $this->metalkol = $this->createSite('METALKOL');
        $this->manager = $this->createUser('manager', [$this->kamao]);
        $this->tech = $this->createUser('technician', [$this->kamao]);
        $this->equipment = $this->createEquipment($this->kamao, 'KAM-ADT-01', 'available', 7000);
        $this->dir = sys_get_temp_dir() . '/pm-photos-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function photoService(int $userId): PhotoService
    {
        $c = $this->appAs($userId)->container();

        return new PhotoService(
            $this->db, $c->get(EquipmentRepository::class), $c->get(EquipmentPhotoRepository::class),
            $c->get(ActivityLogger::class), $c->get(Auth::class), $this->dir
        );
    }

    private function tmpFile(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'pmup');
        file_put_contents($path, $content);

        return ['tmp_name' => $path, 'name' => 'engin.png', 'size' => strlen($content), 'error' => UPLOAD_ERR_OK];
    }

    public function testIdentityAndContractAreNormalised(): void
    {
        $service = $this->service($this->manager, EquipmentService::class);
        $id = $service->create([
            'site_id' => $this->kamao, 'code' => 'KAM-BEN-01', 'name' => 'Benne', 'plate_number' => 'ab 1234 cd',
            'chassis_number' => 'lzzab123', 'odometer_km' => '120500', 'ownership' => 'rental', 'contract_ref' => 'PO-77',
            'contract_party' => 'Loxea', 'contract_start' => '2026-01-01', 'contract_end' => '2026-12-31', 'assignment' => 'Fosse nord',
        ]);
        $row = $this->row('SELECT * FROM equipment WHERE id = :id', ['id' => $id]);
        self::assertSame(['AB 1234 CD', 'LZZAB123', 120500, 'rental', 'PO-77'], [$row['plate_number'], $row['chassis_number'], (int) $row['odometer_km'], $row['ownership'], $row['contract_ref']]);

        // Repassage en propriété : le contrat est vidé.
        $service->update($id, ['site_id' => $this->kamao, 'code' => 'KAM-BEN-01', 'name' => 'Benne', 'ownership' => 'owned', 'contract_ref' => 'PO-77']);
        self::assertNull($this->value('SELECT contract_ref FROM equipment WHERE id = :id', ['id' => $id]));

        try {
            $service->create(['site_id' => $this->kamao, 'code' => 'X', 'name' => 'X', 'ownership' => 'po', 'contract_start' => '2026-05-01', 'contract_end' => '2026-04-01']);
            self::fail('Dates inversées acceptées');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('contract_end', $e->errors);
        }
    }

    public function testReadingsUpdateCountersAndNeverGoBackwards(): void
    {
        $meters = $this->service($this->tech, MeterService::class);
        $meters->record($this->equipment, ['hour_meter' => '7050', 'odometer_km' => '88000', 'fuel_level' => '60', 'note' => 'Prise de poste']);
        $row = $this->row('SELECT hour_meter, odometer_km, fuel_level, meter_updated_at FROM equipment WHERE id = :id', ['id' => $this->equipment]);
        self::assertSame([7050, 88000, 60], [(int) $row['hour_meter'], (int) $row['odometer_km'], (int) $row['fuel_level']]);
        self::assertNotNull($row['meter_updated_at']);

        // Carburant seul : les compteurs sont conservés.
        $meters->record($this->equipment, ['fuel_level' => '25']);
        self::assertSame(7050, (int) $this->value('SELECT hour_meter FROM equipment WHERE id = :id', ['id' => $this->equipment]));
        self::assertSame(2, (int) $this->value('SELECT COUNT(*) FROM meter_readings WHERE equipment_id = :id', ['id' => $this->equipment]));
        self::assertSame(2, (int) $this->value("SELECT COUNT(*) FROM activity_log WHERE action = 'reading'"));

        foreach ([['hour_meter' => '6999'], ['odometer_km' => '100'], []] as $bad) {
            try {
                $meters->record($this->equipment, $bad);
                self::fail('Relevé invalide accepté : ' . json_encode($bad));
            } catch (ValidationException) {
                self::assertSame(2, (int) $this->value('SELECT COUNT(*) FROM meter_readings'));
            }
        }
    }

    public function testReadingOutsideScopeIs404(): void
    {
        $other = $this->createEquipment($this->metalkol, 'MET-01');
        $this->expectException(\App\Core\HttpException::class);
        $this->service($this->tech, MeterService::class)->record($other, ['hour_meter' => '1']);
    }

    public function testPhotoUploadValidatesContentAndScope(): void
    {
        $service = $this->photoService($this->tech);
        $first = $service->upload($this->equipment, $this->tmpFile((string) base64_decode(self::PNG)), 'Vue avant');
        $second = $service->upload($this->equipment, $this->tmpFile((string) base64_decode(self::PNG)), null);

        $photos = $this->db->fetchAll('SELECT id, is_primary, mime, width FROM equipment_photos ORDER BY id');
        self::assertSame([1, 0], array_map('intval', array_column($photos, 'is_primary')), 'La première photo devient principale');
        self::assertSame('image/png', $photos[0]['mime']);
        self::assertFileExists($service->path($this->equipment, $first)['path']);

        $service->setPrimary($this->equipment, $second);
        self::assertSame($second, (int) $this->value('SELECT id FROM equipment_photos WHERE is_primary = 1'));

        // Un faux PNG (script renommé) est refusé.
        try {
            $service->upload($this->equipment, $this->tmpFile('<?php echo 1;'), null);
            self::fail('Contenu non image accepté');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('photo', $e->errors);
        }

        // Hors périmètre : photo invisible.
        $outsider = $this->createUser('technician', [$this->metalkol]);
        self::assertNull($this->photoService($outsider)->path($this->equipment, $first));

        // Suppression de la principale : la suivante prend le relais, le fichier disparaît.
        $path = $service->path($this->equipment, $second)['path'];
        $service->delete($this->equipment, $second);
        self::assertFileDoesNotExist($path);
        self::assertSame($first, (int) $this->value('SELECT id FROM equipment_photos WHERE is_primary = 1'));
    }

    public function testEquipmentPageShowsVitalsAndContractsFeedTheSiteWindow(): void
    {
        $this->db->execute(
            "UPDATE equipment SET ownership = 'rental', contract_ref = 'LOC-9', contract_end = CURDATE() + INTERVAL 10 DAY, plate_number = 'KZ-001', fuel_level = 12 WHERE id = :id",
            ['id' => $this->equipment]
        );
        $session = Session::memory(['auth_user_id' => $this->manager, '_csrf_token' => str_repeat('a', 64)]);
        $page = $this->http($session, 'GET', "/equipment/{$this->equipment}");
        self::assertSame(200, $page->status);
        self::assertStringContainsString('KZ-001', $page->body);
        self::assertStringContainsString('LOC-9', $page->body);
        self::assertStringContainsString('vital__icon--bad', $page->body, 'Carburant bas signalé');

        $site = $this->http($session, 'GET', "/sites/{$this->kamao}");
        self::assertStringContainsString('KAM-ADT-01 · ', $site->body, 'Contrat à échéance listé');

        // Relevé via HTTP (technicien), puis la photo d'un autre site reste inaccessible.
        $techSession = Session::memory(['auth_user_id' => $this->tech, '_csrf_token' => str_repeat('a', 64)]);
        $res = $this->http($techSession, 'POST', "/equipment/{$this->equipment}/readings", ['_csrf' => str_repeat('a', 64), 'fuel_level' => '80']);
        self::assertSame(302, $res->status);
        self::assertSame(80, (int) $this->value('SELECT fuel_level FROM equipment WHERE id = :id', ['id' => $this->equipment]));
        self::assertSame(404, $this->http($techSession, 'GET', "/equipment/{$this->equipment}/photos/999")->status);
    }
}
