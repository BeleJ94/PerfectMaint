<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Repositories\PartRepository;

/** Catalogue des pièces détachées (référentiel commun aux sites). */
final class PartService
{
    public function __construct(
        private readonly Database $db,
        private readonly PartRepository $parts,
        private readonly ActivityLogger $logger,
    ) {
    }

    private static function rules(): array
    {
        return [
            'reference' => 'required|string|max:40',
            'name' => 'required|string|max:150',
            'unit' => 'required|string|max:10',
            'unit_cost' => 'numeric|min:0|max:1000000',
            'is_active' => 'bool',
        ];
    }

    public function create(array $input): int
    {
        $data = $this->prepare($input, null);

        return $this->db->transaction(function () use ($data): int {
            $id = $this->parts->create($data);
            $this->logger->log('create', 'part', $id, null, null, $data, $data['reference'] . ' – ' . $data['name']);

            return $id;
        });
    }

    public function update(int $id, array $input): void
    {
        $data = $this->prepare($input, $id);
        $this->db->transaction(function () use ($id, $data): void {
            $before = $this->parts->find($id) ?? throw HttpException::notFound();
            $this->parts->update($id, $data);
            $this->logger->log('update', 'part', $id, null, $before, $data, $data['reference']);
        });
    }

    private function prepare(array $input, ?int $id): array
    {
        $data = Validator::validate($input, self::rules());
        $data['reference'] = mb_strtoupper($data['reference']);
        $data['unit_cost'] ??= 0.0;
        if ($this->parts->referenceExists($data['reference'], $id)) {
            throw Validator::fail('reference', 'unique');
        }

        return $data;
    }
}
