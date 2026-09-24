<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DataTableRequest;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Domain\EquipmentStatus;
use App\Repositories\ActivityLogRepository;
use App\Repositories\EquipmentRepository;
use App\Repositories\PreventivePlanRepository;
use App\Repositories\SiteRepository;
use App\Repositories\WorkOrderRepository;
use App\Services\EquipmentService;

final class EquipmentController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly EquipmentRepository $equipment,
        private readonly EquipmentService $service,
        private readonly SiteRepository $sites,
        private readonly WorkOrderRepository $workOrders,
        private readonly PreventivePlanRepository $plans,
        private readonly ActivityLogRepository $activity,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        return $this->render('equipment/index', [
            'title' => t('nav.equipment'),
            'sites' => $this->sites->listActive(),
            'categories' => $this->equipment->categories(),
            'statuses' => EquipmentStatus::cases(),
            'filters' => ['site_id' => $this->siteFilter($request), 'status' => $request->string('status')],
        ]);
    }

    public function data(Request $request): Response
    {
        $dt = DataTableRequest::fromQuery($request->query, EquipmentRepository::SORTABLE, ['e.code', 'ASC']);

        return $this->json($this->equipment->datatable($dt, [
            'site_id' => $this->siteFilter($request),
            'status' => in_array($request->string('status'), EquipmentStatus::values(), true) ? $request->string('status') : null,
            'category_id' => $request->int('category_id'),
        ]));
    }

    public function search(Request $request): Response
    {
        $term = (string) $request->string('q');
        if (mb_strlen($term) < 1) {
            return $this->json(['results' => []]);
        }

        return $this->json(['results' => $this->equipment->search($term, $this->siteFilter($request))]);
    }

    public function show(Request $request, array $params): Response
    {
        $item = $this->equipment->find($this->id($params)) ?? throw HttpException::notFound();

        return $this->render('equipment/show', [
            'title' => $item['code'],
            'item' => $item,
            'statuses' => EquipmentStatus::cases(),
            'history' => $this->equipment->statusHistory((int) $item['id']),
            'workOrders' => $this->workOrders->recentForEquipment((int) $item['id']),
            'plans' => $this->plans->forEquipment((int) $item['id']),
            'activity' => $this->user()->can('activity.view') ? $this->activity->forEntity('equipment', (int) $item['id']) : [],
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(['site_id' => $this->siteFilter($request)], null);
    }

    public function store(Request $request): Response
    {
        $id = $this->service->create($request->body);

        return $this->redirect("/equipment/$id", t('flash.created'));
    }

    public function edit(Request $request, array $params): Response
    {
        $item = $this->equipment->find($this->id($params)) ?? throw HttpException::notFound();

        return $this->form($item, (int) $item['id']);
    }

    public function update(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->service->update($id, $request->body);

        return $this->redirect("/equipment/$id", t('flash.updated'));
    }

    public function status(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->service->changeStatus($id, $request->body);
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'message' => t('flash.status_changed')]);
        }

        return $this->redirect("/equipment/$id", t('flash.status_changed'));
    }

    public function archive(Request $request, array $params): Response
    {
        $this->service->archive($this->id($params));

        return $this->redirect('/equipment', t('flash.archived'));
    }

    private function form(array $item, ?int $id): Response
    {
        return $this->render('equipment/form', [
            'title' => $id === null ? t('equipment.new') : t('equipment.edit'),
            'item' => $item,
            'id' => $id,
            'sites' => $this->sites->listActive(),
            'categories' => $this->equipment->categories(),
        ]);
    }
}
