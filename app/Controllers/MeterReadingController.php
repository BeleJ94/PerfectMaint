<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DataTableRequest;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\EquipmentRepository;
use App\Repositories\MeterReadingRepository;
use App\Repositories\SiteRepository;
use App\Services\MeterReadingService;

/** Répertoire général des index (administrateur) : tous les relevés, modification et suppression. */
final class MeterReadingController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly MeterReadingRepository $readings,
        private readonly MeterReadingService $service,
        private readonly SiteRepository $sites,
        private readonly EquipmentRepository $equipment,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        // Lien depuis une fiche engin (?equipment_id=) : filtre prérempli.
        $equipmentId = $request->int('equipment_id');

        return $this->render('readings/index', [
            'title' => t('nav.readings'),
            'equipment' => $equipmentId !== null ? $this->equipment->find($equipmentId) : null,
            'sites' => $this->sites->listActive(),
            'recorders' => $this->readings->recorders(),
        ]);
    }

    public function data(Request $request): Response
    {
        $dt = DataTableRequest::fromQuery($request->query, MeterReadingRepository::SORTABLE, ['r.recorded_at', 'DESC']);
        $date = static fn (?string $v): ?string => $v !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;

        return $this->json($this->readings->datatable($dt, [
            'site_id' => $request->int('site_id'),
            'equipment_id' => $request->int('equipment_id'),
            'user_id' => $request->int('user_id'),
            'from' => $date($request->string('from')),
            'to' => $date($request->string('to')),
        ]));
    }

    public function edit(Request $request, array $params): Response
    {
        $reading = $this->readings->find($this->id($params)) ?? throw HttpException::notFound();

        return $this->render('readings/form', ['title' => t('readings.edit'), 'item' => $reading]);
    }

    public function update(Request $request, array $params): Response
    {
        $this->service->update($this->id($params), $request->body);

        // Modale du répertoire (AJAX) : réponse JSON ; formulaire classique : retour à la liste.
        return $request->wantsJson()
            ? $this->json(['ok' => true, 'message' => t('flash.updated')])
            : $this->redirect('/readings', t('flash.updated'));
    }

    public function delete(Request $request, array $params): Response
    {
        $this->service->delete($this->id($params));

        return $request->wantsJson()
            ? $this->json(['ok' => true, 'message' => t('flash.reading_deleted')])
            : $this->redirect('/readings', t('flash.reading_deleted'));
    }
}
