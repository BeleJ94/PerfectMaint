<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DataTableRequest;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Role;
use App\Repositories\SiteRepository;
use App\Repositories\UserRepository;
use App\Services\UserService;

final class UserController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly UserRepository $users,
        private readonly UserService $service,
        private readonly SiteRepository $sites,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        return $this->render('users/index', ['title' => t('nav.users'), 'roles' => Role::cases()]);
    }

    public function data(Request $request): Response
    {
        $dt = DataTableRequest::fromQuery($request->query, UserRepository::SORTABLE, ['u.last_name', 'ASC']);
        $role = $request->string('role');

        return $this->json($this->users->datatable($dt, ['role' => in_array($role, Role::values(), true) ? $role : null]));
    }

    public function create(Request $request): Response
    {
        return $this->form(['role' => 'technician', 'locale' => 'fr', 'is_active' => 1, 'site_ids' => []], null);
    }

    public function store(Request $request): Response
    {
        $this->service->create($request->body);

        return $this->redirect('/users', t('flash.created'));
    }

    public function edit(Request $request, array $params): Response
    {
        $user = $this->users->find($this->id($params)) ?? throw HttpException::notFound();

        return $this->form($user, (int) $user['id']);
    }

    public function update(Request $request, array $params): Response
    {
        $this->service->update($this->id($params), $request->body);

        return $this->redirect('/users', t('flash.updated'));
    }

    private function form(array $user, ?int $id): Response
    {
        return $this->render('users/form', [
            'title' => $id === null ? t('users.new') : t('users.edit'),
            'item' => $user,
            'id' => $id,
            'roles' => Role::cases(),
            'sites' => $this->sites->listActive(),
            'minPassword' => UserService::MIN_PASSWORD,
        ]);
    }
}
