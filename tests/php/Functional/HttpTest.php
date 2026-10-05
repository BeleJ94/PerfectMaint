<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\App;
use App\Core\Response;
use App\Core\Session;
use Tests\DatabaseTestCase;

final class HttpTest extends DatabaseTestCase
{
    private const AJAX = ['x-requested-with' => 'XMLHttpRequest'];

    private int $lyon;
    private int $lille;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lyon = $this->createSite('LYO');
        $this->lille = $this->createSite('LIL');
    }

    /** Session authentifiée + jeton CSRF connu. */
    private function sessionFor(int $userId): Session
    {
        return Session::memory(['auth_user_id' => $userId, '_csrf_token' => str_repeat('a', 64)]);
    }

    private function csrf(): array
    {
        return ['_csrf' => str_repeat('a', 64)];
    }

    private function login(Session $session, string $email, string $password): Response
    {
        $this->http($session, 'GET', '/login');

        return $this->http($session, 'POST', '/login', ['_csrf' => $session->get('_csrf_token'), 'email' => $email, 'password' => $password]);
    }

    public function testLoginPageAndRedirectWhenAnonymous(): void
    {
        $session = Session::memory();
        $page = $this->http($session, 'GET', '/login');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('name="_csrf"', $page->body);
        self::assertStringContainsString('<html lang="fr">', $page->body, 'Interface en français par défaut');

        self::assertSame(302, $this->http($session, 'GET', '/equipment')->status);
        self::assertSame('/login', $this->http($session, 'GET', '/equipment')->headers['Location']);
        self::assertSame(401, $this->http($session, 'GET', '/equipment/data', [], self::AJAX)->status);
    }

    public function testLoginRequiresCsrfAndValidCredentials(): void
    {
        $email = 'tech@test.local';
        $this->createUser('technician', [$this->lyon], false, $email);

        $session = Session::memory();
        self::assertSame(419, $this->http($session, 'POST', '/login', ['email' => $email, 'password' => self::PASSWORD])->status);

        $bad = $this->login($session, $email, 'wrong');
        self::assertSame(422, $bad->status);
        self::assertStringContainsString('Identifiants incorrects', $bad->body);

        $ok = $this->login($session, $email, self::PASSWORD);
        self::assertSame(302, $ok->status);
        self::assertSame('/terrain', $ok->headers['Location'], 'Les techniciens arrivent sur l\'écran terrain');
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM activity_log WHERE action = 'login'"));
    }

    public function testFailedLoginIsLoggedForKnownAccountsOnly(): void
    {
        $email = 'manager@test.local';
        $id = $this->createUser('manager', [$this->lyon], false, $email);
        $session = Session::memory();
        $this->login($session, $email, 'wrong');
        $this->login($session, 'nobody@test.local', 'wrong');

        $logs = $this->db->fetchAll("SELECT * FROM activity_log WHERE action = 'login_failed'");
        self::assertCount(1, $logs, 'Un identifiant inconnu ne remplit pas le journal');
        self::assertSame($id, (int) $logs[0]['entity_id']);
        self::assertNull($logs[0]['user_id'], "Personne n'est authentifié");
        self::assertSame($email, $logs[0]['user_name']);
        self::assertSame('127.0.0.1', $logs[0]['ip_address']);
    }

    public function testActivitySummaryAndFiltersOverHttp(): void
    {
        $admin = $this->createUser('admin');
        $tech = $this->createUser('technician', [$this->lyon]);
        $equipment = $this->createEquipment($this->lyon, 'LYO-1');
        $session = $this->sessionFor($admin);
        $this->http($session, 'POST', '/work-orders', $this->csrf() + ['equipment_id' => $equipment, 'title' => 'Test', 'priority' => 'normal']);

        $summary = json_decode($this->http($session, 'GET', '/activity/summary', [], self::AJAX)->body, true);
        self::assertSame($admin, (int) $summary['users'][0]['user_id']);
        self::assertSame(1, (int) $summary['users'][0]['actions']);

        $mine = json_decode($this->http($session, 'GET', "/activity/data?draw=1&user_id=$admin&action=create", [], self::AJAX)->body, true);
        self::assertSame(1, $mine['recordsFiltered']);
        self::assertSame('work_order', $mine['data'][0]['entity_type']);
        $other = json_decode($this->http($session, 'GET', "/activity/data?draw=1&user_id=$tech", [], self::AJAX)->body, true);
        self::assertSame(0, $other['recordsFiltered']);

        $page = $this->http($session, 'GET', "/activity?user_id=$admin");
        self::assertSame(200, $page->status);
        self::assertMatchesRegularExpression('/<option value="' . $admin . '" selected>/', $page->body, "Filtre utilisateur prérempli par l'URL");

        self::assertSame(403, $this->http($this->sessionFor($tech), 'GET', '/activity/summary', [], self::AJAX)->status);
    }

    public function testAccountIsLockedAfterRepeatedFailures(): void
    {
        $email = 'manager@test.local';
        $this->createUser('manager', [$this->lyon], false, $email);
        $session = Session::memory();
        for ($i = 0; $i < 5; $i++) {
            $this->login($session, $email, 'wrong');
        }
        $locked = $this->login($session, $email, self::PASSWORD);
        self::assertSame(422, $locked->status);
        self::assertStringContainsString('verrouillé', $locked->body);
    }

    public function testEveryPostRouteRejectsMissingCsrfToken(): void
    {
        $admin = $this->createUser('admin');
        $session = Session::memory(['auth_user_id' => $admin]);
        foreach (['/equipment', '/work-orders', '/sites', '/users', '/parts', '/preventive/generate', '/logout', '/locale'] as $path) {
            self::assertSame(419, $this->http($session, 'POST', $path, ['code' => 'X'])->status, $path);
            self::assertSame(419, $this->http($session, 'POST', $path, [], self::AJAX + ['x-csrf-token' => 'forged'])->status, $path);
        }
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM equipment'));
    }

    public function testPermissionsAndSiteScopeOverHttp(): void
    {
        $tech = $this->createUser('technician', [$this->lyon]);
        $viewer = $this->createUser('viewer', [$this->lyon]);
        $foreign = $this->createEquipment($this->lille, 'LIL-1');
        $own = $this->createEquipment($this->lyon, 'LYO-1');

        $session = $this->sessionFor($tech);
        self::assertSame(403, $this->http($session, 'GET', '/users')->status);
        self::assertSame(403, $this->http($session, 'GET', '/equipment/create')->status);
        self::assertSame(404, $this->http($session, 'GET', "/equipment/$foreign")->status);
        self::assertSame(200, $this->http($session, 'GET', "/equipment/$own")->status);

        $json = json_decode($this->http($session, 'GET', '/equipment/data?draw=1&site_id=' . $this->lille, [], self::AJAX)->body, true);
        // Un filtre vers un site hors périmètre est ignoré : seules les données autorisées reviennent.
        self::assertSame(['LYO-1'], array_column($json['data'], 'code'));

        $viewerSession = $this->sessionFor($viewer);
        self::assertSame(403, $this->http($viewerSession, 'POST', '/work-orders', $this->csrf() + ['equipment_id' => $own, 'title' => 'x'])->status);
        self::assertSame(403, $this->http($viewerSession, 'GET', '/terrain')->status);
    }

    public function testFieldEquipmentSearchStaysInFieldLayoutAndScope(): void
    {
        $tech = $this->createUser('technician', [$this->lyon]);
        $first = $this->createEquipment($this->lyon, 'LYO-1');
        $second = $this->createEquipment($this->lyon, 'LYO-2');
        $other = $this->createEquipment($this->lille, 'LIL-1');
        $session = $this->sessionFor($tech);

        $home = $this->http($session, 'GET', '/terrain')->body;
        self::assertStringContainsString('href="/terrain/engins"', $home, 'La recherche reste dans l\'interface terrain');

        $list = $this->http($session, 'GET', '/terrain/engins');
        self::assertSame(200, $list->status);
        self::assertStringContainsString('field-app', $list->body);
        self::assertStringContainsString("/terrain/engins/$first\"", $list->body);
        self::assertStringContainsString("/terrain/engins/$second\"", $list->body);
        self::assertStringNotContainsString('LIL-1', $list->body, 'Engin hors périmètre');

        $filtered = $this->http($session, 'GET', '/terrain/engins?q=LYO-2')->body;
        self::assertStringContainsString("/terrain/engins/$second\"", $filtered);
        self::assertStringNotContainsString("/terrain/engins/$first\"", $filtered);

        $show = $this->http($session, 'GET', "/terrain/engins/$first");
        self::assertSame(200, $show->status);
        self::assertStringContainsString('field-app', $show->body);
        self::assertStringContainsString("/terrain/declarer?equipment_id=$first", $show->body);
        self::assertSame(404, $this->http($session, 'GET', "/terrain/engins/$other")->status);

        $viewer = $this->sessionFor($this->createUser('viewer', [$this->lyon]));
        self::assertSame(403, $this->http($viewer, 'GET', '/terrain/engins')->status);
    }

    public function testFieldDeclarationFlowAndValidationRedirect(): void
    {
        $tech = $this->createUser('technician', [$this->lyon]);
        $equipment = $this->createEquipment($this->lyon, 'LYO-1');
        $session = $this->sessionFor($tech);

        $invalid = $this->http($session, 'POST', '/terrain/declarer', $this->csrf() + ['equipment_id' => $equipment, 'title' => ''], ['referer' => 'http://x/terrain/declarer']);
        self::assertSame(302, $invalid->status);
        self::assertSame('/terrain/declarer', $invalid->headers['Location']);
        $form = $this->http($session, 'GET', '/terrain/declarer');
        self::assertStringContainsString('Le champ « Intitulé » est obligatoire.', html_entity_decode($form->body));

        $ok = $this->http($session, 'POST', '/terrain/declarer', $this->csrf() + ['equipment_id' => $equipment, 'title' => 'Fuite', 'priority' => 'high', 'immobilizing' => '1']);
        self::assertSame(302, $ok->status);
        $woId = (int) $this->value('SELECT id FROM work_orders');
        self::assertSame("/terrain/ot/$woId", $ok->headers['Location']);
        self::assertSame('breakdown', $this->value('SELECT status FROM equipment WHERE id = :id', ['id' => $equipment]));

        $sheet = $this->http($session, 'GET', "/terrain/ot/$woId")->body;
        self::assertStringContainsString('data-wo-pdf', $sheet, 'Bon de travail téléchargeable depuis le terrain');
        self::assertStringContainsString('id="wo-data"', $sheet);

        $start = $this->http($session, 'POST', "/work-orders/$woId/transition", ['status' => 'in_progress'], self::AJAX + ['x-csrf-token' => str_repeat('a', 64)]);
        self::assertSame(200, $start->status);
        self::assertTrue(json_decode($start->body, true)['ok']);
        self::assertSame('maintenance', $this->value('SELECT status FROM equipment WHERE id = :id', ['id' => $equipment]));

        $bad = $this->http($session, 'POST', "/work-orders/$woId/transition", ['status' => 'done'], self::AJAX + ['x-csrf-token' => str_repeat('a', 64)]);
        self::assertSame(422, $bad->status);
        self::assertArrayHasKey('resolution', json_decode($bad->body, true)['errors']);
    }

    public function testOutputIsEscaped(): void
    {
        $manager = $this->createUser('manager', [$this->lyon]);
        $session = $this->sessionFor($manager);
        $payload = '<img src=x onerror=alert(1)>';
        $this->http($session, 'POST', '/equipment', $this->csrf() + ['site_id' => $this->lyon, 'code' => 'XSS-1', 'name' => $payload]);
        $id = (int) $this->value('SELECT id FROM equipment');

        $page = $this->http($session, 'GET', "/equipment/$id");
        self::assertSame(200, $page->status);
        self::assertStringNotContainsString($payload, $page->body);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $page->body);

        $json = $this->http($session, 'GET', '/equipment/data?draw=1', [], self::AJAX);
        self::assertSame($payload, json_decode($json->body, true)['data'][0]['name'], 'JSON brut : l\'échappement est fait au rendu par le JS');
    }

    public function testSecurityHeadersAndErrorPages(): void
    {
        $response = $this->http(Session::memory(), 'GET', '/login');
        self::assertStringContainsString("script-src 'self'", $response->headers['Content-Security-Policy']);
        self::assertSame('DENY', $response->headers['X-Frame-Options']);
        self::assertSame('nosniff', $response->headers['X-Content-Type-Options']);

        $admin = $this->createUser('admin');
        $notFound = $this->http($this->sessionFor($admin), 'GET', '/does-not-exist');
        self::assertSame(404, $notFound->status);
        self::assertStringNotContainsString('<script>', substr($notFound->body, 0, strpos($notFound->body, '<script') ?: strlen($notFound->body)));
    }

    public function testLocaleSwitchPersistsForUser(): void
    {
        $admin = $this->createUser('admin');
        $session = $this->sessionFor($admin);
        $this->http($session, 'POST', '/locale', $this->csrf() + ['locale' => 'en', 'back' => '/equipment']);

        self::assertSame('en', $this->value('SELECT locale FROM users WHERE id = :id', ['id' => $admin]));
        $page = $this->http($session, 'GET', '/equipment');
        self::assertStringContainsString('<html lang="en">', $page->body);
        self::assertStringContainsString('New equipment', $page->body);
    }

    public function testAllMainPagesRenderForAdmin(): void
    {
        $admin = $this->createUser('admin');
        $equipment = $this->createEquipment($this->lyon, 'LYO-1');
        $part = $this->createPart('P-1');
        $this->setStock($part, $this->lyon, 4);
        $session = $this->sessionFor($admin);
        $this->http($session, 'POST', '/work-orders', $this->csrf() + ['equipment_id' => $equipment, 'title' => 'Test', 'priority' => 'normal']);
        $wo = (int) $this->value('SELECT id FROM work_orders');
        $this->http($session, 'POST', '/preventive', $this->csrf() + ['equipment_id' => $equipment, 'title' => 'Rév.', 'interval_days' => '30', 'is_active' => '1']);
        $plan = (int) $this->value('SELECT id FROM preventive_plans');

        $pages = ['/', '/equipment', "/equipment/$equipment", "/equipment/$equipment/edit", '/equipment/create', '/work-orders',
            "/work-orders/$wo", "/work-orders/$wo/edit", "/work-orders/create?equipment_id=$equipment", '/preventive', '/preventive/create',
            "/preventive/$plan/edit", '/parts', "/parts/$part", '/parts/create', '/terrain', '/terrain/declarer', "/terrain/ot/$wo", '/terrain/engins', "/terrain/engins/$equipment",
            '/sites', "/sites/{$this->lyon}", "/sites/{$this->lyon}/edit", '/catalog', '/users', '/users/create', "/users/$admin/edit", '/activity'];
        foreach ($pages as $path) {
            $response = $this->http($session, 'GET', $path);
            self::assertSame(200, $response->status, $path);
            self::assertStringContainsString('</html>', $response->body, $path);
        }
        foreach (['/equipment/data', '/work-orders/data', '/preventive/data', '/parts/data', '/sites/data', '/users/data', '/activity/data', '/dashboard/data'] as $path) {
            $response = $this->http($session, 'GET', $path . '?draw=1', [], self::AJAX);
            self::assertSame(200, $response->status, $path);
            self::assertIsArray(json_decode($response->body, true), $path);
        }
    }
}
