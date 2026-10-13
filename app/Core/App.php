<?php

declare(strict_types=1);

namespace App\Core;

use App\Security\Auth;
use App\Security\SiteScope;
use Throwable;

/**
 * Noyau HTTP : Request → Response. Chaîne : routage → langue → authentification →
 * CSRF (toute méthode non GET) → permission → contrôleur. Aucune sortie directe (testable).
 */
final class App
{
    public const CSP = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; "
        . "frame-ancestors 'none'; base-uri 'self'; form-action 'self'";

    private function __construct(private readonly array $config, private readonly Container $container)
    {
    }

    public static function create(array $config, Session $session, Database $db, Request $request): self
    {
        $root = dirname(__DIR__, 2);
        Url::configure((string) $config['base_path'], $root . '/public');

        $c = new Container();
        $c->set(Database::class, $db);
        $c->set(Session::class, $session);
        $c->set(Request::class, $request);
        $csrf = new Csrf($session);
        Csrf::setCurrent($csrf);
        $c->set(Csrf::class, $csrf);
        $i18n = new I18n($root . '/lang', (string) $config['default_locale']);
        I18n::setCurrent($i18n);
        $c->set(I18n::class, $i18n);
        $c->set(View::class, new View($root . '/app/Views'));
        $c->factory(Auth::class, static fn (Container $c) => new Auth(
            $c->get(Session::class),
            $c->get(\App\Repositories\IdentityRepository::class),
            $c->get(Csrf::class),
            $config['security'],
        ));
        $c->factory(\App\Services\MailTransport::class, static fn () => new \App\Services\SmtpMailTransport());
        // Le périmètre de sites découle de l'utilisateur authentifié : injecté dans tous les repositories.
        $c->factory(SiteScope::class, static fn (Container $c) => $c->get(Auth::class)->scope());

        $router = new Router();
        (require $root . '/app/routes.php')($router);
        $c->set(Router::class, $router);

        return new self($config, $c);
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function handle(Request $request): Response
    {
        try {
            $response = $this->dispatch($request);
        } catch (ValidationException $e) {
            $response = $this->validationResponse($request, $e);
        } catch (HttpException $e) {
            $response = $this->errorResponse($request, $e->status, $e->getMessage());
        } catch (Throwable $e) {
            $this->report($e);
            $response = $this->errorResponse($request, 500, 'errors.server', $e);
        }

        return $this->secure($response);
    }

    private function dispatch(Request $request): Response
    {
        $route = $this->container->get(Router::class)->match($request->method, $request->path);
        $session = $this->container->get(Session::class);
        $auth = $this->container->get(Auth::class);
        $user = $auth->user();

        $locale = (string) $session->get('locale', $user?->locale ?? $this->config['default_locale']);
        if (in_array($locale, $this->config['locales'], true)) {
            $this->container->get(I18n::class)->setLocale($locale);
        }

        if ($request->method !== 'GET' && $request->method !== 'HEAD') {
            $this->container->get(Csrf::class)->validateRequest($request);
        }
        if ($route['options']['auth'] && $user === null) {
            return $request->wantsJson()
                ? Response::json(['error' => t('errors.unauthenticated')], 401)
                : Response::redirect(url('/login'));
        }
        $permission = $route['options']['permission'] ?? null;
        if ($permission !== null && !$user?->can($permission)) {
            throw HttpException::forbidden();
        }

        FormState::load((array) $session->pullFlash('errors', []), (array) $session->pullFlash('old', []));
        $view = $this->container->get(View::class);
        $view->share('currentUser', $user);
        $view->share('currentPath', $request->path);
        // Fenêtres par site dans la navigation : sites actifs du périmètre (pages HTML uniquement).
        $navSites = $user !== null && !$request->wantsJson()
            ? $this->container->get(\App\Repositories\SiteRepository::class)->listActive()
            : [];
        $view->share('navSites', $navSites);
        // Site de travail choisi dans le menu (contexte appliqué par défaut à toutes les pages)
        $context = $session->get('site_context');
        $view->share('siteContext', $context !== null && in_array((int) $context, array_map('intval', array_column($navSites, 'id')), true) ? (int) $context : null);
        $view->share('flashSuccess', $session->pullFlash('success'));
        $view->share('flashError', $session->pullFlash('error'));

        [$class, $method] = $route['handler'];
        $controller = $this->container->get($class);

        return $controller->$method($request, $route['params']);
    }

    private function validationResponse(Request $request, ValidationException $e): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['error' => t('validation.failed'), 'errors' => $e->errors], 422);
        }
        $session = $this->container->get(Session::class);
        $session->flash('errors', $e->errors);
        $old = $request->body;
        unset($old['_csrf'], $old['password'], $old['password_confirmation']);
        $session->flash('old', $old);
        $session->flash('error', t('validation.failed'));

        return Response::redirect($this->backUrl($request));
    }

    /** Retour à la page d'origine (même application uniquement). */
    private function backUrl(Request $request): string
    {
        $referer = $request->header('referer');
        $path = $referer !== null ? (string) parse_url($referer, PHP_URL_PATH) : '';
        $query = $referer !== null ? (string) parse_url($referer, PHP_URL_QUERY) : '';
        $base = Url::basePath();
        if ($path === '' || ($base !== '' && !str_starts_with($path, $base)) || str_starts_with($path, '//')) {
            return url('/');
        }

        return $path . ($query !== '' ? '?' . $query : '');
    }

    private function errorResponse(Request $request, int $status, string $messageKey, ?Throwable $e = null): Response
    {
        $message = str_starts_with($messageKey, 'errors.') ? t($messageKey) : $messageKey;
        if ($request->wantsJson()) {
            return Response::json(['error' => $message], $status);
        }
        try {
            $html = $this->container->get(View::class)->render('errors/error', [
                'status' => $status,
                'message' => $message,
                'debug' => $this->config['debug'] && $e !== null ? (string) $e : null,
            ], 'layouts/minimal');
        } catch (Throwable) {
            $html = '<h1>' . $status . '</h1><p>' . e($message) . '</p>';
        }

        return Response::html($html, $status);
    }

    private function report(Throwable $e): void
    {
        $file = dirname(__DIR__, 2) . '/storage/logs/app.log';
        @file_put_contents($file, '[' . date('c') . '] ' . $e . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function secure(Response $response): Response
    {
        return $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'same-origin')
            ->withHeader('Permissions-Policy', 'geolocation=(), microphone=()')
            ->withHeader('Content-Security-Policy', self::CSP)
            ->withHeader('Cache-Control', $response->headers['Cache-Control'] ?? 'no-store');
    }
}
