<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DataTableRequest;

/** Personnel de terrain (chauffeurs, opérateurs, maintenanciers…), toujours filtré par site. */
final class PersonnelRepository extends Repository
{
    public const SORTABLE = [
        'name' => 'p.last_name',
        'employee_no' => 'p.employee_no',
        'job' => 'p.job',
        'site' => 's.name',
        'team' => 't.name',
        'is_active' => 'p.is_active',
    ];

    private const FROM = 'personnel p
        JOIN sites s ON s.id = p.site_id
        LEFT JOIN teams t ON t.id = p.team_id';

    public function datatable(DataTableRequest $dt, array $filters): array
    {
        [$scopeSql, $params] = $this->scope->clause('p.site_id');
        $where = [];
        $fp = [];
        if (!empty($filters['site_id'])) {
            $where[] = 'p.site_id = :f_site';
            $fp['f_site'] = (int) $filters['site_id'];
        }
        if (!empty($filters['job'])) {
            $where[] = 'p.job = :f_job';
            $fp['f_job'] = (string) $filters['job'];
        }
        if (!empty($filters['team_id'])) {
            $where[] = 'p.team_id = :f_team';
            $fp['f_team'] = (int) $filters['team_id'];
        }
        if (empty($filters['inactive'])) {
            $where[] = 'p.is_active = 1';
        }

        return $this->paginate(
            $dt,
            "p.id, p.employee_no, p.first_name, p.last_name, CONCAT(p.first_name, ' ', p.last_name) AS name,
             p.job, p.phone, p.is_active, s.name AS site, t.name AS team, t.shift,
             (SELECT GROUP_CONCAT(e.code ORDER BY e.code SEPARATOR ', ') FROM equipment_assignments a
                JOIN equipment e ON e.id = a.equipment_id WHERE a.personnel_id = p.id AND a.ended_at IS NULL) AS equipment",
            self::FROM,
            [$scopeSql],
            $params,
            $where,
            $fp,
            ['p.first_name', 'p.last_name', 'p.employee_no', 'p.phone'],
        );
    }

    public function find(int $id): ?array
    {
        [$scopeSql, $params] = $this->scope->clause('p.site_id');

        return $this->db->fetchOne(
            "SELECT p.*, CONCAT(p.first_name, ' ', p.last_name) AS name, s.name AS site, t.name AS team
               FROM " . self::FROM . " WHERE p.id = :id AND $scopeSql",
            ['id' => $id] + $params
        );
    }

    /**
     * Personnel actif d'un site, éventuellement restreint à certaines fonctions.
     *
     * @param list<string> $jobs
     */
    public function forSite(int $siteId, array $jobs = []): array
    {
        [$scopeSql, $params] = $this->scope->clause('p.site_id');
        $jobSql = '';
        foreach (array_values($jobs) as $i => $job) {
            $params["job$i"] = $job;
        }
        if ($jobs !== []) {
            $jobSql = ' AND p.job IN (' . implode(', ', array_map(static fn ($i) => ":job$i", array_keys(array_values($jobs)))) . ')';
        }

        return $this->db->fetchAll(
            "SELECT p.id, p.first_name, p.last_name, CONCAT(p.first_name, ' ', p.last_name) AS name, p.job, p.phone,
                    p.employee_no, p.team_id, t.name AS team, t.shift
               FROM personnel p LEFT JOIN teams t ON t.id = p.team_id
              WHERE p.site_id = :site AND p.is_active = 1 AND $scopeSql $jobSql
              ORDER BY p.last_name, p.first_name",
            ['site' => $siteId] + $params
        );
    }

    public function members(int $teamId): array
    {
        [$scopeSql, $params] = $this->scope->clause('p.site_id');

        return $this->db->fetchAll(
            "SELECT p.id, CONCAT(p.first_name, ' ', p.last_name) AS name, p.job, p.phone, p.employee_no
               FROM personnel p WHERE p.team_id = :team AND p.is_active = 1 AND $scopeSql
              ORDER BY FIELD(p.job, 'team_lead', 'supervisor', 'dispatcher') DESC, p.last_name",
            ['team' => $teamId] + $params
        );
    }

    public function employeeNoExists(string $no, ?int $exceptId = null): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT 1 FROM personnel WHERE employee_no = :no AND id <> :id',
            ['no' => $no, 'id' => $exceptId ?? 0]
        );
    }

    public function create(array $data): int
    {
        $this->scope->assertAllows((int) $data['site_id']);

        return $this->db->insert(
            'INSERT INTO personnel (site_id, team_id, employee_no, first_name, last_name, job, phone, license_number, notes, is_active)
             VALUES (:site_id, :team_id, :employee_no, :first_name, :last_name, :job, :phone, :license_number, :notes, :is_active)',
            self::params($data)
        );
    }

    public function update(int $id, array $data): void
    {
        $this->scope->assertAllows((int) $data['site_id']);
        [$scopeSql, $params] = $this->scope->clause('site_id');
        $this->db->execute(
            "UPDATE personnel SET site_id = :site_id, team_id = :team_id, employee_no = :employee_no, first_name = :first_name,
                    last_name = :last_name, job = :job, phone = :phone, license_number = :license_number, notes = :notes,
                    is_active = :is_active
              WHERE id = :id AND $scopeSql",
            self::params($data) + ['id' => $id] + $params
        );
    }

    private static function params(array $data): array
    {
        return [
            'site_id' => (int) $data['site_id'],
            'team_id' => $data['team_id'] ?? null,
            'employee_no' => $data['employee_no'] ?? null,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'job' => $data['job'],
            'phone' => $data['phone'] ?? null,
            'license_number' => $data['license_number'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ];
    }
}
