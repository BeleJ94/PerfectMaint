<?php

declare(strict_types=1);

namespace App\Services;

/** Envoi effectif d'un e-mail (SMTP en production, double de test dans les tests). */
interface MailTransport
{
    /**
     * @param array{host: string, port: int, encryption: string, username: ?string, password: ?string, from_address: string, from_name: ?string} $smtp
     * @param list<string> $to
     * @throws MailException si le serveur refuse la connexion, l'authentification ou le message
     */
    public function send(array $smtp, array $to, string $subject, string $html, string $text): void;
}
