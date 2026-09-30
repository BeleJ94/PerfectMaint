<?php

declare(strict_types=1);

namespace App\Repositories;

/** Agrégats pour le planning d'activités et les rapports destinés au management. */
final class ManagementRepository extends Repository
{
    /**
     * Événements planifiés entre $from et $to inclus : préventifs à échéance, OT ouverts à échéance, fins de contrat.
     *
     * @return list<array{date: string, kind: string, title: string, subtitle: string, site: string, id: int, equipment_id: int, overdue: bool, priority: ?string}>
     */
    public function events(string $from, string $to, ?int $siteId = null): array
    {
        $events = [];
        $today = date('Y-m-d');
        foreach ($this->preventive($from, $to, $siteId) as $r) {
            $events[] = ['date' => $r['d'], 'kind' => $r['open_wo'] ? 'preventive_planned' : 'preventive', 'title' => $r['title'],
                'subtitle' => $r['code'], 'site' => $r['site'], 'id' => (int) ($r['open_wo'] ?: $r['id']), 'equipment_id' => (int) $r['equipment_id'],
                'overdue' => $r['d'] < $today, 'priority' => null, 'code' => $r['code'], 'equipment_name' => $r['equipment_name'],
                'plan_id' => (int) $r['id'], 'wo_reference' => $r['wo_reference'], 'status' => $r['wo_status'],
                'interval_days' => $r['interval_days'], 'interval_hours' => $r['interval_hours'], 'last_done_at' => $r['last_done_at'],
                'next_due_hours' => $r['next_due_hours'], 'hour_meter' => $r['hour_meter']];
        }
        foreach ($this->workOrdersDue($from, $to, $siteId) as $r) {
            $events[] = ['date' => $r['d'], 'kind' => 'work_order', 'title' => $r['title'], 'subtitle' => $r['reference'] . ' · ' . $r['code'],
                'site' => $r['site'], 'id' => (int) $r['id'], 'equipment_id' => (int) $r['equipment_id'], 'overdue' => $r['d'] < $today, 'priority' => $r['priority'],
                'code' => $r['code'], 'equipment_name' => $r['equipment_name'], 'wo_reference' => $r['reference'], 'status' => $r['status'],
                'assignee' => $r['assignee'], 'immobilizing' => (bool) $r['immobilizing']];
        }
        foreach ($this->contractsEnd($from, $to, $siteId) as $r) {
            $events[] = ['date' => $r['d'], 'kind' => 'contract', 'title' => $r['contract_ref'] ?? $r['code'], 'subtitle' => $r['code'] . ($r['contract_party'] ? ' · ' . $r['contract_party'] : ''),
                'site' => $r['site'], 'id' => (int) $r['equipment_id'], 'equipment_id' => (int) $r['equipment_id'], 'overdue' => false, 'priority' => null,
                'code' => $r['code'], 'equipment_name' => $r['equipment_name'], 'ownership' => $r['ownership'], 'contract_ref' => $r['contract_ref'],
                'contract_party' => $r['contract_party'], 'contract_start' => $r['contract_start']];
        }
        usort($events, static fn (array $a, array $b) => [$a['date'], $a['kind']] <=> [$b['date'], $b['kind']]);

        return $events;
    }

    /** Éléments en retard avant $from (à rattraper). */
    public function overdueCount(string $before, ?int $siteId = null): int
    {
        return count($this->preventive('2000-01-01', date('Y-m-d', strtotime($before . ' -1 day')), $siteId))
            + count($this->workOrdersDue('2000-01-01', date('Y-m-d', strtotime($before . ' -1 day')), $siteId));
    }

    private function siteFilter(string $column, ?int $siteId, array &$params): string
    {
        if ($siteId === null) {
            return '';
        }
        $params['site'] = $siteId;

        return " AND $column = :site";
    }

