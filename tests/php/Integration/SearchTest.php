<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Session;
use Tests\DatabaseTestCase;

/** Recherche rapide (Ctrl + K) : engins et OT du périmètre, selon les droits ; menu et notifications du gabarit. */
final class SearchTest extends DatabaseTestCase
{
    public function testQuickSearchIsScopedToTheUserSites(): void
    {
        $kamao = $this->createSite('KAMAO');
        $other = $this->createSite('METALKOL');
        $this->createEquipment($kamao, 'KAM-HLX-01');
        $this->createEquipment($other, 'MET-HLX-02');
        $session = Session::memory(['auth_user_id' => $this->createUser('manager', [$kamao]), '_csrf_token' => str_repeat('a', 64)]);
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];

        $json = json_decode($this->http($session, 'GET', '/search?q=HLX', [], $ajax)->body, true);
        self::assertSame(['KAM-HLX-01'], array_column($json['equipment'], 'code'), 'Engins du périmètre uniquement');
        self::assertSame([], json_decode($this->http($session, 'GET', '/search?q=H', [], $ajax)->body, true)['equipment'], 'Au moins 2 caractères');

        $page = $this->http($session, 'GET', '/');
        self::assertStringContainsString('data-cmdk-open', $page->body, 'Recherche rapide dans la barre supérieure');
        self::assertStringContainsString(t('nav.section_admin'), $page->body, 'Administration visible pour le responsable');

        $encoder = Session::memory(['auth_user_id' => $this->createUser('encoder', [$kamao]), '_csrf_token' => str_repeat('a', 64)]);
        self::assertSame(403, $this->http($encoder, 'GET', '/search?q=HLX', [], $ajax)->status);
    }
}
