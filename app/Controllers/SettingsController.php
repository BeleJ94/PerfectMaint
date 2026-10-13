<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Services\MailService;

/** Paramètres : envoi d'e-mails (serveur SMTP, destinataires des alarmes) et tests d'envoi. */
final class SettingsController extends Controller
{
    public function __construct(ControllerContext $ctx, private readonly MailService $mail)
    {
        parent::__construct($ctx);
    }

    public function mail(Request $request): Response
    {
        return $this->render('settings/mail', [
            'title' => t('nav.mail_settings'),
            'item' => $this->mail->settings(),
            'configured' => $this->mail->isConfigured(),
            'defaultUrl' => $this->currentBaseUrl($request),
        ]);
    }

    public function saveMail(Request $request): Response
    {
        $this->mail->save($request->body);

        return $this->redirect('/settings/mail', t('flash.updated'));
    }

    public function testMail(Request $request): Response
    {
        $to = (string) $request->string('test_to');

        return $this->attempt(function () use ($to): string {
            $this->mail->sendTest($to);

            return t('mail.test_sent', ['email' => $to]);
        });
    }

    public function sendAlerts(Request $request): Response
    {
        return $this->attempt(function (): string {
            $count = $this->mail->sendAlerts();

            return $count > 0 ? t('mail.alerts_sent', ['count' => $count]) : t('mail.alerts_none');
        });
    }

    /**
     * Tente un envoi : un refus du serveur SMTP s'affiche tel quel en tête de page,
     * sans le message générique « champs à corriger » (aucun champ n'est en cause).
     *
     * @param callable(): string $send renvoie le message de succès
     */
    private function attempt(callable $send): Response
    {
        try {
            return $this->redirect('/settings/mail', $send());
        } catch (ValidationException $e) {
            if (!isset($e->errors['smtp'])) {
                throw $e;
            }
            $this->ctx->session->flash('error', $e->errors['smtp']);

            return $this->redirect('/settings/mail');
        }
    }

    /** Adresse de l'application telle que vue par le navigateur (proposée pour les liens des e-mails). */
    private function currentBaseUrl(Request $request): string
    {
        $host = $request->header('host');
        if ($host === null || !preg_match('/^[a-z0-9.\-:\[\]]+$/i', $host)) {
            return '';
        }
        $https = ($request->server['HTTPS'] ?? '') !== '' && $request->server['HTTPS'] !== 'off';

        return ($https ? 'https' : 'http') . '://' . $host . \App\Core\Url::basePath();
    }
}
