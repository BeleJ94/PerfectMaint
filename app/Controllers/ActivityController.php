<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DataTableRequest;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ActivityLogRepository;
use App\Repositories\SiteRepository;

/**
 * Journal d'activité : qui a fait quoi, quand, sur quel élément (liste filtrable, synthèse par utilisateur).
 */
final class ActivityController extends Controller
{
    public const ENTITY_TYPES = ['equipment', 'work_order', 'preventive_plan', 'part', 'personnel', 'team', 'site', 'user', 'brand', 'model', 'category', 'settings'];

    /** Actions journalisées (filtre « Action »). */
    public const ACTIONS = [
        'create', 'update', 'archive', 'status', 'transition', 'done',
        'technician', 'technician_remove', 'comment', 'part_consumed',
        'reading', 'fluid_top_up', 'fluid_change', 'assign', 'unassign',
        'photo_add', 'photo_primary', 'photo_delete',
        'stock_in', 'stock_out', 'stock_adjust', 'stock_settings',
        'login', 'login_failed', 'logout',
    ];

    /** Fiche ouverte depuis une entrée du journal : type d'élément => [chemin, permission]. */
    private const ENTITY_LINKS = [
        'equipment' => ['/equipment/{id}', 'equipment.view'],
        'work_order' => ['/work-orders/{id}', 'workorder.view'],
        'preventive_plan' => ['/preventive/{id}/edit', 'preventive.manage'],
        'part' => ['/parts/{id}', 'parts.view'],
        'personnel' => ['/personnel/{id}/edit', 'personnel.manage'],
        'team' => ['/teams/{id}/edit', 'personnel.manage'],
        'site' => ['/sites/{id}', 'dashboard.view'],
        'user' => ['/users/{id}/edit', 'users.manage'],
    ];

    public function __construct(
        ControllerContext $ctx,
        private readonly ActivityLogRepository $activity,
        private readonly SiteRepository $sites,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        $user = $this->user();
        $links = [];
        foreach (self::ENTITY_LINKS as $type => [$path, $permission]) {
            if ($user->can($permission)) {
                $links[$type] = url($path);
            }
        }

        return $this->render('activity/index', [
            'title' => t('nav.activity'),
            'sites' => $this->sites->listActive(),
            'entityTypes' => self::ENTITY_TYPES,
            'actions' => self::ACTIONS,
            'actors' => $this->activity->actors(),
            'filters' => $this->filters($request),
            'links' => $links,
        ]);
    }

    public function data(Request $request): Response
    {
        $dt = DataTableRequest::fromQuery($request->query, ActivityLogRepository::SORTABLE, ['l.id', 'DESC']);

        return $this->json($this->activity->datatable($dt, $this->filters($request)));
    }

    /** Synthèse par utilisateur pour les filtres courants. */
    public function summary(Request $request): Response
    {
        return $this->json(['users' => $this->activity->byUser($this->filters($request))]);
    }

    /** Filtres validés, communs à la page (préremplissage par l'URL), à la liste et à la synthèse. */
    private function filters(Request $request): array
    {
        $type = $request->string('entity_type');
        $action = $request->string('action');
        $from = $request->string('from');
        $to = $request->string('to');
        $isDate = static fn (?string $d): bool => $d !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
        $userId = $request->int('user_id');

        return [
            'user_id' => $userId !== null && $userId >= 0 ? $userId : null,
            'action' => in_array($action, self::ACTIONS, true) ? $action : null,
            'entity_type' => in_array($type, self::ENTITY_TYPES, true) ? $type : null,
            'entity_id' => $request->int('entity_id'),
            'site_id' => $this->siteFilter($request),
            'from' => $isDate($from) ? $from : null,
            'to' => $isDate($to) ? $to : null,
        ];
    }
}
