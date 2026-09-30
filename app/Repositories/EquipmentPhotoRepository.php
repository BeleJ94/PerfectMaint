<?php

declare(strict_types=1);

namespace App\Repositories;

/** Photos d'engins : l'accès passe toujours par l'engin, donc par le périmètre de sites. */
final class EquipmentPhotoRepository extends Repository
{
    public function forEquipment(int $equipmentId): array
    {
        [$scopeSql, $params] = $this->scope->clause('e.site_id');

        return $this->db->fetchAll(
            "SELECT p.id, p.equipment_id, p.caption, p.is_primary, p.width, p.height, p.created_at,
                    CONCAT(u.first_name, ' ', u.last_name) AS uploaded_by
               FROM equipment_photos p
               JOIN equipment e ON e.id = p.equipment_id
               LEFT JOIN users u ON u.id = p.uploaded_by
              WHERE p.equipment_id = :id AND $scopeSql
              ORDER BY p.is_primary DESC, p.id DESC",
            ['id' => $equipmentId] + $params
        );
    }

    public function find(int $equipmentId, int $photoId): ?array
    {
        [$scopeSql, $params] = $this->scope->clause('e.site_id');

        return $this->db->fetchOne(
            "SELECT p.*, e.site_id, e.code AS equipment_code
               FROM equipment_photos p JOIN equipment e ON e.id = p.equipment_id
              WHERE p.id = :pid AND p.equipment_id = :eid AND $scopeSql",
            ['pid' => $photoId, 'eid' => $equipmentId] + $params
        );
    }

    public function count(int $equipmentId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM equipment_photos WHERE equipment_id = :id', ['id' => $equipmentId]);
    }

    public function create(array $data): int
    {
        return $this->db->insert(
            'INSERT INTO equipment_photos (equipment_id, file_name, original_name, mime, size_bytes, width, height, caption, is_primary, uploaded_by)
             VALUES (:equipment_id, :file_name, :original_name, :mime, :size_bytes, :width, :height, :caption, :is_primary, :uploaded_by)',
            $data
        );
    }

    public function setPrimary(int $equipmentId, int $photoId): void
    {
        $this->db->execute(
            'UPDATE equipment_photos SET is_primary = (id = :pid) WHERE equipment_id = :eid',
            ['pid' => $photoId, 'eid' => $equipmentId]
        );
    }

    public function delete(int $photoId): void
    {
        $this->db->execute('DELETE FROM equipment_photos WHERE id = :id', ['id' => $photoId]);
    }
}
