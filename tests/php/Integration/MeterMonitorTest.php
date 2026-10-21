<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\HttpException;
use App\Core\Session;
use App\Services\MeterMonitorService;
use App\Services\PreventiveService;
use Tests\DatabaseTestCase;

/** Supervision des index : rythme tiré des relevés, échéances projetées, fraîcheur des relevés. */
final class MeterMonitorTest extends DatabaseTestCase
{
    private int $kamao;
    private int $metalkol;
    private int $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kamao = $this->createSite('KAMAO');
        $this->metalkol = $this->createSite('METALKOL');
        $this->manager = $this->createUser('manager', [$this->kamao]);
    }

    /** Un relevé par jour sur $days jours, à $perDay heures par jour, jusqu'à l'index courant de l'engin. */
    private function dailyReadings(int $equipment, int $current, int $perDay, int $days, int $lastDaysAgo = 0): void
    {
        for ($i = $days; $i >= 0; $i--) {
            $this->db->execute(
                'INSERT INTO meter_readings (equipment_id, hour_meter, recorded_at) VALUES (:e, :h, :at)',
                ['e' => $equipment, 'h' => $current - $i * $perDay, 'at' => date('Y-m-d 08:00:00', strtotime('-' . ($i + $lastDaysAgo) . ' days'))]
            );
        }
    }

    private function plan(int $equipment, int $interval, int $lastDone): int
    {
        return $this->service($this->manager, PreventiveService::class)->create([
            'equipment_id' => (string) $equipment, 'title' => 'Vidange', 'interval_hours' => (string) $interval,
            'last_done_hours' => (string) $lastDone, 'last_done_at' => date('Y-m-d'), 'is_active' => '1',
        ]);
    }

    public function testOverviewProjectsDueDatesFromDailyReadings(): void
    {
        $ok = $this->createEquipment($this->kamao, 'KAM-01', 'available', 1100);
        $soon = $this->createEquipment($this->kamao, 'KAM-02', 'available', 1460);
        $due = $this->createEquipment($this->kamao, 'KAM-03', 'available', 1520);
        $noPlan = $this->createEquipment($this->kamao, 'KAM-04', 'available', 300);
        $this->createEquipment($this->metalkol, 'MET-01', 'available', 9999);
        foreach ([$ok => 1100, $soon => 1460, $due => 1520] as $id => $hours) {
            $this->dailyReadings($id, $hours, 10, 10);
            $this->plan($id, 500, 1000);
        }
        $this->dailyReadings($noPlan, 300, 5, 3, 4);

        $overview = $this->service($this->manager, MeterMonitorService::class)->overview();
        self::assertSame(['KAM-03', 'KAM-02', 'KAM-01', 'KAM-04'], array_column($overview['rows'], 'code'), 'Le plus urgent d\'abord, hors périmètre exclu');
        self::assertSame(['equipment' => 4, 'due' => 1, 'soon' => 1, 'check' => 0, 'ok' => 1, 'read_today' => 3, 'stale' => 1], $overview['summary']);

        $rows = array_column($overview['rows'], null, 'code');
        $plan = $rows['KAM-01']['plans'][0];
        self::assertSame(10.0, $rows['KAM-01']['rate_hours']);
        self::assertSame([1100, 10, date('Y-m-d', strtotime('-1 day'))], [$rows['KAM-01']['last_reading']['value'], $rows['KAM-01']['last_reading']['delta'], substr($rows['KAM-01']['last_reading']['previous_at'], 0, 10)], 'Détail du relevé du jour');
        self::assertSame([400, 20, 40, date('Y-m-d', strtotime('+40 days')), 'ok'], [$plan['remaining'], $plan['pct'], $plan['days_left'], $plan['due_on'], $plan['status']]);
        self::assertSame([-20, 'due'], [$rows['KAM-03']['plans'][0]['remaining'], $rows['KAM-03']['status']]);
        self::assertSame(['none', 4, true], [$rows['KAM-04']['status'], $rows['KAM-04']['days_since'], $rows['KAM-04']['stale']]);
    }

    public function testDetailReturnsSeriesAndRespectsScope(): void
    {
        $equipment = $this->createEquipment($this->kamao, 'KAM-01', 'available', 1100);
        $this->dailyReadings($equipment, 1100, 10, 40);
        $this->plan($equipment, 500, 1000);
        $service = $this->service($this->manager, MeterMonitorService::class);

        $detail = $service->detail($equipment, 30);
        self::assertCount(31, $detail['series'], 'Relevés des 30 derniers jours');
        self::assertSame(1100, $detail['series'][30]['hour_meter']);
        self::assertSame(10.0, $detail['rate_hours']);
        self::assertSame([1500, 'h', 'hour_meter'], [$detail['plans'][0]['next_due'], $detail['plans'][0]['unit'], $detail['metric']]);
        self::assertCount(41, $service->detail($equipment, 90)['series']);

        $this->expectException(HttpException::class);
        $service->detail($this->createEquipment($this->metalkol, 'MET-01'), 30);
    }

    /** Véhicule suivi au kilométrage : échéance, rythme et compteur de référence en km. */
    public function testKilometrePlansAreProjectedOnTheOdometer(): void
    {
        $bus = $this->createEquipment($this->kamao, 'BUS-01', 'available');
        // Catégorie « Bus » : suivie au kilométrage par défaut (migration 0020).
        $this->db->execute(
            "UPDATE equipment SET hour_meter = NULL, odometer_km = 59200,
                    category_id = (SELECT id FROM equipment_categories WHERE code = 'BUS') WHERE id = :id",
            ['id' => $bus]
        );
        for ($i = 10; $i >= 0; $i--) {
            $this->db->execute(
                'INSERT INTO meter_readings (equipment_id, odometer_km, recorded_at) VALUES (:e, :km, :at)',
                ['e' => $bus, 'km' => 59200 - $i * 200, 'at' => date('Y-m-d 08:00:00', strtotime("-$i days"))]
            );
        }
        $this->service($this->manager, PreventiveService::class)->create([
            'equipment_id' => (string) $bus, 'title' => 'Vidange', 'interval_km' => '10000', 'last_done_km' => '50000', 'last_done_at' => date('Y-m-d'), 'is_active' => '1',
        ]);

        $row = $this->service($this->manager, MeterMonitorService::class)->overview()['rows'][0];
        self::assertSame(['odometer_km', 'km', 59200, 200.0], [$row['metric'], $row['unit'], $row['current'], $row['rate']]);
        $plan = $row['plans'][0];
        self::assertSame([60000, 'km', 800, 92, 4, 'soon'], [$plan['next_due'], $plan['unit'], $plan['remaining'], $plan['pct'], $plan['days_left'], $plan['status']]);
        self::assertSame([50000, true], [$plan['start'], $plan['start_confirmed']], 'Bornes de l\'avancement : départ saisi');

        // Forçage sur l'engin : prime sur la catégorie ; le plan au km n'est plus calculé mais signalé.
        $this->db->execute("UPDATE equipment SET meter_basis = 'hours' WHERE id = :id", ['id' => $bus]);
        $row = $this->service($this->manager, MeterMonitorService::class)->overview()['rows'][0];
        self::assertSame(['hour_meter', 'check', 'mismatch', null], [$row['metric'], $row['status'], $row['plans'][0]['issue'], $row['plans'][0]['pct']]);
    }

    /** « Dans les temps » seulement si la situation est établie : sinon « à vérifier », avec la raison. */
    public function testUnestablishedSituationsAreToCheck(): void
    {
        $legacy = $this->createEquipment($this->kamao, 'KAM-01', 'available', 1100);
        $this->dailyReadings($legacy, 1100, 10, 10);
        // Plan ancien, sans point de départ saisi (déduit de l'échéance).
        $this->db->execute(
            "INSERT INTO preventive_plans (site_id, equipment_id, title, interval_hours, next_due_hours, is_active)
             VALUES (:s, :e, 'Vidange', 500, 1500, 1)",
            ['s' => $this->kamao, 'e' => $legacy]
        );
        $fresh = $this->createEquipment($this->kamao, 'KAM-02', 'available', 1000);
        $this->dailyReadings($fresh, 1000, 0, 0); // un seul relevé : rythme inconnu
        $this->plan($fresh, 500, 1000);

        $rows = array_column($this->service($this->manager, MeterMonitorService::class)->overview()['rows'], null, 'code');
        self::assertSame(['check', 'unconfirmed', 1000, false], [$rows['KAM-01']['status'], $rows['KAM-01']['plans'][0]['issue'], $rows['KAM-01']['plans'][0]['start'], $rows['KAM-01']['plans'][0]['start_confirmed']]);
        self::assertSame(['check', 'no_rate', 0], [$rows['KAM-02']['status'], $rows['KAM-02']['plans'][0]['issue'], $rows['KAM-02']['plans'][0]['pct']]);
    }

    public function testPagesAndFeeds(): void
    {
        $equipment = $this->createEquipment($this->kamao, 'KAM-02', 'available', 1460);
        $this->dailyReadings($equipment, 1460, 10, 10);
        $this->plan($equipment, 500, 1000);
        $session = Session::memory(['auth_user_id' => $this->createUser('viewer', [$this->kamao]), '_csrf_token' => str_repeat('a', 64)]);
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];

        $page = $this->http($session, 'GET', '/meters');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('id="meters-data"', $page->body);
        self::assertStringContainsString('"can_read":false', $page->body, 'Un lecteur ne saisit pas de relevé');

        $screen = $this->http($session, 'GET', '/meters/screen?rotate=sites');
        self::assertSame(200, $screen->status);
        self::assertStringContainsString('"rotate":true', $screen->body);
        self::assertStringNotContainsString('id="sidebar"', $screen->body, 'Affichage mural sans navigation');
        self::assertStringContainsString('"rotate":false', $this->http($session, 'GET', "/meters/screen?rotate=sites&site_id={$this->kamao}")->body, 'Un seul site : pas de rotation');

        $data = json_decode($this->http($session, 'GET', '/meters/data', [], $ajax)->body, true);
        self::assertSame(['KAM-02', 'soon'], [$data['rows'][0]['code'], $data['rows'][0]['status']]);
        $series = json_decode($this->http($session, 'GET', "/meters/$equipment/series?days=90", [], $ajax)->body, true);
        self::assertSame([90, 11], [$series['days'], count($series['series'])]);

        self::assertStringContainsString('href="/meters"', $this->http($session, 'GET', '/')->body, 'Tuile du tableau de bord');
        $notifications = json_decode($this->http($session, 'GET', '/notifications', [], $ajax)->body, true);
        self::assertSame(1, array_column($notifications['items'], 'count', 'key')['meter_soon'] ?? null);
    }
}
