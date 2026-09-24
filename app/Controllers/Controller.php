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

    /** Filtre de site demandé dans l'interface, ignoré s'il sort du périmètre. */
    protected function siteFilter(Request $request): ?int
    {
        $siteId = $request->int('site_id');

        return $siteId !== null && $this->user()->scope()->allows($siteId) ? $siteId : null;
    }

    protected function id(array $params): int
    {
        return (int) ($params['id'] ?? 0);
    }
}