    private function preventive(string $from, string $to, ?int $siteId): array
    {
        [$scopeSql, $params] = $this->scope->clause('p.site_id');
        $siteSql = $this->siteFilter('p.site_id', $siteId, $params);

        return $this->db->fetchAll(
            "SELECT p.id, p.title, p.next_due_date AS d, p.equipment_id, e.code, e.name AS equipment_name, e.hour_meter, s.name AS site,
                    p.interval_days, p.interval_hours, p.last_done_at, p.next_due_hours,
                    (SELECT w.id FROM work_orders w WHERE w.preventive_plan_id = p.id AND w.status IN ('open', 'in_progress', 'waiting_part') LIMIT 1) AS open_wo,
                    (SELECT w.reference FROM work_orders w WHERE w.preventive_plan_id = p.id AND w.status IN ('open', 'in_progress', 'waiting_part') LIMIT 1) AS wo_reference,
                    (SELECT w.status FROM work_orders w WHERE w.preventive_plan_id = p.id AND w.status IN ('open', 'in_progress', 'waiting_part') LIMIT 1) AS wo_status
               FROM preventive_plans p JOIN equipment e ON e.id = p.equipment_id AND e.is_archived = 0 JOIN sites s ON s.id = p.site_id AND s.is_active = 1
              WHERE p.is_active = 1 AND p.next_due_date BETWEEN :f AND :t AND $scopeSql $siteSql",
            ['f' => $from, 't' => $to] + $params
        );
    }

    private function workOrdersDue(string $from, string $to, ?int $siteId): array
    {
        [$scopeSql, $params] = $this->scope->clause('w.site_id');
        $siteSql = $this->siteFilter('w.site_id', $siteId, $params);

        return $this->db->fetchAll(
            "SELECT w.id, w.reference, w.title, w.priority, w.status, w.immobilizing, w.due_date AS d, w.equipment_id, e.code, e.name AS equipment_name,
                    s.name AS site, CONCAT(u.first_name, ' ', u.last_name) AS assignee
               FROM work_orders w JOIN equipment e ON e.id = w.equipment_id JOIN sites s ON s.id = w.site_id AND s.is_active = 1
               LEFT JOIN users u ON u.id = w.assigned_to
              WHERE w.status IN ('open', 'in_progress', 'waiting_part') AND w.preventive_plan_id IS NULL
                AND w.due_date BETWEEN :f AND :t AND $scopeSql $siteSql",
            ['f' => $from, 't' => $to] + $params
        );
    }

    private function contractsEnd(string $from, string $to, ?int $siteId): array
    {
        [$scopeSql, $params] = $this->scope->clause('e.site_id');
        $siteSql = $this->siteFilter('e.site_id', $siteId, $params);

        return $this->db->fetchAll(
            "SELECT e.id AS equipment_id, e.code, e.name AS equipment_name, e.ownership, e.contract_ref, e.contract_party, e.contract_start,
                    e.contract_end AS d, s.name AS site
               FROM equipment e JOIN sites s ON s.id = e.site_id AND s.is_active = 1
              WHERE e.is_archived = 0 AND e.ownership <> 'owned' AND e.contract_end BETWEEN :f AND :t AND $scopeSql $siteSql",
            ['f' => $from, 't' => $to] + $params
        );
    }

    // ---------------------------------------------------------------- Rapports

    /** Indicateurs par site sur la période [$from ; maintenant]. */
    public function siteReport(string $from): array
    {
        [$scopeSql, $params] = $this->scope->clause('s.id');

        return $this->db->fetchAll(
            "SELECT s.id, s.name, s.activity,
                    (SELECT COUNT(*) FROM equipment e WHERE e.site_id = s.id AND e.is_archived = 0) AS fleet,
                    (SELECT COUNT(*) FROM equipment e WHERE e.site_id = s.id AND e.is_archived = 0 AND e.status = 'available') AS available,
                    (SELECT COUNT(*) FROM equipment e WHERE e.site_id = s.id AND e.is_archived = 0 AND e.status = 'breakdown') AS down,
                    (SELECT COUNT(*) FROM work_orders w WHERE w.site_id = s.id AND w.type = 'corrective' AND w.reported_at >= :f1) AS breakdowns,
                    (SELECT COUNT(*) FROM work_orders w WHERE w.site_id = s.id AND w.status = 'done' AND w.completed_at >= :f2) AS closed,
                    (SELECT COUNT(*) FROM work_orders w WHERE w.site_id = s.id AND w.status IN ('open', 'in_progress', 'waiting_part')) AS open_wo,
                    (SELECT ROUND(SUM(TIMESTAMPDIFF(MINUTE, w.reported_at, w.completed_at)) / 60, 1) FROM work_orders w
                      WHERE w.site_id = s.id AND w.immobilizing = 1 AND w.status = 'done' AND w.completed_at >= :f3) AS downtime_hours,
                    (SELECT ROUND(AVG(TIMESTAMPDIFF(MINUTE, w.reported_at, w.completed_at)) / 60, 1) FROM work_orders w
                      WHERE w.site_id = s.id AND w.type = 'corrective' AND w.status = 'done' AND w.completed_at >= :f4) AS mttr_hours,
                    (SELECT SUM(m.quantity * p.unit_cost) FROM stock_movements m JOIN parts p ON p.id = m.part_id
                      WHERE m.site_id = s.id AND m.type = 'out' AND m.created_at >= :f5) AS consumption_value,
                    (SELECT SUM(f.quantity) FROM fluid_logs f JOIN parts p ON p.id = f.part_id AND p.part_type = 'lubricant'
                      WHERE f.site_id = s.id AND f.logged_at >= :f6) AS lubricant_qty,
                    (SELECT COUNT(*) FROM work_orders w WHERE w.site_id = s.id AND w.failure_cause IN ('misuse', 'accident') AND w.completed_at >= :f7) AS usage_failures
               FROM sites s
              WHERE s.is_active = 1 AND s.activity <> 'office' AND $scopeSql
              ORDER BY s.name",
            ['f1' => $from, 'f2' => $from, 'f3' => $from, 'f4' => $from, 'f5' => $from, 'f6' => $from, 'f7' => $from] + $params
        );
    }

