<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\SiteRepository;
use App\Services\DashboardService;

final class DashboardController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly DashboardService $dashboard,
        private readonly SiteRepository $sites,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        $siteId = $this->siteFilter($request);

        return $this->render('dashboard/index', [
            'title' => t('nav.dashboard'),
            'sites' => $this->sites->listActive(),
            'siteId' => $siteId,
            'stats' => $this->dashboard->data($siteId),
        ]);
    }

    public function data(Request $request): Response
    {
        return $this->json($this->dashboard->data($this->siteFilter($request)));
    }
}
