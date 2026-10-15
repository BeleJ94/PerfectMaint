<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DataTableRequest;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\EquipmentDocumentRepository;
use App\Repositories\EquipmentRepository;
use App\Repositories\SiteRepository;
use App\Services\EquipmentDocumentService;

/**
 * Documents de bord : vue d'ensemble des documents à échéance de tout le parc (assurance, contrôle technique,
 * vignette, stationnement…), une ligne par engin et par type, et saisie depuis la liste.
 */
final class DocumentController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly EquipmentDocumentRepository $documents,
        private readonly EquipmentDocumentService $service,
        private readonly EquipmentRepository $equipment,
        private readonly SiteRepository $sites,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        return $this->render('documents/index', [
            'title' => t('nav.documents'),
            'sites' => $this->sites->listActive(),
            'types' => $this->documents->types(),
            'filters' => $filters,
            'summary' => $this->documents->boardSummary($filters),
        ]);
    }

    public function data(Request $request): Response
    {
        $dt = DataTableRequest::fromQuery($request->query, EquipmentDocumentRepository::SORTABLE, ['x.state_rank, x.expires_at, x.code', 'ASC']);

        $result = $this->documents->datatable($dt, $this->filters($request));
        $canManage = $this->user()->can('documents.manage');
        foreach ($result['data'] as &$row) {
            $row['file_url'] = $row['file_name'] !== null ? url('/equipment/' . $row['equipment_id'] . '/documents/' . $row['id'] . '/file') : null;
            $row['renew_url'] = $canManage ? url('/documents/create', ['equipment_id' => $row['equipment_id'], 'document_type_id' => $row['document_type_id']]) : null;
            unset($row['file_name']);
        }

        return $this->json($result);
    }

    /** Formulaire de saisie hors fiche engin (engin et type présélectionnables). */
    public function create(Request $request): Response
    {
        $equipment = $request->int('equipment_id') !== null ? $this->equipment->find((int) $request->int('equipment_id')) : null;

        return $this->render('documents/form', [
            'title' => t('documents.add'),
            'equipment' => $equipment,
            'types' => $this->documents->types(),
            'typeId' => $request->int('document_type_id'),
        ]);
    }

    public function store(Request $request): Response
    {
        $equipmentId = (int) $request->int('equipment_id');
        if ($equipmentId <= 0) {
            throw \App\Core\Validator::fail('equipment_id', 'required');
        }
        $this->service->add($equipmentId, $request->body, $request->file('document_file'));

        return $this->redirect('/documents', t('flash.document_saved'));
    }

    /** @return array{site_id: ?int, document_type_id: ?int, state: ?string} */
    private function filters(Request $request): array
    {
        $state = $request->string('state');

        return [
            'site_id' => $this->siteFilter($request),
            'document_type_id' => $request->int('document_type_id'),
            'state' => in_array($state, array_merge(EquipmentDocumentRepository::STATES, ['action']), true) ? $state : null,
        ];
    }
}
