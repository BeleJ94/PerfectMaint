<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Session;
use Tests\DatabaseTestCase;

/** Saisie des index par un encodeur : accès limité, contrôles d'écart, correction de sa propre saisie. */
final class MeterEntryTest extends DatabaseTestCase
{
    private const CSRF = ['_csrf' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'];
    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    private int $kamao;
    private int $metalkol;
    private int $encoder;
    private int $equipment;
    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kamao = $this->createSite('KAMAO');
        $this->metalkol = $this->createSite('METALKOL');
        $this->encoder = $this->createUser('encoder', [$this->kamao], false, 'encodeur@test.local');
        $this->equipment = $this->createEquipment($this->kamao, 'KAM-01', 'available', 1000);
        $this->db->execute(
            'INSERT INTO meter_readings (equipment_id, hour_meter, recorded_at) VALUES (:e, 1000, :at)',
            ['e' => $this->equipment, 'at' => date('Y-m-d 07:00:00', strtotime('-1 day'))]
        );
        $this->session = Session::memory(['auth_user_id' => $this->encoder, '_csrf_token' => str_repeat('a', 64)]);
    }

    private function post(int $equipment, array $body, ?Session $session = null): array
    {
        $res = $this->http($session ?? $this->session, 'POST', "/saisie/$equipment", self::CSRF + $body, self::AJAX);

        return [$res->status, json_decode($res->body, true)];
    }

    private function hours(): int
    {
        return (int) $this->value('SELECT hour_meter FROM equipment WHERE id = :id', ['id' => $this->equipment]);
    }

    public function testEncoderOnlyReachesTheEntryScreen(): void
    {
        $login = $this->http(Session::memory(['_csrf_token' => str_repeat('a', 64)]), 'POST', '/login', self::CSRF + ['email' => 'encodeur@test.local', 'password' => self::PASSWORD]);
        self::assertStringEndsWith('/saisie', $login->headers['Location'], 'Arrivée directe sur la saisie');

        $this->createEquipment($this->metalkol, 'MET-01');
        $page = $this->http($this->session, 'GET', '/saisie');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('KAM-01', $page->body);
        self::assertStringNotContainsString('MET-01', $page->body, 'Engins de ses sites uniquement');
        self::assertStringNotContainsString('id="sidebar"', $page->body, 'Pas de menu');

        foreach (['/', '/equipment', '/meters', '/work-orders', '/settings/mail'] as $path) {
            self::assertSame(403, $this->http($this->session, 'GET', $path)->status, $path);
        }
        self::assertSame(403, $this->http($this->session, 'POST', "/equipment/{$this->equipment}/readings", self::CSRF + ['hour_meter' => '1001'], self::AJAX)->status);
    }

    public function testEncoderSupervisionTabIsLimitedToTheirSites(): void
    {
        $other = $this->createEquipment($this->metalkol, 'MET-01');
        $page = $this->http($this->session, 'GET', '/saisie/supervision');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('id="sup-data"', $page->body);
        self::assertStringContainsString('/saisie/supervision', $page->body, 'Onglet dans la navigation de l\'encodeur');

        $data = json_decode($this->http($this->session, 'GET', '/saisie/supervision/data', [], self::AJAX)->body, true);
        self::assertSame(['KAM-01'], array_column($data['rows'], 'code'), 'Engins de ses sites uniquement');
        $series = json_decode($this->http($this->session, 'GET', "/saisie/supervision/{$this->equipment}/series?days=90", [], self::AJAX)->body, true);
        self::assertSame([90, 1], [$series['days'], count($series['series'])]);
        self::assertSame(404, $this->http($this->session, 'GET', "/saisie/supervision/$other/series", [], self::AJAX)->status, 'Engin hors périmètre');
    }

