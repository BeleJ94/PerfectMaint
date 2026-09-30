<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DataTableRequest;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ActivityLogRepository;
use App\Repositories\SiteRepository;

final class ActivityController extends Controller
{
    public const ENTITY_TYPES = ['equipment', 'work_order', 'preventive_plan', 'part', 'site', 'user'];

    public function __construct(
        ControllerContext $ctx,
        private readonly ActivityLogRepository $activity,
        private readonly SiteRepository $sites,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        return $this->render('activity/index', [
            'title' => t('nav.activity'),
            'sites' => $this->sites->listActive(),
            'entityTypes' => self::ENTITY_TYPES,
        ]);
    }

    public function data(Request $request): Response
    {
        $dt = DataTableRequest::fromQuery($request->query, ActivityLogRepository::SORTABLE, ['l.id', 'DESC']);
        $type = $request->string('entity_type');
        $from = $request->string('from');
        $to = $request->string('to');
        $isDate = static fn (?string $d): bool => $d !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;

        return $this->json($this->activity->datatable($dt, [
            'entity_type' => in_array($type, self::ENTITY_TYPES, true) ? $type : null,
            'entity_id' => $request->int('entity_id'),
            'site_id' => $this->siteFilter($request),
            'from' => $isDate($from) ? $from : null,
            'to' => $isDate($to) ? $to : null,
        ]));
    }
}
