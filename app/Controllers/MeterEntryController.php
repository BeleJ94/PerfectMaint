<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Domain\MeterForecast;
use App\Repositories\SiteRepository;
use App\Services\MeterEntryService;
use App\Services\MeterMonitorService;
use DateTimeImmutable;

/**
 * Écrans de l'encodeur : saisie des index du jour, rapport de saisie d'une journée (lecture seule)
 * et supervision des index de ses sites (échéances, relevés manquants, évolution).
 */
final class MeterEntryController extends Controller
{
    /** Site de travail de l'encodeur, mémorisé pour la session (0 = tous ses sites). */
    private const SESSION_SITE = 'entry_site';

    public function __construct(
        ControllerContext $ctx,
        private readonly MeterEntryService $entries,
        private readonly SiteRepository $sites,
        private readonly MeterMonitorService $monitor,
    ) {
        parent::__construct($ctx);
    }

    /** Supervision des index sur le site de travail : la même lecture que l'écran mural, adaptée au téléphone. */
    public function supervision(Request $request): Response
    {
        $sites = $this->sites->listActive();
        $siteId = $this->workSite($request, $sites);

        return $this->render('entry/supervision', [
            'title' => t('entry.tab_supervision'),
            'sites' => $sites,
            'siteId' => $siteId,
            'overview' => $this->monitor->overview($siteId),
            'rules' => ['pct' => (int) (MeterForecast::ALERT_RATIO * 100), 'days' => MeterForecast::ALERT_DAYS,
                'stale' => MeterForecast::STALE_DAYS, 'window' => MeterForecast::WINDOW_DAYS],
        ], 200, 'layouts/entry');
    }

    public function supervisionData(Request $request): Response
    {
        return $this->json($this->monitor->overview($this->workSite($request, $this->sites->listActive())));
    }

    public function supervisionSeries(Request $request, array $params): Response
    {
        $days = in_array($request->int('days'), MeterMonitorService::PERIODS, true) ? (int) $request->int('days') : MeterMonitorService::PERIODS[0];

        return $this->json($this->monitor->detail($this->id($params), $days));
    }

    public function index(Request $request): Response
    {
        $sites = $this->sites->listActive();
        $siteId = $this->workSite($request, $sites);

        return $this->render('entry/index', [
            'title' => t('nav.meter_entry'),
            'sites' => $sites,
            'siteId' => $siteId,
            'sheet' => $this->entries->sheet($siteId),
        ], 200, 'layouts/entry');
    }

    /** Rapport de saisie d'une journée : ce qui a été relevé, par qui, et ce qui ne l'a pas été. Aucune modification possible. */
    public function report(Request $request): Response
    {
        $sites = $this->sites->listActive();
        $siteId = $this->workSite($request, $sites);
        $today = new DateTimeImmutable('today');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->string('date')) ?: $today;
        $date = min($date, $today);

        return $this->render('entry/report', [
            'title' => t('entry.report'),
            'sites' => $sites,
            'siteId' => $siteId,
            'date' => $date,
            'today' => $today,
            'report' => $this->entries->report($date, $siteId),
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

    /**
     * Site sur lequel l'encodeur travaille : celui choisi dans le sélecteur (mémorisé), sinon son premier site.
     * Null = tous ses sites.
     *
     * @param list<array<string, mixed>> $sites sites de son périmètre
     */
    private function workSite(Request $request, array $sites): ?int
    {
        $ids = array_map('intval', array_column($sites, 'id'));
        $session = $this->ctx->session;
        if (array_key_exists('site_id', $request->query)) {
            $chosen = $request->int('site_id');
            $session->set(self::SESSION_SITE, $chosen !== null && in_array($chosen, $ids, true) ? $chosen : 0);
        }
        $stored = $session->get(self::SESSION_SITE);
        if ($stored === 0 && count($ids) > 1) {
            return null;
        }

        return is_int($stored) && in_array($stored, $ids, true) ? $stored : ($ids[0] ?? null);
    }
}
