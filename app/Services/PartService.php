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
    /** Pièce, pneu, lubrifiant (huile, graisse…), filtre, consommable, carburant. */
    public const TYPES = ['part', 'tyre', 'lubricant', 'filter', 'consumable', 'fuel'];

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
            'part_type' => 'in:' . implode(',', self::TYPES),
            'manufacturer' => 'string|max:80',
            'manufacturer_ref' => 'string|max:60',
            'is_wear_part' => 'bool',
            'expected_life_hours' => 'int|min:1|max:200000',
            'expected_life_km' => 'int|min:1|max:5000000',
            'model_ids' => 'array',
            'is_active' => 'bool',
        ];
    }

    public function create(array $input): int
    {
        $data = $this->prepare($input, null);

        return $this->db->transaction(function () use ($data): int {
            $id = $this->parts->create($data);
            $this->parts->setCompatibility($id, $data['model_ids']);
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
            $this->parts->setCompatibility($id, $data['model_ids']);
            $this->logger->log('update', 'part', $id, null, $before, $data, $data['reference']);
        });
    }

    private function prepare(array $input, ?int $id): array
    {
        $data = Validator::validate($input, self::rules());
        $data['reference'] = mb_strtoupper($data['reference']);
        $data['unit_cost'] ??= 0.0;
        $data['part_type'] ??= 'part';
        $data['model_ids'] = array_values(array_filter(array_map('intval', (array) $data['model_ids']), static fn (int $v) => $v > 0));
        if ($this->parts->referenceExists($data['reference'], $id)) {
            throw Validator::fail('reference', 'unique');
        }

        return $data;
    }
}
