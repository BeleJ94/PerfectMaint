<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DataTableRequest;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Domain\WorkOrderStatus;
use App\Domain\Workforce;
use App\Repositories\PersonnelRepository;
use App\Repositories\ActivityLogRepository;
use App\Repositories\EquipmentRepository;
use App\Repositories\PartRepository;
use App\Repositories\SiteRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkOrderRepository;
use App\Services\WorkOrderService;

final class WorkOrderController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly WorkOrderRepository $workOrders,
        private readonly WorkOrderService $service,
        private readonly EquipmentRepository $equipment,
        private readonly SiteRepository $sites,
        private readonly UserRepository $users,
        private readonly PartRepository $parts,
        private readonly ActivityLogRepository $activity,
        private readonly PersonnelRepository $personnel,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        return $this->render('work_orders/index', [
            'title' => t('nav.work_orders'),
            'sites' => $this->sites->listActive(),
            'filters' => [
                'site_id' => $this->siteFilter($request),
                'status' => $request->string('status') ?? 'active',
                'equipment_id' => $request->int('equipment_id'),
            ],
        ]);
    }

    public function data(Request $request): Response
    {
        $dt = DataTableRequest::fromQuery($request->query, WorkOrderRepository::SORTABLE, ['w.id', 'DESC']);
        $status = $request->string('status');
        $allowedStatus = array_merge(WorkOrderStatus::values(), ['active', 'overdue']);

        return $this->json($this->workOrders->datatable($dt, [
            'site_id' => $this->siteFilter($request),
            'status' => in_array($status, $allowedStatus, true) ? $status : null,
            'type' => in_array($request->string('type'), WorkOrderService::TYPES, true) ? $request->string('type') : null,
            'priority' => in_array($request->string('priority'), WorkOrderService::PRIORITIES, true) ? $request->string('priority') : null,
            'equipment_id' => $request->int('equipment_id'),
            'week' => $request->int('week') !== null && $request->int('week') > 200000 ? $request->int('week') : null,
            'closed_days' => in_array($request->int('closed_days'), [7, 30, 90, 365], true) ? $request->int('closed_days') : null,
            'reported_days' => in_array($request->int('reported_days'), [7, 30, 90, 365], true) ? $request->int('reported_days') : null,
            'cause' => in_array($request->string('cause'), array_merge(WorkOrderService::FAILURE_CAUSES, ['usage']), true) ? $request->string('cause') : null,
            'immobilizing' => $request->string('immobilizing') === '1',
            'assigned_to' => $request->string('mine') === '1' ? $this->user()->id : null,
        ]));
    }

    public function create(Request $request): Response
    {
        $equipment = null;
        if ($request->int('equipment_id') !== null) {
            $equipment = $this->equipment->find((int) $request->int('equipment_id'));
        }

        return $this->render('work_orders/form', [
            'title' => t('work_orders.new'),
            'item' => ['priority' => 'normal', 'type' => 'corrective', 'immobilizing' => 1],
            'id' => null,
            'equipment' => $equipment,
            'assignees' => $equipment !== null ? $this->users->assignableForSite((int) $equipment['site_id']) : [],
        ]);
    }

    /** Intervenants possibles pour l'engin choisi (sélecteur dynamique du formulaire). */
    public function assignees(Request $request): Response
    {
        $equipment = $this->equipment->find((int) $request->int('equipment_id')) ?? throw HttpException::notFound();

        return $this->json(['results' => $this->users->assignableForSite((int) $equipment['site_id'])]);
    }

    public function store(Request $request): Response
    {
        $id = $this->service->create($request->body);

        return $this->redirect("/work-orders/$id", t('flash.created'));
    }

    public function show(Request $request, array $params): Response
    {
        $wo = $this->workOrders->find($this->id($params)) ?? throw HttpException::notFound();

        return $this->render('work_orders/show', $this->detailData($wo));
    }

    /** Données communes à la fiche OT (bureau et terrain). */
    public function detailData(array $wo): array
    {
        $status = WorkOrderStatus::from($wo['status']);
        $user = $this->user();
        $canExecute = $user->can('workorder.execute')
            && ($user->can('workorder.manage') || $wo['assigned_to'] === null || (int) $wo['assigned_to'] === $user->id);
        $transitions = array_values(array_filter(
            $status->allowedTransitions(),
            static fn (WorkOrderStatus $s): bool => $s !== WorkOrderStatus::Cancelled || $user->can('workorder.manage')
        ));

        return [
            'title' => $wo['reference'],
            'wo' => $wo,
            'status' => $status,
            'transitions' => $canExecute ? $transitions : [],
            'canExecute' => $canExecute && !$status->isClosed(),
            'parts' => $this->workOrders->parts((int) $wo['id']),
            'availableParts' => $canExecute && !$status->isClosed() ? $this->parts->availableOnSite((int) $wo['site_id']) : [],
            'activity' => $user->can('activity.view') ? $this->activity->forEntity('work_order', (int) $wo['id']) : [],
            'technicians' => $this->workOrders->technicians((int) $wo['id']),
            'comments' => $this->workOrders->comments((int) $wo['id']),
            'maintenanceStaff' => $canExecute ? $this->personnel->forSite((int) $wo['site_id'], Workforce::MAINTENANCE_JOBS) : [],
            'canComment' => $user->can('workorder.execute'),
        ];
    }

    public function edit(Request $request, array $params): Response
    {
        $wo = $this->workOrders->find($this->id($params)) ?? throw HttpException::notFound();
        if (WorkOrderStatus::from($wo['status'])->isClosed()) {
            throw HttpException::forbidden();
        }

        return $this->render('work_orders/form', [
            'title' => t('work_orders.edit') . ' ' . $wo['reference'],
            'item' => $wo,
            'id' => (int) $wo['id'],
            'equipment' => $this->equipment->find((int) $wo['equipment_id']),
            'assignees' => $this->users->assignableForSite((int) $wo['site_id']),
        ]);
    }

    public function update(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->service->update($id, $request->body);

        return $this->redirect("/work-orders/$id", t('flash.updated'));
    }

    public function transition(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->service->transition($id, $request->body);
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'message' => t('flash.status_changed')]);
        }
        $back = $request->string('back') === 'terrain' ? "/terrain/ot/$id" : "/work-orders/$id";

        return $this->redirect($back, t('flash.status_changed'));
    }

    public function addTechnician(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->service->addTechnician($id, $request->body);

        return $this->redirect($this->back($request, $id), t('flash.technician_added'));
    }

    public function removeTechnician(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->service->removeTechnician($id, (int) $params['technicianId']);

        return $this->redirect($this->back($request, $id), t('flash.updated'));
    }

    public function comment(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->service->addComment($id, $request->body);
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'message' => t('flash.comment_added')]);
        }

        return $this->redirect($this->back($request, $id) . '#comments', t('flash.comment_added'));
    }

    private function back(Request $request, int $id): string
    {
        return $request->string('back') === 'terrain' ? "/terrain/ot/$id" : "/work-orders/$id";
    }

    public function addPart(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->service->consumePart($id, $request->body);
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'message' => t('flash.part_added')]);
        }
        $back = $request->string('back') === 'terrain' ? "/terrain/ot/$id" : "/work-orders/$id";

        return $this->redirect($back, t('flash.part_added'));
    }
}
