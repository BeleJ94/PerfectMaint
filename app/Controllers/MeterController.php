<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Domain\MeterForecast;
use App\Repositories\SiteRepository;
use App\Services\MeterMonitorService;

/** Supervision des index : échéances au compteur projetées et évolution par engin. */
final class MeterController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly MeterMonitorService $monitor,
        private readonly SiteRepository $sites,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        $siteId = $this->siteFilter($request);

        return $this->render('meters/index', [
            'title' => t('nav.meters'),
            'sites' => $this->sites->listActive(),
            'siteId' => $siteId,
            'overview' => $this->monitor->overview($siteId),
            'selected' => $request->int('equipment_id'),
            'rules' => self::rules(),
        ]);
    }

    /**
     * Affichage mural pour une salle de supervision : plein écran, sans interaction, actualisé seul.
     * ?rotate=sites alterne les sites au lieu de les regrouper.
     */
    public function screen(Request $request): Response
    {
        $siteId = $this->siteFilter($request);
        $siteName = null;
        foreach ($this->sites->listActive() as $site) {
            if ((int) $site['id'] === $siteId) {
                $siteName = $site['name'];
            }
        }

        return $this->render('meters/screen', [
            'title' => t('nav.meters'),
            'siteId' => $siteId,
            'siteName' => $siteName,
            'overview' => $this->monitor->overview($siteId),
            'rotate' => $siteId === null && $request->string('rotate') === 'sites',
            'rules' => self::rules(),
        ], 200, 'layouts/screen');
    }

    /** Seuils affichés dans les libellés d'aide. */
    private static function rules(): array
    {
        return ['pct' => (int) (MeterForecast::ALERT_RATIO * 100), 'days' => MeterForecast::ALERT_DAYS, 'stale' => MeterForecast::STALE_DAYS, 'window' => MeterForecast::WINDOW_DAYS];
    }

    public function data(Request $request): Response
    {
        return $this->json($this->monitor->overview($this->siteFilter($request)));
    }

    public function series(Request $request, array $params): Response
    {
        $days = in_array($request->int('days'), MeterMonitorService::PERIODS, true) ? (int) $request->int('days') : MeterMonitorService::PERIODS[0];

        return $this->json($this->monitor->detail($this->id($params), $days));
    }
}
