<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Référentiel commun à tous les sites : catégories, marques et modèles d'engins.
 * Données non rattachées à un site : pas de clause de périmètre.
 */
final class CatalogRepository extends Repository
{
    /** Catégories actives (+ $includeId, pour ne pas perdre la valeur d'un engin existant). */
    public function categories(?int $includeId = null): array
    {
        return $this->db->fetchAll(
            'SELECT id, code, name, family, meter_basis FROM equipment_categories WHERE is_active = 1 OR id = :inc ORDER BY family, name',
            ['inc' => $includeId ?? 0]
        );
    }

    public function findCategory(int $id): ?array
    {
        return $this->db->fetchOne('SELECT id, code, name, family, meter_basis FROM equipment_categories WHERE id = :id', ['id' => $id]);
    }

    public function updateCategoryMeterBasis(int $id, string $basis): void
    {
        $this->db->execute('UPDATE equipment_categories SET meter_basis = :b WHERE id = :id', ['b' => $basis, 'id' => $id]);
    }

    /** Marques avec nombre de modèles et d'engins (tous sites). */
    public function brands(bool $activeOnly = false): array
    {
        return $this->db->fetchAll(
            'SELECT b.id, b.name, b.is_active,
                    (SELECT COUNT(*) FROM equipment_models m WHERE m.brand_id = b.id) AS model_count,
                    (SELECT COUNT(*) FROM equipment e WHERE e.brand_id = b.id AND e.is_archived = 0) AS equipment_count
               FROM equipment_brands b' . ($activeOnly ? ' WHERE b.is_active = 1' : '') . '
              ORDER BY b.name'
        );
    }

    public function findBrand(int $id): ?array
    {
        return $this->db->fetchOne('SELECT id, name, is_active FROM equipment_brands WHERE id = :id', ['id' => $id]);
    }

    public function brandNameExists(string $name, ?int $exceptId = null): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT 1 FROM equipment_brands WHERE name = :name AND id <> :id',
            ['name' => $name, 'id' => $exceptId ?? 0]
        );
    }

    public function createBrand(string $name): int
    {
        return $this->db->insert('INSERT INTO equipment_brands (name) VALUES (:name)', ['name' => $name]);
    }

    public function updateBrand(int $id, string $name, bool $active): void
    {
        $this->db->execute(
            'UPDATE equipment_brands SET name = :name, is_active = :a WHERE id = :id',
            ['name' => $name, 'a' => $active ? 1 : 0, 'id' => $id]
        );
    }

    /** Modèles (actifs par défaut), avec marque et catégorie. */
    public function models(bool $activeOnly = true, ?int $includeId = null): array
    {
        return $this->db->fetchAll(
            'SELECT m.id, m.brand_id, m.category_id, m.name, m.is_active, b.name AS brand, c.name AS category,
                    (SELECT COUNT(*) FROM equipment e WHERE e.model_id = m.id AND e.is_archived = 0) AS equipment_count
               FROM equipment_models m
               JOIN equipment_brands b ON b.id = m.brand_id
               LEFT JOIN equipment_categories c ON c.id = m.category_id
              WHERE ' . ($activeOnly ? '(m.is_active = 1 AND b.is_active = 1) OR m.id = :inc' : '1=1 OR m.id = :inc') . '
              ORDER BY b.name, m.name',
            ['inc' => $includeId ?? 0]
        );
    }

    public function findModel(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT m.id, m.brand_id, m.category_id, m.name, m.is_active, b.name AS brand
               FROM equipment_models m JOIN equipment_brands b ON b.id = m.brand_id WHERE m.id = :id',
            ['id' => $id]
        );
    }

    public function modelNameExists(int $brandId, string $name, ?int $exceptId = null): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT 1 FROM equipment_models WHERE brand_id = :b AND name = :name AND id <> :id',
            ['b' => $brandId, 'name' => $name, 'id' => $exceptId ?? 0]
        );
    }

    public function createModel(int $brandId, ?int $categoryId, string $name): int
    {
        return $this->db->insert(
            'INSERT INTO equipment_models (brand_id, category_id, name) VALUES (:b, :c, :name)',
            ['b' => $brandId, 'c' => $categoryId, 'name' => $name]
        );
    }

    public function updateModel(int $id, ?int $categoryId, string $name, bool $active): void
    {
        $this->db->execute(
            'UPDATE equipment_models SET category_id = :c, name = :name, is_active = :a WHERE id = :id',
            ['c' => $categoryId, 'name' => $name, 'a' => $active ? 1 : 0, 'id' => $id]
        );
    }
}
