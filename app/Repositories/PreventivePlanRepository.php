<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DataTableRequest;

final class PreventivePlanRepository extends Repository
{
    public const SORTABLE = [
        'title' => 'p.title',
        'equipment' => 'e.code',
        'site' => 's.name',
        'next_due_date' => 'p.next_due_date',
        'next_due_hours' => 'p.next_due_hours',
        'next_due_km' => 'p.next_due_km',
        'last_done_at' => 'p.last_done_at',
    ];

    private const FROM = 'preventive_plans p
        JOIN equipment e ON e.id = p.equipment_id
        JOIN sites s ON s.id = p.site_id';

    public function datatable(DataTableRequest $dt, array $filters): array
    {
        [$scopeSql, $params] = $this->scope->clause('p.site_id');
        $where = [];
        $fp = [];
        if (!empty($filters['site_id'])) {
            $where[] = 'p.site_id = :f_site';
            $fp['f_site'] = (int) $filters['site_id'];
        }
        if (!empty($filters['equipment_id'])) {
            $where[] = 'p.equipment_id = :f_equipment';
            $fp['f_equipment'] = (int) $filters['equipment_id'];
        }
        if (($filters['due'] ?? '') === '1') {
            $where[] = '(p.next_due_date <= CURDATE() + INTERVAL 7 DAY OR (p.next_due_hours IS NOT NULL AND e.hour_meter >= p.next_due_hours)
                     OR (p.next_due_km IS NOT NULL AND e.odometer_km >= p.next_due_km))';
        }
        if (($filters['active'] ?? '1') !== 'all') {
            $where[] = 'p.is_active = 1';
        }

