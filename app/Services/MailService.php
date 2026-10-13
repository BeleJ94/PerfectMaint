<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Repositories\SettingsRepository;

/**
 * Envoi d'e-mails : configuration SMTP (page Paramètres), e-mail de test et récapitulatif des alarmes.
 * Le mot de passe SMTP n'est jamais renvoyé à l'écran ni journalisé.
 */
final class MailService
{
    public const ENCRYPTIONS = ['tls', 'ssl', 'none'];
    public const MAX_RECIPIENTS = 20;
    /** Nombre maximal d'engins détaillés dans le récapitulatif. */
    private const DIGEST_ROWS = 30;

    private const PREFIX = 'mail.';
    private const DEFAULTS = [
        'host' => null, 'port' => '587', 'encryption' => 'tls', 'username' => null, 'password' => null,
        'from_address' => null, 'from_name' => 'PerfectMaint', 'recipients' => null, 'alerts_enabled' => '0', 'app_url' => null,
    ];

    public function __construct(
        private readonly Database $db,
        private readonly SettingsRepository $settings,
        private readonly MailTransport $transport,
        private readonly NotificationService $notifications,
        private readonly MeterMonitorService $meters,
        private readonly ActivityLogger $logger,
    ) {
    }

    /** Configuration pour l'écran : le mot de passe est remplacé par un simple indicateur. */
    public function settings(): array
    {
        $raw = $this->raw();
        $raw['has_password'] = ($raw['password'] ?? '') !== '';
        unset($raw['password']);
        $raw['recipients'] = self::parseRecipients((string) $raw['recipients']);
        $raw['alerts_enabled'] = $raw['alerts_enabled'] === '1';

        return $raw;
    }

    public function isConfigured(): bool
    {
        $raw = $this->raw();

        return ($raw['host'] ?? '') !== '' && ($raw['from_address'] ?? '') !== '';
    }

    public function save(array $input): void
    {
        $data = Validator::validate($input, [
            'host' => 'required|string|max:190',
            'port' => 'required|int|min:1|max:65535',
            'encryption' => 'required|in:' . implode(',', self::ENCRYPTIONS),
            'username' => 'string|max:190',
            'password' => 'string|max:255',
            'from_address' => 'required|email|max:190',
            'from_name' => 'string|max:120',
            'recipients' => 'string|max:2000',
            'alerts_enabled' => 'bool',
            'app_url' => 'string|max:190',
        ]);
        $recipients = self::parseRecipients((string) $data['recipients']);
        foreach ($recipients as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw ValidationException::withMessage('recipients', t('mail.invalid_recipient', ['email' => $address]));
            }
        }
        if (count($recipients) > self::MAX_RECIPIENTS) {
            throw ValidationException::withMessage('recipients', t('mail.too_many_recipients', ['max' => self::MAX_RECIPIENTS]));
        }
        if ($data['alerts_enabled'] && $recipients === []) {
            throw ValidationException::withMessage('recipients', t('mail.recipients_required'));
        }
        if ($data['app_url'] !== null && !preg_match('#^https?://[^\s/]+#i', $data['app_url'])) {
            throw ValidationException::withMessage('app_url', t('mail.invalid_url'));
        }

