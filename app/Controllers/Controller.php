<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Security\CurrentUser;

abstract class Controller
{
    public function __construct(protected readonly ControllerContext $ctx)
    {
    }

    protected function render(string $template, array $data = [], int $status = 200, ?string $layout = 'layouts/app'): Response
    {
        return Response::html($this->ctx->view->render($template, $data, $layout), $status);
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function redirect(string $path, ?string $success = null): Response
    {
        if ($success !== null) {
            $this->ctx->session->flash('success', $success);
        }

        return Response::redirect(url($path));
    }

    protected function user(): CurrentUser
    {
        return $this->ctx->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');
    }

    protected function authorize(string $permission): void
    {
        if (!$this->user()->can($permission)) {
            throw HttpException::forbidden();
        }
    }

    /**
     * Filtre de site : paramètre explicite de la requête (vide = tous les sites), sinon site de travail
     * choisi dans le menu (session). Ignoré s'il sort du périmètre.
     */
    protected function siteFilter(Request $request): ?int
    {
        $explicit = array_key_exists('site_id', $request->query) || array_key_exists('site_id', $request->body);
        $siteId = $explicit ? $request->int('site_id') : $this->ctx->session->get('site_context');
        $siteId = $siteId !== null ? (int) $siteId : null;

        return $siteId !== null && $this->user()->scope()->allows($siteId) ? $siteId : null;
    }

    protected function id(array $params): int
    {
        return (int) ($params['id'] ?? 0);
    }
}
