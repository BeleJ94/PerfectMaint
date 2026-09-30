<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\CatalogRepository;
use App\Services\CatalogService;

/** Référentiel : marques et modèles d'engins. */
final class CatalogController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly CatalogRepository $catalog,
        private readonly CatalogService $service,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        return $this->render('catalog/index', [
            'title' => t('nav.catalog'),
            'brands' => $this->catalog->brands(),
            'models' => $this->catalog->models(false),
            'categories' => $this->catalog->categories(),
        ]);
    }

    public function storeBrand(Request $request): Response
    {
        $id = $this->service->createBrand($request->body);
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'id' => $id, 'message' => t('flash.created')]);
        }

        return $this->redirect('/catalog', t('flash.created'));
    }

    public function updateBrand(Request $request, array $params): Response
    {
        $this->service->updateBrand($this->id($params), $request->body);
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'message' => t('flash.updated')]);
        }

        return $this->redirect('/catalog', t('flash.updated'));
    }

    public function storeModel(Request $request): Response
    {
        $model = $this->service->createModel($request->body);
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'model' => $model, 'message' => t('flash.created')]);
        }

        return $this->redirect('/catalog', t('flash.created'));
    }

    public function updateModel(Request $request, array $params): Response
    {
        $this->service->updateModel($this->id($params), $request->body);
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'message' => t('flash.updated')]);
        }

        return $this->redirect('/catalog', t('flash.updated'));
    }
}
