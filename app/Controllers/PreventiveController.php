<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DataTableRequest;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\EquipmentRepository;
use App\Repositories\PreventivePlanRepository;
use App\Repositories\SiteRepository;
use App\Services\PreventiveService;

final class PreventiveController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly PreventivePlanRepository $plans,
        private readonly PreventiveService $service,
        private readonly EquipmentRepository $equipment,
        private readonly SiteRepository $sites,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        return $this->render('preventive/index', [
            'title' => t('nav.preventive'),
            'sites' => $this->sites->listActive(),
            'filters' => ['site_id' => $this->siteFilter($request), 'due' => $request->string('due')],
        ]);
    }

    public function data(Request $request): Response
    {
        $dt = DataTableRequest::fromQuery($request->query, PreventivePlanRepository::SORTABLE, ['p.next_due_date', 'ASC']);

        return $this->json($this->plans->datatable($dt, [
            'site_id' => $this->siteFilter($request),
            'equipment_id' => $request->int('equipment_id'),
            'due' => $request->string('due'),
        ]));
    }

    public function create(Request $request): Response
    {
        $equipment = $request->int('equipment_id') !== null ? $this->equipment->find((int) $request->int('equipment_id')) : null;

        return $this->form(['is_active' => 1, 'interval_days' => 90], null, $equipment);
    }

    public function store(Request $request): Response
    {
        $this->service->create($request->body);

        return $this->redirect('/preventive', t('flash.created'));
    }

    public function edit(Request $request, array $params): Response
    {
        $plan = $this->plans->find($this->id($params)) ?? throw HttpException::notFound();

        return $this->form($plan, (int) $plan['id'], $this->equipment->find((int) $plan['equipment_id']));
    }

    public function update(Request $request, array $params): Response
    {
        $this->service->update($this->id($params), $request->body);

        return $this->redirect('/preventive', t('flash.updated'));
    }

    public function generate(Request $request): Response
    {
        $created = $this->service->generateDue();
        $message = t('preventive.generated', ['count' => count($created)]);
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'created' => count($created), 'message' => $message]);
        }

        return $this->redirect('/preventive', $message);
    }

    private function form(array $plan, ?int $id, ?array $equipment): Response
    {
        return $this->render('preventive/form', [
            'title' => $id === null ? t('preventive.new') : t('preventive.edit'),
            'item' => $plan,
            'id' => $id,
            'equipment' => $equipment,
        ]);
    }
}
