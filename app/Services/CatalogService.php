<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Repositories\CatalogRepository;
use App\Repositories\FuelGaugeRepository;

/** Gestion du référentiel marques / modèles (journalisée). */
final class CatalogService
{
    public function __construct(
        private readonly Database $db,
        private readonly CatalogRepository $catalog,
        private readonly ActivityLogger $logger,
        private readonly FuelGaugeRepository $gauges,
    ) {
    }

    public function createBrand(array $input): int
    {
        $data = Validator::validate($input, ['name' => 'required|string|max:60']);
        if ($this->catalog->brandNameExists($data['name'])) {
            throw Validator::fail('name', 'unique');
        }

        return $this->db->transaction(function () use ($data): int {
            $id = $this->catalog->createBrand($data['name']);
            $this->logger->log('create', 'brand', $id, null, null, $data, $data['name']);

            return $id;
        });
    }

    public function updateBrand(int $id, array $input): void
    {
        $data = Validator::validate($input, ['name' => 'required|string|max:60', 'is_active' => 'bool']);
        if ($this->catalog->brandNameExists($data['name'], $id)) {
            throw Validator::fail('name', 'unique');
        }
        $this->db->transaction(function () use ($id, $data): void {
            $before = $this->catalog->findBrand($id) ?? throw HttpException::notFound();
            $this->catalog->updateBrand($id, $data['name'], $data['is_active']);
            $this->logger->log('update', 'brand', $id, null, $before, $this->catalog->findBrand($id), $data['name']);
        });
    }

    /** @return array{id: int, name: string, brand_id: int, category_id: int|null} */
    public function createModel(array $input): array
    {
        $data = Validator::validate($input, [
            'brand_id' => 'required|int',
            'category_id' => 'int',
            'name' => 'required|string|max:80',
        ]);
        $brand = $this->catalog->findBrand($data['brand_id']) ?? throw Validator::fail('brand_id', 'exists');
        if ($data['category_id'] !== null && $this->catalog->findCategory($data['category_id']) === null) {
            throw Validator::fail('category_id', 'exists');
        }
        if ($this->catalog->modelNameExists($data['brand_id'], $data['name'])) {
            throw Validator::fail('name', 'unique');
        }

        return $this->db->transaction(function () use ($data, $brand): array {
            $id = $this->catalog->createModel($data['brand_id'], $data['category_id'], $data['name']);
            $this->logger->log('create', 'model', $id, null, null, $data, $brand['name'] . ' ' . $data['name']);

            return ['id' => $id, 'name' => $data['name'], 'brand_id' => $data['brand_id'], 'category_id' => $data['category_id']];
        });
    }

    public function updateModel(int $id, array $input): void
    {
        $data = Validator::validate($input, [
            'category_id' => 'int',
            'name' => 'required|string|max:80',
            'is_active' => 'bool',
        ]);
        $this->db->transaction(function () use ($id, $data): void {
            $before = $this->catalog->findModel($id) ?? throw HttpException::notFound();
            if ($this->catalog->modelNameExists((int) $before['brand_id'], $data['name'], $id)) {
                throw Validator::fail('name', 'unique');
            }
            $this->catalog->updateModel($id, $data['category_id'], $data['name'], $data['is_active']);
            $this->logger->log('update', 'model', $id, null, $before, $this->catalog->findModel($id), $before['brand'] . ' ' . $data['name']);
        });
    }

    /** Compteur de suivi par défaut des engins de la catégorie (heures ou km). */
    public function updateCategory(int $id, array $input): void
    {
        $data = Validator::validate($input, ['meter_basis' => 'required|in:hours,km']);
        $this->db->transaction(function () use ($id, $data): void {
            $before = $this->catalog->findCategory($id) ?? throw HttpException::notFound();
            $this->catalog->updateCategoryMeterBasis($id, $data['meter_basis']);
            $this->logger->log('update', 'category', $id, null, ['meter_basis' => $before['meter_basis']], ['meter_basis' => $data['meter_basis']], $before['name']);
        });
    }

    // ------------------------------------------------------------------ Indicateurs de carburant

    /** Type d'indicateur : à graduations (aiguille, barres : N graduations) ou numérique en %. */
    private static function gaugeData(array $input): array
    {
        $data = Validator::validate($input, [
            'name' => 'required|string|max:80',
            'kind' => 'required|in:marks,percent',
            'marks' => 'int|min:1|max:20',
            'is_active' => 'bool',
        ]);
        if ($data['kind'] === 'marks' && $data['marks'] === null) {
            throw Validator::fail('marks', 'required');
        }
        if ($data['kind'] === 'percent') {
            $data['marks'] = null;
        }

        return $data;
    }

    public function createGauge(array $input): int
    {
        $data = self::gaugeData($input);
        if ($this->gauges->nameExists($data['name'])) {
            throw Validator::fail('name', 'unique');
        }

        return $this->db->transaction(function () use ($data): int {
            $id = $this->gauges->create($data['name'], $data['kind'], $data['marks']);
            $this->logger->log('create', 'fuel_gauge', $id, null, null, $data, $data['name']);

            return $id;
        });
    }

    public function updateGauge(int $id, array $input): void
    {
        $data = self::gaugeData($input + ['is_active' => '1']);
        if ($this->gauges->nameExists($data['name'], $id)) {
            throw Validator::fail('name', 'unique');
        }
        $this->db->transaction(function () use ($id, $data): void {
            $before = $this->gauges->find($id) ?? throw HttpException::notFound();
            $this->gauges->update($id, $data['name'], $data['kind'], $data['marks'], (bool) $data['is_active']);
            $this->logger->log('update', 'fuel_gauge', $id, null, $before, $this->gauges->find($id), $data['name']);
        });
    }

    /** Indicateur et capacité du réservoir d'un modèle (repris par ses engins, sauf réglage propre à l'engin). */
    public function setModelFuel(int $modelId, array $input): void
    {
        $data = Validator::validate($input, [
            'fuel_gauge_id' => 'int',
            'tank_capacity_l' => 'int|min:1|max:20000',
        ]);
        if ($data['fuel_gauge_id'] !== null && $this->gauges->find($data['fuel_gauge_id']) === null) {
            throw Validator::fail('fuel_gauge_id', 'exists');
        }
        $this->db->transaction(function () use ($modelId, $data): void {
            $before = $this->catalog->findModel($modelId) ?? throw HttpException::notFound();
            $this->gauges->setModelFuel($modelId, $data['fuel_gauge_id'], $data['tank_capacity_l']);
            $this->logger->log('update', 'model', $modelId, null,
                ['fuel_gauge_id' => $before['fuel_gauge_id'] ?? null, 'tank_capacity_l' => $before['tank_capacity_l'] ?? null],
                $data, $before['brand'] . ' ' . $before['name']);
        });
    }
}