    public function testWorkSiteIsShownAndCanBeSwitched(): void
    {
        $single = $this->http($this->session, 'GET', '/saisie')->body;
        self::assertStringContainsString('Site KAMAO', $single, 'Site d\'affectation affiché');
        self::assertStringNotContainsString('id="entry-site"', $single, 'Un seul site : pas de sélecteur');

        $this->createEquipment($this->metalkol, 'MET-01');
        $both = Session::memory(['auth_user_id' => $this->createUser('encoder', [$this->kamao, $this->metalkol]), '_csrf_token' => str_repeat('a', 64)]);
        $page = $this->http($both, 'GET', '/saisie')->body;
        self::assertStringContainsString('id="entry-site"', $page);
        self::assertStringContainsString('KAM-01', $page, 'Premier site par défaut');
        self::assertStringNotContainsString('MET-01', $page);

        $page = $this->http($both, 'GET', "/saisie?site_id={$this->metalkol}")->body;
        self::assertStringContainsString('MET-01', $page);
        self::assertStringNotContainsString('KAM-01', $page);
        self::assertStringContainsString('MET-01', $this->http($both, 'GET', '/saisie/rapport')->body, 'Choix conservé d\'un écran à l\'autre');

        $all = $this->http($both, 'GET', '/saisie?site_id=')->body;
        self::assertStringContainsString('KAM-01', $all);
        self::assertStringContainsString('MET-01', $all);

        // Un site hors de son affectation n'est jamais affiché.
        $other = $this->createSite('KINGAKILA');
        $this->createEquipment($other, 'KIN-01');
        self::assertStringNotContainsString('KIN-01', $this->http($both, 'GET', "/saisie?site_id=$other")->body);
    }

    public function testReportOfAPastDayKeepsReadingsReadOnly(): void
    {
        $this->db->execute(
            'INSERT INTO meter_readings (equipment_id, hour_meter, recorded_at) VALUES (:e, 980, :at)',
            ['e' => $this->equipment, 'at' => date('Y-m-d 07:00:00', strtotime('-2 days'))]
        );
        $missing = $this->createEquipment($this->kamao, 'KAM-02');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $page = $this->http($this->session, 'GET', "/saisie/rapport?date=$yesterday");
        self::assertSame(200, $page->status);
        self::assertStringContainsString('value="' . $yesterday . '"', $page->body);
        self::assertMatchesRegularExpression('/KAM-01.*\+20 h.*KAM-02/s', $page->body, 'Relevé de la veille avec son écart, puis engin non relevé');
        self::assertSame(1, substr_count($page->body, 'data-backfill-form'), 'Rattrapage proposé pour l\'engin non relevé uniquement');
        self::assertStringContainsString("/saisie/$missing/jour", $page->body);
        self::assertStringNotContainsString('data-backfill-form', $this->http($this->session, 'GET', '/saisie/rapport')->body, 'Pas de rattrapage le jour même');
        self::assertStringNotContainsString('data-entry-form', $page->body);
        self::assertSame(1000, $this->hours());

        // Date future ou invalide : retour à aujourd'hui.
        foreach (['2999-01-01', 'n-importe-quoi'] as $date) {
            self::assertStringContainsString('value="' . date('Y-m-d') . '"', $this->http($this->session, 'GET', "/saisie/rapport?date=$date")->body);
        }
        self::assertSame(403, $this->http(Session::memory(['auth_user_id' => $this->createUser('viewer', [$this->kamao])]), 'GET', '/saisie/rapport')->status);
    }

    public function testReadingIsSavedUnderTheEncoderName(): void
    {
        [$status, $json] = $this->post($this->equipment, ['hour_meter' => '1012', 'fuel_level' => '60']);
        self::assertSame(200, $status);
        self::assertStringContainsString('is-done', $json['html']);
        self::assertSame(1012, $this->hours());
        $reading = $this->row('SELECT hour_meter, fuel_level, recorded_by FROM meter_readings ORDER BY id DESC LIMIT 1');
        self::assertSame([1012, 60, $this->encoder], [(int) $reading['hour_meter'], (int) $reading['fuel_level'], (int) $reading['recorded_by']]);

        self::assertSame(422, $this->post($this->equipment, ['fuel_level' => '50'])[0], 'Index obligatoire');
        self::assertSame(404, $this->post($this->createEquipment($this->metalkol, 'MET-01'), ['hour_meter' => '200'])[0], 'Hors périmètre');
    }

