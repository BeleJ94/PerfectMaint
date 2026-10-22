<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\CatalogRepository;
use App\Repositories\FuelGaugeRepository;
use App\Services\CatalogService;

/** Référentiel : marques et modèles d'engins. */
final class CatalogController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly CatalogRepository $catalog,
        private readonly CatalogService $service,
        private readonly FuelGaugeRepository $gauges,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        return $this->render('catalog/index', [
            'title' => t('nav.catalog'),
            'brands' => $this->catalog->brands(),
            'models' => $this->catalog->models(false),
            'categories' => $this->catalog->categoriesWithUsage(),
            'completeness' => $this->catalog->completeness(),
            'gauges' => $this->gauges->all(),
        ]);
    }

    public function storeBrand(Request $request): Response
    {
        $id = $this->service->createBrand($request->body);
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'id' => $id, 'message' => t('flash.created')]);
        }

        return $this->redirect('/catalog#brands', t('flash.created'));
    }

    public function updateBrand(Request $request, array $params): Response
    {
        $this->service->updateBrand($this->id($params), $request->body);
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'message' => t('flash.updated')]);
        }

        return $this->redirect('/catalog#brands', t('flash.updated'));
    }

    public function storeModel(Request $request): Response
    {
        $model = $this->service->createModel($request->body);
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'model' => $model, 'message' => t('flash.created')]);
        }

        return $this->redirect('/catalog#models', t('flash.created'));
    }

    public function updateModel(Request $request, array $params): Response
    {
        $this->service->updateModel($this->id($params), $request->body);
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'message' => t('flash.updated')]);
        }

        return $this->redirect('/catalog#models', t('flash.updated'));
    }

    public function updateCategory(Request $request, array $params): Response
    {
        $this->service->updateCategory($this->id($params), $request->body);
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'message' => t('flash.updated')]);
        }

        return $this->redirect('/catalog#categories', t('flash.updated'));
    }

    public function modelFuel(Request $request, array $params): Response
    {
        $this->service->setModelFuel($this->id($params), $request->body);

        return $this->redirect('/catalog#models', t('flash.updated'));
    }

    public function storeGauge(Request $request): Response
    {
        $this->service->createGauge($request->body);

        return $this->redirect('/catalog#gauges', t('flash.created'));
    }

    public function updateGauge(Request $request, array $params): Response
    {
        $this->service->updateGauge($this->id($params), $request->body);

        return $this->redirect('/catalog#gauges', t('flash.updated'));
    }
}
