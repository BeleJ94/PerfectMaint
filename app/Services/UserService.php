<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Domain\Role;
use App\Repositories\UserRepository;
use App\Security\Auth;

final class UserService
{
    public const MIN_PASSWORD = 10;

    public function __construct(
        private readonly Database $db,
        private readonly UserRepository $users,
        private readonly ActivityLogger $logger,
        private readonly Auth $auth,
    ) {
    }

    private static function rules(bool $passwordRequired): array
    {
        return [
            'email' => 'required|email|max:190',
            'first_name' => 'required|string|max:80',
            'last_name' => 'required|string|max:80',
            'role' => 'required|in:' . implode(',', Role::values()),
            'locale' => 'required|in:fr,en',
            'all_sites' => 'bool',
            'is_active' => 'bool',
            'site_ids' => 'array',
            'password' => ($passwordRequired ? 'required|' : '') . 'string|min:' . self::MIN_PASSWORD . '|max:200',
        ];
    }

    public function create(array $input): int
    {
        $data = $this->prepare($input, null);

        return $this->db->transaction(function () use ($data): int {
            $id = $this->users->create($data, password_hash((string) $data['password'], PASSWORD_DEFAULT));
            $this->users->syncSites($id, $data['site_ids']);
            unset($data['password']);
            $this->logger->log('create', 'user', $id, null, null, $data, $data['email']);

            return $id;
        });
    }

    public function update(int $id, array $input): void
    {
        $data = $this->prepare($input, $id);
        if ($id === $this->auth->user()?->id && (!$data['is_active'] || $data['role'] !== Role::Admin->value)) {
            // Empêche un administrateur de se retirer ses propres droits par erreur.
            throw Validator::fail('role', 'self_lockout');
        }
        $this->db->transaction(function () use ($id, $data): void {
            $before = $this->users->find($id) ?? throw HttpException::notFound();
            $this->users->update($id, $data);
            $this->users->syncSites($id, $data['site_ids']);
            if ($data['password'] !== null) {
                $this->users->updatePassword($id, password_hash($data['password'], PASSWORD_DEFAULT));
            }
            $after = $data;
            $after['password'] = $data['password'] !== null ? 'changed' : null;
            unset($before['last_login_at']);
            $this->logger->log('update', 'user', $id, null, $before + ['password' => null], $after, $data['email']);
        });
    }

    private function prepare(array $input, ?int $id): array
    {
        $data = Validator::validate($input, self::rules($id === null));
        $data['email'] = mb_strtolower($data['email']);
        if ($this->users->emailExists($data['email'], $id)) {
            throw Validator::fail('email', 'unique');
        }
        $data['site_ids'] = array_values(array_unique(array_map('intval', $data['site_ids'])));
        if ($data['role'] !== Role::Admin->value && !$data['all_sites'] && $data['site_ids'] === []) {
            throw Validator::fail('site_ids', 'required');
        }

        return $data;
    }
}
