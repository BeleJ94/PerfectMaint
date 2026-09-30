<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DataTableRequest;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\SiteRepository;
use App\Services\SiteService;

final class SiteController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly SiteRepository $sites,
        private readonly SiteService $service,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        return $this->render('sites/index', ['title' => t('nav.sites')]);
    }

    public function data(Request $request): Response
    {
        $dt = DataTableRequest::fromQuery($request->query, SiteRepository::SORTABLE, ['s.name', 'ASC']);

        return $this->json($this->sites->datatable($dt));
    }

    public function create(Request $request): Response
    {
        return $this->render('sites/form', ['title' => t('sites.new'), 'item' => ['is_active' => 1], 'id' => null]);
    }

    public function store(Request $request): Response
    {
        $this->service->create($request->body);

        return $this->redirect('/sites', t('flash.created'));
    }

    public function edit(Request $request, array $params): Response
    {
        $site = $this->sites->find($this->id($params)) ?? throw HttpException::notFound();

        return $this->render('sites/form', ['title' => t('sites.edit'), 'item' => $site, 'id' => (int) $site['id']]);
    }

    public function update(Request $request, array $params): Response
    {
        $this->service->update($this->id($params), $request->body);

        return $this->redirect('/sites', t('flash.updated'));
    }
}
