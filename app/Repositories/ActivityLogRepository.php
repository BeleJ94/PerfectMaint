<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DataTableRequest;

final class ActivityLogRepository extends Repository
{
    public const SORTABLE = [
        'created_at' => 'l.id',
        'user_name' => 'l.user_name',
        'entity_type' => 'l.entity_type',
        'action' => 'l.action',
    ];

    public function insert(array $entry): int
    {
        return $this->db->insert(
            'INSERT INTO activity_log (user_id, user_name, site_id, entity_type, entity_id, action, summary,
                                       before_data, after_data, ip_address, user_agent)
             VALUES (:user_id, :user_name, :site_id, :entity_type, :entity_id, :action, :summary,
                     :before_data, :after_data, :ip, :ua)',
            [
                'user_id' => $entry['user_id'] ?? null,
                'user_name' => $entry['user_name'] ?? null,
                'site_id' => $entry['site_id'] ?? null,
                'entity_type' => $entry['entity_type'],
                'entity_id' => $entry['entity_id'] ?? null,
                'action' => $entry['action'],
                'summary' => isset($entry['summary']) ? mb_substr((string) $entry['summary'], 0, 255) : null,
                'before_data' => $entry['before_data'] ?? null,
                'after_data' => $entry['after_data'] ?? null,
                'ip' => $entry['ip'] ?? null,
                'ua' => $entry['user_agent'] ?? null,
            ]
        );
    }

    /** Entrées sans site (utilisateurs, catalogue…) visibles uniquement avec un périmètre global. */
    private function visibility(): array
    {
        [$scopeSql, $params] = $this->scope->clause('l.site_id');

        return [$this->scope->isAll() ? '1=1' : $scopeSql, $params];
    }

    public function datatable(DataTableRequest $dt, array $filters): array
    {
        [$visSql, $params] = $this->visibility();
        $where = [];
        $fp = [];
        if (!empty($filters['entity_type'])) {
            $where[] = 'l.entity_type = :f_type';
            $fp['f_type'] = (string) $filters['entity_type'];
        }
        if (!empty($filters['entity_id'])) {
            $where[] = 'l.entity_id = :f_id';
            $fp['f_id'] = (int) $filters['entity_id'];
        }
        if (!empty($filters['site_id'])) {
            $where[] = 'l.site_id = :f_site';
            $fp['f_site'] = (int) $filters['site_id'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'l.created_at >= :f_from';
            $fp['f_from'] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[] = 'l.created_at <= :f_to';
            $fp['f_to'] = $filters['to'] . ' 23:59:59';
        }

        return $this->paginate(
            $dt,
            'l.id, l.created_at, l.user_name, l.entity_type, l.entity_id, l.action, l.summary,
             l.before_data, l.after_data, l.ip_address, s.name AS site',
            'activity_log l LEFT JOIN sites s ON s.id = l.site_id',
            [$visSql],
            $params,
            $where,
            $fp,
            ['l.user_name', 'l.summary', 'l.entity_type', 'l.action'],
        );
    }

    public function forEntity(string $entityType, int $entityId, int $limit = 30): array
    {
        [$visSql, $params] = $this->visibility();

        return $this->db->fetchAll(
            "SELECT l.id, l.created_at, l.user_name, l.action, l.summary, l.before_data, l.after_data
               FROM activity_log l
              WHERE l.entity_type = :type AND l.entity_id = :id AND $visSql
              ORDER BY l.id DESC LIMIT " . max(1, $limit),
            ['type' => $entityType, 'id' => $entityId] + $params
        );
    }
}
