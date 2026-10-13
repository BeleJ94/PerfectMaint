<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\SiteRepository;
use App\Services\MeterEntryService;

/** Écran de saisie des index (encodeurs) : pensé pour le téléphone et la tablette, un engin par carte. */
final class MeterEntryController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly MeterEntryService $entries,
        private readonly SiteRepository $sites,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        $siteId = $this->siteFilter($request);

        return $this->render('entry/index', [
            'title' => t('nav.meter_entry'),
            'sites' => $this->sites->listActive(),
            'siteId' => $siteId,
            'sheet' => $this->entries->sheet($siteId),
        ], 200, 'layouts/entry');
    }

    /** Enregistre le relevé d'un engin ; un écart anormal renvoie d'abord une demande de confirmation (409). */
    public function save(Request $request, array $params): Response
    {
        $id = $this->id($params);
        if ($request->input('confirm') === null) {
            $warning = $this->entries->warning($id, $request->body);
            if ($warning !== null) {
                return $this->json(['ok' => false, 'confirm' => $warning], 409);
            }
        }
        $this->entries->save($id, $request->body);

        return $this->json([
            'ok' => true,
            'message' => t('flash.reading_saved'),
            'html' => $this->ctx->view->partial('entry/_card', ['row' => $this->entries->row($id)]),
        ]);
    }
}
