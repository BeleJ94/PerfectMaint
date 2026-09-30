<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Domain\EquipmentStatus;
use App\Repositories\EquipmentRepository;
use App\Repositories\PartRepository;
use App\Repositories\PreventivePlanRepository;
use App\Repositories\SiteRepository;
use App\Repositories\TeamRepository;
use App\Repositories\WorkOrderRepository;
use App\Services\PreventiveService;

/** Fenêtre dédiée à un site : parc, disponibilité, interventions en cours. */
final class SiteOverviewController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly SiteRepository $sites,
        private readonly EquipmentRepository $equipment,
        private readonly WorkOrderRepository $workOrders,
        private readonly PreventivePlanRepository $plans,
        private readonly PartRepository $parts,
        private readonly TeamRepository $teams,
    ) {
        parent::__construct($ctx);
    }

    public function show(Request $request, array $params): Response
    {
        $site = $this->sites->find($this->id($params)) ?? throw HttpException::notFound();
        $siteId = (int) $site['id'];

        $byStatus = $this->equipment->countByStatus($siteId);
        $counts = [];
        foreach (EquipmentStatus::cases() as $status) {
            $counts[$status->value] = $byStatus[$status->value] ?? 0;
        }
        $total = array_sum($counts);

        return $this->render('sites/overview', [
            'title' => $site['name'],
            'site' => $site,
            'counts' => $counts,
            'total' => $total,
            'availability' => $total > 0 ? round($counts['available'] / $total * 100, 1) : null,
            'fleet' => $this->equipment->fleetForSite($siteId),
            'workOrders' => $this->workOrders->openForSite($siteId),
            'woKpis' => $this->workOrders->kpis($siteId),
            'preventiveDue' => $this->plans->countDue(PreventiveService::DEFAULT_HORIZON_DAYS, $siteId),
            'partsLow' => $this->parts->countBelowMin($siteId),
            'contracts' => $this->equipment->contractsEnding(30, $siteId),
            'teams' => $this->teams->list($siteId),
        ]);
    }
}