    /** Répartition des OT clôturés par cause de panne. */
    public function causes(string $from, ?int $siteId = null): array
    {
        [$scopeSql, $params] = $this->scope->clause('w.site_id');
        $siteSql = $this->siteFilter('w.site_id', $siteId, $params);

        return $this->db->fetchAll(
            "SELECT COALESCE(w.failure_cause, 'unknown') AS cause, COUNT(*) AS n
               FROM work_orders w
              WHERE w.type = 'corrective' AND w.status = 'done' AND w.completed_at >= :f AND $scopeSql $siteSql
              GROUP BY cause ORDER BY n DESC",
            ['f' => $from] + $params
        );
    }

    /** Engins les plus pénalisants : immobilisation et coût des pièces sur la période. */
    public function topEquipment(string $from, ?int $siteId = null, int $limit = 10): array
    {
        [$scopeSql, $params] = $this->scope->clause('e.site_id');
        $siteSql = $this->siteFilter('e.site_id', $siteId, $params);

        return $this->db->fetchAll(
            "SELECT e.id, e.code, e.brand, e.model, s.name AS site, e.status,
                    COUNT(DISTINCT w.id) AS work_orders,
                    ROUND(SUM(CASE WHEN w.immobilizing = 1 THEN TIMESTAMPDIFF(MINUTE, w.reported_at, COALESCE(w.completed_at, NOW())) ELSE 0 END) / 60, 1) AS downtime_hours,
                    (SELECT SUM(wp.quantity * wp.unit_cost) FROM work_order_parts wp JOIN work_orders w2 ON w2.id = wp.work_order_id
                      WHERE w2.equipment_id = e.id AND wp.created_at >= :f2) AS parts_cost
               FROM equipment e
               JOIN sites s ON s.id = e.site_id
               JOIN work_orders w ON w.equipment_id = e.id AND w.reported_at >= :f1 AND w.type = 'corrective'
              WHERE e.is_archived = 0 AND $scopeSql $siteSql
              GROUP BY e.id
              ORDER BY downtime_hours DESC, parts_cost DESC
              LIMIT " . max(1, $limit),
            ['f1' => $from, 'f2' => $from] + $params
        );
    }

    /** Pannes par conducteur en poste (causes d'utilisation), pour cibler la formation. */
    public function operatorFailures(string $from, ?int $siteId = null, int $limit = 8): array
    {
        [$scopeSql, $params] = $this->scope->clause('w.site_id');
        $siteSql = $this->siteFilter('w.site_id', $siteId, $params);

        return $this->db->fetchAll(
            "SELECT w.operator_id, w.operator_name, s.name AS site, COUNT(*) AS breakdowns,
                    SUM(w.failure_cause IN ('misuse', 'accident')) AS usage_related
               FROM work_orders w JOIN sites s ON s.id = w.site_id
              WHERE w.type = 'corrective' AND w.operator_id IS NOT NULL AND w.reported_at >= :f AND $scopeSql $siteSql
              GROUP BY w.operator_id, w.operator_name, s.name
              ORDER BY usage_related DESC, breakdowns DESC LIMIT " . max(1, $limit),
            ['f' => $from] + $params
        );
    }
}
