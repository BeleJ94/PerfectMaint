<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Session;
use App\Services\WorkOrderService;
use Tests\DatabaseTestCase;

/** Tableau de bord interactif : fenêtres de détail (filtres serveur) et notifications. */
final class DashboardDrillTest extends DatabaseTestCase
{
    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    private int $site;
    private int $manager;
    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->createSite('KAMAO');
        $this->manager = $this->createUser('manager', [$this->site]);
        $this->session = Session::memory(['auth_user_id' => $this->manager, '_csrf_token' => str_repeat('a', 64)]);
    }

    private function json(string $path): array
    {
        $res = $this->http($this->session, 'GET', $path, [], self::AJAX);
        self::assertSame(200, $res->status, $path);

        return json_decode($res->body, true);
    }

    public function testDashboardExposesDrillTriggersAndWeekKeys(): void
    {
        $page = $this->http($this->session, 'GET', '/');
        self::assertSame(200, $page->status);
        self::assertGreaterThanOrEqual(7, substr_count($page->body, 'data-drill="'), 'Chaque KPI ouvre un détail');
        self::assertStringContainsString('data-notifications', $page->body);
        preg_match('#id="dashboard-data">(.*?)</script>#s', $page->body, $m);
        $data = json_decode(html_entity_decode($m[1]), true);
        self::assertCount(8, $data['weekly']['keys']);
        self::assertSame((int) date('oW'), end($data['weekly']['keys']));
    }

    public function testDrillFiltersWeekClosedAndContracts(): void
    {
        $eq = $this->createEquipment($this->site, 'KAM-01');
        $wo = $this->service($this->manager, WorkOrderService::class);
        $old = $wo->create(['equipment_id' => $eq, 'title' => 'Ancien']);
        $this->db->execute('UPDATE work_orders SET reported_at = NOW() - INTERVAL 21 DAY WHERE id = :id', ['id' => $old]);
        $recent = $wo->create(['equipment_id' => $eq, 'title' => 'Récent']);
        $wo->transition($recent, ['status' => 'done', 'resolution' => 'OK', 'failure_cause' => 'wear']);

        $week = (int) date('oW');
        self::assertSame(['Récent'], array_column($this->json("/work-orders/data?draw=1&week=$week")['data'], 'title'));
        self::assertSame(['Récent'], array_column($this->json('/work-orders/data?draw=1&closed_days=90&type=corrective')['data'], 'title'));
        self::assertSame(2, $this->json('/work-orders/data?draw=1&week=abc')['recordsFiltered'], 'Paramètre invalide ignoré');

        $this->db->execute("UPDATE equipment SET ownership = 'rental', contract_end = CURDATE() + INTERVAL 5 DAY WHERE id = :id", ['id' => $eq]);
        $this->createEquipment($this->site, 'KAM-02');
        self::assertSame(['KAM-01'], array_column($this->json('/equipment/data?draw=1&contract_days=30')['data'], 'code'));
    }

    public function testReportAndSiteDrillFilters(): void
    {
        $eq = $this->createEquipment($this->site, 'KAM-01');
        $wo = $this->service($this->manager, WorkOrderService::class);
        $misuse = $wo->create(['equipment_id' => $eq, 'title' => 'Choc', 'immobilizing' => '1']);
        $wo->transition($misuse, ['status' => 'done', 'resolution' => 'OK', 'failure_cause' => 'misuse']);
        $wear = $wo->create(['equipment_id' => $eq, 'title' => 'Usure']);
        $wo->transition($wear, ['status' => 'done', 'resolution' => 'OK', 'failure_cause' => 'wear']);
        $old = $wo->create(['equipment_id' => $eq, 'title' => 'Ancien']);
        $this->db->execute('UPDATE work_orders SET reported_at = NOW() - INTERVAL 60 DAY WHERE id = :id', ['id' => $old]);

        $titles = fn (string $q) => array_column($this->json("/work-orders/data?draw=1&$q")['data'], 'title');
        self::assertSame(['Choc'], $titles('cause=usage&closed_days=30'));
        self::assertSame(['Usure'], $titles('cause=wear'));
        self::assertSame(['Choc'], $titles('immobilizing=1&closed_days=30'));
        self::assertEqualsCanonicalizing(['Choc', 'Usure'], $titles('reported_days=30'));
        self::assertCount(3, $titles('cause=bogus'), 'Cause inconnue ignorée');

        foreach (["/sites/{$this->site}", '/reports'] as $path) {
            $page = $this->http($this->session, 'GET', $path);
            self::assertSame(200, $page->status, $path);
            self::assertGreaterThanOrEqual(5, substr_count($page->body, 'data-drill="'), $path);
        }
    }

    public function testNotificationsListActionableItemsWithDrillSpecs(): void
    {
        $eq = $this->createEquipment($this->site, 'KAM-01');
        $wo = $this->service($this->manager, WorkOrderService::class);
        $id = $wo->create(['equipment_id' => $eq, 'title' => 'Fuite', 'priority' => 'critical', 'immobilizing' => '1', 'due_date' => date('Y-m-d', strtotime('-2 days'))]);

        $json = $this->json('/notifications');
        $byKey = array_column($json['items'], null, 'key');
        self::assertSame(['down', 'critical', 'overdue', 'meter_stale'], array_keys($byKey), 'KAM-01 n\'a jamais été relevé');
        self::assertSame(3, $json['count']);
        self::assertSame(['status' => 'active', 'priority' => 'critical'], $byKey['critical']['drill']['params']);
        self::assertSame('work_orders', $byKey['overdue']['drill']['table']);
        self::assertStringEndsWith('/work-orders/data', $byKey['overdue']['drill']['source']);

        // Le détail pointé par la notification renvoie bien l'OT.
        self::assertSame([$id], array_map('intval', array_column($this->json('/work-orders/data?draw=1&status=active&priority=critical')['data'], 'id')));

        // Hors périmètre : rien.
        $other = $this->createUser('manager', [$this->createSite('METALKOL')]);
        $res = $this->http(Session::memory(['auth_user_id' => $other]), 'GET', '/notifications', [], self::AJAX);
        self::assertSame([], json_decode($res->body, true)['items']);
    }
}
