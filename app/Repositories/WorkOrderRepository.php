<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DataTableRequest;

final class WorkOrderRepository extends Repository
{
    public const SORTABLE = [
        'reference' => 'w.id',
        'title' => 'w.title',
        'equipment' => 'e.code',
        'site' => 's.name',
        'type' => 'w.type',
        'priority' => "FIELD(w.priority, 'critical', 'high', 'normal', 'low')",
        'status' => "FIELD(w.status, 'open', 'in_progress', 'waiting_part', 'done', 'cancelled')",
        'assignee' => 'a.last_name',
        'reported_at' => 'w.reported_at',
        'due_date' => 'w.due_date',
    ];

    private const FROM = 'work_orders w
        JOIN equipment e ON e.id = w.equipment_id
        JOIN sites s ON s.id = w.site_id
        LEFT JOIN users a ON a.id = w.assigned_to
        LEFT JOIN users r ON r.id = w.reported_by';

    private const OPEN = "('open', 'in_progress', 'waiting_part')";

    public function datatable(DataTableRequest $dt, array $filters): array
    {
        [$scopeSql, $params] = $this->scope->clause('w.site_id');
        [$where, $fp] = $this->filters($filters);

        return $this->paginate(
            $dt,
            "w.id, w.reference, w.title, w.type, w.priority, w.status, w.immobilizing, w.reported_at, w.due_date,
             w.completed_at, e.id AS equipment_id, e.code AS equipment_code, e.name AS equipment_name,
             s.name AS site, CONCAT(a.first_name, ' ', a.last_name) AS assignee,
             (w.due_date IS NOT NULL AND w.due_date < CURDATE() AND w.status IN " . self::OPEN . ') AS overdue',
            self::FROM,
            [$scopeSql],
            $params,
            $where,
            $fp,
            ['w.reference', 'w.title', 'e.code', 'e.name'],
        );
    }

    /** @return array{0: list<string>, 1: array<string, mixed>} */
    private function filters(array $filters): array
    {
        $where = [];
        $p = [];
        if (!empty($filters['site_id'])) {
            $where[] = 'w.site_id = :f_site';
            $p['f_site'] = (int) $filters['site_id'];
        }
        if (!empty($filters['equipment_id'])) {
            $where[] = 'w.equipment_id = :f_equipment';
            $p['f_equipment'] = (int) $filters['equipment_id'];
        }
        if (($filters['status'] ?? '') === 'active') {
            $where[] = 'w.status IN ' . self::OPEN;
        } elseif (($filters['status'] ?? '') === 'overdue') {
            $where[] = 'w.status IN ' . self::OPEN . ' AND w.due_date < CURDATE()';
        } elseif (!empty($filters['status'])) {
            $where[] = 'w.status = :f_status';
            $p['f_status'] = (string) $filters['status'];
        }
        if (!empty($filters['type'])) {
            $where[] = 'w.type = :f_type';
            $p['f_type'] = (string) $filters['type'];
        }
        if (!empty($filters['priority'])) {
            $where[] = 'w.priority = :f_priority';
            $p['f_priority'] = (string) $filters['priority'];
        }
        if (!empty($filters['assigned_to'])) {
            $where[] = 'w.assigned_to = :f_assigned';
            $p['f_assigned'] = (int) $filters['assigned_to'];
        }

        return [$where, $p];
    }

    public function find(int $id): ?array
    {
        [$scopeSql, $params] = $this->scope->clause('w.site_id');

        return $this->db->fetchOne(
            "SELECT w.*, e.code AS equipment_code, e.name AS equipment_name, e.status AS equipment_status,
                    e.hour_meter AS equipment_hour_meter, s.name AS site, s.code AS site_code,
                    CONCAT(a.first_name, ' ', a.last_name) AS assignee,
                    CONCAT(r.first_name, ' ', r.last_name) AS reporter,
                    p.title AS plan_title
               FROM " . self::FROM . "
               LEFT JOIN preventive_plans p ON p.id = w.preventive_plan_id
              WHERE w.id = :id AND $scopeSql",
            ['id' => $id] + $params
        );
    }

    public function findForUpdate(int $id): ?array
    {
        [$scopeSql, $params] = $this->scope->clause('w.site_id');

        return $this->db->fetchOne(
            "SELECT w.* FROM work_orders w WHERE w.id = :id AND $scopeSql FOR UPDATE",
            ['id' => $id] + $params
        );
    }

    /** Référence OT-AAAA-NNNNN, séquence annuelle verrouillée (dans une transaction). */
    public function nextReference(int $year): string
    {
        $this->db->execute(
            'INSERT INTO work_order_sequences (year, last_number) VALUES (:y, 0)
             ON DUPLICATE KEY UPDATE last_number = last_number',
            ['y' => $year]
        );
        $last = (int) $this->db->fetchValue(
            'SELECT last_number FROM work_order_sequences WHERE year = :y FOR UPDATE',
            ['y' => $year]
        );
        $next = $last + 1;
        $this->db->execute('UPDATE work_order_sequences SET last_number = :n WHERE year = :y', ['n' => $next, 'y' => $year]);

        return sprintf('OT-%d-%05d', $year, $next);
    }

    public function create(array $data): int
    {
        $this->scope->assertAllows((int) $data['site_id']);

        return $this->db->insert(
            'INSERT INTO work_orders (reference, site_id, equipment_id, preventive_plan_id, type, priority, status,
                                      immobilizing, title, description, reported_by, assigned_to, due_date, hour_meter)
             VALUES (:reference, :site_id, :equipment_id, :plan_id, :type, :priority, :status,
                     :immobilizing, :title, :description, :reported_by, :assigned_to, :due_date, :hour_meter)',
            [
                'reference' => $data['reference'],
                'site_id' => (int) $data['site_id'],
                'equipment_id' => (int) $data['equipment_id'],
                'plan_id' => $data['preventive_plan_id'] ?? null,
                'type' => $data['type'],
                'priority' => $data['priority'],
                'status' => $data['status'] ?? 'open',
                'immobilizing' => !empty($data['immobilizing']) ? 1 : 0,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'reported_by' => $data['reported_by'] ?? null,
                'assigned_to' => $data['assigned_to'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'hour_meter' => $data['hour_meter'] ?? null,
            ]
        );
    }

    /** Mise à jour partielle sur une liste blanche de colonnes. */
    public function update(int $id, array $fields): void
    {
        $allowed = ['priority', 'status', 'immobilizing', 'title', 'description', 'resolution', 'assigned_to',
            'due_date', 'started_at', 'completed_at', 'labor_minutes', 'hour_meter'];
        $set = [];
        $params = [];
        foreach ($fields as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                throw new \InvalidArgumentException("Colonne non modifiable : $column");
            }
            $set[] = "$column = :v_$column";
            $params["v_$column"] = is_bool($value) ? (int) $value : $value;
        }
        if ($set === []) {
            return;
        }
        [$scopeSql, $scopeParams] = $this->scope->clause('site_id');
        $this->db->execute(
            'UPDATE work_orders SET ' . implode(', ', $set) . " WHERE id = :id AND $scopeSql",
            $params + ['id' => $id] + $scopeParams
        );
    }

