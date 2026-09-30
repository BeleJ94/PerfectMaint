<?php

declare(strict_types=1);

namespace App\Repositories;

/** Affectations chauffeur / opérateur ↔ engin (historisées : ended_at NULL = en cours). */
final class AssignmentRepository extends Repository
{
    private const SELECT = "SELECT a.id, a.equipment_id, a.personnel_id, a.shift, a.started_at, a.ended_at, a.note,
            CONCAT(p.first_name, ' ', p.last_name) AS name, p.job, p.phone, p.employee_no,
            e.code AS equipment_code, e.site_id
       FROM equipment_assignments a
       JOIN personnel p ON p.id = a.personnel_id
       JOIN equipment e ON e.id = a.equipment_id";

    public function current(int $equipmentId): array
    {
        [$scopeSql, $params] = $this->scope->clause('e.site_id');

        return $this->db->fetchAll(
            self::SELECT . " WHERE a.equipment_id = :id AND a.ended_at IS NULL AND $scopeSql
              ORDER BY FIELD(a.shift, 'day', 'night', 'rotation'), a.started_at",
            ['id' => $equipmentId] + $params
        );
    }

    public function history(int $equipmentId, int $limit = 12): array
    {
        [$scopeSql, $params] = $this->scope->clause('e.site_id');

        return $this->db->fetchAll(
            self::SELECT . " WHERE a.equipment_id = :id AND a.ended_at IS NOT NULL AND $scopeSql
              ORDER BY a.ended_at DESC LIMIT " . max(1, $limit),
            ['id' => $equipmentId] + $params
        );
    }

    public function find(int $id): ?array
    {
        [$scopeSql, $params] = $this->scope->clause('e.site_id');

        return $this->db->fetchOne(self::SELECT . " WHERE a.id = :id AND $scopeSql", ['id' => $id] + $params);
    }

    /** Affectations ouvertes à clôturer avant une nouvelle : même engin et même shift, ou même personne. */
    public function openConflicts(int $equipmentId, int $personnelId, string $shift): array
    {
        return $this->db->fetchAll(
            'SELECT id, equipment_id, personnel_id, shift FROM equipment_assignments
              WHERE ended_at IS NULL AND ((equipment_id = :e AND shift = :s) OR personnel_id = :p)
              FOR UPDATE',
            ['e' => $equipmentId, 's' => $shift, 'p' => $personnelId]
        );
    }

    /** Conducteur en poste sur l'engin à un instant donné (utilisé pour rattacher une panne à l'utilisateur). */
    public function operatorAt(int $equipmentId, string $at): ?array
    {
        return $this->db->fetchOne(
            "SELECT a.personnel_id, CONCAT(p.first_name, ' ', p.last_name) AS name
               FROM equipment_assignments a JOIN personnel p ON p.id = a.personnel_id
              WHERE a.equipment_id = :e AND a.started_at <= :at AND (a.ended_at IS NULL OR a.ended_at > :at2)
              ORDER BY a.started_at DESC LIMIT 1",
            ['e' => $equipmentId, 'at' => $at, 'at2' => $at]
        );
    }

    public function create(int $equipmentId, int $personnelId, string $shift, ?string $note, ?int $userId): int
    {
        return $this->db->insert(
            'INSERT INTO equipment_assignments (equipment_id, personnel_id, shift, note, assigned_by)
             VALUES (:e, :p, :s, :n, :u)',
            ['e' => $equipmentId, 'p' => $personnelId, 's' => $shift, 'n' => $note, 'u' => $userId]
        );
    }

    public function close(int $id): void
    {
        $this->db->execute('UPDATE equipment_assignments SET ended_at = NOW() WHERE id = :id AND ended_at IS NULL', ['id' => $id]);
    }

    /** Effectif affecté par site (fenêtre site). */
    public function countOpenForSite(int $siteId): int
    {
        [$scopeSql, $params] = $this->scope->clause('e.site_id');

        return (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM equipment_assignments a JOIN equipment e ON e.id = a.equipment_id
              WHERE a.ended_at IS NULL AND e.site_id = :site AND $scopeSql",
            ['site' => $siteId] + $params
        );
    }
}
