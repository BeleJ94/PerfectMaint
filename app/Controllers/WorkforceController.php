<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DataTableRequest;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Workforce;
use App\Repositories\PersonnelRepository;
use App\Repositories\SiteRepository;
use App\Repositories\TeamRepository;
use App\Services\WorkforceService;

/** Équipes par shift, personnel de terrain et affectations aux engins. */
final class WorkforceController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly TeamRepository $teams,
        private readonly PersonnelRepository $personnel,
        private readonly SiteRepository $sites,
        private readonly WorkforceService $service,
    ) {
        parent::__construct($ctx);
    }

    // ---------------------------------------------------------------- Équipes

    public function teams(Request $request): Response
    {
        $siteId = $this->siteFilter($request);
        $teams = $this->teams->list($siteId);
        foreach ($teams as &$team) {
            $team['members'] = $this->personnel->members((int) $team['id']);
        }
        unset($team);

        return $this->render('teams/index', [
            'title' => t('nav.teams'),
            'teams' => $teams,
            'sites' => $this->sites->listActive(),
            'siteId' => $siteId,
        ]);
    }

    public function createTeam(Request $request): Response
    {
        return $this->teamForm(['site_id' => $this->siteFilter($request), 'kind' => 'maintenance', 'shift' => 'day', 'is_active' => 1], null);
    }

    public function storeTeam(Request $request): Response
    {
        $this->service->createTeam($request->body);

        return $this->redirect('/teams', t('flash.created'));
    }

    public function editTeam(Request $request, array $params): Response
    {
        $team = $this->teams->find($this->id($params)) ?? throw HttpException::notFound();

        return $this->teamForm($team, (int) $team['id']);
    }

    public function updateTeam(Request $request, array $params): Response
    {
        $this->service->updateTeam($this->id($params), $request->body);

        return $this->redirect('/teams', t('flash.updated'));
    }

    private function teamForm(array $item, ?int $id): Response
    {
        $people = [];
        foreach ($this->sites->listActive() as $site) {
            foreach ($this->personnel->forSite((int) $site['id']) as $person) {
                $people[] = $person + ['site_id' => $site['id']];
            }
        }

        return $this->render('teams/form', [
            'title' => $id === null ? t('teams.new') : t('teams.edit'),
            'item' => $item,
            'id' => $id,
            'sites' => $this->sites->listActive(),
            'people' => $people,
        ]);
    }

    // ---------------------------------------------------------------- Personnel

    public function personnel(Request $request): Response
    {
        return $this->render('personnel/index', [
            'title' => t('nav.personnel'),
            'sites' => $this->sites->listActive(),
            'teams' => $this->teams->list(),
            'filters' => ['site_id' => $this->siteFilter($request), 'job' => $request->string('job'), 'team_id' => $request->int('team_id')],
        ]);
    }

    public function personnelData(Request $request): Response
    {
        $dt = DataTableRequest::fromQuery($request->query, PersonnelRepository::SORTABLE, ['p.last_name', 'ASC']);

        return $this->json($this->personnel->datatable($dt, [
            'site_id' => $this->siteFilter($request),
            'job' => in_array($request->string('job'), Workforce::JOBS, true) ? $request->string('job') : null,
            'team_id' => $request->int('team_id'),
            'inactive' => $request->string('inactive') === '1',
        ]));
    }

    public function createPerson(Request $request): Response
    {
        return $this->personForm([
            'site_id' => $this->siteFilter($request), 'team_id' => $request->int('team_id'),
            'job' => $request->string('job') ?? 'driver', 'is_active' => 1,
        ], null, $request->string('back'));
    }

    public function storePerson(Request $request): Response
    {
        $this->service->createPerson($request->body);
        $back = $request->string('back') === 'teams' ? '/teams' : '/personnel';

        return $this->redirect($back, t('flash.created'));
    }

    public function editPerson(Request $request, array $params): Response
    {
        $person = $this->personnel->find($this->id($params)) ?? throw HttpException::notFound();

        return $this->personForm($person, (int) $person['id']);
    }

    public function updatePerson(Request $request, array $params): Response
    {
        $this->service->updatePerson($this->id($params), $request->body);

        return $this->redirect('/personnel', t('flash.updated'));
    }

    private function personForm(array $item, ?int $id, ?string $back = null): Response
    {
        return $this->render('personnel/form', [
            'title' => $id === null ? t('personnel.new') : t('personnel.edit'),
            'back' => $back,
            'item' => $item,
            'id' => $id,
            'sites' => $this->sites->listActive(),
            'teams' => $this->teams->list(),
        ]);
    }

    // ---------------------------------------------------------------- Affectations aux engins

    public function assign(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->service->assign($id, $request->body);
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'message' => t('flash.assigned')]);
        }

        return $this->redirect("/equipment/$id", t('flash.assigned'));
    }

    public function unassign(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->service->unassign($id, (int) $params['assignmentId']);

        return $this->redirect("/equipment/$id", t('flash.unassigned'));
    }
}