    /** OT ouverts d'un engin, du plus récent au plus ancien (hors $exceptId). */
    public function openForEquipment(int $equipmentId, ?int $exceptId = null): array
    {
        [$scopeSql, $params] = $this->scope->clause('w.site_id');

        return $this->db->fetchAll(
            "SELECT w.id, w.reference, w.title, w.status, w.priority, w.immobilizing, w.reported_at
               FROM work_orders w
              WHERE w.equipment_id = :equipment AND w.id <> :except AND w.status IN " . self::OPEN . "
                AND $scopeSql
              ORDER BY w.reported_at DESC, w.id DESC",
            ['equipment' => $equipmentId, 'except' => $exceptId ?? 0] + $params
        );
    }

    public function recentForEquipment(int $equipmentId, int $limit = 10): array
    {
        [$scopeSql, $params] = $this->scope->clause('w.site_id');

        return $this->db->fetchAll(
            "SELECT w.id, w.reference, w.title, w.type, w.status, w.priority, w.reported_at, w.completed_at
               FROM work_orders w
              WHERE w.equipment_id = :equipment AND $scopeSql
              ORDER BY w.reported_at DESC, w.id DESC LIMIT " . max(1, $limit),
            ['equipment' => $equipmentId] + $params
        );
    }

    /** OT ouverts assignés à l'utilisateur ou non assignés, pour l'écran terrain. */
    public function fieldQueue(int $userId, int $limit = 50): array
    {
        [$scopeSql, $params] = $this->scope->clause('w.site_id');

        return $this->db->fetchAll(
            "SELECT w.id, w.reference, w.title, w.status, w.priority, w.immobilizing, w.due_date, w.assigned_to,
                    e.code AS equipment_code, e.name AS equipment_name, s.name AS site
               FROM work_orders w
               JOIN equipment e ON e.id = w.equipment_id
               JOIN sites s ON s.id = w.site_id
              WHERE $scopeSql AND w.status IN " . self::OPEN . "
                AND (w.assigned_to = :user OR w.assigned_to IS NULL)
              ORDER BY (w.assigned_to IS NULL), FIELD(w.priority, 'critical', 'high', 'normal', 'low'), w.reported_at
              LIMIT " . max(1, $limit),
            ['user' => $userId] + $params
        );
    }

