<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Session;
use App\Core\ValidationException;
use App\Services\MailException;
use App\Services\MailService;
use App\Services\MailTransport;
use App\Services\PreventiveService;
use Tests\DatabaseTestCase;

/** Paramètres d'envoi d'e-mails, e-mail de test et récapitulatif des alarmes (transport SMTP simulé). */
final class MailSettingsTest extends DatabaseTestCase
{
    private const CSRF = ['_csrf' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'];
    private const SMTP = [
        'host' => 'smtp.test.local', 'port' => '587', 'encryption' => 'tls', 'username' => 'robot', 'password' => 'S3cret-smtp',
        'from_address' => 'gmao@test.local', 'from_name' => 'GMAO', 'recipients' => "chef@test.local\ndirection@test.local, chef@test.local",
        'alerts_enabled' => '1', 'app_url' => 'https://gmao.test.local/',
    ];

    private int $admin;
    /** @var object{sent: list<array>, fail: ?string} */
    private object $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser('admin', [], true);
        $this->transport = new class implements MailTransport {
            public array $sent = [];
            public ?string $fail = null;

            public function send(array $smtp, array $to, string $subject, string $html, string $text): void
            {
                if ($this->fail !== null) {
                    throw new MailException($this->fail);
                }
                $this->sent[] = compact('smtp', 'to', 'subject', 'html', 'text');
            }
        };
    }

    private function mail(): MailService
    {
        $app = $this->appAs($this->admin);
        $app->container()->set(MailTransport::class, $this->transport);

        return $app->container()->get(MailService::class);
    }

    public function testSettingsAreStoredAndThePasswordIsNeverExposed(): void
    {
        $this->mail()->save(self::SMTP);
        $settings = $this->mail()->settings();
        self::assertSame(['smtp.test.local', '587', 'tls', 'robot', true], [$settings['host'], $settings['port'], $settings['encryption'], $settings['username'], $settings['has_password']]);
        self::assertArrayNotHasKey('password', $settings);
        self::assertSame(['chef@test.local', 'direction@test.local'], $settings['recipients'], 'Doublons retirés');
        self::assertSame('https://gmao.test.local', $settings['app_url']);
        self::assertTrue($settings['alerts_enabled']);
        self::assertStringNotContainsString('S3cret-smtp', (string) $this->value("SELECT CONCAT(COALESCE(before_data, ''), after_data) FROM activity_log WHERE entity_type = 'settings'"));

        // Champ mot de passe laissé vide : celui enregistré est conservé et sert à l'envoi.
        $this->mail()->save(['password' => '', 'from_name' => 'Atelier'] + self::SMTP);
        $this->mail()->sendTest('moi@test.local');
        self::assertSame('S3cret-smtp', $this->transport->sent[0]['smtp']['password']);
        self::assertSame(['moi@test.local', 'Atelier', 587], [$this->transport->sent[0]['to'][0], $this->transport->sent[0]['smtp']['from_name'], $this->transport->sent[0]['smtp']['port']]);
    }

    public function testInvalidSettingsAreRefused(): void
    {
        foreach ([
            'recipients' => ['recipients' => 'pas-une-adresse'],
            'from_address' => ['from_address' => 'x'],
            'port' => ['port' => '70000'],
            'app_url' => ['app_url' => 'gmao.test.local'],
        ] as $field => $override) {
            try {
                $this->mail()->save($override + self::SMTP);
                self::fail("Valeur invalide acceptée : $field");
            } catch (ValidationException $e) {
                self::assertArrayHasKey($field, $e->errors);
            }
        }
        // Alarmes activées sans destinataire : refusé.
        $this->expectException(ValidationException::class);
        $this->mail()->save(['recipients' => ''] + self::SMTP);
    }

    public function testSmtpFailureIsReportedWithTheServerMessage(): void
    {
        try {
            $this->mail()->sendTest('moi@test.local');
            self::fail('Envoi sans configuration');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('smtp', $e->errors);
        }

        $this->mail()->save(self::SMTP);
        $this->transport->fail = 'SMTP Error: Could not authenticate.';
        try {
            $this->mail()->sendTest('moi@test.local');
            self::fail('Échec SMTP non signalé');
        } catch (ValidationException $e) {
            self::assertStringContainsString('Could not authenticate', $e->errors['smtp']);
        }
    }

    public function testAlertDigestGoesToRecipientsOnlyWhenThereIsSomethingToReport(): void
    {
        $this->mail()->save(self::SMTP);
        self::assertSame(0, $this->mail()->sendAlerts(), 'Rien à signaler');
        self::assertSame([], $this->transport->sent);

        $site = $this->createSite('KAMAO');
        $equipment = $this->createEquipment($site, 'KAM-01', 'available', 1520);
        $this->service($this->admin, PreventiveService::class)->create([
            'equipment_id' => (string) $equipment, 'title' => 'Vidange', 'interval_hours' => '500', 'last_done_hours' => '1000', 'last_done_at' => date('Y-m-d'), 'is_active' => '1',
        ]);

        self::assertGreaterThan(0, $this->mail()->sendAlerts());
        $mail = $this->transport->sent[0];
        self::assertSame(['chef@test.local', 'direction@test.local'], $mail['to']);
        self::assertStringContainsString('1 échéance(s) atteinte(s)', $mail['subject']);
        foreach (['KAM-01', 'Vidange', 'https://gmao.test.local/meters'] as $needle) {
            self::assertStringContainsString($needle, $mail['html']);
            self::assertStringContainsString($needle, $mail['text']);
        }
    }

    public function testPageIsReservedToAdministrators(): void
    {
        $session = Session::memory(['auth_user_id' => $this->admin, '_csrf_token' => str_repeat('a', 64)]);
        $empty = $this->http($session, 'GET', '/settings/mail');
        self::assertSame(200, $empty->status);
        self::assertStringNotContainsString('/settings/mail/test', $empty->body, 'Pas de test avant configuration');

        self::assertSame(302, $this->http($session, 'POST', '/settings/mail', self::CSRF + ['_form' => 'smtp'] + self::SMTP)->status);
        $page = $this->http($session, 'GET', '/settings/mail');
        self::assertStringContainsString('smtp.test.local', $page->body);
        self::assertStringContainsString('/settings/mail/test', $page->body);
        self::assertStringNotContainsString('S3cret-smtp', $page->body);

        $manager = Session::memory(['auth_user_id' => $this->createUser('manager', [$this->createSite('KAMAO')]), '_csrf_token' => str_repeat('a', 64)]);
        self::assertSame(403, $this->http($manager, 'GET', '/settings/mail')->status);
        self::assertSame(403, $this->http($manager, 'POST', '/settings/mail/test', self::CSRF + ['test_to' => 'x@test.local'])->status);
    }
}
