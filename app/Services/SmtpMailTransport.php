<?php

declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/** Envoi par SMTP (PHPMailer) : STARTTLS, SSL implicite ou sans chiffrement, avec ou sans authentification. */
final class SmtpMailTransport implements MailTransport
{
    /** Délai maximal de dialogue avec le serveur : une mauvaise adresse ne doit pas bloquer la page. */
    private const TIMEOUT_SECONDS = 15;

    public function send(array $smtp, array $to, string $subject, string $html, string $text): void
    {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Timeout = self::TIMEOUT_SECONDS;
            $mail->Host = $smtp['host'];
            $mail->Port = $smtp['port'];
            $mail->SMTPSecure = match ($smtp['encryption']) {
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'tls' => PHPMailer::ENCRYPTION_STARTTLS,
                default => '',
            };
            // « Aucun » : pas de STARTTLS opportuniste, le choix de l'administrateur est respecté.
            $mail->SMTPAutoTLS = $smtp['encryption'] !== 'none';
            $mail->SMTPAuth = ($smtp['username'] ?? '') !== '';
            $mail->Username = (string) ($smtp['username'] ?? '');
            $mail->Password = (string) ($smtp['password'] ?? '');
            $mail->setFrom($smtp['from_address'], (string) ($smtp['from_name'] ?? ''));
            foreach ($to as $address) {
                $mail->addAddress($address);
            }
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $html;
            $mail->AltBody = $text;
            $mail->send();
        } catch (PHPMailerException $e) {
            throw new MailException($mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage(), 0, $e);
        }
    }
}