    public function parts(int $workOrderId): array
    {
        [$scopeSql, $params] = $this->scope->clause('w.site_id');

        return $this->db->fetchAll(
            "SELECT wp.id, wp.quantity, wp.unit_cost, wp.created_at, p.id AS part_id, p.reference, p.name, p.unit,
                    CONCAT(u.first_name, ' ', u.last_name) AS created_by
               FROM work_order_parts wp
               JOIN work_orders w ON w.id = wp.work_order_id
               JOIN parts p ON p.id = wp.part_id
               LEFT JOIN users u ON u.id = wp.created_by
              WHERE wp.work_order_id = :wo AND $scopeSql
              ORDER BY wp.created_at, wp.id",
            ['wo' => $workOrderId] + $params
        );
    }

    public function addPart(int $workOrderId, int $partId, float $quantity, float $unitCost, ?int $userId): int
    {
        return $this->db->insert(
            'INSERT INTO work_order_parts (work_order_id, part_id, quantity, unit_cost, created_by)
             VALUES (:wo, :part, :qty, :cost, :user)',
            ['wo' => $workOrderId, 'part' => $partId, 'qty' => $quantity, 'cost' => $unitCost, 'user' => $userId]
        );
    }

    // --- Indicateurs -------------------------------------------------------

    /** @return array{open: int, overdue: int, critical: int, done_30d: int, mttr_hours: ?float} */
    public function kpis(?int $siteId = null): array
    {
        [$scopeSql, $params] = $this->scope->clause('site_id');
        $siteSql = '';
        if ($siteId !== null) {
            $siteSql = ' AND site_id = :site';
            $params['site'] = $siteId;
        }
        $row = $this->db->fetchOne(
            "SELECT
                SUM(status IN " . self::OPEN . ") AS open_count,
                SUM(status IN " . self::OPEN . " AND due_date < CURDATE()) AS overdue,
                SUM(status IN " . self::OPEN . " AND priority = 'critical') AS critical,
                SUM(status = 'done' AND completed_at >= NOW() - INTERVAL 30 DAY) AS done_30d,
                AVG(CASE WHEN status = 'done' AND type = 'corrective' AND completed_at >= NOW() - INTERVAL 90 DAY
                         THEN TIMESTAMPDIFF(MINUTE, reported_at, completed_at) END) / 60 AS mttr_hours
               FROM work_orders WHERE $scopeSql $siteSql",
            $params
        ) ?? [];

        return [
            'open' => (int) ($row['open_count'] ?? 0),
            'overdue' => (int) ($row['overdue'] ?? 0),
            'critical' => (int) ($row['critical'] ?? 0),
            'done_30d' => (int) ($row['done_30d'] ?? 0),
            'mttr_hours' => isset($row['mttr_hours']) ? round((float) $row['mttr_hours'], 1) : null,
        ];
    }

    /** OT créés par semaine ISO et par type sur les $weeks dernières semaines. */
    public function weeklyCreated(int $weeks, ?int $siteId = null): array
    {
        [$scopeSql, $params] = $this->scope->clause('site_id');
        $siteSql = '';
        if ($siteId !== null) {
            $siteSql = ' AND site_id = :site';
            $params['site'] = $siteId;
        }
        $params['weeks'] = $weeks;

        return $this->db->fetchAll(
            "SELECT YEARWEEK(reported_at, 3) AS yw, type, COUNT(*) AS n
               FROM work_orders
              WHERE $scopeSql $siteSql AND reported_at >= CURDATE() - INTERVAL :weeks WEEK
              GROUP BY yw, type ORDER BY yw",
            $params
        );
    }
}
