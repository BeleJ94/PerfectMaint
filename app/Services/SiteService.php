<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Repositories\SiteRepository;

final class SiteService
{
    /** Type d'activité : bureau, terrassement / roulage minier, transport de personnel. */
    public const ACTIVITIES = ['office', 'earthmoving', 'transport'];

    public function __construct(
        private readonly Database $db,
        private readonly SiteRepository $sites,
        private readonly ActivityLogger $logger,
    ) {
    }

    private static function rules(): array
    {
        return [
            'code' => 'required|string|max:20',
            'name' => 'required|string|max:120',
            'activity' => 'required|in:' . implode(',', self::ACTIVITIES),
            'city' => 'string|max:120',
            'address' => 'string|max:255',
            'is_active' => 'bool',
        ];
    }

    public function create(array $input): int
    {
        $data = Validator::validate($input, self::rules());
        $data['code'] = mb_strtoupper($data['code']);
        if ($this->sites->codeExists($data['code'])) {
            throw Validator::fail('code', 'unique');
        }

        return $this->db->transaction(function () use ($data): int {
            $id = $this->sites->create($data);
            $this->logger->log('create', 'site', $id, $id, null, $data, $data['name']);

            return $id;
        });
    }

    public function update(int $id, array $input): void
    {
        $data = Validator::validate($input, self::rules());
        $data['code'] = mb_strtoupper($data['code']);
        if ($this->sites->codeExists($data['code'], $id)) {
            throw Validator::fail('code', 'unique');
        }
        $this->db->transaction(function () use ($id, $data): void {
            $before = $this->sites->find($id) ?? throw HttpException::notFound();
            $this->sites->update($id, $data);
            $this->logger->log('update', 'site', $id, $id, $before, $data, $data['name']);
        });
    }
}
