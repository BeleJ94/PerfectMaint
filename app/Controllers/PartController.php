<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DataTableRequest;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\PartRepository;
use App\Repositories\SiteRepository;
use App\Services\PartService;
use App\Services\StockService;

final class PartController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly PartRepository $parts,
        private readonly PartService $service,
        private readonly StockService $stock,
        private readonly SiteRepository $sites,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        return $this->render('parts/index', [
            'title' => t('nav.parts'),
            'sites' => $this->sites->listActive(),
            'filters' => ['site_id' => $this->siteFilter($request), 'low' => $request->string('low')],
        ]);
    }

    public function data(Request $request): Response
    {
        $dt = DataTableRequest::fromQuery($request->query, PartRepository::SORTABLE, ['p.reference', 'ASC']);

        return $this->json($this->parts->datatable($dt, [
            'site_id' => $this->siteFilter($request),
            'low' => $request->string('low'),
        ]));
    }

    public function show(Request $request, array $params): Response
    {
        $part = $this->parts->find($this->id($params)) ?? throw HttpException::notFound();

        return $this->render('parts/show', [
            'title' => $part['reference'],
            'part' => $part,
            'stocks' => $this->parts->stocks((int) $part['id']),
            'movements' => $this->parts->movements((int) $part['id']),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->render('parts/form', ['title' => t('parts.new'), 'item' => ['unit' => 'u', 'is_active' => 1], 'id' => null]);
    }

    public function store(Request $request): Response
    {
        $id = $this->service->create($request->body);

        return $this->redirect("/parts/$id", t('flash.created'));
    }

    public function edit(Request $request, array $params): Response
    {
        $part = $this->parts->find($this->id($params)) ?? throw HttpException::notFound();

        return $this->render('parts/form', ['title' => t('parts.edit'), 'item' => $part, 'id' => (int) $part['id']]);
    }

    public function update(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->service->update($id, $request->body);

        return $this->redirect("/parts/$id", t('flash.updated'));
    }

    public function movement(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->stock->moveFromInput($id, $request->body);

        return $this->redirect("/parts/$id", t('flash.stock_moved'));
    }

    public function settings(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->stock->updateSettings($id, $request->body);

        return $this->redirect("/parts/$id", t('flash.updated'));
    }
}
