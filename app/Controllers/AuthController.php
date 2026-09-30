<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\IdentityRepository;
use App\Services\ActivityLogger;

final class AuthController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly ActivityLogger $logger,
        private readonly IdentityRepository $identities,
    ) {
        parent::__construct($ctx);
    }

    public function showLogin(Request $request): Response
    {
        if ($this->ctx->auth->check()) {
            return $this->redirect('/');
        }

        return $this->render('auth/login', ['email' => ''], 200, 'layouts/minimal');
    }

    public function login(Request $request): Response
    {
        $email = (string) $request->string('email');
        $result = $this->ctx->auth->attempt($email, (string) ($request->body['password'] ?? ''));
        if (!$result['ok']) {
            return $this->render('auth/login', ['email' => $email, 'error' => t($result['error'])], 422, 'layouts/minimal');
        }
        $user = $result['user'];
        $this->logger->log('login', 'user', $user->id, null, null, null, $user->email);

        // Les techniciens arrivent directement sur l'écran terrain.
        return $this->redirect($user->role->value === 'technician' ? '/terrain' : '/');
    }

    public function logout(Request $request): Response
    {
        $user = $this->ctx->auth->user();
        if ($user !== null) {
            $this->logger->log('logout', 'user', $user->id, null, null, null, $user->email);
        }
        $this->ctx->auth->logout();

        return $this->redirect('/login');
    }

    /** Site de travail (sélecteur du menu) : le site choisi ouvre sa fenêtre, « tous les sites » revient à la page d'origine. */
    public function switchSite(Request $request): Response
    {
        $siteId = $request->int('site_id');
        $back = $request->string('back');
        $back = $back !== null && str_starts_with($back, '/') && !str_starts_with($back, '//') ? $back : '/';
        if ($siteId !== null && $this->user()->scope()->allows($siteId)) {
            $this->ctx->session->set('site_context', $siteId);

            return Response::redirect(url('/sites/' . $siteId));
        }
        $this->ctx->session->remove('site_context');

        return Response::redirect(url(preg_match('#^/sites/\d+$#', $back) ? '/' : $back));
    }

    public function switchLocale(Request $request): Response
    {
        $locale = $request->string('locale');
        if (in_array($locale, ['fr', 'en'], true)) {
            $this->ctx->session->set('locale', $locale);
            $user = $this->ctx->auth->user();
            if ($user !== null) {
                $this->identities->updateLocale($user->id, $locale);
            }
        }
        $back = $request->string('back');

        return Response::redirect($back !== null && str_starts_with($back, '/') && !str_starts_with($back, '//')
            ? url($back) : url('/'));
    }
}
