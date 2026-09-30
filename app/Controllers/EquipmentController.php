<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DataTableRequest;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Domain\EquipmentStatus;
use App\Domain\Workforce;
use App\Repositories\ActivityLogRepository;
use App\Repositories\AssignmentRepository;
use App\Repositories\PersonnelRepository;
use App\Repositories\CatalogRepository;
use App\Repositories\EquipmentPhotoRepository;
use App\Repositories\FluidRepository;
use App\Repositories\PartRepository;
use App\Services\FluidService;
use App\Repositories\EquipmentRepository;
use App\Repositories\PreventivePlanRepository;
use App\Repositories\SiteRepository;
use App\Repositories\WorkOrderRepository;
use App\Services\EquipmentService;
use App\Services\MeterService;
use App\Services\PhotoService;

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
        private readonly CatalogRepository $catalog,
        private readonly EquipmentPhotoRepository $photos,
        private readonly MeterService $meters,
        private readonly PhotoService $photoService,
        private readonly AssignmentRepository $assignments,
        private readonly PersonnelRepository $personnel,
        private readonly PartRepository $parts,
        private readonly FluidRepository $fluids,
        private readonly FluidService $fluidService,
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
            'contract_days' => in_array($request->int('contract_days'), [30, 60, 90], true) ? $request->int('contract_days') : null,
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
            'photos' => $this->photos->forEquipment((int) $item['id']),
            'readings' => $this->equipment->readings((int) $item['id']),
            'assignments' => $this->assignments->current((int) $item['id']),
            'compatibleParts' => $item['model_id'] !== null ? $this->parts->forModel((int) $item['model_id'], (int) $item['site_id']) : [],
            'fluidLogs' => $this->fluids->forEquipment((int) $item['id']),
            'fluidRates' => $this->fluids->ratesForEquipment((int) $item['id']),
            'fluidOptions' => $this->user()->can('equipment.reading') ? $this->parts->fluidsOnSite((int) $item['site_id'], $item['model_id'] !== null ? (int) $item['model_id'] : null) : [],
            'assignmentHistory' => $this->assignments->history((int) $item['id']),
            'operators' => $this->user()->can('equipment.assign') ? $this->personnel->forSite((int) $item['site_id'], Workforce::OPERATOR_JOBS) : [],
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

    /** Relevé de compteurs / carburant (dialogue fiche engin ou écran terrain). */
    public function reading(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->meters->record($id, $request->body);
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'message' => t('flash.reading_saved')]);
        }

        return $this->redirect("/equipment/$id", t('flash.reading_saved'));
    }

    /** Appoint / vidange de lubrifiant ou carburant. */
    public function fluid(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->fluidService->log($id, $request->body);
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'message' => t('flash.fluid_saved')]);
        }

        return $this->redirect("/equipment/$id", t('flash.fluid_saved'));
    }

    public function uploadPhoto(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $photoId = $this->photoService->upload($id, $request->file('photo'), $request->string('caption'));
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'id' => $photoId, 'url' => url("/equipment/$id/photos/$photoId"), 'message' => t('flash.photo_added')]);
        }

        return $this->redirect("/equipment/$id", t('flash.photo_added'));
    }

    /** Sert une photo après contrôle du périmètre (fichiers hors racine web). */
    public function photo(Request $request, array $params): Response
    {
        $file = $this->photoService->path($this->id($params), (int) $params['photoId']) ?? throw HttpException::notFound();
        $etag = '"' . $file['file_name'] . '"';
        $headers = [
            'Content-Type' => $file['mime'],
            'Cache-Control' => 'private, max-age=604800, immutable',
            'ETag' => $etag,
            'Content-Disposition' => 'inline',
        ];
        if ($request->header('if-none-match') === $etag) {
            return new Response('', 304, $headers);
        }

        return new Response((string) file_get_contents($file['path']), 200, $headers);
    }

    public function primaryPhoto(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->photoService->setPrimary($id, (int) $params['photoId']);
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'message' => t('flash.updated')]);
        }

        return $this->redirect("/equipment/$id", t('flash.updated'));
    }

    public function deletePhoto(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->photoService->delete($id, (int) $params['photoId']);

        return $this->redirect("/equipment/$id", t('flash.photo_deleted'));
    }

    private function form(array $item, ?int $id): Response
    {
        return $this->render('equipment/form', [
            'title' => $id === null ? t('equipment.new') : t('equipment.edit'),
            'item' => $item,
            'id' => $id,
            'sites' => $this->sites->listActive(),
            'categories' => $this->catalog->categories(isset($item['category_id']) ? (int) $item['category_id'] : null),
            'brands' => $this->catalog->brands(true),
            'models' => $this->catalog->models(true, isset($item['model_id']) ? (int) $item['model_id'] : null),
        ]);
    }
}
