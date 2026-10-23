<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Session;
use Tests\DatabaseTestCase;

/** Rattrapage d'un jour passé par l'encodeur et répertoire général des index de l'administrateur. */
final class MeterReadingTest extends DatabaseTestCase
{
    private const CSRF = ['_csrf' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'];
    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    private int $site;
    private int $equipment;
    private Session $encoder;
    private Session $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->createSite('KAMAO');
        $this->equipment = $this->createEquipment($this->site, 'KAM-01', 'available', 1000);
        $this->encoder = Session::memory(['auth_user_id' => $this->createUser('encoder', [$this->site]), '_csrf_token' => str_repeat('a', 64)]);
        $this->admin = Session::memory(['auth_user_id' => $this->createUser('admin', [], true), '_csrf_token' => str_repeat('a', 64)]);
    }

    private function reading(int $hours, string $daysAgo): int
    {
        return $this->db->insert(
            'INSERT INTO meter_readings (equipment_id, hour_meter, recorded_at) VALUES (:e, :h, :at)',
            ['e' => $this->equipment, 'h' => $hours, 'at' => date('Y-m-d 07:00:00', strtotime("-$daysAgo days"))]
        );
    }

    private function backfill(string $date, array $body): array
    {
        $res = $this->http($this->encoder, 'POST', "/saisie/{$this->equipment}/jour", self::CSRF + ['date' => $date] + $body, self::AJAX);

        return [$res->status, json_decode($res->body, true)];
    }

    private function hours(): int
    {
        return (int) $this->value('SELECT hour_meter FROM equipment WHERE id = :id', ['id' => $this->equipment]);
    }

    public function testMissingPastDayCanBeBackfilledBetweenItsNeighbours(): void
    {
        $this->reading(1000, '3');
        $this->reading(1040, '1');
        $day = date('Y-m-d', strtotime('-2 days'));

        self::assertSame(422, $this->backfill($day, ['hour_meter' => '990'])[0], 'Sous le relevé précédent');
        self::assertSame(422, $this->backfill($day, ['hour_meter' => '1050', 'confirm' => '1'])[0], 'Au-dessus du relevé suivant');

        [$status] = $this->backfill($day, ['hour_meter' => '1020', 'fuel_level' => '40']);
        self::assertSame(200, $status);
        self::assertSame($day . ' 12:00:00', $this->value('SELECT recorded_at FROM meter_readings WHERE hour_meter = 1020'));
        self::assertSame(1000, $this->hours(), 'Relevé plus récent existant : compteur de l\'engin inchangé');

        [$status, $json] = $this->backfill($day, ['hour_meter' => '1021']);
        self::assertSame(422, $status, 'Un seul relevé par jour');
        self::assertStringContainsString('déjà un relevé', implode(' ', $json['errors']));
    }

    public function testBackfillRulesOnDatesAndLatestReading(): void
    {
        $this->reading(1000, '5');
        self::assertSame(422, $this->backfill(date('Y-m-d'), ['hour_meter' => '1001'])[0], 'Pas le jour même');
        self::assertSame(422, $this->backfill(date('Y-m-d', strtotime('+1 day')), ['hour_meter' => '1001'])[0]);

        [$status, $json] = $this->backfill(date('Y-m-d', strtotime('-4 days')), ['hour_meter' => '1500']);
        self::assertSame(409, $status, 'Écart anormal : confirmation demandée');
        self::assertArrayHasKey('confirm', $json);

        self::assertSame(200, $this->backfill(date('Y-m-d', strtotime('-4 days')), ['hour_meter' => '1010'])[0]);
        self::assertSame(1010, $this->hours(), 'Relevé devenu le plus récent : compteur de l\'engin mis à jour');
    }

    public function testDirectoryIsReservedToTheAdministrator(): void
    {
        $id = $this->reading(1000, '1');
        $manager = Session::memory(['auth_user_id' => $this->createUser('manager', [$this->site]), '_csrf_token' => str_repeat('a', 64)]);
        foreach (['/readings', '/readings/data', "/readings/$id/edit"] as $path) {
            self::assertSame(403, $this->http($manager, 'GET', $path)->status, $path);
        }
        self::assertSame(403, $this->http($this->encoder, 'POST', "/readings/$id/delete", self::CSRF)->status);

        self::assertSame(200, $this->http($this->admin, 'GET', '/readings')->status);
        $data = json_decode($this->http($this->admin, 'GET', '/readings/data', [], self::AJAX)->body, true);
        self::assertSame(['KAM-01'], array_column($data['data'], 'code'));
        self::assertStringContainsString('value="1000"', $this->http($this->admin, 'GET', "/readings/$id/edit")->body);
    }