    /** Carburant lu sur l'indicateur : « 2 » sur un indicateur à 4 tirets, réservoir de 80 L = 40 L (50 %). */
    public function testFuelMarkIsConvertedWithTheModelGauge(): void
    {
        $brand = $this->db->insert("INSERT INTO equipment_brands (name) VALUES ('Toyota Test')");
        $gauge = $this->db->insert("INSERT INTO fuel_gauges (name, kind, marks) VALUES ('Aiguille 4 tirets (test)', 'marks', 4)");
        $model = $this->db->insert(
            "INSERT INTO equipment_models (brand_id, name, fuel_gauge_id, tank_capacity_l) VALUES (:b, 'Land Cruiser', :g, 80)",
            ['b' => $brand, 'g' => $gauge]
        );
        $this->db->execute('UPDATE equipment SET model_id = :m WHERE id = :id', ['m' => $model, 'id' => $this->equipment]);

        $page = $this->http($this->session, 'GET', '/saisie');
        self::assertStringContainsString('name="fuel_mark"', $page->body, 'Saisie par graduation');

        [$status] = $this->post($this->equipment, ['hour_meter' => '1010', 'fuel_mark' => '2']);
        self::assertSame(200, $status);
        $reading = $this->row('SELECT fuel_level, fuel_mark, fuel_marks, fuel_liters FROM meter_readings ORDER BY id DESC LIMIT 1');
        self::assertSame([50, 2.0, 4, 40.0], [(int) $reading['fuel_level'], (float) $reading['fuel_mark'], (int) $reading['fuel_marks'], (float) $reading['fuel_liters']]);
        self::assertSame(40.0, (float) $this->db->fetchValue('SELECT fuel_liters FROM equipment WHERE id = :id', ['id' => $this->equipment]));

        // Correction de sa propre saisie : demi-graduation acceptée (1,5 / 4 de 80 L = 30 L), hors indicateur refusé.
        $this->post($this->equipment, ['hour_meter' => '1010', 'fuel_mark' => '1,5']);
        self::assertSame(30.0, (float) $this->db->fetchValue('SELECT fuel_liters FROM meter_readings ORDER BY id DESC LIMIT 1'));
        self::assertSame(422, $this->post($this->equipment, ['hour_meter' => '1010', 'fuel_mark' => '4.3'])[0]);
        self::assertSame(422, $this->post($this->equipment, ['hour_meter' => '1010', 'fuel_mark' => '5'])[0]);

        // Réglage propre à l'engin : prime sur le modèle (réservoir additionnel de 130 L).
        $this->db->execute('UPDATE equipment SET tank_capacity_l = 130 WHERE id = :id', ['id' => $this->equipment]);
        $this->post($this->equipment, ['hour_meter' => '1010', 'fuel_mark' => '4']);
        self::assertSame(130.0, (float) $this->db->fetchValue('SELECT fuel_liters FROM meter_readings ORDER BY id DESC LIMIT 1'));
    }

    public function testFuelMarkWithoutGaugeIsRefused(): void
    {
        self::assertSame(422, $this->post($this->equipment, ['hour_meter' => '1010', 'fuel_mark' => '2'])[0], 'Indicateur non configuré : saisie en %');
    }

    public function testAbnormalJumpNeedsConfirmation(): void
    {
        [$status, $json] = $this->post($this->equipment, ['hour_meter' => '1100']);
        self::assertSame(409, $status);
        self::assertStringContainsString('100 h', $json['confirm']);
        self::assertSame(1000, $this->hours(), 'Rien enregistré avant confirmation');

        self::assertSame(200, $this->post($this->equipment, ['hour_meter' => '1100', 'confirm' => '1'])[0]);
        self::assertSame(1100, $this->hours());
    }

    public function testEncoderCorrectsTheirOwnEntryOfTheDay(): void
    {
        $this->post($this->equipment, ['hour_meter' => '1021']);
        // Faute de frappe corrigée à la baisse : le relevé du jour est remplacé, pas doublé.
        self::assertSame(200, $this->post($this->equipment, ['hour_meter' => '1012'])[0]);
        self::assertSame(1012, $this->hours());
        self::assertSame(2, (int) $this->value('SELECT COUNT(*) FROM meter_readings WHERE equipment_id = :e', ['e' => $this->equipment]));

        // Jamais sous le relevé précédent (1 000 h hier).
        [$status, $json] = $this->post($this->equipment, ['hour_meter' => '990']);
        self::assertSame(422, $status);
        self::assertArrayHasKey('hour_meter', $json['errors']);
        self::assertSame(1012, $this->hours());
    }

    public function testEntryOfSomeoneElseCannotBeLowered(): void
    {
        $technician = Session::memory(['auth_user_id' => $this->createUser('technician', [$this->kamao]), '_csrf_token' => str_repeat('a', 64)]);
        self::assertSame(200, $this->post($this->equipment, ['hour_meter' => '1015'], $technician)[0]);

        self::assertSame(422, $this->post($this->equipment, ['hour_meter' => '1010'])[0], 'Le compteur ne recule pas');
        self::assertSame(200, $this->post($this->equipment, ['hour_meter' => '1018'])[0], 'Nouveau relevé accepté');
        self::assertSame(3, (int) $this->value('SELECT COUNT(*) FROM meter_readings WHERE equipment_id = :e', ['e' => $this->equipment]));
    }
}
