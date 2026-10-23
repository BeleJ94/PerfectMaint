<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\EquipmentRepository;
use App\Repositories\WorkOrderRepository;

/**
 * Recherche rapide (Ctrl + K) : engins (code, nom, plaque, châssis) et OT (référence, titre),
 * dans le périmètre de l'utilisateur et selon ses droits. Les pages du menu sont filtrées côté navigateur.
 */
final class SearchController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly EquipmentRepository $equipment,
        private readonly WorkOrderRepository $workOrders,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        $term = trim((string) $request->string('q'));
        if (mb_strlen($term) < 2) {
            return $this->json(['equipment' => [], 'work_orders' => []]);
        }
        $user = $this->user();

        return $this->json([
            'equipment' => $user->can('equipment.view') ? array_map(static fn (array $e) => [
                'id' => (int) $e['id'], 'code' => $e['code'], 'name' => $e['name'], 'site' => $e['site'], 'status' => $e['status'],
                'url' => url('/equipment/' . $e['id']),
            ], $this->equipment->search($term, null, 6)) : [],
            'work_orders' => $user->can('workorder.view') ? array_map(static fn (array $w) => [
                'id' => (int) $w['id'], 'reference' => $w['reference'], 'title' => $w['title'], 'status' => $w['status'],
                'equipment' => $w['equipment_code'], 'site' => $w['site'], 'url' => url('/work-orders/' . $w['id']),
            ], $this->workOrders->quickSearch($term)) : [],
        ]);
    }
}
