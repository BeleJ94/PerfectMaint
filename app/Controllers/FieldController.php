<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\EquipmentRepository;
use App\Repositories\WorkOrderRepository;
use App\Services\WorkOrderService;

/**
 * Écrans terrain, pensés mobile d'abord : file de travail, déclaration de panne, exécution d'un OT.
 */
final class FieldController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly WorkOrderRepository $workOrders,
        private readonly WorkOrderService $service,
        private readonly EquipmentRepository $equipment,
        private readonly WorkOrderController $workOrderController,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        return $this->render('field/index', [
            'title' => t('nav.field'),
            'queue' => $this->workOrders->fieldQueue($this->user()->id),
        ], 200, 'layouts/field');
    }

    public function declare(Request $request): Response
    {
        $equipment = $request->int('equipment_id') !== null ? $this->equipment->find((int) $request->int('equipment_id')) : null;

        return $this->render('field/declare', [
            'title' => t('field.declare'),
            'equipment' => $equipment,
        ], 200, 'layouts/field');
    }

    public function storeDeclaration(Request $request): Response
    {
        $input = $request->body;
        $input['type'] = 'corrective';
        $input['assigned_to'] = null;
        $id = $this->service->create($input);

        return $this->redirect("/terrain/ot/$id", t('field.declared'));
    }

    public function show(Request $request, array $params): Response
    {
        $wo = $this->workOrders->find($this->id($params)) ?? throw HttpException::notFound();

        return $this->render('field/show', $this->workOrderController->detailData($wo), 200, 'layouts/field');
    }
}