        $before = $this->raw();
        $values = [
            'host' => $data['host'],
            'port' => (string) $data['port'],
            'encryption' => $data['encryption'],
            'username' => $data['username'],
            // Champ laissé vide : le mot de passe enregistré est conservé (sauf si l'identifiant est retiré).
            'password' => $data['username'] === null ? null : ($data['password'] ?? $before['password']),
            'from_address' => $data['from_address'],
            'from_name' => $data['from_name'],
            'recipients' => implode(', ', $recipients),
            'alerts_enabled' => $data['alerts_enabled'] ? '1' : '0',
            'app_url' => $data['app_url'] !== null ? rtrim($data['app_url'], '/') : null,
        ];
        $this->db->transaction(function () use ($values, $before): void {
            $stored = [];
            foreach ($values as $name => $value) {
                $stored[self::PREFIX . $name] = $value;
            }
            $this->settings->setMany($stored);
            // Le mot de passe ne figure pas dans le journal d'activité.
            unset($before['password'], $values['password']);
            $this->logger->log('update', 'settings', null, null, $before, $values, t('nav.mail_settings'));
        });
    }

    /** E-mail de test vers une adresse choisie, avec la configuration enregistrée. */
    public function sendTest(string $to): void
    {
        $to = trim($to);
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessage('test_to', t('validation.email', ['field' => t('fields.test_to')]));
        }
        $now = format_date(date('Y-m-d H:i:s'), true);
        $this->deliver(
            [$to],
            t('mail.test_subject'),
            self::layout(t('mail.test_subject'), '<p>' . self::h(t('mail.test_body', ['date' => $now])) . '</p>'),
            t('mail.test_body', ['date' => $now])
        );
    }

    /**
     * Récapitulatif des alarmes du périmètre courant, envoyé aux destinataires configurés.
     *
     * @return int nombre de points d'attention envoyés (0 = rien à signaler, aucun e-mail)
     */
    public function sendAlerts(): int
    {
        $recipients = $this->settings()['recipients'];
        if ($recipients === []) {
            throw ValidationException::withMessage('smtp', t('mail.recipients_required'));
        }
        $digest = $this->alertDigest();
        if ($digest['count'] === 0) {
            return 0;
        }
        $this->deliver($recipients, $digest['subject'], $digest['html'], $digest['text']);

        return $digest['count'];
    }

    /**
     * @return array{count: int, subject: string, html: string, text: string}
     */
    public function alertDigest(): array
    {
        $items = $this->notifications->items();
        $overview = $this->meters->overview();
        $urgent = array_values(array_filter($overview['rows'], static fn (array $r) => in_array($r['status'], ['due', 'soon'], true)));
        $base = (string) ($this->raw()['app_url'] ?? '');
        $date = format_date(date('Y-m-d H:i:s'), true);

        $html = '<p>' . self::h(t('mail.alerts_intro', ['date' => $date])) . '</p><ul>';
        $text = t('mail.alerts_intro', ['date' => $date]) . "\n\n";
        foreach ($items as $item) {
            $html .= '<li style="color:' . ($item['level'] === 'bad' ? '#B42318' : '#1B2430') . '">' . self::h($item['label']) . '</li>';
            $text .= '- ' . $item['label'] . "\n";
        }
        $html .= '</ul>';

        if ($urgent !== []) {
            $cell = 'style="padding:6px 10px;border-bottom:1px solid #DDE1E6;text-align:left"';
            $html .= '<h3 style="margin:20px 0 8px;font-size:15px">' . self::h(t('mail.alerts_meters')) . '</h3>'
                . '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px"><tr>';
            foreach (['fields.equipment_id', 'fields.site_id', 'meters.col_next', 'meters.col_remaining', 'fields.status'] as $heading) {
                $html .= "<th $cell>" . self::h(t($heading)) . '</th>';
            }
            $html .= '</tr>';
            $text .= "\n" . t('mail.alerts_meters') . "\n";
            foreach (array_slice($urgent, 0, self::DIGEST_ROWS) as $row) {
                $plan = $row['plans'][0];
                $remaining = $plan['remaining'] <= 0
                    ? t('mail.over', ['n' => format_number(-$plan['remaining'])])
                    : format_number($plan['remaining']) . ' h' . ($plan['due_on'] !== null ? ' · ' . format_date($plan['due_on']) : '');
                $status = t('meter_status.' . $row['status']);
                $html .= "<tr><td $cell><strong>" . self::h($row['code']) . "</strong></td><td $cell>" . self::h($row['site']) . "</td><td $cell>" . self::h($plan['title'])
                    . "</td><td $cell>" . self::h($remaining) . "</td><td $cell><span style=\"color:" . ($row['status'] === 'due' ? '#B42318' : '#B54708') . '">'
                    . self::h($status) . '</span></td></tr>';
                $text .= sprintf("- %s (%s) : %s, %s [%s]\n", $row['code'], $row['site'], $plan['title'], $remaining, $status);
            }
            $html .= '</table>';
            if (count($urgent) > self::DIGEST_ROWS) {
                $more = t('mail.alerts_more', ['count' => count($urgent) - self::DIGEST_ROWS]);
                $html .= '<p style="color:#5B6573">' . self::h($more) . '</p>';
                $text .= $more . "\n";
            }
        }
        if ($base !== '') {
            $html .= '<p style="margin-top:20px"><a href="' . self::h($base . '/meters') . '" style="color:#1F4E79">' . self::h(t('mail.open_app')) . '</a></p>';
            $text .= "\n" . $base . "/meters\n";
        }

        $summary = $overview['summary'];
        $subject = t('mail.alerts_subject', ['due' => $summary['due'], 'soon' => $summary['soon'], 'count' => count($items)]);

        return ['count' => count($items), 'subject' => $subject, 'html' => self::layout($subject, $html), 'text' => $text];
    }

    /** @param list<string> $to */
    private function deliver(array $to, string $subject, string $html, string $text): void
    {
        $raw = $this->raw();
        if (($raw['host'] ?? '') === '' || ($raw['from_address'] ?? '') === '') {
            throw ValidationException::withMessage('smtp', t('mail.not_configured'));
        }
        try {
            $this->transport->send([
                'host' => (string) $raw['host'],
                'port' => (int) $raw['port'],
                'encryption' => (string) $raw['encryption'],
                'username' => $raw['username'],
                'password' => $raw['password'],
                'from_address' => (string) $raw['from_address'],
                'from_name' => $raw['from_name'],
            ], $to, $subject, $html, $text);
        } catch (MailException $e) {
            throw ValidationException::withMessage('smtp', t('mail.send_failed', ['error' => $e->getMessage()]));
        }
    }

    /** @return array<string, string|null> configuration brute, valeurs par défaut comprises */
    private function raw(): array
    {
        $stored = $this->settings->all(self::PREFIX);
        $raw = [];
        foreach (self::DEFAULTS as $name => $default) {
            $raw[$name] = array_key_exists(self::PREFIX . $name, $stored) ? $stored[self::PREFIX . $name] : $default;
        }

        return $raw;
    }

    /** @return list<string> adresses séparées par virgule, point-virgule, espace ou retour à la ligne */
    private static function parseRecipients(string $value): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', $value) ?: []))));
    }

    /** Échappement pour le corps HTML d'un e-mail : entités numériques, comprises de tous les logiciels de messagerie. */
    private static function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');
    }

    private static function layout(string $title, string $body): string
    {
        return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1B2430;max-width:720px">'
            . '<h2 style="margin:0 0 12px;font-size:18px;color:#1F4E79">' . self::h($title) . '</h2>' . $body
            . '<p style="margin-top:24px;font-size:12px;color:#5B6573">PerfectMaint</p></div>';
    }
}
