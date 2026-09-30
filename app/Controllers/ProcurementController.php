<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DataTableRequest;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ProcurementRepository;
use App\Repositories\SiteRepository;
use App\Services\PartService;

/** Fenêtre « Besoins achats & magasin » destinée aux équipes achats et magasin. */
final class ProcurementController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly ProcurementRepository $procurement,
        private readonly SiteRepository $sites,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        $siteId = $this->siteFilter($request);

        return $this->render('procurement/index', [
            'title' => t('nav.procurement'),
            'sites' => $this->sites->listActive(),
            'summary' => $this->procurement->summary($siteId),
            'bySite' => $this->procurement->consumptionValueBySite(30),
            'top' => $this->procurement->topConsumed(30, $siteId),
            'filters' => [
                'site_id' => $siteId,
                'part_type' => $request->string('part_type'),
                'level' => $request->string('level') ?? 'action',
            ],
        ]);
    }

    public function data(Request $request): Response
    {
        $dt = DataTableRequest::fromQuery($request->query, ProcurementRepository::SORTABLE, ['x.level_rank', 'ASC']);
        $level = $request->string('level');

        return $this->json($this->procurement->datatable($dt, [
            'site_id' => $this->siteFilter($request),
            'part_type' => in_array($request->string('part_type'), PartService::TYPES, true) ? $request->string('part_type') : null,
            'level' => in_array($level, array_merge(ProcurementRepository::LEVELS, ['action']), true) ? $level : null,
        ]));
    }
}
