<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\DataTableRequest;
use App\Core\HttpException;
use App\Core\Session;
use App\Core\ValidationException;
use App\Repositories\EquipmentDocumentRepository;
use App\Repositories\EquipmentRepository;
use App\Repositories\ManagementRepository;
use App\Security\Auth;
use App\Services\ActivityLogger;
use App\Services\EquipmentDocumentService;
use App\Services\NotificationService;
use Tests\DatabaseTestCase;

/** Documents à échéance des engins : assurance, contrôle technique… (renouvellement, alertes, planning, périmètre). */
final class EquipmentDocumentTest extends DatabaseTestCase
{
    private int $kamao;
    private int $metalkol;
    private int $manager;
    private int $tech;
    private int $equipment;
    private int $insurance;
    private int $inspection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kamao = $this->createSite('KAMAO');
        $this->metalkol = $this->createSite('METALKOL');
        $this->manager = $this->createUser('manager', [$this->kamao]);
        $this->tech = $this->createUser('technician', [$this->kamao]);
        $this->equipment = $this->createEquipment($this->kamao, 'BUS-216');
        $this->insurance = (int) $this->value("SELECT id FROM document_types WHERE code = 'insurance'");
        $this->inspection = (int) $this->value("SELECT id FROM document_types WHERE code = 'inspection'");
    }

    private function day(int $offset): string
    {
        return date('Y-m-d', strtotime("$offset days"));
    }

    public function testRenewalKeepsHistoryAndNewestIsCurrent(): void
    {
        $service = $this->service($this->manager, EquipmentDocumentService::class);
        $old = $service->add($this->equipment, ['document_type_id' => $this->insurance, 'issuer' => 'SONAS', 'reference' => 'N06001',
            'issued_at' => $this->day(-370), 'expires_at' => $this->day(-5)]);
        $service->add($this->equipment, ['document_type_id' => $this->insurance, 'issuer' => 'SONAS', 'reference' => 'N06002',
            'issued_at' => $this->day(-4), 'expires_at' => $this->day(360)]);

        $groups = $this->service($this->manager, EquipmentDocumentRepository::class)->forEquipment($this->equipment);
        $insurance = array_values(array_filter($groups, fn (array $g) => (int) $g['type']['id'] === $this->insurance))[0];
        self::assertSame('N06002', $insurance['current']['reference']);
        self::assertSame('valid', $insurance['current']['state']);
        self::assertSame((string) $old, (string) $insurance['history'][0]['id']);
        self::assertCount(4, $groups, 'Tous les types actifs sont proposés, même sans document');

        // Le document expiré remplacé ne déclenche plus d'alerte.
        self::assertSame([], $this->service($this->manager, EquipmentDocumentRepository::class)->expiring());
        self::assertSame(2, (int) $this->value("SELECT COUNT(*) FROM activity_log WHERE action = 'document_add'"));
    }

    public function testExpiringFeedsAlertsNotificationsAndPlanning(): void
    {
        $service = $this->service($this->manager, EquipmentDocumentService::class);
        $service->add($this->equipment, ['document_type_id' => $this->insurance, 'expires_at' => $this->day(-1)]);
        $service->add($this->equipment, ['document_type_id' => $this->inspection, 'expires_at' => $this->day(20)]);
        $service->add($this->createEquipment($this->kamao, 'BUS-217'), ['document_type_id' => $this->inspection, 'expires_at' => $this->day(200)]);

        $expiring = $this->service($this->manager, EquipmentDocumentRepository::class)->expiring();
        self::assertSame(['insurance', 'inspection'], array_column($expiring, 'type_code'));

        $keys = array_column($this->service($this->manager, NotificationService::class)->items(false), 'count', 'key');
        self::assertSame(1, $keys['documents_expired']);
        self::assertSame(1, $keys['documents_soon']);

        $events = $this->service($this->manager, ManagementRepository::class)->events($this->day(-3), $this->day(30));
        $docs = array_values(array_filter($events, static fn (array $e) => $e['kind'] === 'document'));
        self::assertCount(2, $docs);
        self::assertTrue($docs[0]['overdue']);
    }

    public function testValidationAndCorrection(): void
    {
        $service = $this->service($this->manager, EquipmentDocumentService::class);
        foreach ([
            ['document_type_id' => $this->insurance],
            ['document_type_id' => 999, 'expires_at' => $this->day(10)],
            ['document_type_id' => $this->insurance, 'issued_at' => $this->day(10), 'expires_at' => $this->day(5)],
        ] as $bad) {
            try {
                $service->add($this->equipment, $bad);
                self::fail('Document invalide accepté : ' . json_encode($bad));
            } catch (ValidationException) {
                self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM equipment_documents'));
            }
        }

        $id = $service->add($this->equipment, ['document_type_id' => $this->insurance, 'reference' => 'ERR', 'expires_at' => $this->day(10)]);
        $service->update($this->equipment, $id, ['document_type_id' => $this->insurance, 'reference' => 'N0600125024870', 'expires_at' => $this->day(365)]);
        self::assertSame('N0600125024870', $this->value('SELECT reference FROM equipment_documents WHERE id = :id', ['id' => $id]));
        $service->delete($this->equipment, $id);
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM equipment_documents'));
    }

    public function testScopeAndPermissions(): void
    {
        $other = $this->createEquipment($this->metalkol, 'MET-01');
        try {
            $this->service($this->manager, EquipmentDocumentService::class)->add($other, ['document_type_id' => $this->insurance, 'expires_at' => $this->day(10)]);
            self::fail('Engin hors périmètre accepté');
        } catch (HttpException) {
            self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM equipment_documents'));
        }

        // Le technicien voit les documents mais ne peut pas en saisir.
        $session = Session::memory(['auth_user_id' => $this->tech, '_csrf_token' => str_repeat('a', 64)]);
        $res = $this->http($session, 'POST', "/equipment/{$this->equipment}/documents",
            ['document_type_id' => $this->insurance, 'expires_at' => $this->day(10), '_csrf' => str_repeat('a', 64)]);
        self::assertSame(403, $res->status);
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM equipment_documents'));
        $page = $this->http($session, 'GET', "/equipment/{$this->equipment}");
        self::assertSame(200, $page->status);
        self::assertStringContainsString('id="documents"', $page->body);
    }

    private function fileService(int $userId, string $dir): EquipmentDocumentService
    {
        $c = $this->appAs($userId)->container();

        return new EquipmentDocumentService($this->db, $c->get(EquipmentRepository::class), $c->get(EquipmentDocumentRepository::class),
            $c->get(ActivityLogger::class), $c->get(Auth::class), $dir);
    }

    private function tmpFile(string $content, string $name): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'pmdoc');
        file_put_contents($path, $content);

        return ['tmp_name' => $path, 'name' => $name, 'size' => strlen($content), 'error' => UPLOAD_ERR_OK];
    }

    public function testAttachedFileIsCheckedStoredReplacedAndRemoved(): void
    {
        $dir = sys_get_temp_dir() . '/pm-docs-' . bin2hex(random_bytes(4));
        $service = $this->fileService($this->manager, $dir);
        $pdf = "%PDF-1.4
1 0 obj << /Type /Catalog >> endobj
trailer << /Root 1 0 R >>
%%EOF
";
        try {
            try {
                $service->add($this->equipment, ['document_type_id' => $this->insurance, 'expires_at' => $this->day(30)], $this->tmpFile('<?php echo 1;', 'x.pdf'));
                self::fail('Fichier non PDF accepté');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('document_file', $e->errors);
                self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM equipment_documents'));
            }

            $id = $service->add($this->equipment, ['document_type_id' => $this->insurance, 'expires_at' => $this->day(30)], $this->tmpFile($pdf, 'attestation.pdf'));
            $file = $service->file($this->equipment, $id);
            self::assertSame(['application/pdf', 'attestation.pdf'], [$file['mime'], $file['name']]);
            self::assertFileExists($file['path']);
            self::assertNull($this->fileService($this->manager, $dir)->file($this->createEquipment($this->kamao, 'AUTRE'), $id), 'Fichier servi pour un autre engin');

            // Remplacement : l'ancien fichier est supprimé du disque.
            $service->update($this->equipment, $id, ['document_type_id' => $this->insurance, 'expires_at' => $this->day(30)], $this->tmpFile($pdf, 'nouvelle.pdf'));
            self::assertFileDoesNotExist($file['path']);
            $replaced = $service->file($this->equipment, $id);
            self::assertSame('nouvelle.pdf', $replaced['name']);

            $service->update($this->equipment, $id, ['document_type_id' => $this->insurance, 'expires_at' => $this->day(30)], null, true);
            self::assertNull($service->file($this->equipment, $id));
            self::assertFileDoesNotExist($replaced['path']);
            self::assertSame([], glob($dir . '/*') ?: []);
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
    }

    public function testBoardListsEveryMachineAndTypeWithState(): void
    {
        $service = $this->service($this->manager, EquipmentDocumentService::class);
        $service->add($this->equipment, ['document_type_id' => $this->insurance, 'expires_at' => $this->day(-2)]);
        $service->add($this->equipment, ['document_type_id' => $this->inspection, 'expires_at' => $this->day(200)]);
        $this->createEquipment($this->metalkol, 'HORS-PERIMETRE');

        $repo = $this->service($this->manager, EquipmentDocumentRepository::class);
        $summary = $repo->boardSummary([]);
        self::assertSame([1, 0, 2, 1], [$summary['expired'], $summary['soon'], $summary['missing'], $summary['valid']], '1 engin × 4 types, hors périmètre exclu');

        $dt = DataTableRequest::fromQuery([], EquipmentDocumentRepository::SORTABLE, ['x.state_rank, x.expires_at, x.code', 'ASC']);
        $rows = $repo->datatable($dt, ['state' => 'action'])['data'];
        self::assertCount(1, $rows);
        self::assertSame(['BUS-216', 'expired'], [$rows[0]['code'], $rows[0]['state']]);

        $session = Session::memory(['auth_user_id' => $this->tech]);
        $page = $this->http($session, 'GET', '/documents');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('data-table="documents"', $page->body);
        $json = json_decode($this->http($session, 'GET', '/documents/data?state=expired', [], ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])->body, true);
        self::assertSame(1, $json['recordsFiltered']);
        self::assertNull($json['data'][0]['renew_url'], 'Le technicien ne peut pas saisir');
        self::assertSame(403, $this->http($session, 'GET', '/documents/create')->status);
    }
}
