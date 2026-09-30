<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\Session;
use Tests\DatabaseTestCase;

/** Menu restructuré : site de travail, sections métier, ordre selon le rôle. */
final class NavigationTest extends DatabaseTestCase
{
    private const CSRF = ['_csrf' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'];

    private int $kamao;
    private int $chemaf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kamao = $this->createSite('KAMAO');
        $this->chemaf = $this->createSite('CHEMAF');
    }

    private function session(int $userId): Session
    {
        return Session::memory(['auth_user_id' => $userId, '_csrf_token' => str_repeat('a', 64)]);
    }

    public function testWorkingSiteIsAppliedByDefaultAndCanBeCleared(): void
    {
        $manager = $this->createUser('manager', [$this->kamao, $this->chemaf]);
        $this->createEquipment($this->kamao, 'KAM-01');
        $this->createEquipment($this->chemaf, 'CHE-01');
        $session = $this->session($manager);

        $res = $this->http($session, 'POST', '/context/site', self::CSRF + ['site_id' => $this->kamao, 'back' => '/equipment']);
        self::assertStringEndsWith("/sites/{$this->kamao}", $res->headers['Location']);
        self::assertSame($this->kamao, $session->get('site_context'));

        $json = fn (string $q) => array_column(json_decode($this->http($session, 'GET', "/equipment/data?draw=1$q", [], ['X-Requested-With' => 'XMLHttpRequest'])->body, true)['data'], 'code');
        self::assertSame(['KAM-01'], $json(''), 'Site de travail appliqué par défaut');
        self::assertEqualsCanonicalizing(['KAM-01', 'CHE-01'], $json('&site_id='), 'Choix explicite « tous les sites »');
        self::assertSame(['CHE-01'], $json("&site_id={$this->chemaf}"));

        $page = $this->http($session, 'GET', '/work-orders');
        self::assertMatchesRegularExpression('#<option value="' . $this->kamao . '" selected>#', $page->body, 'Filtre de la page pré-rempli');

        $res = $this->http($session, 'POST', '/context/site', self::CSRF + ['site_id' => '', 'back' => "/sites/{$this->kamao}"]);
        self::assertStringEndsWith('/', $res->headers['Location']);
        self::assertNull($session->get('site_context'));
    }

    public function testSiteOutsideScopeIsRefused(): void
    {
        $manager = $this->createUser('manager', [$this->kamao]);
        $session = $this->session($manager);
        $this->http($session, 'POST', '/context/site', self::CSRF + ['site_id' => $this->chemaf]);
        self::assertNull($session->get('site_context'));
    }

    public function testMenuSectionsFollowTheRole(): void
    {
        $admin = $this->http($this->session($this->createUser('admin')), 'GET', '/')->body;
        foreach (['Pilotage', 'Maintenance', 'Parc &amp; équipes', 'Magasin', 'Paramètres', 'Mode terrain', 'data-nav-badge="overdue"', 'Gestion des sites', 'site-switch'] as $needle) {
            self::assertStringContainsString($needle, $admin);
        }
        self::assertLessThan(strpos($admin, '>Maintenance<'), strpos($admin, '>Pilotage<'), 'Pilotage d\'abord pour le management');

        $tech = $this->http($this->session($this->createUser('technician', [$this->kamao])), 'GET', '/work-orders')->body;
        self::assertLessThan(strpos($tech, '>Pilotage<'), strpos($tech, '>Maintenance<'), 'Maintenance d\'abord pour un technicien');
        self::assertStringNotContainsString('nav__group', $tech, 'Pas de paramètres pour un technicien');
        self::assertStringNotContainsString('site-switch', $tech, 'Un seul site : pas de sélecteur');
    }
}
