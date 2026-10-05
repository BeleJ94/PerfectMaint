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
    private const EQUIPMENT_LIMIT = 50;

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

    /** Recherche d'engin sans quitter l'interface terrain (rendu serveur : fonctionne sur réseau faible). */
    public function equipment(Request $request): Response
    {
        $term = mb_substr(trim((string) $request->string('q')), 0, 100);

        return $this->render('field/equipment', [
            'title' => t('field.find_equipment'),
            'term' => $term,
            'items' => $this->equipment->search($term, $this->siteFilter($request), self::EQUIPMENT_LIMIT),
            'limit' => self::EQUIPMENT_LIMIT,
        ], 200, 'layouts/field');
    }

    public function showEquipment(Request $request, array $params): Response
    {
        $item = $this->equipment->find($this->id($params)) ?? throw HttpException::notFound();

        return $this->render('field/equipment_show', [
            'title' => $item['code'],
            'item' => $item,
            'openWorkOrders' => $this->workOrders->openForEquipment((int) $item['id']),
        ], 200, 'layouts/field');
    }
}