        return $this->paginate(
            $dt,
            "p.id, p.title, p.interval_days, p.interval_hours, p.interval_km, p.last_done_at, p.next_due_date,
             p.next_due_hours, p.next_due_km,
             p.is_active, e.id AS equipment_id, e.code AS equipment_code, e.name AS equipment_name, e.hour_meter,
             e.odometer_km,
             s.name AS site,
             (SELECT w.id FROM work_orders w WHERE w.preventive_plan_id = p.id
                 AND w.status IN ('open', 'in_progress', 'waiting_part') LIMIT 1) AS open_work_order_id",
            self::FROM,
            [$scopeSql],
            $params,
            $where,
            $fp,
            ['p.title', 'e.code', 'e.name'],
        );
    }

    public function find(int $id): ?array
    {
        [$scopeSql, $params] = $this->scope->clause('p.site_id');

        return $this->db->fetchOne(
            "SELECT p.*, e.code AS equipment_code, e.name AS equipment_name, e.hour_meter, e.odometer_km, s.name AS site
               FROM " . self::FROM . " WHERE p.id = :id AND $scopeSql",
            ['id' => $id] + $params
        );
    }

    public function findForUpdate(int $id): ?array
    {
        [$scopeSql, $params] = $this->scope->clause('p.site_id');

        return $this->db->fetchOne(
            "SELECT p.* FROM preventive_plans p WHERE p.id = :id AND $scopeSql FOR UPDATE",
            ['id' => $id] + $params
        );
    }

    public function forEquipment(int $equipmentId): array
    {
        [$scopeSql, $params] = $this->scope->clause('p.site_id');

        return $this->db->fetchAll(
            "SELECT p.id, p.title, p.interval_days, p.interval_hours, p.interval_km, p.next_due_date, p.next_due_hours,
                    p.next_due_km, p.is_active
               FROM preventive_plans p WHERE p.equipment_id = :e AND $scopeSql ORDER BY p.next_due_date",
            ['e' => $equipmentId] + $params
        );
    }

    /**
     * Plans actifs arrivés à échéance (date dans l'horizon ou compteur atteint)
     * sans OT préventif déjà ouvert.
     */
    public function dueWithoutOpenWorkOrder(int $horizonDays): array
    {
        [$scopeSql, $params] = $this->scope->clause('p.site_id');
        $params['horizon'] = $horizonDays;

        return $this->db->fetchAll(
            "SELECT p.*, e.hour_meter, e.odometer_km, e.code AS equipment_code
               FROM preventive_plans p
               JOIN equipment e ON e.id = p.equipment_id AND e.is_archived = 0
              WHERE $scopeSql AND p.is_active = 1
                AND (p.next_due_date <= CURDATE() + INTERVAL :horizon DAY
                     OR (p.next_due_hours IS NOT NULL AND e.hour_meter >= p.next_due_hours)
                     OR (p.next_due_km IS NOT NULL AND e.odometer_km >= p.next_due_km))
                AND NOT EXISTS (SELECT 1 FROM work_orders w WHERE w.preventive_plan_id = p.id
                                   AND w.status IN ('open', 'in_progress', 'waiting_part'))
              ORDER BY p.next_due_date",
            $params
        );
    }

    public function countDue(int $horizonDays, ?int $siteId = null): int
    {
        [$scopeSql, $params] = $this->scope->clause('p.site_id');
        $params['horizon'] = $horizonDays;
        $siteSql = '';
        if ($siteId !== null) {
            $siteSql = ' AND p.site_id = :site';
            $params['site'] = $siteId;
        }

        return (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM preventive_plans p JOIN equipment e ON e.id = p.equipment_id
              WHERE $scopeSql $siteSql AND p.is_active = 1 AND e.is_archived = 0
                AND (p.next_due_date <= CURDATE() + INTERVAL :horizon DAY
                     OR (p.next_due_hours IS NOT NULL AND e.hour_meter >= p.next_due_hours)
                     OR (p.next_due_km IS NOT NULL AND e.odometer_km >= p.next_due_km))",
            $params
        );
    }

    public function create(array $data): int
    {
        $this->scope->assertAllows((int) $data['site_id']);

        return $this->db->insert(
            'INSERT INTO preventive_plans (site_id, equipment_id, title, instructions, interval_days, interval_hours,
                                           interval_km, last_done_at, last_done_hours, last_done_km, next_due_date,
                                           next_due_hours, next_due_km, is_active)
             VALUES (:site_id, :equipment_id, :title, :instructions, :interval_days, :interval_hours,
                     :interval_km, :last_done_at, :last_done_hours, :last_done_km, :next_due_date,
                     :next_due_hours, :next_due_km, :is_active)',
            self::params($data)
        );
    }

    public function update(int $id, array $data): void
    {
        $this->scope->assertAllows((int) $data['site_id']);
        [$scopeSql, $params] = $this->scope->clause('site_id');
        $this->db->execute(
            "UPDATE preventive_plans SET site_id = :site_id, equipment_id = :equipment_id, title = :title,
                    instructions = :instructions, interval_days = :interval_days, interval_hours = :interval_hours,
                    interval_km = :interval_km, last_done_at = :last_done_at, last_done_hours = :last_done_hours,
                    last_done_km = :last_done_km, next_due_date = :next_due_date, next_due_hours = :next_due_hours,
                    next_due_km = :next_due_km, is_active = :is_active
              WHERE id = :id AND $scopeSql",
            self::params($data) + ['id' => $id] + $params
        );
    }

    public function delete(int $id): void
    {
        [$scopeSql, $params] = $this->scope->clause('site_id');
        $this->db->execute("DELETE FROM preventive_plans WHERE id = :id AND $scopeSql", ['id' => $id] + $params);
    }

    public function markDone(
        int $id,
        string $doneAt,
        ?int $doneHours,
        ?string $nextDate,
        ?int $nextHours,
        ?int $doneKm = null,
        ?int $nextKm = null,
    ): void {
        [$scopeSql, $params] = $this->scope->clause('site_id');
        $this->db->execute(
            "UPDATE preventive_plans SET last_done_at = :done, last_done_hours = :hours, last_done_km = :km,
                    next_due_date = :next_date, next_due_hours = :next_hours, next_due_km = :next_km
              WHERE id = :id AND $scopeSql",
            ['done' => $doneAt, 'hours' => $doneHours, 'km' => $doneKm, 'next_date' => $nextDate,
                'next_hours' => $nextHours, 'next_km' => $nextKm, 'id' => $id] + $params
        );
    }

    private static function params(array $data): array
    {
        return [
            'site_id' => (int) $data['site_id'],
            'equipment_id' => (int) $data['equipment_id'],
            'title' => $data['title'],
            'instructions' => $data['instructions'] ?? null,
            'interval_days' => $data['interval_days'] ?? null,
            'interval_hours' => $data['interval_hours'] ?? null,
            'interval_km' => $data['interval_km'] ?? null,
            'last_done_at' => $data['last_done_at'] ?? null,
            'last_done_hours' => $data['last_done_hours'] ?? null,
            'last_done_km' => $data['last_done_km'] ?? null,
            'next_due_date' => $data['next_due_date'] ?? null,
            'next_due_hours' => $data['next_due_hours'] ?? null,
            'next_due_km' => $data['next_due_km'] ?? null,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ];
    }
}
