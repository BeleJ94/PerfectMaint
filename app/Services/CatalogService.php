<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Repositories\CatalogRepository;

/** Gestion du référentiel marques / modèles (journalisée). */
final class CatalogService
{
    public function __construct(
        private readonly Database $db,
        private readonly CatalogRepository $catalog,
        private readonly ActivityLogger $logger,
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
}