    public function testDirectoryFiltersByEquipmentAndAnswersAjax(): void
    {
        $this->reading(1000, '2');
        $other = $this->createEquipment($this->site, 'KAM-02');
        $id = $this->db->insert('INSERT INTO meter_readings (equipment_id, hour_meter, recorded_at) VALUES (:e, 50, :at)',
            ['e' => $other, 'at' => date('Y-m-d 07:00:00', strtotime('-1 day'))]);

        $data = json_decode($this->http($this->admin, 'GET', "/readings/data?equipment_id=$other", [], self::AJAX)->body, true);
        self::assertSame(['KAM-02'], array_column($data['data'], 'code'));
        self::assertStringContainsString('KAM-02', $this->http($this->admin, 'GET', "/readings?equipment_id=$other")->body, 'Filtre prérempli');

        $res = $this->http($this->admin, 'POST', "/readings/$id", self::CSRF + ['recorded_at' => date('Y-m-d\T07:00', strtotime('-1 day')), 'hour_meter' => '60'], self::AJAX);
        self::assertSame([200, true], [$res->status, json_decode($res->body, true)['ok']]);
        $res = $this->http($this->admin, 'POST', "/readings/$id", self::CSRF + ['recorded_at' => date('Y-m-d\T07:00', strtotime('+1 day')), 'hour_meter' => '60'], self::AJAX);
        self::assertSame(422, $res->status, 'Date future refusée, erreurs en JSON');
        self::assertArrayHasKey('recorded_at', json_decode($res->body, true)['errors']);

        $res = $this->http($this->admin, 'POST', "/readings/$id/delete", self::CSRF, self::AJAX);
        self::assertSame(200, $res->status);
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM meter_readings WHERE id = :id', ['id' => $id]));
    }

    public function testBulkDeleteIsAllOrNothingAndResyncsEachEquipment(): void
    {
        $first = $this->reading(1000, '3');
        $second = $this->reading(1020, '2');
        $third = $this->reading(1040, '1');
        $this->db->execute('UPDATE equipment SET hour_meter = 1040 WHERE id = :id', ['id' => $this->equipment]);
        $other = $this->createEquipment($this->createSite('METALKOL'), 'MET-01', 'available', 10);
        $foreign = $this->db->insert('INSERT INTO meter_readings (equipment_id, hour_meter, recorded_at) VALUES (:e, 10, NOW())', ['e' => $other]);

        $manager = Session::memory(['auth_user_id' => $this->createUser('manager', [$this->site]), '_csrf_token' => str_repeat('a', 64)]);
        self::assertSame(403, $this->http($manager, 'POST', '/readings/delete', self::CSRF + ['ids' => [$third]], self::AJAX)->status);
        self::assertSame(422, $this->http($this->admin, 'POST', '/readings/delete', self::CSRF + ['ids' => []], self::AJAX)->status);

        // Un identifiant inconnu annule tout le lot.
        self::assertSame(404, $this->http($this->admin, 'POST', '/readings/delete', self::CSRF + ['ids' => [$third, 999999]], self::AJAX)->status);
        self::assertSame(3, (int) $this->value('SELECT COUNT(*) FROM meter_readings WHERE equipment_id = :e', ['e' => $this->equipment]));

        $res = $this->http($this->admin, 'POST', '/readings/delete', self::CSRF + ['ids' => [$second, $third, $foreign]], self::AJAX);
        self::assertSame(200, $res->status);
        self::assertSame(3, json_decode($res->body, true)['count']);
        self::assertSame([$first], array_map('intval', array_column($this->db->fetchAll('SELECT id FROM meter_readings'), 'id')));
        self::assertSame(1000, $this->hours(), 'Compteur recalé sur le relevé restant');
        self::assertSame(3, (int) $this->value("SELECT COUNT(*) FROM activity_log WHERE action = 'reading_delete'"), 'Chaque relevé journalisé');
    }

    public function testAdministratorEditsAndDeletesReadingsAndMetersFollow(): void
    {
        $old = $this->reading(1000, '3');
        $last = $this->reading(1050, '1');
        $this->db->execute('UPDATE equipment SET hour_meter = 1050 WHERE id = :id', ['id' => $this->equipment]);
        $at = date('Y-m-d\T07:00', strtotime('-1 day'));

        $res = $this->http($this->admin, 'POST', "/readings/$last", self::CSRF + ['recorded_at' => $at, 'hour_meter' => '990']);
        self::assertSame(302, $res->status);
        self::assertSame(1050, (int) $this->value('SELECT hour_meter FROM meter_readings WHERE id = :id', ['id' => $last]), 'Sous le relevé précédent : refusé');

        $this->http($this->admin, 'POST', "/readings/$last", self::CSRF + ['recorded_at' => $at, 'hour_meter' => '1030', 'note' => 'Faute de frappe']);
        self::assertSame(1030, $this->hours(), 'Correction du dernier relevé : compteur recalé');
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM activity_log WHERE action = 'reading_update'"));

        $this->http($this->admin, 'POST', "/readings/$last/delete", self::CSRF);
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM meter_readings WHERE id = :id', ['id' => $last]));
        self::assertSame(1000, $this->hours(), 'Suppression : retour au relevé précédent');
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM activity_log WHERE action = 'reading_delete'"));
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM meter_readings WHERE id = :id', ['id' => $old]));
    }
}
